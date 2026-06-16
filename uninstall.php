<?php
/**
 * پاک‌سازی هنگام حذف افزونه.
 * فقط در صورتی داده‌ها حذف می‌شوند که گزینه‌ی «حذف داده‌ها هنگام حذف افزونه» فعال باشد.
 */
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$option_key = 'tlscp_options';
$opts = get_option($option_key, array());
$remove = is_array($opts) && isset($opts['remove_data_on_uninstall']) && $opts['remove_data_on_uninstall'] === 'yes';

if (!$remove) {
    return;
}

// حذف جدول‌ها
$logs = $wpdb->prefix . 'tlscp_logs';
$orders = $wpdb->prefix . 'tlscp_orders';
$wpdb->query("DROP TABLE IF EXISTS {$logs}");
$wpdb->query("DROP TABLE IF EXISTS {$orders}");

// حذف تنظیمات
delete_option($option_key);

// حذف متاهای محصول/تنوع افزونه
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_tlscp\_%'");

// حذف رویدادهای کران
$timestamp = wp_next_scheduled('tlscp_cron_sync');
while ($timestamp) {
    wp_unschedule_event($timestamp, 'tlscp_cron_sync');
    $timestamp = wp_next_scheduled('tlscp_cron_sync');
}

// پاک‌سازی transientهای پروموشن
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_tlscp\_promo\_%' OR option_name LIKE '\_transient\_timeout\_tlscp\_promo\_%'");
