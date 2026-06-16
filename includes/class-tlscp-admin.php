<?php
if (!defined('ABSPATH')) { exit; }

class TLSCP_Admin {
    private $api;
    private $sync;
    private $orders;
    private $pricing;
    private $logger;

    public function __construct($api, $sync, $orders, $pricing, $logger) {
        $this->api = $api;
        $this->sync = $sync;
        $this->orders = $orders;
        $this->pricing = $pricing;
        $this->logger = $logger;
    }

    public function init() {
        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_enqueue_scripts', array($this, 'assets'));
        add_action('admin_post_tlscp_save_settings', array($this, 'save_settings'));
        add_action('admin_post_tlscp_export_products', array($this, 'export_products'));
        add_action('admin_post_tlscp_export_logs', array($this, 'export_logs'));
        add_action('admin_post_tlscp_export_orders', array($this, 'export_orders'));

        add_action('wp_ajax_tlscp_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_tlscp_sync_product', array($this, 'ajax_sync_product'));
        add_action('wp_ajax_tlscp_sync_all', array($this, 'ajax_sync_all'));
        add_action('wp_ajax_tlscp_import_orders', array($this, 'ajax_import_orders'));
        add_action('wp_ajax_tlscp_retry_log', array($this, 'ajax_retry_log'));
    }

    public function menu() {
        add_menu_page('تکنولایف', 'تکنولایف', 'manage_woocommerce', 'tlscp-dashboard', array($this, 'page_dashboard'), 'dashicons-store', 56);
        add_submenu_page('tlscp-dashboard', 'داشبورد', 'داشبورد', 'manage_woocommerce', 'tlscp-dashboard', array($this, 'page_dashboard'));
        add_submenu_page('tlscp-dashboard', 'تنظیمات اتصال', 'تنظیمات اتصال', 'manage_woocommerce', 'tlscp-settings', array($this, 'page_settings'));
        add_submenu_page('tlscp-dashboard', 'قوانین قیمت‌گذاری', 'قوانین قیمت‌گذاری', 'manage_woocommerce', 'tlscp-pricing', array($this, 'page_pricing'));
        add_submenu_page('tlscp-dashboard', 'محصولات', 'محصولات', 'manage_woocommerce', 'tlscp-products', array($this, 'page_products'));
        add_submenu_page('tlscp-dashboard', 'سفارش‌ها', 'سفارش‌ها', 'manage_woocommerce', 'tlscp-orders', array($this, 'page_orders'));
        add_submenu_page('tlscp-dashboard', 'لاگ و خطایابی', 'لاگ و خطایابی', 'manage_woocommerce', 'tlscp-logs', array($this, 'page_logs'));
    }

    public function assets($hook) {
        $is_product_edit = false;
        if ($hook === 'post.php' || $hook === 'post-new.php') {
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            $is_product_edit = $screen && isset($screen->post_type) && $screen->post_type === 'product';
        }
        if (strpos($hook, 'tlscp') === false && !$is_product_edit) { return; }
        wp_enqueue_style('tlscp-admin', TLSCP_PLUGIN_URL . 'assets/admin.css', array(), TLSCP_VERSION);
        wp_enqueue_script('tlscp-admin', TLSCP_PLUGIN_URL . 'assets/admin.js', array('jquery'), TLSCP_VERSION, true);
        wp_localize_script('tlscp-admin', 'TLSCP', array('ajaxurl' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('tlscp_admin_nonce')));
    }

    private function opts() {
        return wp_parse_args(get_option(TLSCP_OPTION_KEY, array()), TLSCP_Installer::default_options());
    }

    private function header($title) {
        echo '<div class="wrap tlscp-wrap" dir="rtl"><h1>' . esc_html($title) . '</h1>';
        echo '<div class="tlscp-tabs"><a href="' . esc_url(admin_url('admin.php?page=tlscp-dashboard')) . '">داشبورد</a><a href="' . esc_url(admin_url('admin.php?page=tlscp-settings')) . '">اتصال</a><a href="' . esc_url(admin_url('admin.php?page=tlscp-pricing')) . '">قیمت‌گذاری</a><a href="' . esc_url(admin_url('admin.php?page=tlscp-products')) . '">محصولات</a><a href="' . esc_url(admin_url('admin.php?page=tlscp-orders')) . '">سفارش‌ها</a><a href="' . esc_url(admin_url('admin.php?page=tlscp-logs')) . '">لاگ‌ها</a></div>';
    }

