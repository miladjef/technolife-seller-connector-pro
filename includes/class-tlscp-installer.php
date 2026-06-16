<?php
if (!defined('ABSPATH')) { exit; }

class TLSCP_Installer {
    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        $logs = $wpdb->prefix . 'tlscp_logs';
        $orders = $wpdb->prefix . 'tlscp_orders';

        $sql_logs = "CREATE TABLE {$logs} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            action VARCHAR(100) NOT NULL,
            method VARCHAR(12) NULL,
            endpoint VARCHAR(255) NULL,
            object_type VARCHAR(50) NULL,
            object_id VARCHAR(100) NULL,
            http_status INT NULL,
            success TINYINT(1) NOT NULL DEFAULT 0,
            message TEXT NULL,
            request_body LONGTEXT NULL,
            response_body LONGTEXT NULL,
            retry_payload LONGTEXT NULL,
            PRIMARY KEY (id),
            KEY action (action),
            KEY success (success),
            KEY object_type (object_type),
            KEY created_at (created_at)
        ) {$charset};";

        $sql_orders = "CREATE TABLE {$orders} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_code VARCHAR(100) NOT NULL,
            wc_order_id BIGINT UNSIGNED NULL,
            trace_number VARCHAR(100) NULL,
            status VARCHAR(100) NULL,
            total_price DECIMAL(20,2) NULL,
            order_date DATETIME NULL,
            raw_data LONGTEXT NULL,
            updated_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY order_code (order_code),
            KEY wc_order_id (wc_order_id),
            KEY status (status),
            KEY order_date (order_date)
        ) {$charset};";

        dbDelta($sql_logs);
        dbDelta($sql_orders);

        $defaults = self::default_options();
        $existing = get_option(TLSCP_OPTION_KEY, array());
        if (!is_array($existing)) {
            $existing = array();
        }
        update_option(TLSCP_OPTION_KEY, wp_parse_args($existing, $defaults), false);

        add_filter('cron_schedules', array(__CLASS__, 'add_cron_schedule'));
        if (!wp_next_scheduled('tlscp_cron_sync')) {
            wp_schedule_event(time() + 300, 'tlscp_15min', 'tlscp_cron_sync');
        }
    }

    public static function deactivate() {
        $timestamp = wp_next_scheduled('tlscp_cron_sync');
        while ($timestamp) {
            wp_unschedule_event($timestamp, 'tlscp_cron_sync');
            $timestamp = wp_next_scheduled('tlscp_cron_sync');
        }
    }

    public static function add_cron_schedule($schedules) {
        if (!isset($schedules['tlscp_15min'])) {
            $schedules['tlscp_15min'] = array(
                'interval' => 15 * MINUTE_IN_SECONDS,
                'display'  => 'Technolife هر ۱۵ دقیقه',
            );
        }
        return $schedules;
    }

    public static function default_options() {
        return array(
            'api_base_url' => 'https://seller-api.technolife.com',
            'api_key' => '',
            'secret_key' => '',
            'secret_is_base64' => 'yes',
            'encryption_payload_mode' => 'path',
            'global_markup_percent' => '0',
            'price_unit' => 'rial',
            'category_rules' => array(),
            'category_strategy' => 'replace_global',
            'multi_category_strategy' => 'highest',
            'price_source' => 'sale_or_regular',
            'rounding' => 'none',
            'out_of_range_strategy' => 'block',
            'sync_leasing_bnpl' => 'no',
            'leasing_percent' => '0',
            'bnpl_percent' => '0',
            'inventory_reserve' => '0',
            'inventory_max_send' => '',
            'inventory_unmanaged_qty' => '5',
            'leave_time' => '1',
            'max_buy_per_order' => '1',
            'auto_sync_on_save' => 'no',
            'auto_hide_zero_stock' => 'yes',
            'cron_enabled' => 'no',
            'cron_sync_prices' => 'no',
            'cron_sync_inventory' => 'yes',
            'cron_import_orders' => 'yes',
            'orders_from_days' => '7',
            'orders_max_pages' => '3',
            'auto_create_orders' => 'yes',
            'order_status' => 'wc-processing',
            'reduce_stock_on_import' => 'no',
            'log_retention_days' => '30'
        );
    }
}
