# Stripe connect Integration with WP-CrowdFunding plugin

This plugin handles the integration with stripe in order to use stripe connect with the WP-CrowdFunding plugin

## What does the plugin do?

- Creates the Stripe Checkout gateway to pay via Stripe.
- Handles the connection from campaign owners to Stripe, this way, they can get paid once the withdrawal happens.
- Creates an endpoint in the WP Rest API that handles the events from stripe (You have to set up the webhook at Stripe).
- Works with the 'minimum funding' feature, once a percentage of the funding has been reached, the plugin starts to bill all the on-hold orders.

## Changes needed in WP-CrowdFunding Plugin

- Change the queries in WP-Crowdfunding functions class, you have to change this:

  - At 'wp-crowdfunding/includes/Functions.php::354-359':

    ```php

    $query = "SELECT SUM(ltoim.meta_value) as total_sales_amount
                    FROM {$wpdb->prefix}woocommerce_order_itemmeta woim
    LEFT JOIN {$wpdb->prefix}woocommerce_order_items oi ON woim.order_item_id = oi.order_item_id
                    LEFT JOIN {$wpdb->prefix}posts wpposts ON order_id = wpposts.ID
    LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta ltoim ON ltoim.order_item_id = oi.order_item_id AND ltoim.meta_key = '_line_total'
                    WHERE woim.meta_key = '_product_id' AND woim.meta_value IN ($placeholders) AND (wpposts.post_status = 'wc-processing' OR wpposts.post_status = 'wc-completed' OR wpposts.post_status = 'wc-on-hold') ;";

    ```

  - At 'wp-crowdfunding/includes/Functions.php::671-681':

    ```php

    $query = "SELECT
            SUM(ltoim.meta_value) as total_sales_amount
        FROM
            {$wpdb->prefix}woocommerce_order_itemmeta woim
        LEFT JOIN
            {$wpdb->prefix}woocommerce_order_items oi ON woim.order_item_id = oi.order_item_id
        LEFT JOIN
            {$wpdb->prefix}posts wpposts ON order_id = wpposts.ID
        LEFT JOIN
            {$wpdb->prefix}woocommerce_order_itemmeta ltoim ON ltoim.order_item_id = oi.order_item_id AND ltoim.meta_key = '_line_total'
        WHERE
            woim.meta_key = '_product_id' AND woim.meta_value IN ($placeholders) AND (wpposts.post_status = 'wc-processing' OR wpposts.post_status = 'wc-completed' OR wpposts.post_status = 'wc-on-hold') ;";

    ```