    private function footer() { echo '</div>'; }

    public function page_dashboard() {
        $this->header('داشبورد تکنولایف');
        $connected = $this->count_connected_products();
        $logs_failed = count($this->logger->recent(20, 0));
        $orders = count($this->orders->recent_orders(20));
        ?>
        <div class="tlscp-cards">
            <div class="tlscp-card"><h3>محصولات متصل</h3><strong><?php echo esc_html($connected); ?></strong><p>دارای sellerItemCode و اتصال فعال</p></div>
            <div class="tlscp-card"><h3>سفارش‌های اخیر</h3><strong><?php echo esc_html($orders); ?></strong><p>ذخیره‌شده در افزونه</p></div>
            <div class="tlscp-card"><h3>خطاهای اخیر</h3><strong><?php echo esc_html($logs_failed); ?></strong><p>آخرین عملیات ناموفق API</p></div>
        </div>
        <div class="tlscp-panel">
            <h2>عملیات سریع</h2>
            <button class="button button-primary" id="tlscp-test-connection">تست اتصال API</button>
            <button class="button" id="tlscp-sync-all" data-price="1" data-inventory="1">همگام‌سازی قیمت و موجودی محصولات متصل</button>
            <button class="button" id="tlscp-import-orders">دریافت سفارش‌های تکنولایف</button>
            <div id="tlscp-ajax-result"></div>
        </div>
        <?php
        $this->footer();
    }

