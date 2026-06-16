<?php
if (!defined('ABSPATH')) { exit; }

/**
 * صف‌بندی پردازش پس‌زمینه.
 * در صورت وجود Action Scheduler (همراه ووکامرس) از آن استفاده می‌شود؛
 * در غیر این صورت به wp-cron تک‌رویدادی fallback می‌کند.
 */
class TLSCP_Queue {
    private $sync;
    private $orders;
    const GROUP = 'tlscp';

    public function __construct($sync, $orders) {
        $this->sync = $sync;
        $this->orders = $orders;
    }

    public function init() {
        add_action('tlscp_async_sync_product', array($this, 'handle_sync_product'), 10, 2);
        add_action('tlscp_async_import_orders', array($this, 'handle_import_orders'), 10, 1);
    }

    public function enabled() {
        $opts = wp_parse_args(get_option(TLSCP_OPTION_KEY, array()), TLSCP_Installer::default_options());
        return isset($opts['use_action_scheduler']) && $opts['use_action_scheduler'] === 'yes' && function_exists('as_enqueue_async_action');
    }

    /**
     * زمان‌بندی یک عملیات پس‌زمینه (async در صورت امکان).
     */
    public function dispatch($hook, $args = array(), $delay = 0) {
        if ($this->enabled()) {
            // جلوگیری از صف‌بندی تکراری همان عملیات
            if (function_exists('as_has_scheduled_action') && as_has_scheduled_action($hook, $args, self::GROUP)) {
                return false;
            }
            if (!function_exists('as_has_scheduled_action') && function_exists('as_next_scheduled_action') && as_next_scheduled_action($hook, $args, self::GROUP)) {
                return false;
            }
            if ($delay > 0 && function_exists('as_schedule_single_action')) {
                as_schedule_single_action(time() + $delay, $hook, $args, self::GROUP);
            } else {
                as_enqueue_async_action($hook, $args, self::GROUP);
            }
            return true;
        }
        // fallback: wp-cron تک‌رویدادی
        if (!wp_next_scheduled($hook, $args)) {
            wp_schedule_single_event(time() + max(1, $delay), $hook, $args);
            return true;
        }
        return false;
    }

    /**
     * صف‌بندی همگام‌سازی کامل کاتالوگ: یک job مستقل برای هر محصول متصل.
     */
    public function enqueue_full_sync($force = false) {
        $ids = get_posts(array(
            'post_type' => array('product', 'product_variation'),
            'post_status' => array('publish', 'draft', 'private'),
            'posts_per_page' => -1,
            'meta_query' => array(
                array('key' => '_tlscp_enabled', 'value' => 'yes'),
                array('key' => '_tlscp_seller_item_code', 'value' => '', 'compare' => '!='),
            ),
            'fields' => 'ids',
            'no_found_rows' => true,
        ));
        $count = 0;
        foreach ($ids as $pid) {
            if ($this->dispatch('tlscp_async_sync_product', array((int) $pid, $force ? 1 : 0))) {
                $count++;
            }
        }
        return array('queued' => $count, 'total' => count($ids), 'method' => $this->enabled() ? 'action_scheduler' : 'wp_cron');
    }

    public function enqueue_import_orders($days = 0) {
        return $this->dispatch('tlscp_async_import_orders', array((int) $days));
    }

    // ---- handlers ----
    public function handle_sync_product($product_id, $force = 0) {
        $this->sync->sync_product(absint($product_id), (bool) $force);
    }

    public function handle_import_orders($days = 0) {
        $days = absint($days);
        $this->orders->import_recent($days > 0 ? $days : null, 100);
    }
}
