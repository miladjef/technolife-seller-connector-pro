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
        add_filter('cron_schedules', array('TLSCP_Installer', 'add_cron_schedule'));
        add_action('tlscp_cron_sync', array($this, 'run'));
        if (!wp_next_scheduled('tlscp_cron_sync')) {
            wp_schedule_event(time() + 300, 'tlscp_15min', 'tlscp_cron_sync');
        }
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
        if (!empty($opts['log_retention_days']) && class_exists('TLSCP_Logger')) {
            $logger = new TLSCP_Logger();
            $logger->clear_old(absint($opts['log_retention_days']));
        }
    }
}