    public function page_settings() {
        $opts = $this->opts();
        $this->header('تنظیمات اتصال تکنولایف');
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="tlscp-form">
            <?php wp_nonce_field('tlscp_save_settings'); ?><input type="hidden" name="action" value="tlscp_save_settings"><input type="hidden" name="section" value="settings">
            <div class="tlscp-panel"><h2>API</h2>
                <table class="form-table" role="presentation">
                    <tr><th>Base URL</th><td><input type="url" class="regular-text ltr" name="api_base_url" value="<?php echo esc_attr($opts['api_base_url']); ?>"></td></tr>
                    <tr><th>API Key</th><td><input type="password" class="regular-text ltr" name="api_key" value="<?php echo esc_attr($opts['api_key']); ?>" autocomplete="new-password"><p class="description">بدون عبارت Bearer وارد کنید.</p></td></tr>
                    <tr><th>Secret Key</th><td><input type="password" class="regular-text ltr" name="secret_key" value="<?php echo esc_attr($opts['secret_key']); ?>" autocomplete="new-password"><?php
                        if (!empty($opts['secret_key'])) {
                            $klen = TLSCP_Crypto::decoded_key_length($opts['secret_key'], $opts['secret_is_base64'] === 'yes');
                            if ($klen === 16) {
                                echo '<p class="description" style="color:#006b2d">✔ کلید معتبر است (۱۶ بایت، مناسب AES-128).</p>';
                            } elseif ($klen === -1) {
                                echo '<p class="description" style="color:#b42318">✖ مقدار Secret یک Base64 معتبر نیست. تیک «Base64 است؟» را بررسی کنید.</p>';
                            } else {
                                echo '<p class="description" style="color:#b42318">✖ طول کلید پس از decode برابر ' . esc_html($klen) . ' بایت است، اما باید دقیقاً ۱۶ بایت باشد. اتصال با این کلید خطای 401 می‌دهد.</p>';
                            }
                        }
                    ?></td></tr>
                    <tr><th>Secret Base64 است؟</th><td><label><input type="checkbox" name="secret_is_base64" value="yes" <?php checked($opts['secret_is_base64'], 'yes'); ?>> بله</label></td></tr>
                    <tr><th>Payload برای encrypted-secret</th><td><select name="encryption_payload_mode">
                            <option value="path" <?php selected($opts['encryption_payload_mode'], 'path'); ?>>Endpoint Path</option>
                            <option value="path_query" <?php selected($opts['encryption_payload_mode'], 'path_query'); ?>>Endpoint Path + Query</option>
                            <option value="full_url" <?php selected($opts['encryption_payload_mode'], 'full_url'); ?>>Full URL بدون Query</option>
                            <option value="full_url_query" <?php selected($opts['encryption_payload_mode'], 'full_url_query'); ?>>Full URL + Query</option>
                            <option value="path_body" <?php selected($opts['encryption_payload_mode'], 'path_body'); ?>>Path + Body</option>
                            <option value="body" <?php selected($opts['encryption_payload_mode'], 'body'); ?>>Body فقط</option>
                            <option value="empty" <?php selected($opts['encryption_payload_mode'], 'empty'); ?>>Empty String</option>
                        </select><p class="description">طبق PDF، encrypted-secret با AES-128-GCM و آدرس endpoint ساخته می‌شود. اگر تکنولایف خطای 401 داد، حالت‌های Path یا Path+Query معمولاً منطقی‌ترین گزینه‌ها هستند.</p></td></tr>
                </table>
            </div>
            <div class="tlscp-panel"><h2>موجودی و سفارش</h2>
                <table class="form-table" role="presentation">
                    <tr><th>رزرو موجودی برای سایت</th><td><input type="number" min="0" name="inventory_reserve" value="<?php echo esc_attr($opts['inventory_reserve']); ?>"></td></tr>
                    <tr><th>سقف موجودی ارسالی</th><td><input type="number" min="0" name="inventory_max_send" value="<?php echo esc_attr($opts['inventory_max_send']); ?>"> <span class="description">خالی یعنی بدون سقف.</span></td></tr>
                    <tr><th>موجودی محصولات بدون انبارش</th><td><input type="number" min="0" name="inventory_unmanaged_qty" value="<?php echo esc_attr($opts['inventory_unmanaged_qty']); ?>"> <span class="description">برای محصولاتی که موجودی ووکامرس‌شان مدیریت نمی‌شود ولی «موجود» هستند، این تعداد به تکنولایف ارسال می‌شود.</span></td></tr>
                    <tr><th>بازه ارسال leaveTime</th><td><input type="number" min="0" name="leave_time" value="<?php echo esc_attr($opts['leave_time']); ?>"></td></tr>
                    <tr><th>حداکثر خرید در سفارش</th><td><input type="number" min="1" name="max_buy_per_order" value="<?php echo esc_attr($opts['max_buy_per_order']); ?>"></td></tr>
                    <tr><th>مخفی‌کردن خودکار موجودی صفر</th><td><label><input type="checkbox" name="auto_hide_zero_stock" value="yes" <?php checked($opts['auto_hide_zero_stock'], 'yes'); ?>> فعال</label></td></tr>
                    <tr><th>ساخت سفارش ووکامرس</th><td><label><input type="checkbox" name="auto_create_orders" value="yes" <?php checked($opts['auto_create_orders'], 'yes'); ?>> سفارش‌های تکنولایف در ووکامرس ساخته شوند</label></td></tr>
                    <tr><th>تعداد روز دریافت سفارش</th><td><input type="number" min="1" max="365" name="orders_from_days" value="<?php echo esc_attr($opts['orders_from_days']); ?>"></td></tr>
                    <tr><th>حداکثر صفحات سفارش</th><td><input type="number" min="1" max="20" name="orders_max_pages" value="<?php echo esc_attr($opts['orders_max_pages']); ?>"> <span class="description">هر صفحه طبق API حداکثر ۱۰۰ آیتم دارد.</span></td></tr>
                    <tr><th>وضعیت سفارش ووکامرس</th><td><select name="order_status"><option value="wc-processing" <?php selected($opts['order_status'], 'wc-processing'); ?>>در حال انجام</option><option value="wc-on-hold" <?php selected($opts['order_status'], 'wc-on-hold'); ?>>در انتظار بررسی</option><option value="wc-pending" <?php selected($opts['order_status'], 'wc-pending'); ?>>در انتظار پرداخت</option></select></td></tr>
                    <tr><th>کاهش موجودی بعد از ساخت سفارش</th><td><label><input type="checkbox" name="reduce_stock_on_import" value="yes" <?php checked($opts['reduce_stock_on_import'], 'yes'); ?>> موجودی ووکامرس برای سفارش‌های جدید تکنولایف کاهش داده شود</label></td></tr>
                    <tr><th>همگام‌سازی هنگام ذخیره محصول</th><td><label><input type="checkbox" name="auto_sync_on_save" value="yes" <?php checked($opts['auto_sync_on_save'], 'yes'); ?>> بعد از ذخیره محصول، قیمت و موجودی به تکنولایف ارسال شود</label></td></tr>
                    <tr><th>کران خودکار</th><td><label><input type="checkbox" name="cron_enabled" value="yes" <?php checked($opts['cron_enabled'], 'yes'); ?>> فعال</label> &nbsp; <label><input type="checkbox" name="cron_sync_inventory" value="yes" <?php checked($opts['cron_sync_inventory'], 'yes'); ?>> موجودی</label> &nbsp; <label><input type="checkbox" name="cron_sync_prices" value="yes" <?php checked($opts['cron_sync_prices'], 'yes'); ?>> قیمت</label> &nbsp; <label><input type="checkbox" name="cron_import_orders" value="yes" <?php checked($opts['cron_import_orders'], 'yes'); ?>> سفارش</label></td></tr>
                </table>
            </div>
            <?php submit_button('ذخیره تنظیمات'); ?>
        </form>
        <?php $this->footer();
    }

