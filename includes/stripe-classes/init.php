<?php

class Sp_Checkout_Init
{
    protected $is_active = false;
    protected $client_id;
    protected $client_secret;
    protected $publishable_key;

    /**
     * @var null
     *
     * Instance of this class
     */
    protected static $_instance = null;

    /**
     * @return null|WPCF
     */
    public static function instance()
    {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    function __construct()
    {
        $settings = get_option('woocommerce_sp_stripe_checkout_settings');
        !is_array($settings) ? $settings = array() : 0;

        if (!empty($settings['enabled'])  && $settings['enabled'] == 'yes') {
            $this->is_active = true;
            add_action('wpcf_dashboard_after_dashboard_form', array($this, 'generate_stripe_connect_form'), 10);
        }

        if (!empty($settings['test_mode']) && $settings['test_mode'] === 'yes') {
            $this->client_id = empty($settings['test_client_id']) ? '' : $settings['test_client_id'];
            $this->client_secret = empty($settings['test_secret_key']) ? '' : $settings['test_secret_key'];
            $this->publishable_key = empty($settings['test_publishable_key']) ? '' : $settings['test_publishable_key'];
        } else {
            $this->client_id = empty($settings['live_client_id']) ? '' : $settings['live_client_id'];
            $this->client_secret = empty($settings['secret_key']) ? '' : $settings['secret_key'];
            $this->publishable_key = empty($settings['publishable_key']) ? '' : $settings['publishable_key'];
        }

        if (!empty($settings['enabled'])  && $settings['enabled'] == 'yes') {
            add_action('wp_ajax_wpcf_stripe_disconnect', array($this, 'stripe_disconnect'));
            add_filter('woocommerce_available_payment_gateways', array($this, 'filter_gateways'), 1);
        }
    }

    public function generate_stripe_connect_form()
    {
        wp_enqueue_script('dashboard-script', SP_PLUGIN_URL . 'assets/js/dashboard.js', array('jquery'), 1.0, true);

        $this->capture_on_hold_payments(191);

        $stripe_user_id = $this->get_authorized_from_stripe_application();
        $authorize_request_body = array(
            'response_type' => 'code',
            'scope' => 'read_write',
            'client_id' => $this->client_id
        );
        $url = 'https://connect.stripe.com/oauth/authorize' . '?' . http_build_query($authorize_request_body);

        $html = '';
        $html .= '<div class="wpneo-single"><div class="wpneo-name float-left"><p>Stripe:</p></div><div class="wpneo-fields float-right">';

        if ($stripe_user_id) {
            $html .= '<p><span>' . __('Connected', 'stripe-payment') . '</span>: ' . $stripe_user_id . '</p>'; // Connect Button
            $html .= '<a class="stripe-connect wpcf-stripe-connect-deauth" href="javascript:void(0)"><span>' . __('Disconnect', 'stripe-payment') . '</span></a>'; // Disconnect Button
        } else {
            $html .= '<a href="' . $url . '" class="stripe-connect"><span>' . __('Connect with Stripe', 'stripe-payment') . '</span></a>';
        }
        $html .= '</div></div>';
        echo $html;
    }

    public function get_authorized_from_stripe_application()
    {
        $user = wp_get_current_user();

        if (isset($_GET['code'])) { // Redirect w/ code
            $code = sanitize_text_field($_GET['code']);

            \Stripe\Stripe::setClientId($this->client_id);
            \Stripe\Stripe::setApiKey($this->client_secret);

            $response = \Stripe\OAuth::token([
                'grant_type' => 'authorization_code',
                'code' => $code,
            ]);

            // Access the connected account id in the response
            $connected_account_id = $response->stripe_user_id;

            if ($connected_account_id) {
                update_user_meta($user->ID, 'stripe_user_id', $connected_account_id);
                SP_Stripe_Log::log_update('live', "User ID: " . $user->ID . " - Account ID: " . $connected_account_id, __('Connected user stripe account', 'stripe-payment'));
                return $connected_account_id;
            }
        } else {
            return get_user_meta($user->ID, 'stripe_user_id', true);
        }
        return false;
    }

    /**
     * @param $gateways
     * @return mixed
     */

    function filter_gateways($gateways)
    {
        if (function_exists('WC')) {
            if (!empty(WC()->cart) || null !== WC()->cart) {
                foreach (WC()->cart->get_cart() as $key => $values) {
                    if (isset($values['product_id'])) {
                        $_product = wc_get_product($values['product_id']);
                        if ($_product->is_type('crowdfunding') || $_product->is_type('reward')) {
                            if (is_array($gateways)) {
                                //Check if this campaign owner connected with stripe?
                                $post = get_post($_product->get_id());
                                $campaign_owner_id = $post->post_author;
                                $campaign_owner = get_user_meta($campaign_owner_id, 'stripe_user_id', true);
                                if (!$campaign_owner) {
                                    unset($gateways['wpneo_stripe_connect']);
                                }
                            }
                        } else {
                            unset($gateways['wpneo_stripe_connect']);
                        }
                    }
                }
            }
        }
        return $gateways;
    }

    // Stripe disconnect action
    public function stripe_disconnect()
    {
        $user = wp_get_current_user();
        $stripe_user_id = get_user_meta($user->ID, 'stripe_user_id', true);
        $request_body = array(
            'client_id'         => $this->client_id,
            'stripe_user_id'    => $stripe_user_id
        );

        \Stripe\Stripe::setClientId($this->client_id);
        \Stripe\Stripe::setApiKey($this->client_secret);

        $resp = \Stripe\OAuth::deauthorize($request_body);

        $redirect = get_permalink(get_option('wpneo_crowdfunding_dashboard_page_id')) . '?page_type=dashboard';
        if (!empty($resp['stripe_user_id'])) {
            update_user_meta($user->ID, 'stripe_user_id', '');
            die(json_encode(array('success' => 1, 'message' => __('Stripe disconnected', 'stripe-payment'), 'redirect' => $redirect)));
        } else {
            die(json_encode(array('success' => 0, 'message' => __('Something went wrong, please try again', 'stripe-payment'), 'redirect' => $redirect)));
        }
    }

    /**
     * Check on hold orders of a campaign
     */
    function capture_on_hold_payments($campaign_id)
    {
        $on_hold_orders = $this->get_orders_ids_by_product_id($campaign_id, array('wc-on-hold'));
        echo '<pre>';
        print_r($on_hold_orders);
        echo '</pre>';
        if (!empty($on_hold_orders)) {
            foreach ($on_hold_orders as $order_id) {

                $order = wc_get_order($order_id);
                $payment_intent = get_post_meta($order_id, '_sp_payment_id', true);
                $order_status = $order->status;
                if (!empty($payment_intent) && $order_status == 'on-hold') {
                    echo "Order " . $order_id . " - Status " . $order_status . " - Payment Intent ID: " . $payment_intent;
                    echo '<br>';
                }
            }
        }
    }

    /**
     * Get All orders IDs for a given product ID.
     *
     * @param  integer  $product_id (required)
     * @param  array    $order_status (optional) Default is 'wc-completed'
     *
     * @return array
     */
    function get_orders_ids_by_product_id($product_id, $order_status = array('wc-completed'))
    {
        global $wpdb;

        $results = $wpdb->get_col("
        SELECT order_items.order_id
        FROM {$wpdb->prefix}woocommerce_order_items as order_items
        LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta as order_item_meta ON order_items.order_item_id = order_item_meta.order_item_id
        LEFT JOIN {$wpdb->posts} AS posts ON order_items.order_id = posts.ID
        WHERE posts.post_type = 'shop_order'
        AND posts.post_status IN ( '" . implode("','", $order_status) . "' )
        AND order_items.order_item_type = 'line_item'
        AND order_item_meta.meta_key = '_product_id'
        AND order_item_meta.meta_value = '$product_id'
    ");

        return $results;
    }
}
