<?php

class Stripe_Payment_Functions
{
    /**
     * Checks if minimum fund raise of the campaign has been reached
     * 
     * @return bool 
     */
    public function minimum_fund_reached(\WC_Product $product)
    {
        // Check minimum funding in order to setup a payment or capture inmediatly
        $product_id = $product->get_id();
        $raised_percent = wpcf_function()->get_raised_percent($product_id);
        $minimum_percent = get_post_meta($product_id, 'freeit-minimum-funding-required', true);

        return $raised_percent > $minimum_percent;
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
    public static function get_stripe_amount($total, $currency = '')
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
            "amount" => sp_functions()->reset_stripe_amount($charge_data->amount, $charge_data->currency),
            "amount_refunded" => sp_functions()->reset_stripe_amount($charge_data->amount_refunded, $charge_data->currency),
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
            "origin_time" => $origin_time,
            "payment_method" => $charge_data->payment_method
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
}