    public function page_pricing() {
        $opts = $this->opts();
        $terms = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false));
        if (is_wp_error($terms)) { $terms = array(); }
        $rules = isset($opts['category_rules']) && is_array($opts['category_rules']) ? $opts['category_rules'] : array();
        $this->header('قوانین هوشمند قیمت‌گذاری');
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="tlscp-form">
            <?php wp_nonce_field('tlscp_save_settings'); ?><input type="hidden" name="action" value="tlscp_save_settings"><input type="hidden" name="section" value="pricing">
            <div class="tlscp-panel"><h2>قانون کلی</h2>
                <table class="form-table" role="presentation">
                    <tr><th>درصد افزایش کلی</th><td><input type="number" step="0.01" name="global_markup_percent" value="<?php echo esc_attr($opts['global_markup_percent']); ?>"> ٪</td></tr>
                    <tr><th>واحد قیمت فروشگاه</th><td><select name="price_unit"><option value="rial" <?php selected($opts['price_unit'], 'rial'); ?>>ریال (بدون تبدیل)</option><option value="toman" <?php selected($opts['price_unit'], 'toman'); ?>>تومان (در ۱۰ ضرب شود)</option></select><p class="description">تکنولایف قیمت‌ها را به <strong>ریال</strong> می‌خواهد. اگر قیمت محصولات ووکامرس شما به تومان است، این گزینه را روی «تومان» بگذارید تا قیمت‌ها ۱۰ برابر و هم‌واحدِ بازه‌ی مجاز تکنولایف شوند.</p></td></tr>
                    <tr><th>قیمت مبنا</th><td><select name="price_source"><option value="sale_or_regular" <?php selected($opts['price_source'], 'sale_or_regular'); ?>>فروش ویژه؛ اگر نبود قیمت عادی</option><option value="regular" <?php selected($opts['price_source'], 'regular'); ?>>قیمت عادی</option><option value="current" <?php selected($opts['price_source'], 'current'); ?>>قیمت فعلی ووکامرس</option></select></td></tr>
                    <tr><th>نحوه اعمال دسته‌بندی</th><td><select name="category_strategy"><option value="replace_global" <?php selected($opts['category_strategy'], 'replace_global'); ?>>درصد دسته‌بندی جایگزین درصد کلی شود</option><option value="add_to_global" <?php selected($opts['category_strategy'], 'add_to_global'); ?>>درصد دسته‌بندی با درصد کلی جمع شود</option></select></td></tr>
                    <tr><th>اگر محصول چند دسته داشت</th><td><select name="multi_category_strategy"><option value="highest" <?php selected($opts['multi_category_strategy'], 'highest'); ?>>بیشترین درصد</option><option value="lowest" <?php selected($opts['multi_category_strategy'], 'lowest'); ?>>کمترین درصد</option></select></td></tr>
                    <tr><th>گرد کردن</th><td><select name="rounding"><option value="none" <?php selected($opts['rounding'], 'none'); ?>>بدون گرد کردن خاص</option><option value="1000" <?php selected($opts['rounding'], '1000'); ?>>به ۱۰۰۰ ریال رو به بالا</option><option value="10000" <?php selected($opts['rounding'], '10000'); ?>>به ۱۰,۰۰۰ ریال رو به بالا</option><option value="100000" <?php selected($opts['rounding'], '100000'); ?>>به ۱۰۰,۰۰۰ ریال رو به بالا</option></select></td></tr>
                    <tr><th>خروج از بازه مجاز تکنولایف</th><td><select name="out_of_range_strategy"><option value="block" <?php selected($opts['out_of_range_strategy'], 'block'); ?>>ارسال نشود و خطا ثبت شود</option><option value="clamp" <?php selected($opts['out_of_range_strategy'], 'clamp'); ?>>به حداقل/حداکثر مجاز اصلاح شود</option></select></td></tr>
                    <tr><th>قیمت اقساطی/اعتباری</th><td><label><input type="checkbox" name="sync_leasing_bnpl" value="yes" <?php checked($opts['sync_leasing_bnpl'], 'yes'); ?>> همراه قیمت نقدی ارسال شود</label><br>درصد leasing: <input type="number" step="0.01" name="leasing_percent" value="<?php echo esc_attr($opts['leasing_percent']); ?>"> ٪ &nbsp; درصد bnpl: <input type="number" step="0.01" name="bnpl_percent" value="<?php echo esc_attr($opts['bnpl_percent']); ?>"> ٪</td></tr>
                </table>
            </div>
            <div class="tlscp-panel"><h2>درصد اختصاصی دسته‌بندی‌ها</h2><p>اگر برای دسته‌بندی درصد وارد شود، طبق تنظیم بالا با درصد کلی رفتار می‌شود. خانه خالی یعنی استفاده از درصد کلی.</p>
                <table class="widefat striped"><thead><tr><th>دسته‌بندی</th><th>درصد افزایش تکنولایف</th></tr></thead><tbody>
                <?php foreach ($terms as $term): $val = isset($rules[$term->term_id]) ? $rules[$term->term_id] : ''; ?>
                    <tr><td><?php echo esc_html($term->name); ?></td><td><input type="number" step="0.01" name="category_rules[<?php echo esc_attr($term->term_id); ?>]" value="<?php echo esc_attr($val); ?>"> ٪</td></tr>
                <?php endforeach; ?>
                </tbody></table>
            </div>
            <?php submit_button('ذخیره قوانین قیمت‌گذاری'); ?>
        </form>
        <?php $this->footer();
    }

    public function page_products() {
        $this->header('محصولات متصل به تکنولایف');
        $q = new WP_Query(array('post_type' => array('product','product_variation'), 'post_status' => 'any', 'posts_per_page' => 50, 'meta_query' => array(array('key' => '_tlscp_enabled', 'value' => 'yes'))));
        ?>
        <p><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tlscp_export_products'), 'tlscp_export_products')); ?>">خروجی اکسل/CSV محصولات</a></p>
        <table class="widefat striped"><thead><tr><th>ID</th><th>محصول</th><th>ProductCode</th><th>SellerItemCode</th><th>آخرین قیمت</th><th>آخرین موجودی</th><th>خطا</th><th>عملیات</th></tr></thead><tbody>
        <?php foreach ($q->posts as $p): ?>
            <tr><td><?php echo esc_html($p->ID); ?></td><td><a href="<?php echo esc_url(get_edit_post_link($p->ID)); ?>"><?php echo esc_html(get_the_title($p)); ?></a></td><td><?php echo esc_html(get_post_meta($p->ID, '_tlscp_product_code', true)); ?></td><td><?php echo esc_html(get_post_meta($p->ID, '_tlscp_seller_item_code', true)); ?></td><td><?php echo esc_html(get_post_meta($p->ID, '_tlscp_last_price', true)); ?></td><td><?php echo esc_html(get_post_meta($p->ID, '_tlscp_last_stock', true)); ?></td><td><?php echo esc_html(get_post_meta($p->ID, '_tlscp_last_error', true)); ?></td><td><button class="button tlscp-sync-product" data-product-id="<?php echo esc_attr($p->ID); ?>">همگام‌سازی</button></td></tr>
        <?php endforeach; ?>
        </tbody></table><div id="tlscp-ajax-result"></div>
        <?php $this->footer();
    }

    public function page_orders() {
        $this->header('سفارش‌های تکنولایف');
        $orders = $this->orders->recent_orders(100);
        ?>
        <p><button class="button button-primary" id="tlscp-import-orders">دریافت سفارش‌ها از API</button> <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tlscp_export_orders'), 'tlscp_export_orders')); ?>">خروجی اکسل/CSV سفارش‌ها</a></p>
        <table class="widefat striped"><thead><tr><th>کد سفارش</th><th>سفارش ووکامرس</th><th>رهگیری</th><th>وضعیت تکنولایف</th><th>مبلغ</th><th>تاریخ</th></tr></thead><tbody>
        <?php foreach ($orders as $o): ?>
            <?php $wc_order = !empty($o['wc_order_id']) ? wc_get_order($o['wc_order_id']) : false; ?>
            <tr><td><?php echo esc_html($o['order_code']); ?></td><td><?php echo $wc_order ? '<a href="' . esc_url($wc_order->get_edit_order_url()) . '">#' . esc_html($wc_order->get_id()) . '</a>' : '-'; ?></td><td><?php echo esc_html($o['trace_number']); ?></td><td><?php echo esc_html($o['status']); ?></td><td><?php echo esc_html($o['total_price']); ?></td><td><?php echo esc_html($o['order_date']); ?></td></tr>
        <?php endforeach; ?>
        </tbody></table><div id="tlscp-ajax-result"></div>
        <?php $this->footer();
    }

    public function page_logs() {
        $this->header('لاگ و خطایابی');
        $logs = $this->logger->recent(100);
        ?>
        <p><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tlscp_export_logs'), 'tlscp_export_logs')); ?>">خروجی اکسل/CSV لاگ‌ها</a></p>
        <table class="widefat striped"><thead><tr><th>ID</th><th>زمان</th><th>عملیات</th><th>Endpoint</th><th>HTTP</th><th>وضعیت</th><th>پیام</th><th>تکرار</th></tr></thead><tbody>
        <?php foreach ($logs as $log): ?>
            <tr><td><?php echo esc_html($log['id']); ?></td><td><?php echo esc_html($log['created_at']); ?></td><td><?php echo esc_html($log['action']); ?></td><td class="ltr"><?php echo esc_html($log['method'] . ' ' . $log['endpoint']); ?></td><td><?php echo esc_html($log['http_status']); ?></td><td><?php echo $log['success'] ? '<span class="tlscp-badge tlscp-ok">موفق</span>' : '<span class="tlscp-badge tlscp-error">ناموفق</span>'; ?></td><td><?php echo esc_html($log['message']); ?></td><td><?php if (!$log['success'] && $log['retry_payload']) : ?><button class="button tlscp-retry-log" data-log-id="<?php echo esc_attr($log['id']); ?>">اجرای مجدد</button><?php else: ?>-<?php endif; ?></td></tr>
        <?php endforeach; ?>
        </tbody></table><div id="tlscp-ajax-result"></div>
        <?php $this->footer();
    }

    public function save_settings() {
        if (!current_user_can('manage_woocommerce')) { wp_die('دسترسی غیرمجاز'); }
        check_admin_referer('tlscp_save_settings');
        $opts = $this->opts();
        $section = isset($_POST['section']) ? sanitize_text_field(wp_unslash($_POST['section'])) : 'settings';

        $text_fields = array('api_base_url','api_key','secret_key','encryption_payload_mode','global_markup_percent','price_unit','category_strategy','multi_category_strategy','price_source','rounding','out_of_range_strategy','leasing_percent','bnpl_percent','inventory_reserve','inventory_max_send','inventory_unmanaged_qty','leave_time','max_buy_per_order','orders_from_days','orders_max_pages','order_status','log_retention_days');
        foreach ($text_fields as $f) {
            if (isset($_POST[$f])) { $opts[$f] = sanitize_text_field(wp_unslash($_POST[$f])); }
        }
        if ($section === 'pricing') {
            foreach (array('sync_leasing_bnpl') as $cb) {
                $opts[$cb] = isset($_POST[$cb]) ? 'yes' : 'no';
            }
        } else {
            foreach (array('secret_is_base64','auto_hide_zero_stock','auto_create_orders','cron_enabled','cron_sync_inventory','cron_sync_prices','cron_import_orders','auto_sync_on_save','reduce_stock_on_import') as $cb) {
                $opts[$cb] = isset($_POST[$cb]) ? 'yes' : 'no';
            }
        }
        if (isset($_POST['category_rules']) && is_array($_POST['category_rules'])) {
            $rules = array();
            foreach ($_POST['category_rules'] as $term_id => $percent) {
                $percent = sanitize_text_field(wp_unslash($percent));
                if ($percent !== '') { $rules[absint($term_id)] = $percent; }
            }
            $opts['category_rules'] = $rules;
        }
        update_option(TLSCP_OPTION_KEY, $opts, false);
        wp_safe_redirect(add_query_arg(array('page' => $section === 'pricing' ? 'tlscp-pricing' : 'tlscp-settings', 'updated' => '1'), admin_url('admin.php')));
        exit;
    }

    public function ajax_test_connection() {
        $this->ajax_guard();
        wp_send_json($this->api->test_connection());
    }

    public function ajax_sync_product() {
        $this->ajax_guard();
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        wp_send_json($this->sync->sync_product($product_id));
    }

    public function ajax_sync_all() {
        $this->ajax_guard();
        $price = !empty($_POST['price']);
        $inventory = !empty($_POST['inventory']);
        wp_send_json($this->sync->sync_all_connected($price, $inventory, 50));
    }

    public function ajax_import_orders() {
        $this->ajax_guard();
        wp_send_json($this->orders->import_recent(null, 100));
    }

    public function ajax_retry_log() {
        $this->ajax_guard();
        $log_id = isset($_POST['log_id']) ? absint($_POST['log_id']) : 0;
        wp_send_json($this->sync->retry_log($log_id));
    }

    private function ajax_guard() {
        if (!current_user_can('manage_woocommerce')) { wp_send_json_error(array('message' => 'دسترسی غیرمجاز'), 403); }
        check_ajax_referer('tlscp_admin_nonce', 'nonce');
    }

    public function export_products() { check_admin_referer('tlscp_export_products'); TLSCP_Export::export_products(); }
    public function export_logs() { check_admin_referer('tlscp_export_logs'); TLSCP_Export::export_logs($this->logger); }
    public function export_orders() { check_admin_referer('tlscp_export_orders'); TLSCP_Export::export_orders($this->orders); }

    private function count_connected_products() {
        $q = new WP_Query(array('post_type' => array('product','product_variation'), 'post_status' => 'any', 'posts_per_page' => 1, 'meta_query' => array(array('key' => '_tlscp_enabled', 'value' => 'yes'), array('key' => '_tlscp_seller_item_code', 'value' => '', 'compare' => '!='))));
        return (int) $q->found_posts;
    }
}
