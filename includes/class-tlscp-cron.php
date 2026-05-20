<?php
if (!defined('ABSPATH')) { exit; }

class TLSCP_Cron {
    private $sync;
    private $orders;

    public function __construct($sync, $orders) {
        $this->sync = $sync;
        $this->orders = $orders;
    }

    public function init() {
        add_filter('cron_schedules', array($this, 'schedules'));
        add_action('tlscp_cron_sync', array($this, 'run'));
    }

    public function schedules($schedules) {
        $schedules['tlscp_15min'] = array('interval' => 15 * MINUTE_IN_SECONDS, 'display' => 'Technolife هر ۱۵ دقیقه');
        return $schedules;
    }

    public function run() {
        $opts = wp_parse_args(get_option(TLSCP_OPTION_KEY, array()), TLSCP_Installer::default_options());
        if ($opts['cron_enabled'] !== 'yes') { return; }
        if ($opts['cron_import_orders'] === 'yes') {
            $this->orders->import_recent(absint($opts['orders_from_days']), 100);
        }
        if ($opts['cron_sync_prices'] === 'yes' || $opts['cron_sync_inventory'] === 'yes') {
            $this->sync->sync_all_connected($opts['cron_sync_prices'] === 'yes', $opts['cron_sync_inventory'] === 'yes', 50);
        }
    }
}
