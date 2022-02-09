<?php

class SP_Api_Handler
{
    private $endpoint_secret;

    function __construct()
    {
        // Setup API Endpoints
        add_action('rest_api_init', array($this, 'sp_register_api_route'));

        // Check cron job
        add_action('init', array($this, 'check_cron_job'));

        // Set endpoint secret key
        add_action('init', array($this, 'set_endpoint_secret_key'));
    }

    public function set_endpoint_secret_key()
    {
        $stripe_gateway = WC()->payment_gateways->payment_gateways()['sp_stripe_checkout'];
        $this->endpoint_secret = $stripe_gateway->get_option('endpoint_secret') ?: null;
    }

    /**
     * If the stripe_biller cron job has no functions attached, add the needed one
     */
    function check_cron_job()
    {
        if (!has_action('stripe_biller')) {
            add_action('stripe_biller', array($this, 'capture_on_hold_payments'));
        }
    }


    function sp_register_api_route()
    {
        register_rest_route('stripe-payment/v1', '/stripe', [
            'methods'   => WP_REST_Server::CREATABLE,
            'callback'  => array($this, 'sp_endpoint')
        ]);

        // register_rest_route('stripe-payment/v1', '/stripe', [
        //     'methods'   => WP_REST_Server::READABLE,
        //     'callback'  => array($this, 'test')
        // ]);
    }

    // function test($request)
    // {
    //     $product_id = $_GET['id'];
    //     $this->capture_on_hold_payments($product_id);
    //     return rest_ensure_response('Done');
    // }

    function sp_endpoint(WP_REST_Request $request)
    {
        if (!$this->endpoint_secret) rest_ensure_response('No endpoint secret set');

        // Get the post_id from the value of '_stripe_intent_id' at the post_meta table
        $endpoint_secret = $this->endpoint_secret;
        $event           = $request->get_body();
        $signature       = $request->get_header('stripe_signature');
        try {
            $stripe_event    = \Stripe\Webhook::constructEvent($event, $signature, $endpoint_secret);

            switch ($stripe_event->type) {
                case 'checkout.session.completed':
                    $session = $stripe_event->data->object;
                    $session_id = $session->id;
                    $order = $this->get_order_from_session_id($session_id);

                    if ($order) {
                        /**
                         * Check order products to see if minimum fund has been raised
                         */
                        foreach ($order->get_items() as $item) {
                            $product = wc_get_product($item['product_id']);
                            if ($product->get_type() == 'crowdfunding') {
                                if (sp_functions()->minimum_fund_reached($product)) {
                                    // Check if there is any on-hold order
                                    if (!empty($this->get_orders_ids_by_product_id($product->get_id()))) {
                                        // Schedule cron event if it don't exists yet
                                        $hook_name = 'stripe_biller';
                                        $is_scheduled = wp_next_scheduled($hook_name, array($product->get_id()));
                                        if (!$is_scheduled) {
                                            $scheduled = wp_schedule_event(time(), 'every_minute', $hook_name, array($product->get_id()));
                                            if ($scheduled)
                                                SP_Stripe_Log::log_update('live', 'Scheduled stripe biller for campaign: ' . $product->get_id(), 'Stripe Scheduled biller event');
                                        }

                                        // Uncomment for debugging
                                        // $this->capture_on_hold_payments($product->get_id());
                                    }
                                }
                            }
                        }
                    }
                    break;
                case 'payment_intent.payment_failed':
                    $payment_intent = $stripe_event->data->object;
                    $payment_status = $payment_intent->status;

                    switch ($payment_status) {
                        case 'requires_payment_method':
                            break;
                        case 'insufficient_funds':
                            break;
                        case 'authentication_required':
                            break;
                        default:
                            break;
                    }

                    SP_Stripe_Log::log_update('dead', 'Payment intent failed for campaign #', 'Stripe Payment failed');
                    break;

                default:

                    break;
            }
        } catch (\Stripe\Exception\SignatureVerificationException $signature_error) {
            SP_Stripe_Log::log_update('dead', $signature_error, 'Stripe signature error');
        } catch (\Throwable $th) {
            SP_Stripe_Log::log_update('dead', $th, 'Stripe Unknow error');
        }

        return rest_ensure_response('Done');
    }

