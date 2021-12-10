<?php

if (!defined('ABSPATH')) {
    exit;
}

class SP_Payment_Gateway extends WC_Payment_Gateway
{

    public function __construct()
    {
        $this->id       = 'sp_stripe_checkout';
        $this->method_title = __('Stripe Checkout Gateway', 'stripe-payment');
        $this->has_fields = true;
        $this->supports = array(
            'products',
            'refunds'
        );
        $this->init_form_fields();
        $this->init_settings();
        $this->enabled = $this->get_option('enabled');
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->application_fee = $this->get_option('receivers_percent');
        $this->sp_order_button = $this->get_option('sp_order_button');
        $this->order_button_text = __($this->sp_order_button, 'stripe-payment');
        $this->method_description = sprintf(__("Accepts Stripe payments via credit or debit card.", 'stripe-payment') . " <p><a target='_blank' href='https://focoazul.com/'>  " . __('Visit developer', 'stripe-payment') . " </a> </p> ");

        if ($this->get_option('test_mode') === 'yes') {
            $this->testmode = true;
            $this->client_id = $this->get_option('test_client_id');
            $this->secret_key = $this->get_option('test_secret_key');
            $this->publishable_key = $this->get_option('test_publishable_key');
        } else {
            $this->testmode = false;
            $this->client_id = $this->get_option('live_client_id');
            $this->secret_key = $this->get_option('secret_key');
            $this->publishable_key = $this->get_option('publishable_key');
        }

        if ($this->testmode) {
            $this->description .= ' ' . sprintf(__('TEST MODE ENABLED. In test mode, you can use the card number 4242424242424242 with any CVC and a valid expiration date or check the documentation "<a href="%s">Testing Stripe</a>" for more card numbers.', 'woocommerce-gateway-stripe'), 'https://stripe.com/docs/testing');
            $this->description  = trim($this->description);
        }

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));

        add_action('wc_ajax_sp_stripe_checkout_order', array($this, 'sp_stripe_checkout_order_callback'));
        add_action('wc_ajax_sp_stripe_cancel_order', array($this, 'sp_stripe_cancel_order'));

        add_action('woocommerce_available_payment_gateways', array($this, 'sp_disable_gateway_for_order_pay'));
        add_action('set_logged_in_cookie', array($this, 'sp_set_cookie_on_current_request'));

        // Set stripe API key.
        \Stripe\Stripe::setApiKey($this->secret_key);
        \Stripe\Stripe::setAppInfo('WordPress freeit-payment-gateway');
    }

    /**
     * Initialize form fields.
     */
    public function init_form_fields()
    {
        $this->form_fields = include('sp-settings-page.php');
    }

    /**
     * Checks if gateway should be available to use.    
     */
    public function is_available()
    {

        if ('yes' === $this->enabled) {

            // Check if required variables are set
            if (isset($this->client_id)) {
                if (!isset($this->secret_key) || !isset($this->publishable_key) || !$this->publishable_key || !$this->secret_key || !$this->application_fee) {
                    return false;
                }
            }

            return true;
        }
        return false;
    }

    /**
     * Payment form on checkout page    
     */
    public function payment_fields()
    {
        echo '<div class="status-box">';

        if ($this->description) {
            echo apply_filters('sp_stripe_desc', wpautop(wp_kses_post("<span>" . $this->description . "</span>")));
        }
        echo "</div>";
    }

    /**
     * loads stripe checkout scripts.
     * @since 3.3.4
     */
    public function payment_scripts()
    {
        if ((is_checkout() || is_product() || is_cart() || is_add_payment_method_page())  && !is_order_received_page()) {
            wp_register_script('stripe_v3_js', 'https://js.stripe.com/v3/');

            wp_enqueue_script('sp_checkout_script', SP_PLUGIN_URL . 'assets/js/sp-checkout.js', array('stripe_v3_js', 'jquery'), 1.0, true);

            $public_key = $this->public_key;
            $secret_key = $this->secret_key;

            $sp_checkout_params = array(
                'key'                                           => isset($public_key) ? $public_key : '',
                'wp_ajaxurl'                                    => admin_url("admin-ajax.php"),
                'wc_ajaxurl'                                    => WC_AJAX::get_endpoint('%%change_end%%'),
            );
            wp_localize_script('sp_checkout_script', 'sp_checkout_params', $sp_checkout_params);
        }
    }

    /**
     * Proceed with current request using new login session (to ensure consistent nonce).
     */
    public function sp_set_cookie_on_current_request($cookie)
    {
        $_COOKIE[LOGGED_IN_COOKIE] = $cookie;
    }

    /**
     * Disable stripe checkout gateway for order-pay page 
     */
    function sp_disable_gateway_for_order_pay($available_gateways)
    {
        if (is_wc_endpoint_url('order-pay')) {

            unset($available_gateways['sp_stripe_checkout']);
        }
        return $available_gateways;
    }

    /**
     * Process the payment
     */
    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);
        $currency =  $order->get_currency();

        $total = $this->get_stripe_amount($order->get_total(), get_woocommerce_currency());

        $session_data = array(
            'payment_method_types' => ['card'],
            'locale'               => 'auto',
            'success_url'          => home_url() . '/?wc-ajax=sp_stripe_checkout_order' . '&sessionid={CHECKOUT_SESSION_ID}' . '&order_id=' . $order_id . '&_wpnonce=' . wp_create_nonce('sp_checkout_nonce'),
            'cancel_url'           => home_url() . '/?wc-ajax=sp_stripe_cancel_order' . '&_wpnonce=' . wp_create_nonce('sp_checkout_nonce') . '&order_id=' . $order_id,

        );

        $email = (WC()->version < '2.7.0') ? $order->billing_email : $order->get_billing_email();

        if (!empty($email)) {
            $session_data['customer_email'] = $email;
        }

        /**Stripe connection  */

        // Payment Intent with campaign owner info

        $payment_mode = 'payment';
        $stripe_receiver_account = null;
        foreach ($order->get_items() as $item) {
            $product = wc_get_product($item['product_id']);
            if ($product->get_type() == 'crowdfunding') {
                // Get campaign owner stripe ID
                $campaign_author = $product->post->post_author;
                $stripe_receiver_account = get_user_meta($campaign_author, 'stripe_user_id', true);

                if (!$this->minimum_fund_reached($product)) {
                    $payment_mode = 'setup';
                }
            }
        }
        $session_data['mode'] = $payment_mode;

        if ($payment_mode == 'payment') {
            // Set line items
            $session_data['line_items'] = array(
                [
                    'name'     => esc_html(__('Total', 'payment-gateway-stripe-and-woocommerce-integration')),
                    'amount'   => $total,
                    'currency' => strtolower(get_woocommerce_currency()),
                    'quantity' => 1,
                ]
            );

            // Set payment intent data
            $receivers_percent = $this->application_fee;
            $receivers_percent = absint($receivers_percent);
            $reciever_amount = ($total * $receivers_percent) / 100;
            $application_fee = $total - $reciever_amount;

            $payment_intent_data = [
                'description'            => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES) . ' Order #' . $order->get_order_number(),
                'application_fee_amount' => $application_fee,
                'on_behalf_of'           => $stripe_receiver_account,
                'transfer_data'          => [
                    'destination'        => $stripe_receiver_account,
                ]
            ];
            $session_data['payment_intent_data'] = $payment_intent_data;
        }

        $session = \Stripe\Checkout\Session::create($session_data);

        return  array(
            'result'   => 'success',
            'redirect' => $this->get_payment_session_checkout_url($session->id, $order),
        );
    }


    /**
     * Checks if minimum fund raise of the campaign has been reached
     * 
     * @return bool 
     */
    private function minimum_fund_reached(\WC_Product $product)
    {
        // Check minimum funding in order to setup a payment or capture inmediatly
        $product_id = $product->get_id();
        $raised_percent = wpcf_function()->get_raised_percent($product_id);
        $minimum_percent = get_post_meta($product_id, 'freeit-minimum-funding-required', true);

        return $raised_percent > $minimum_percent;
    }

    function get_payment_session_checkout_url($session_id, $order)
    {

        return sprintf(
            '#response=%s',
            base64_encode(
                wp_json_encode(
                    array(
                        'session_id' => $session_id,
                        'order_id'      => (WC()->version < '2.7.0') ? $order->id : $order->get_id(),
                        'time'          => rand(
                            0,
                            999999
                        ),
                    )
                )
            )
        );
    }

    /**
     * creates order after checkout session is completed.
     * 
     * 
     */
    public function sp_stripe_checkout_order_callback()
    {

        if (!$this->verify_nonce(SP_PLUGIN_NAME, 'sp_checkout_nonce')) {
            die(_e('Access Denied', 'stripe-payment'));
        }

        $session_id = sanitize_text_field($_GET['sessionid']);
        $order_id = intval($_GET['order_id']);
        $order = wc_get_order($order_id);

        $order_time = date('Y-m-d H:i:s', time() + get_option('gmt_offset') * 3600);

        $session = \Stripe\Checkout\Session::retrieve($session_id);

        // Check payment mode, If it's "setup"
        if ($session->mode == "setup") {
            //Retrieve setup intent ID
            $payment_id = $session->setup_intent;
            $setup_intent = \Stripe\SetupIntent::retrieve($payment_id);

            $data = [
                'id'             => $setup_intent->id,
                'mode'           => $setup_intent->livemode ? 'live' : 'test',
                'transaction_id' => $setup_intent->id,
                'source_type'    => 'card',
                'status'         => 'Waiting minimum fund',
                'paid'           => 'Paid',
                'captured'       => 'Uncaptured'
            ];
        } else {
            $payment_id = $session->payment_intent;


            $payment_intent = \Stripe\PaymentIntent::retrieve($payment_id);
            $charge_details = $payment_intent->charges['data'];

            foreach ($charge_details as $charge) {
                $charge_response = $charge;
            }

            $data = $this->make_charge_params($charge_response, $order_id);
        }

        add_post_meta($order_id, '_sp_payment_id', $payment_id);

        if ($data['paid'] == 'Paid') {

            if ($data['captured'] == 'Captured') {
                $order->payment_complete($data['id']);
            } else {
                $order->update_status('on-hold');
            }

            $order->set_transaction_id($data['transaction_id']);

            $order->add_order_note(__('Payment Status : ', 'payment-gateway-stripe-and-woocommerce-integration') . ucfirst($data['status']) . ' [ ' . $order_time . ' ] . ' . __('Source : ', 'payment-gateway-stripe-and-woocommerce-integration') . $data['source_type'] . '. ' . __('Charge Status :', 'payment-gateway-stripe-and-woocommerce-integration') . $data['captured'] . (is_null($data['transaction_id']) ? '' : '.' . __('Transaction ID : ', 'payment-gateway-stripe-and-woocommerce-integration') . $data['transaction_id']));
            WC()->cart->empty_cart();
            add_post_meta($order_id, '_sp_stripe_payment_charge', $data);
            SP_Stripe_Log::log_update('live', $data, get_bloginfo('blogname') . ' - Charge - Order #' . $order_id);

            // Return thank you page redirect.
            $result =  array(
                'result'    => 'success',
                'redirect'  => $this->get_return_url($order),
            );

            /**
             * Check order products to see if minimum fund has been raised
             
            foreach ($order->get_items() as $item) {
                $product = wc_get_product($item['product_id']);
                if ($product->get_type() == 'crowdfunding') {

                    if ($this->minimum_fund_reached($product)) {
                        $this->capture_on_hold_payments($product->ID);
                    }
                }
            }*/

            wp_safe_redirect($result['redirect']);
            exit;
        } else {
            wc_add_notice($data['status'], $notice_type = 'error');
            SP_Stripe_Log::log_update('dead', $charge_response, get_bloginfo('blogname') . ' - Charge - Order #' . $order_id);
        }
    }


    /**
     * Check on hold orders of a campaign
     */
    function capture_on_hold_payments($campaign_id)
    {
        $on_hold_orders = $this->get_orders_ids_by_product_id($campaign_id);
        if (!empty($on_hold_orders)) {
            foreach ($on_hold_orders as $order_id) {

                $order = wc_get_order($order_id);
                $payment_intent = get_post_meta($order_id, '_sp_payment_id', true);
                $order_status = $order->status;
                if (!empty($payment_intent) && $order_status == 'on-hold') {
                    $stripe = new \Stripe\StripeClient(
                        $this->client_id
                    );
                    $reponse = $stripe->paymentIntents->capture($payment_intent);
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
    function get_orders_ids_by_product_id($product_id, $order_status = array('wc-on-hold'))
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

    /**
     * Handler when user cancel his order on stripe checkout page
     */
    public function sp_stripe_cancel_order()
    {

        if (!$this->verify_nonce(SP_PLUGIN_NAME, 'sp_checkout_nonce')) {
            die(_e('Access Denied', 'stripe-payment'));
        }

        $order_id = intval($_GET['order_id']);
        $order = wc_get_order($order_id);

        if (isset($_GET['createaccount']) && absint($_GET['createaccount']) == 1) {
            $userID = (WC()->version < '2.7.0') ? $order->user_id : $order->get_user_id();
            wc_set_customer_auth_cookie($userID);
        }

        wc_add_notice(__('You have cancelled Stripe Checkout Session. Please try to process your order again.', 'stripe-payment'), 'notice');
        wp_redirect(wc_get_checkout_url());
        exit;
    }

    /**
     * 	Verifying nonce
     *
     *	@param string $plugin_id unique plugin id. Note: This id is used as an identifier in filter name so please use characters allowed in filters 
     *	@param string $nonce_id Nonce id. If not specified then uses plugin id
     *	@return boolean if user allowed or not
     */
    public static function verify_nonce($plugin_id, $nonce_id = '')
    {
        $nonce = (isset($_REQUEST['_wpnonce']) ? sanitize_text_field($_REQUEST['_wpnonce']) : '');
        $nonce = (is_array($nonce) ? $nonce[0] : $nonce); //in some cases multiple nonces are declared
        $nonce_id = ($nonce_id == "" ? $plugin_id : $nonce_id); //if nonce id not provided then uses plugin id as nonce id

        if (!(wp_verify_nonce($nonce, $nonce_id))) //verifying nonce
        {
            return false;
        } else {
            return true;
        }
    }

    /**
     *Creates charge parameters from charge response.
     */
    public function make_charge_params($charge_value, $order_id)
    {
        $wc_order = wc_get_order($order_id);
        $charge_data = json_decode(json_encode($charge_value));
        $origin_time = date('Y-m-d H:i:s', time() + get_option('gmt_offset') * 3600);
        $charge_parsed = array(
            "id" => $charge_data->id,
            "amount" => $this->reset_stripe_amount($charge_data->amount, $charge_data->currency),
            "amount_refunded" => $this->reset_stripe_amount($charge_data->amount_refunded, $charge_data->currency),
            "currency" => strtoupper($charge_data->currency),
            "order_amount" => (WC()->version < '2.7.0') ? $wc_order->order_total : $wc_order->get_total(),
            "order_currency" => (WC()->version < '2.7.0') ? $wc_order->order_currency : $wc_order->get_currency(),
            "captured" => $charge_data->captured ? "Captured" : "Uncaptured",
            "transaction_id" => $charge_data->balance_transaction,
            "mode" => (false == $charge_data->livemode) ? 'Test' : 'Live',
            "metadata" => $charge_data->metadata,
            "created" => date('Y-m-d H:i:s', $charge_data->created),
            "paid" => $charge_data->paid ? 'Paid' : 'Not Paid',
            "receiptemail" => (null == $charge_data->receipt_email) ? 'Receipt not send' : $charge_data->receipt_email,
            "receiptnumber" => (null == $charge_data->receipt_number) ? 'No Receipt Number' : $charge_data->receipt_number,
            "source_type" => ('card' == $charge_data->payment_method_details->type) ? $charge_data->payment_method_details->card->brand . "( " . $charge_data->payment_method_details->card->funding . " )" : 'Undefined',
            "status" => $charge_data->status,
            "origin_time" => $origin_time
        );
        $trans_time = date('Y-m-d H:i:s', time() + ((get_option('gmt_offset') * 3600) + 10));
        $tranaction_data = array(
            "id" => $charge_data->id,
            "total_amount" => $charge_parsed['amount'],
            "currency" => $charge_parsed['currency'],
            "balance_amount" => 0,
            "origin_time" => $trans_time
        );
        return $charge_parsed;
    }

    /**
     *List of zero decimal currencies supported by stripe.
     */
    public static function zerocurrency()
    {
        return array("BIF", "CLP", "DJF", "GNF", "JPY", "KMF", "KRW", "MGA", "PYG", "RWF", "VUV", "XAF", "XOF", "XPF", "VND");
    }

    /**
     *Gets stripe amount.
     */
    public  static function get_stripe_amount($total, $currency = '')
    {
        if (!$currency) {
            $currency = get_woocommerce_currency();
        }
        if (in_array(strtoupper($currency), self::zerocurrency())) {
            // Zero decimal currencies
            $total = absint($total);
        } else {
            $total = round($total, 2) * 100; // In cents
        }
        return $total;
    }

    /**
     *Reset stripe amount after charge response.
     */
    public function reset_stripe_amount($total, $currency = '')
    {
        if (!$currency) {
            $currency = get_woocommerce_currency();
        }
        if (in_array(strtoupper($currency), self::zerocurrency())) {
            // Zero decimal currencies
            $total = absint($total);
        } else {
            $total = round($total, 2) / 100; // In cents
        }
        return $total;
    }
}
