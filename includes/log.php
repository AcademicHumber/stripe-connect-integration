<?php

class SP_Stripe_Log
{
    public static function init_live_log()
    {
        $content = "<------------------- Stripe Payment Live Log File ( " . SP_VERSION . " ) ------------------->\n";
        return $content;
    }
    public static function init_dead_log()
    {
        $content = "<------------------- Stripe Payment Dead Log File ( " . SP_VERSION . " ) ------------------->\n";
        return $content;
    }
    public static function log_update($type, $msg, $title)
    {
        if (WC()->version >= '2.7.0') {
            $log = wc_get_logger();
            $head = "<------------------- Stripe Payment ( " . $title . " ) ------------------->\n";
            switch ($type) {
                case 'live':
                    $log_text = $head . print_r((object)$msg, true);
                    $live_context = array('source' => 'sp_stripe_pay_live');
                    $log->log("debug", $log_text, $live_context);
                    break;
                case 'dead':
                    $log_text = $head . print_r((object)$msg, true);
                    $dead_context = array('source' => 'sp_stripe_pay_dead');
                    $log->log("debug", $log_text, $dead_context);
                    break;
            }
        } else {
            $log = new WC_Logger();
            $head = "<------------------- Stripe Payment ( " . $title . " ) ------------------->\n";
            switch ($type) {
                case 'live':
                    $log_text = $head . print_r((object)$msg, true);
                    $log->add("sp_stripe_pay_live", $log_text);
                    break;
                case 'dead':
                    $log_text = $head . print_r((object)$msg, true);
                    $log->add("sp_stripe_pay_dead", $log_text);
                    break;
            }
        }
    }
}