    /**
     * Gets the order's post ID from the database, querying for the meta value of '_stripe_checkout_session_id' that was saved when the order was created.
     * 
     * @return WC_Order Returns the order from the database or false if the session_id has no order attached
     */
    function get_order_from_session_id($session_id)
    {
        global $wpdb;

        $order_id = $wpdb->get_col("SELECT post_id
         from $wpdb->postmeta
         WHERE meta_value = '$session_id'")[0];

        if (empty($order_id))
            return false;

        $order = wc_get_order($order_id);
        return $order;
    }

    /**
     * Check on hold orders of a campaign
     */
    public function capture_on_hold_payments($campaign_id)
    {
        // Modify query to select each quantity of orders on every iteration
        $on_hold_orders = $this->get_orders_ids_by_product_id($campaign_id);

        if (!empty($on_hold_orders)) {
            foreach ($on_hold_orders as $order_id) {
                $order = wc_get_order($order_id);
                $order_status = $order->status;
                $order_time = date('Y-m-d H:i:s', time() + get_option('gmt_offset') * 3600);

                $user_id = $order->get_user_id();
                $stripe_customer_id = get_user_meta($user_id, 'stripe_customer_id', true) ?: 'cus_KnJFNhoaXrYkaB';
                $payment_method = get_user_meta($user_id, 'stripe_customer_payment_method', true) ?: 'pm_1K7icEBvu1rdRS6s1JaYqsFk';
                if (!empty($stripe_customer_id) && $order_status == 'on-hold') {

                    $total = sp_functions()->get_stripe_amount($order->get_total(), get_woocommerce_currency());
                    $stripe_receiver_account = null;
                    foreach ($order->get_items() as $item) {
                        $product = wc_get_product($item['product_id']);
                        if ($product->get_type() == 'crowdfunding') {
                            // Get campaign owner stripe ID
                            $campaign_author = $product->post->post_author;
                            $stripe_receiver_account = get_user_meta($campaign_author, 'stripe_user_id', true);
                        }
                    }
                    // Set payment intent data
                    $receivers_percent = $this->application_fee;
                    $receivers_percent = absint($receivers_percent);
                    $reciever_amount = ($total * $receivers_percent) / 100;
                    $application_fee = $total - $reciever_amount;

                    if ($stripe_receiver_account) {
                        try {
                            // Create payment intent
                            $payment_intent = \Stripe\PaymentIntent::create(
                                [
                                    'description'    => 'Campaign donation payment for order #' . $order->get_order_number(),
                                    'amount'         => $total,
                                    'currency'       => strtolower(get_woocommerce_currency()),
                                    'off_session'    => true,
                                    'confirm'        => true,
                                    'customer'       => $stripe_customer_id,
                                    'payment_method' => $payment_method,
                                    'application_fee_amount' => $application_fee,
                                    'on_behalf_of'           => $stripe_receiver_account,
                                    'transfer_data'          => [
                                        'destination'        => $stripe_receiver_account,
                                    ],
                                ]
                            );

                            $charge_details = $payment_intent->charges['data'];

                            foreach ($charge_details as $charge) {
                                $charge_response = $charge;
                            }

                            $data = sp_functions()->make_charge_params($charge_response, $order_id);

                            add_post_meta($order_id, '_sp_payment_id', $payment_intent->id);

                            if ($data['paid'] == 'Paid') {

                                if ($data['captured'] == 'Captured') {
                                    $order->payment_complete($data['id']);
                                } else {
                                    $order->update_status('on-hold');
                                }
                            }

                            $order->set_transaction_id($data['transaction_id']);

                            $order->add_order_note(__('Payment Status : ', 'payment-gateway-stripe-and-woocommerce-integration') . ucfirst($data['status']) . ' [ ' . $order_time . ' ] . ' . __('Source : ', 'payment-gateway-stripe-and-woocommerce-integration') . $data['source_type'] . '. ' . __('Charge Status :', 'payment-gateway-stripe-and-woocommerce-integration') . $data['captured'] . (is_null($data['transaction_id']) ? '' : '.' . __('Transaction ID : ', 'payment-gateway-stripe-and-woocommerce-integration') . $data['transaction_id']));
                            add_post_meta($order_id, '_sp_stripe_payment_charge', $data);
                            SP_Stripe_Log::log_update('live', $data, get_bloginfo('blogname') . ' - Charge - Order #' . $order_id);
                        } catch (\Throwable $th) {
                            SP_Stripe_Log::log_update('dead', $th, 'Stripe payment failed for campaign #' . $order_id);
                        }
                    }
                }
            }
        } else {
            wp_clear_scheduled_hook('stripe_biller', $campaign_id);
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
        LIMIT 5
    ");

        return $results;
    }
}
