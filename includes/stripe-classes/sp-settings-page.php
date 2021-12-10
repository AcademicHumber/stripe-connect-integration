<?php
if (!defined('ABSPATH')) {
    exit;
}


return array(

    'sp_settings_title' => array(
        'class' => 'sp-css-class',
        'title' => sprintf('<span style="font-weight: bold; font-size: 16px; color:#23282d;">' . __('Settings', 'stripe-payment') . '<span>'),
        'type' => 'title'
    ),

    'enabled' => array(
        'title' => __('Credit/debit cards', 'stripe-payment'),
        'label' => __('Enable', 'stripe-payment'),
        'type' => 'checkbox',
        'default' => 'no',
        'desc_tip' => __('Enable to accept credit/debit card payments through Stripe.', 'stripe-payment'),
    ),

    'title' => array(
        'title' => __('Title', 'stripe-payment'),
        'type' => 'text',
        'description' => __('Input title for the payment gateway displayed at the checkout.', 'stripe-payment'),
        'default' => __('Stripe', 'stripe-payment'),
        'desc_tip' => true
    ),
    'description' => array(
        'title' => __('Description', 'stripe-payment'),
        'type' => 'textarea',
        'css' => 'width:25em',
        'description' => __('Input texts for the payment gateway displayed at the checkout.', 'stripe-payment'),
        'default' => __('Secure payment via Stripe.', 'stripe-payment'),
        'desc_tip' => true
    ),
    'sp_order_button' => array(
        'title' => __('Order button text', 'stripe-payment'),
        'type' => 'text',
        'description' => __('Input a text that will appear on the order button to place order at the checkout.', 'stripe-payment'),
        'default' => __('Pay via Stripe', 'stripe-payment'),
        'desc_tip' => true
    ),

    'separator' => array(
        'type' => 'title',
        'title' => '<hr>',
        'class' => 'sp_separator'
    ),

    'test_mode' => array(
        'title'         => __('Enable/Disable', 'wp-crowdfunding-pro'),
        'type'             => 'checkbox',
        'label'         => __('Enable Stripe Test Mode', 'wp-crowdfunding-pro'),
        'default'         => 'no'
    ),
    'test_client_id' => array(
        'title'       => __('Stripe Connect Test Client ID', 'wp-crowdfunding-pro'),
        'type'        => 'text',
        'description' => __('Get your client ID from stripe app settings', 'wp-crowdfunding-pro'),
        'default'     => '',
        'desc_tip'    => true,
    ),
    'test_secret_key' => array(
        'title'       => __('Test Secret Key', 'wp-crowdfunding-pro'),
        'type'        => 'text',
        'description' => __('Get your API keys from your stripe account.', 'wp-crowdfunding-pro'),
        'default'     => '',
        'desc_tip'    => true,
    ),
    'test_publishable_key' => array(
        'title'       => __('Test Publishable Key', 'wp-crowdfunding-pro'),
        'type'        => 'text',
        'description' => __('Get your API keys from your stripe account.', 'wp-crowdfunding-pro'),
        'default'     => '',
        'desc_tip'    => true,
    ),

    'separator2' => array(
        'type' => 'title',
        'title' => '<hr>',
        'class' => 'sp_separator'
    ),

    'live_client_id' => array(
        'title'       => __('Stripe Connect Live Client ID', 'wp-crowdfunding-pro'),
        'type'        => 'text',
        'description' => __('Get your client ID from stripe app settings', 'wp-crowdfunding-pro'),
        'default'     => '',
        'desc_tip'    => true,
    ),
    'secret_key' => array(
        'title'       => __('Live Secret Key', 'wp-crowdfunding-pro'),
        'type'        => 'text',
        'description' => __('Get your API keys from your stripe account.', 'wp-crowdfunding-pro'),
        'default'     => '',
        'desc_tip'    => true,
    ),
    'publishable_key' => array(
        'title'       => __('Live Publishable Key', 'wp-crowdfunding-pro'),
        'type'        => 'text',
        'description' => __('Get your API keys from your stripe account.', 'wp-crowdfunding-pro'),
        'default'     => '',
        'desc_tip'    => true,
    ),

    'separator3' => array(
        'type' => 'title',
        'title' => '<hr>',
        'class' => 'sp_separator'
    ),

    'receivers_percent' => array(
        'title'         => __('Receivers percent', 'wp-crowdfunding-pro'),
        'type'             => 'number',
        'desc_tip'         => true,
        'description'     => __('Campaign owner will get this percent, rest amount will credited stripe owner account as application fee', 'wp-crowdfunding-pro'),
        'default'         => ''
    ),
    'sp_statement_descriptor' => array(
        'title' => __('Statement descriptor', 'stripe-payment'),
        'description' => __('Enter a statement descriptor which will appear on customer\'s bank statements. Max 22 characters. ', 'stripe-payment') . '<a href="https://stripe.com/docs/statement-descriptors" target="_blank">' . __('Learn more', 'stripe-payment') . '</a>',
        'type' => 'text',
    ),


);
