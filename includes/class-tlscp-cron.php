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

        $use_queue = isset($opts['use_action_scheduler']) && $opts['use_action_scheduler'] === 'yes' && function_exists('as_enqueue_async_action');
        $queue = ($use_queue && function_exists('tlscp')) ? tlscp()->queue : null;

        if ($opts['cron_import_orders'] === 'yes') {
            if ($queue) {
                $queue->enqueue_import_orders(absint($opts['orders_from_days']));
            } else {
                $this->orders->import_recent(absint($opts['orders_from_days']), 100);
            }
        }

        if ($opts['cron_sync_prices'] === 'yes' || $opts['cron_sync_inventory'] === 'yes') {
            if ($queue) {
                // صف‌بندی per-product برای پایداری روی کاتالوگ بزرگ
                $queue->enqueue_full_sync(false);
            } else {
                // اجرای مستقیم با تشخیص تغییر (force=false) و سقف امن
                $this->sync->sync_all_connected($opts['cron_sync_prices'] === 'yes', $opts['cron_sync_inventory'] === 'yes', 50, false);
            }
        }

        // اسکن بای‌باکس + اعلان (در صورت فعال بودن)
        if (isset($opts['cron_scan_buybox']) && $opts['cron_scan_buybox'] === 'yes') {
            $stats = $this->sync->scan_buybox(150);
            if (class_exists('TLSCP_Notifications')) {
                TLSCP_Notifications::buybox_report($stats);
            }
        }

        // اعلان آستانه‌ی خطا
        if (class_exists('TLSCP_Notifications') && class_exists('TLSCP_Logger')) {
            TLSCP_Notifications::error_threshold(new TLSCP_Logger());
        }

        if (!empty($opts['log_retention_days']) && class_exists('TLSCP_Logger')) {
            $logger = new TLSCP_Logger();
            $logger->clear_old(absint($opts['log_retention_days']));
        }
    }
}
