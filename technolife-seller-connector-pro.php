<?php
/**
 * Plugin Name: Technolife Seller Connector Pro for WooCommerce
 * Plugin URI: https://miladjafarigavzan.ir/
 * Description: اتصال حرفه‌ای ووکامرس به Seller API تکنولایف: قیمت‌گذاری هوشمند، موجودی، سفارش‌ها، پروموشن، لاگ و خروجی اکسل/CSV.
 * Version: 1.0.1
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

define('TLSCP_VERSION', '1.0.1');
define('TLSCP_PLUGIN_FILE', __FILE__);
define('TLSCP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TLSCP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('TLSCP_OPTION_KEY', 'tlscp_options');

require_once TLSCP_PLUGIN_DIR . 'includes/class-tlscp-installer.php';
require_once TLSCP_PLUGIN_DIR . 'includes/class-tlscp-logger.php';
require_once TLSCP_PLUGIN_DIR . 'includes/class-tlscp-crypto.php';
require_once TLSCP_PLUGIN_DIR . 'includes/class-tlscp-api-client.php';
require_once TLSCP_PLUGIN_DIR . 'includes/class-tlscp-pricing.php';
require_once TLSCP_PLUGIN_DIR . 'includes/class-tlscp-product-meta.php';
require_once TLSCP_PLUGIN_DIR . 'includes/class-tlscp-sync.php';
require_once TLSCP_PLUGIN_DIR . 'includes/class-tlscp-orders.php';
require_once TLSCP_PLUGIN_DIR . 'includes/class-tlscp-export.php';
require_once TLSCP_PLUGIN_DIR . 'includes/class-tlscp-admin.php';
require_once TLSCP_PLUGIN_DIR . 'includes/class-tlscp-cron.php';

final class TLSCP_Plugin {
    private static $instance = null;
    public $logger;
    public $api;
    public $pricing;
    public $sync;
    public $orders;
    public $admin;
    public $cron;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->logger  = new TLSCP_Logger();
        $this->api     = new TLSCP_API_Client($this->logger);
        $this->pricing = new TLSCP_Pricing();
        $this->sync    = new TLSCP_Sync($this->api, $this->pricing, $this->logger);
        $this->orders  = new TLSCP_Orders($this->api, $this->logger);
        $this->admin   = new TLSCP_Admin($this->api, $this->sync, $this->orders, $this->pricing, $this->logger);
        $this->cron    = new TLSCP_Cron($this->sync, $this->orders);

        add_action('plugins_loaded', array($this, 'init'));
        add_action('admin_notices', array($this, 'dependency_notice'));
    }

    public function init() {
        load_plugin_textdomain('tlscp', false, dirname(plugin_basename(__FILE__)) . '/languages');
        TLSCP_Product_Meta::init($this->sync);
        $this->admin->init();
        $this->cron->init();
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
    }
}

register_activation_hook(__FILE__, array('TLSCP_Installer', 'activate'));
register_deactivation_hook(__FILE__, array('TLSCP_Installer', 'deactivate'));

function tlscp() {
    return TLSCP_Plugin::instance();
}

tlscp();
