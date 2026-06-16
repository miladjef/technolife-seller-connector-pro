<?php
if (!defined('ABSPATH')) { exit; }
if (!defined('WP_CLI') || !WP_CLI) { return; }

/**
 * دستورات WP-CLI افزونه تکنولایف.
 *
 * نمونه:
 *   wp tlscp sync --force
 *   wp tlscp sync --price --no-inventory
 *   wp tlscp import-orders --days=14
 *   wp tlscp buybox-scan
 *   wp tlscp test
 */
class TLSCP_CLI {

    /**
     * همگام‌سازی قیمت و موجودی محصولات متصل.
     *
     * [--price]            فقط قیمت (پیش‌فرض: هر دو)
     * [--inventory]        فقط موجودی
     * [--force]            نادیده‌گرفتن تشخیص تغییر و ارسال همه
     * [--limit=<n>]        تعداد محصول در هر اجرا (پیش‌فرض 200)
     */
    public function sync($args, $assoc) {
        $price = isset($assoc['price']) || !isset($assoc['inventory']);
        $inventory = isset($assoc['inventory']) || !isset($assoc['price']);
        if (isset($assoc['price']) && !isset($assoc['inventory'])) { $inventory = false; }
        if (isset($assoc['inventory']) && !isset($assoc['price'])) { $price = false; }
        $force = isset($assoc['force']);
        $limit = isset($assoc['limit']) ? absint($assoc['limit']) : 200;

        $res = tlscp()->sync->sync_all_connected($price, $inventory, $limit, $force);
        WP_CLI::success(sprintf('موفق: %d | ناموفق: %d | بدون تغییر: %d',
            $res['success'], $res['failed'], isset($res['skipped']) ? $res['skipped'] : 0));
    }

    /**
     * دریافت سفارش‌های اخیر از تکنولایف.
     *
     * [--days=<n>]  بازه‌ی روز (پیش‌فرض از تنظیمات)
     */
    public function import_orders($args, $assoc) {
        $days = isset($assoc['days']) ? absint($assoc['days']) : null;
        $res = tlscp()->orders->import_recent($days, 100);
        WP_CLI::success(sprintf('وارد شد: %d | ساخته شد: %d | به‌روزرسانی: %d | ناموفق: %d',
            $res['imported'], $res['created'], $res['updated'], $res['failed']));
    }

    /**
     * اسکن وضعیت بای‌باکس محصولات متصل.
     */
    public function buybox_scan($args, $assoc) {
        $res = tlscp()->sync->scan_buybox(500);
        WP_CLI::success(sprintf('اسکن: %d | برنده: %d | بازنده: %d | ناموفق: %d',
            $res['scanned'], $res['winners'], $res['losers'], $res['failed']));
    }

    /**
     * تست اتصال به API.
     */
    public function test($args, $assoc) {
        $res = tlscp()->api->test_connection();
        if (!empty($res['success'])) {
            WP_CLI::success('اتصال موفق بود (HTTP ' . $res['status'] . ').');
        } else {
            WP_CLI::error('اتصال ناموفق: ' . $res['message'] . ' (HTTP ' . $res['status'] . ')');
        }
    }
}

WP_CLI::add_command('tlscp', 'TLSCP_CLI');
