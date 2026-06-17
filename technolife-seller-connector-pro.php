<?php
/**
 * Plugin Name: Technolife Seller Connector Pro for WooCommerce
 * Plugin URI: https://miladjafarigavzan.ir/
 * Description: اتصال حرفه‌ای ووکامرس به Seller API تکنولایف: قیمت‌گذاری هوشمند، موجودی، سفارش‌ها، پروموشن، لاگ و خروجی اکسل/CSV.
 * Version: 1.4.0
 * Author: Milad Jafari Gavzan
 * Text Domain: tlscp
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 */

if (!defined('ABSPATH')) {
    exit;
}

define('TLSCP_VERSION', '1.4.0');
define('TLSCP_PLUGIN_FILE', __FILE__);
define('TLSCP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TLSCP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('TLSCP_OPTION_KEY', 'tlscp_options');

require_once TLSCP_PLUGIN_DIR . 'includes/class-tlscp-installer.php';
require_once TLSCP_PLUGIN_DIR . 'includes/class-tlscp-logger.php';
require_once TLSCP_PLUGIN_DIR . 'includes/class-tlscp-crypto.php';
require_once TLSCP_PLUGIN_DIR . 'includes/class-tlscp-api-client.php';
require_once TLSCP_PLUGIN_DIR . 'includes/class-tlscp-pricing.php';
require_once TLSCP_PLUGIN_DIR . 'includes/class-tlscp-promotions.php';
require_once TLSCP_PLUGIN_DIR . 'includes/class-tlscp-catalog.php';
require_once TLSCP_PLUGIN_DIR . 'includes/class-tlscp-notifications.php';
require_once TLSCP_PLUGIN_DIR . 'includes/class-tlscp-queue.php';
require_once TLSCP_PLUGIN_DIR . 'includes/class-tlscp-product-meta.php';
require_once TLSCP_PLUGIN_DIR . 'includes/class-tlscp-sync.php';
require_once TLSCP_PLUGIN_DIR . 'includes/class-tlscp-orders.php';
require_once TLSCP_PLUGIN_DIR . 'includes/class-tlscp-export.php';
require_once TLSCP_PLUGIN_DIR . 'includes/class-tlscp-admin.php';
require_once TLSCP_PLUGIN_DIR . 'includes/class-tlscp-cron.php';
require_once TLSCP_PLUGIN_DIR . 'includes/class-tlscp-cli.php';

final class TLSCP_Plugin {
    private static $instance = null;
    public $logger;
    public $api;
    public $pricing;
    public $promotions;
    public $catalog;
    public $sync;
    public $orders;
    public $queue;
    public $admin;
    public $cron;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->logger     = new TLSCP_Logger();
        $this->api        = new TLSCP_API_Client($this->logger);
        $this->pricing    = new TLSCP_Pricing();
        $this->promotions = new TLSCP_Promotions($this->api, $this->pricing, $this->logger);
        $this->catalog    = new TLSCP_Catalog($this->api);
        $this->sync       = new TLSCP_Sync($this->api, $this->pricing, $this->logger);
        $this->orders     = new TLSCP_Orders($this->api, $this->logger);
        $this->queue      = new TLSCP_Queue($this->sync, $this->orders);
        $this->admin      = new TLSCP_Admin($this->api, $this->sync, $this->orders, $this->pricing, $this->logger, $this->promotions, $this->catalog, $this->queue);
        $this->cron       = new TLSCP_Cron($this->sync, $this->orders);

        add_action('plugins_loaded', array($this, 'init'));
        add_action('admin_notices', array($this, 'dependency_notice'));
    }

    public function init() {
        load_plugin_textdomain('tlscp', false, dirname(plugin_basename(__FILE__)) . '/languages');
        TLSCP_Product_Meta::init($this->sync);
        $this->admin->init();
        $this->cron->init();
        $this->queue->init();
        $this->register_realtime_hooks();
    }

    /**
     * همگام‌سازی لحظه‌ای: با تغییر موجودی یا ذخیره‌ی محصول، یک job پس‌زمینه صف می‌شود.
     */
    public function register_realtime_hooks() {
        $opts = wp_parse_args(get_option(TLSCP_OPTION_KEY, array()), TLSCP_Installer::default_options());
        if (!isset($opts['realtime_sync']) || $opts['realtime_sync'] !== 'yes') {
            return;
        }
        add_action('woocommerce_product_set_stock', array($this, 'on_stock_change'), 20, 1);
        add_action('woocommerce_variation_set_stock', array($this, 'on_stock_change'), 20, 1);
        add_action('woocommerce_update_product', array($this, 'on_product_update'), 20, 1);
        add_action('woocommerce_update_product_variation', array($this, 'on_product_update'), 20, 1);
    }

    public function on_stock_change($product) {
        $id = is_object($product) && method_exists($product, 'get_id') ? $product->get_id() : absint($product);
        $this->maybe_queue_product($id);
    }

    public function on_product_update($product_id) {
        $this->maybe_queue_product(absint($product_id));
    }

    private function maybe_queue_product($product_id) {
        if (!$product_id) { return; }
        $enabled = get_post_meta($product_id, '_tlscp_enabled', true);
        $code = get_post_meta($product_id, '_tlscp_seller_item_code', true);
        if ($enabled === 'yes' && $code) {
            $this->queue->dispatch('tlscp_async_sync_product', array((int) $product_id, 0), 5);
        }
    }

    public function dependency_notice() {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        if (!class_exists('WooCommerce')) {
            echo '<div class="notice notice-error"><p><strong>Technolife Seller Connector Pro:</strong> این افزونه برای کارکرد کامل به WooCommerce نیاز دارد.</p></div>';
        }
        if (!function_exists('openssl_encrypt')) {
            echo '<div class="notice notice-error"><p><strong>Technolife Seller Connector Pro:</strong> افزونه openssl در PHP فعال نیست؛ تولید encrypted-secret انجام نمی‌شود.</p></div>';
        }
        // یادآوری پیکربندی اولیه (فقط در صفحات افزونه)
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $on_plugin_page = $screen && isset($screen->id) && strpos($screen->id, 'tlscp') !== false;
        if ($on_plugin_page) {
            $opts = wp_parse_args(get_option(TLSCP_OPTION_KEY, array()), TLSCP_Installer::default_options());
            if (empty($opts['api_key']) || empty($opts['secret_key'])) {
                echo '<div class="notice notice-warning"><p><strong>تکنولایف:</strong> برای شروع، API Key و Secret Key را در «تنظیمات اتصال» وارد کنید.</p></div>';
            }
        }
    }
}

// اعلام سازگاری با HPOS (جدول سفارش‌های سفارشی ووکامرس) تا ووکامرس افزونه را ناسازگار اعلام نکند.
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', TLSCP_PLUGIN_FILE, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('product_block_editor', TLSCP_PLUGIN_FILE, true);
    }
});

register_activation_hook(__FILE__, array('TLSCP_Installer', 'activate'));
register_deactivation_hook(__FILE__, array('TLSCP_Installer', 'deactivate'));

function tlscp() {
    return TLSCP_Plugin::instance();
}

tlscp();
