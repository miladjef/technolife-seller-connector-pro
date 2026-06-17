<?php
if (!defined('ABSPATH')) { exit; }

class TLSCP_Admin {
    private $api;
    private $sync;
    private $orders;
    private $pricing;
    private $logger;
    private $promotions;
    private $catalog;
    private $queue;

    public function __construct($api, $sync, $orders, $pricing, $logger, $promotions = null, $catalog = null, $queue = null) {
        $this->api = $api;
        $this->sync = $sync;
        $this->orders = $orders;
        $this->pricing = $pricing;
        $this->logger = $logger;
        $this->promotions = $promotions;
        $this->catalog = $catalog;
        $this->queue = $queue;
    }

    public function init() {
        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_enqueue_scripts', array($this, 'assets'));
        add_action('admin_post_tlscp_save_settings', array($this, 'save_settings'));
        add_action('admin_post_tlscp_export_settings', array($this, 'export_settings'));
        add_action('admin_post_tlscp_import_settings', array($this, 'import_settings'));
        add_action('admin_post_tlscp_export_products', array($this, 'export_products'));
        add_action('admin_post_tlscp_export_logs', array($this, 'export_logs'));
        add_action('admin_post_tlscp_export_orders', array($this, 'export_orders'));
        add_action('wp_dashboard_setup', array($this, 'register_dashboard_widget'));

        add_action('wp_ajax_tlscp_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_tlscp_sync_product', array($this, 'ajax_sync_product'));
        add_action('wp_ajax_tlscp_sync_all', array($this, 'ajax_sync_all'));
        add_action('wp_ajax_tlscp_import_orders', array($this, 'ajax_import_orders'));
        add_action('wp_ajax_tlscp_retry_log', array($this, 'ajax_retry_log'));
        // امکانات جدید
        add_action('wp_ajax_tlscp_item_info', array($this, 'ajax_item_info'));
        add_action('wp_ajax_tlscp_scan_buybox', array($this, 'ajax_scan_buybox'));
        add_action('wp_ajax_tlscp_promo_list', array($this, 'ajax_promo_list'));
        add_action('wp_ajax_tlscp_promo_items', array($this, 'ajax_promo_items'));
        add_action('wp_ajax_tlscp_promo_apply', array($this, 'ajax_promo_apply'));
        add_action('wp_ajax_tlscp_var_options', array($this, 'ajax_var_options'));
        add_action('wp_ajax_tlscp_create_variation', array($this, 'ajax_create_variation'));
        // امکانات نسخه ۱.۳
        add_action('wp_ajax_tlscp_catalog_browse', array($this, 'ajax_catalog_browse'));
        add_action('wp_ajax_tlscp_catalog_link', array($this, 'ajax_catalog_link'));
        add_action('wp_ajax_tlscp_price_preview', array($this, 'ajax_price_preview'));
        add_action('wp_ajax_tlscp_toggle_hide', array($this, 'ajax_toggle_hide'));
        add_action('wp_ajax_tlscp_queue_sync', array($this, 'ajax_queue_sync'));

        // Bulk actions روی جدول محصولات
        add_filter('bulk_actions-edit-product', array($this, 'register_bulk_actions'));
        add_filter('handle_bulk_actions-edit-product', array($this, 'handle_bulk_actions'), 10, 3);
        add_action('admin_notices', array($this, 'bulk_action_notice'));
    }

    public function menu() {
        add_menu_page('تکنولایف', 'تکنولایف', 'manage_woocommerce', 'tlscp-dashboard', array($this, 'page_dashboard'), 'dashicons-store', 56);
        add_submenu_page('tlscp-dashboard', 'داشبورد', 'داشبورد', 'manage_woocommerce', 'tlscp-dashboard', array($this, 'page_dashboard'));
        add_submenu_page('tlscp-dashboard', 'تنظیمات اتصال', 'تنظیمات اتصال', 'manage_woocommerce', 'tlscp-settings', array($this, 'page_settings'));
        add_submenu_page('tlscp-dashboard', 'قوانین قیمت‌گذاری', 'قوانین قیمت‌گذاری', 'manage_woocommerce', 'tlscp-pricing', array($this, 'page_pricing'));
        add_submenu_page('tlscp-dashboard', 'محصولات', 'محصولات', 'manage_woocommerce', 'tlscp-products', array($this, 'page_products'));
        add_submenu_page('tlscp-dashboard', 'مرورگر کاتالوگ', 'مرورگر کاتالوگ', 'manage_woocommerce', 'tlscp-catalog', array($this, 'page_catalog'));
        add_submenu_page('tlscp-dashboard', 'بای‌باکس', 'بای‌باکس', 'manage_woocommerce', 'tlscp-buybox', array($this, 'page_buybox'));
        add_submenu_page('tlscp-dashboard', 'پروموشن و تخفیف', 'پروموشن و تخفیف', 'manage_woocommerce', 'tlscp-promotions', array($this, 'page_promotions'));
        add_submenu_page('tlscp-dashboard', 'ساخت تنوع', 'ساخت تنوع', 'manage_woocommerce', 'tlscp-tools', array($this, 'page_tools'));
        add_submenu_page('tlscp-dashboard', 'سفارش‌ها', 'سفارش‌ها', 'manage_woocommerce', 'tlscp-orders', array($this, 'page_orders'));
        add_submenu_page('tlscp-dashboard', 'تشخیص سلامت', 'تشخیص سلامت', 'manage_woocommerce', 'tlscp-health', array($this, 'page_health'));
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
        echo '<div class="tlscp-tabs"><a href="' . esc_url(admin_url('admin.php?page=tlscp-dashboard')) . '">داشبورد</a><a href="' . esc_url(admin_url('admin.php?page=tlscp-settings')) . '">اتصال</a><a href="' . esc_url(admin_url('admin.php?page=tlscp-pricing')) . '">قیمت‌گذاری</a><a href="' . esc_url(admin_url('admin.php?page=tlscp-products')) . '">محصولات</a><a href="' . esc_url(admin_url('admin.php?page=tlscp-catalog')) . '">کاتالوگ</a><a href="' . esc_url(admin_url('admin.php?page=tlscp-buybox')) . '">بای‌باکس</a><a href="' . esc_url(admin_url('admin.php?page=tlscp-promotions')) . '">پروموشن</a><a href="' . esc_url(admin_url('admin.php?page=tlscp-tools')) . '">ساخت تنوع</a><a href="' . esc_url(admin_url('admin.php?page=tlscp-orders')) . '">سفارش‌ها</a><a href="' . esc_url(admin_url('admin.php?page=tlscp-logs')) . '">لاگ‌ها</a><a href="' . esc_url(admin_url('admin.php?page=tlscp-health')) . '">سلامت</a></div>';
    }

    private function footer() { echo '</div>'; }

    public function page_dashboard() {
        $this->header('داشبورد تکنولایف');
        $connected = $this->count_connected_products();
        $logs_failed = count($this->logger->recent(20, 0));
        $orders = count($this->orders->recent_orders(20));
        $buybox_losers = $this->count_buybox_losers();
        ?>
        <div class="tlscp-cards">
            <div class="tlscp-card"><h3>محصولات متصل</h3><strong><?php echo esc_html($connected); ?></strong><p>دارای sellerItemCode و اتصال فعال</p></div>
            <div class="tlscp-card"><h3>سفارش‌های اخیر</h3><strong><?php echo esc_html($orders); ?></strong><p>ذخیره‌شده در افزونه</p></div>
            <div class="tlscp-card"><h3>بازنده بای‌باکس</h3><strong><?php echo esc_html($buybox_losers); ?></strong><p><a href="<?php echo esc_url(admin_url('admin.php?page=tlscp-buybox')); ?>">مشاهده و اسکن</a></p></div>
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
        if (isset($_GET['tlscp_import'])) {
            $map = array('ok' => array('success', 'تنظیمات با موفقیت وارد شد.'), 'nofile' => array('error', 'فایلی انتخاب نشد.'), 'badjson' => array('error', 'فایل JSON نامعتبر است.'));
            $k = sanitize_text_field(wp_unslash($_GET['tlscp_import']));
            if (isset($map[$k])) { echo '<div class="notice notice-' . esc_attr($map[$k][0]) . ' is-dismissible"><p>' . esc_html($map[$k][1]) . '</p></div>'; }
        }
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="tlscp-form">
            <?php wp_nonce_field('tlscp_save_settings'); ?><input type="hidden" name="action" value="tlscp_save_settings"><input type="hidden" name="section" value="settings">
            <div class="tlscp-panel"><h2>API</h2>
                <table class="form-table" role="presentation">
                    <tr><th>Base URL</th><td><input type="url" class="regular-text ltr" name="api_base_url" value="<?php echo esc_attr($opts['api_base_url']); ?>"></td></tr>
                    <tr><th>API Key</th><td><input type="password" class="regular-text ltr" name="api_key" value="<?php echo esc_attr($opts['api_key']); ?>" autocomplete="new-password"><p class="description">بدون عبارت Bearer وارد کنید.</p></td></tr>
                    <tr><th>Secret Key</th><td><input type="password" class="regular-text ltr" name="secret_key" value="" placeholder="<?php echo TLSCP_Crypto::reveal_secret($opts) !== '' ? '•••••• (برای تغییر، مقدار جدید وارد کنید)' : ''; ?>" autocomplete="new-password"><?php
                        $secret_plain = TLSCP_Crypto::reveal_secret($opts);
                        if ($secret_plain !== '') {
                            $klen = TLSCP_Crypto::decoded_key_length($secret_plain, $opts['secret_is_base64'] === 'yes');
                            if ($klen === 16) {
                                echo '<p class="description" style="color:#006b2d">✔ کلید معتبر است (۱۶ بایت، مناسب AES-128) و در دیتابیس رمزنگاری شده است.</p>';
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
                    <tr><th>هشدار بای‌باکس</th><td><label><input type="checkbox" name="buybox_alerts" value="yes" <?php checked($opts['buybox_alerts'], 'yes'); ?>> در داشبورد و فهرست محصولات، بازنده‌های بای‌باکس مشخص شوند</label></td></tr>
                    <tr><th>ساخت سفارش ووکامرس</th><td><label><input type="checkbox" name="auto_create_orders" value="yes" <?php checked($opts['auto_create_orders'], 'yes'); ?>> سفارش‌های تکنولایف در ووکامرس ساخته شوند</label></td></tr>
                    <tr><th>تعداد روز دریافت سفارش</th><td><input type="number" min="1" max="365" name="orders_from_days" value="<?php echo esc_attr($opts['orders_from_days']); ?>"></td></tr>
                    <tr><th>حداکثر صفحات سفارش</th><td><input type="number" min="1" max="20" name="orders_max_pages" value="<?php echo esc_attr($opts['orders_max_pages']); ?>"> <span class="description">هر صفحه طبق API حداکثر ۱۰۰ آیتم دارد.</span></td></tr>
                    <tr><th>وضعیت سفارش ووکامرس</th><td><select name="order_status"><option value="wc-processing" <?php selected($opts['order_status'], 'wc-processing'); ?>>در حال انجام</option><option value="wc-on-hold" <?php selected($opts['order_status'], 'wc-on-hold'); ?>>در انتظار بررسی</option><option value="wc-pending" <?php selected($opts['order_status'], 'wc-pending'); ?>>در انتظار پرداخت</option></select></td></tr>
                    <tr><th>همگام‌سازی وضعیت تکنولایف ← ووکامرس</th><td><label><input type="checkbox" name="order_status_sync" value="yes" <?php checked($opts['order_status_sync'], 'yes'); ?>> وقتی وضعیت سفارش در تکنولایف تغییر کند، وضعیت سفارش ووکامرس هم به‌روزرسانی شود</label></td></tr>
                    <?php
                    $wc_statuses = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : array();
                    $seen = method_exists($this->orders, 'seen_statuses') ? $this->orders->seen_statuses() : array();
                    $map = isset($opts['order_status_map']) && is_array($opts['order_status_map']) ? $opts['order_status_map'] : array();
                    if (!empty($seen)) : ?>
                    <tr><th>نگاشت وضعیت‌ها</th><td>
                        <table class="widefat striped" style="max-width:560px"><thead><tr><th>وضعیت تکنولایف</th><th>وضعیت ووکامرس</th></tr></thead><tbody>
                        <?php foreach ($seen as $st): $cur = isset($map[$st]) ? $map[$st] : ''; ?>
                            <tr><td class="ltr"><?php echo esc_html($st); ?></td><td><select name="order_status_map[<?php echo esc_attr($st); ?>]"><option value="">— بدون تغییر —</option>
                            <?php foreach ($wc_statuses as $key => $label): ?>
                                <option value="<?php echo esc_attr($key); ?>" <?php selected($cur, $key); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                            </select></td></tr>
                        <?php endforeach; ?>
                        </tbody></table>
                        <p class="description">فقط وضعیت‌هایی که تاکنون در سفارش‌های دریافت‌شده دیده شده‌اند نمایش داده می‌شوند. ابتدا یک‌بار سفارش‌ها را دریافت کنید تا این فهرست پر شود.</p>
                    </td></tr>
                    <?php endif; ?>
                    <tr><th>کاهش موجودی بعد از ساخت سفارش</th><td><label><input type="checkbox" name="reduce_stock_on_import" value="yes" <?php checked($opts['reduce_stock_on_import'], 'yes'); ?>> موجودی ووکامرس برای سفارش‌های جدید تکنولایف کاهش داده شود</label></td></tr>
                    <tr><th>همگام‌سازی هنگام ذخیره محصول</th><td><label><input type="checkbox" name="auto_sync_on_save" value="yes" <?php checked($opts['auto_sync_on_save'], 'yes'); ?>> بعد از ذخیره محصول، قیمت و موجودی به تکنولایف ارسال شود</label></td></tr>
                    <tr><th>کران خودکار</th><td><label><input type="checkbox" name="cron_enabled" value="yes" <?php checked($opts['cron_enabled'], 'yes'); ?>> فعال</label> &nbsp; <label><input type="checkbox" name="cron_sync_inventory" value="yes" <?php checked($opts['cron_sync_inventory'], 'yes'); ?>> موجودی</label> &nbsp; <label><input type="checkbox" name="cron_sync_prices" value="yes" <?php checked($opts['cron_sync_prices'], 'yes'); ?>> قیمت</label> &nbsp; <label><input type="checkbox" name="cron_import_orders" value="yes" <?php checked($opts['cron_import_orders'], 'yes'); ?>> سفارش</label> &nbsp; <label><input type="checkbox" name="cron_scan_buybox" value="yes" <?php checked($opts['cron_scan_buybox'], 'yes'); ?>> اسکن بای‌باکس</label></td></tr>
                </table>
            </div>
            <div class="tlscp-panel"><h2>پیشرفته و پایداری</h2>
                <table class="form-table" role="presentation">
                    <tr><th>تشخیص تغییر (Idempotency)</th><td><label><input type="checkbox" name="idempotent_sync" value="yes" <?php checked($opts['idempotent_sync'], 'yes'); ?>> فقط در صورت تغییر قیمت/موجودی به API ارسال شود (کاهش فراخوانی)</label></td></tr>
                    <tr><th>پردازش پس‌زمینه</th><td><label><input type="checkbox" name="use_action_scheduler" value="yes" <?php checked($opts['use_action_scheduler'], 'yes'); ?>> استفاده از Action Scheduler برای صف‌بندی per-product (مناسب کاتالوگ بزرگ)</label><p class="description">در صورت نبود Action Scheduler، به‌صورت خودکار به wp-cron برمی‌گردد.</p></td></tr>
                    <tr><th>همگام‌سازی لحظه‌ای</th><td><label><input type="checkbox" name="realtime_sync" value="yes" <?php checked($opts['realtime_sync'], 'yes'); ?>> با تغییر موجودی/قیمت محصول، بلافاصله یک job پس‌زمینه صف شود</label></td></tr>
                    <tr><th>فاصله بین درخواست‌ها</th><td><input type="number" min="0" name="request_throttle_ms" value="<?php echo esc_attr($opts['request_throttle_ms']); ?>" style="width:90px"> میلی‌ثانیه &nbsp; | &nbsp; حداکثر تلاش مجدد: <input type="number" min="0" max="5" name="request_max_retries" value="<?php echo esc_attr($opts['request_max_retries']); ?>" style="width:60px"> <span class="description">backoff نمایی روی خطای 429/5xx</span></td></tr>
                </table>
            </div>
            <div class="tlscp-panel"><h2>اعلان‌ها</h2>
                <table class="form-table" role="presentation">
                    <tr><th>ایمیل دریافت اعلان</th><td><input type="email" class="regular-text ltr" name="notify_email" value="<?php echo esc_attr($opts['notify_email']); ?>" placeholder="<?php echo esc_attr(get_option('admin_email')); ?>"></td></tr>
                    <tr><th>هشدار باخت بای‌باکس</th><td><label><input type="checkbox" name="notify_buybox_loss" value="yes" <?php checked($opts['notify_buybox_loss'], 'yes'); ?>> هنگام اسکن خودکار، اگر بازنده‌ای بود ایمیل ارسال شود</label></td></tr>
                    <tr><th>آستانه‌ی هشدار خطا</th><td><input type="number" min="0" name="notify_error_threshold" value="<?php echo esc_attr($opts['notify_error_threshold']); ?>" style="width:80px"> <span class="description">اگر تعداد خطاهای اخیر از این مقدار بیشتر شد، ایمیل ارسال شود (۰ = غیرفعال)</span></td></tr>
                </table>
            </div>
            <div class="tlscp-panel"><h2>پشتیبان‌گیری تنظیمات</h2>
                <p class="description">برای انتقال پیکربندی بین سایت‌ها. کلیدهای حساس (API/Secret) صادر نمی‌شوند و هنگام ورود نیز دست‌نخورده می‌مانند.</p>
                <p><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tlscp_export_settings'), 'tlscp_export_settings')); ?>">خروجی تنظیمات (JSON)</a></p>
                <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px">
                    <?php wp_nonce_field('tlscp_import_settings'); ?>
                    <input type="hidden" name="action" value="tlscp_import_settings">
                    <input type="file" name="settings_file" accept="application/json,.json">
                    <button class="button">ورود تنظیمات</button>
                </form>
            </div>
            <div class="tlscp-panel"><h2>حذف افزونه</h2>
                <table class="form-table" role="presentation">
                    <tr><th>پاک‌سازی هنگام حذف</th><td><label><input type="checkbox" name="remove_data_on_uninstall" value="yes" <?php checked($opts['remove_data_on_uninstall'], 'yes'); ?>> هنگام حذف افزونه، جدول‌ها، تنظیمات و متاها پاک شوند</label></td></tr>
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
                    <tr><th>مبنای قیمت</th><td><select name="price_basis"><option value="wc" <?php selected($opts['price_basis'], 'wc'); ?>>قیمت ووکامرس</option><option value="reference" <?php selected($opts['price_basis'], 'reference'); ?>>قیمت مرجع تکنولایف (referencePrice)</option></select> &nbsp; درصد روی مرجع: <input type="number" step="0.01" name="reference_markup_percent" value="<?php echo esc_attr($opts['reference_markup_percent']); ?>" style="width:80px"> ٪ <p class="description">با انتخاب «قیمت مرجع»، قیمت بر اساس referencePrice تکنولایف + این درصد محاسبه می‌شود (اگر مرجع موجود نباشد به قیمت ووکامرس برمی‌گردد).</p></td></tr>
                    <tr><th>کنترل بازه اقساطی/اعتباری</th><td><label><input type="checkbox" name="enforce_leasing_range" value="yes" <?php checked($opts['enforce_leasing_range'], 'yes'); ?>> اعمال بازه‌ی مجاز روی قیمت اقساطی</label><br><label><input type="checkbox" name="enforce_bnpl_range" value="yes" <?php checked($opts['enforce_bnpl_range'], 'yes'); ?>> اعمال بازه‌ی مجاز روی قیمت اعتباری</label><p class="description">قیمت‌های خارج از بازه ارسال نمی‌شوند تا از رد شدن بی‌صدا جلوگیری شود.</p></td></tr>
                    <tr><th>قیمت‌گذاری رقابتی بای‌باکس</th><td>
                        <label><input type="checkbox" name="competitive_repricing" value="yes" <?php checked($opts['competitive_repricing'], 'yes'); ?>> فعال (فقط وقتی بای‌باکس را باخته‌ایم، قیمت کمی زیر برنده تنظیم می‌شود)</label>
                        <p>میزان کاهش از قیمت برنده:
                            <select name="competitive_undercut_type"><option value="amount" <?php selected($opts['competitive_undercut_type'], 'amount'); ?>>مبلغ (ریال)</option><option value="percent" <?php selected($opts['competitive_undercut_type'], 'percent'); ?>>درصد</option></select>
                            <input type="number" step="0.01" min="0" name="competitive_undercut_value" value="<?php echo esc_attr($opts['competitive_undercut_value']); ?>" style="width:100px">
                        </p>
                        <p>حداکثر کاهش مجاز از قیمت نرمال (کف حاشیه سود): <input type="number" step="0.01" min="0" name="competitive_min_margin_percent" value="<?php echo esc_attr($opts['competitive_min_margin_percent']); ?>" style="width:80px"> ٪</p>
                        <p class="description">قیمت هرگز پایین‌تر از کفِ مجاز API و کفِ حاشیه‌ی سود شما نمی‌رود.</p>
                    </td></tr>
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
        $f_action = isset($_GET['lact']) ? sanitize_text_field(wp_unslash($_GET['lact'])) : '';
        $f_success = isset($_GET['lsucc']) ? sanitize_text_field(wp_unslash($_GET['lsucc'])) : '';
        $f_search = isset($_GET['ls']) ? sanitize_text_field(wp_unslash($_GET['ls'])) : '';
        $page = isset($_GET['lp']) ? max(1, absint($_GET['lp'])) : 1;
        $res = $this->logger->query(array('action' => $f_action, 'success' => $f_success, 'search' => $f_search, 'per_page' => 30, 'page' => $page));
        $logs = $res['rows'];
        $actions = $this->logger->distinct_actions();
        ?>
        <form method="get" class="tlscp-panel" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <input type="hidden" name="page" value="tlscp-logs">
            <select name="lact"><option value="">همه عملیات</option><?php foreach ($actions as $a): ?><option value="<?php echo esc_attr($a); ?>" <?php selected($f_action, $a); ?>><?php echo esc_html($a); ?></option><?php endforeach; ?></select>
            <select name="lsucc"><option value="">همه وضعیت‌ها</option><option value="1" <?php selected($f_success, '1'); ?>>موفق</option><option value="0" <?php selected($f_success, '0'); ?>>ناموفق</option></select>
            <input type="search" name="ls" value="<?php echo esc_attr($f_search); ?>" placeholder="جستجو در endpoint/پیام/کد">
            <button class="button">فیلتر</button>
            <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tlscp_export_logs'), 'tlscp_export_logs')); ?>">خروجی CSV</a>
            <span class="description">مجموع: <?php echo esc_html($res['total']); ?></span>
        </form>
        <table class="widefat striped"><thead><tr><th>ID</th><th>زمان</th><th>عملیات</th><th>Endpoint</th><th>HTTP</th><th>وضعیت</th><th>پیام</th><th>تکرار</th></tr></thead><tbody>
        <?php foreach ($logs as $log): ?>
            <tr><td><?php echo esc_html($log['id']); ?></td><td class="ltr"><?php echo esc_html($log['created_at']); ?></td><td><?php echo esc_html($log['action']); ?></td><td class="ltr"><?php echo esc_html($log['method'] . ' ' . $log['endpoint']); ?></td><td><?php echo esc_html($log['http_status']); ?></td><td><?php echo $log['success'] ? '<span class="tlscp-badge tlscp-ok">موفق</span>' : '<span class="tlscp-badge tlscp-error">ناموفق</span>'; ?></td><td><?php echo esc_html($log['message']); ?></td><td><?php if (!$log['success'] && $log['retry_payload']) : ?><button class="button tlscp-retry-log" data-log-id="<?php echo esc_attr($log['id']); ?>">اجرای مجدد</button><?php else: ?>-<?php endif; ?></td></tr>
        <?php endforeach; ?>
        <?php if (empty($logs)): ?><tr><td colspan="8">رکوردی یافت نشد.</td></tr><?php endif; ?>
        </tbody></table>
        <?php
        if ($res['pages'] > 1) {
            $base = add_query_arg(array('page' => 'tlscp-logs', 'lact' => $f_action, 'lsucc' => $f_success, 'ls' => $f_search), admin_url('admin.php'));
            echo '<p class="tlscp-pager">';
            for ($i = 1; $i <= $res['pages']; $i++) {
                if ($i == $res['page']) {
                    echo '<strong>' . esc_html($i) . '</strong> ';
                } else {
                    echo '<a href="' . esc_url(add_query_arg('lp', $i, $base)) . '">' . esc_html($i) . '</a> ';
                }
            }
            echo '</p>';
        }
        ?>
        <div id="tlscp-ajax-result"></div>
        <?php $this->footer();
    }

    public function page_buybox() {
        $this->header('داشبورد بای‌باکس');
        ?>
        <div class="tlscp-panel">
            <p>با اسکن بای‌باکس، وضعیت برنده/بازنده هر تنوع از تکنولایف خوانده می‌شود و محصولاتی که بای‌باکس را باخته‌اند همراه با اختلاف قیمت نمایش داده می‌شوند.</p>
            <button class="button button-primary" id="tlscp-scan-buybox">اسکن بای‌باکس محصولات متصل</button>
            <div id="tlscp-buybox-result"></div>
        </div>
        <div class="tlscp-panel">
            <h2>وضعیت ذخیره‌شده‌ی محصولات</h2>
            <table class="widefat striped"><thead><tr><th>محصول</th><th>SellerItemCode</th><th>بای‌باکس</th><th>قیمت من</th><th>قیمت برنده</th><th>اختلاف</th><th>آخرین بررسی</th></tr></thead><tbody>
            <?php
            $q = new WP_Query(array('post_type' => array('product','product_variation'), 'post_status' => 'any', 'posts_per_page' => 200, 'meta_query' => array(array('key' => '_tlscp_enabled', 'value' => 'yes'), array('key' => '_tlscp_buybox_winner', 'compare' => 'EXISTS')), 'fields' => 'ids', 'no_found_rows' => true));
            foreach ($q->posts as $pid):
                $winner = get_post_meta($pid, '_tlscp_buybox_winner', true);
                $my = get_post_meta($pid, '_tlscp_remote_cash_price', true);
                $win = get_post_meta($pid, '_tlscp_buybox_price', true);
                $gap = ($my !== '' && $win !== '') ? ((float)$my - (float)$win) : '';
            ?>
                <tr>
                    <td><a href="<?php echo esc_url(get_edit_post_link($pid)); ?>"><?php echo esc_html(get_the_title($pid)); ?></a></td>
                    <td class="ltr"><?php echo esc_html(get_post_meta($pid, '_tlscp_seller_item_code', true)); ?></td>
                    <td><?php echo $winner === 'yes' ? '<span class="tlscp-badge tlscp-ok">برنده</span>' : '<span class="tlscp-badge tlscp-error">بازنده</span>'; ?></td>
                    <td class="ltr"><?php echo esc_html($my !== '' ? number_format((float)$my) : '-'); ?></td>
                    <td class="ltr"><?php echo esc_html($win !== '' ? number_format((float)$win) : '-'); ?></td>
                    <td class="ltr"><?php echo $gap === '' ? '-' : esc_html(number_format($gap)); ?></td>
                    <td class="ltr"><?php echo esc_html(get_post_meta($pid, '_tlscp_rt_updated', true) ?: '-'); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>
        </div>
        <?php $this->footer();
    }

    public function page_promotions() {
        $this->header('پروموشن و تخفیف زمان‌دار');
        ?>
        <div class="tlscp-panel">
            <h2>۱) انتخاب محصول</h2>
            <p>کد محصول (ProductCode) را وارد کنید تا پروموشن‌های فعال و تنوع‌های آن از تکنولایف بارگذاری شوند.</p>
            <input type="text" class="regular-text ltr" id="tlscp-promo-product-code" placeholder="ProductCode">
            <button class="button" id="tlscp-promo-load">بارگذاری پروموشن و تنوع‌ها</button>
            <div id="tlscp-promo-load-result"></div>
        </div>
        <div class="tlscp-panel" id="tlscp-promo-builder" style="display:none">
            <h2>۲) تنظیم تخفیف</h2>
            <table class="form-table" role="presentation">
                <tr><th>پروموشن (marketingGroup)</th><td><select id="tlscp-promo-group" class="regular-text"></select></td></tr>
                <tr><th>تعداد کالای مشمول تخفیف</th><td><input type="number" min="1" id="tlscp-promo-count" value="1"></td></tr>
                <tr><th>درصد تخفیف</th><td><input type="number" step="0.01" min="0" id="tlscp-promo-percent" placeholder="مثلاً 20"> ٪ <span class="description">اولویت بالاتر از قیمت تخفیف</span></td></tr>
                <tr><th>یا قیمت تخفیف</th><td><input type="number" min="0" id="tlscp-promo-price" placeholder="بر اساس واحد فروشگاه"> <span class="description">به واحد قیمت فروشگاه (به ریال تبدیل می‌شود)</span></td></tr>
                <tr><th>تاریخ شروع</th><td><input type="datetime-local" id="tlscp-promo-start"></td></tr>
                <tr><th>تاریخ پایان</th><td><input type="datetime-local" id="tlscp-promo-end"></td></tr>
            </table>
            <h2>۳) انتخاب تنوع‌ها</h2>
            <table class="widefat striped" id="tlscp-promo-items"><thead><tr><th><input type="checkbox" id="tlscp-promo-all"></th><th>SellerItemCode</th><th>تنوع</th><th>قیمت نقدی</th><th>بای‌باکس</th></tr></thead><tbody></tbody></table>
            <p><button class="button button-primary" id="tlscp-promo-apply">اعمال تخفیف روی تنوع‌های انتخاب‌شده</button></p>
            <div id="tlscp-promo-apply-result"></div>
        </div>
        <?php $this->footer();
    }

    public function page_tools() {
        $this->header('ساخت تنوع فروشنده');
        ?>
        <div class="tlscp-panel">
            <h2>ساخت خودکار تنوع</h2>
            <p>کد محصول را وارد کنید تا لیست «رنگ/وزن» و «گارانتی» از تکنولایف بارگذاری شود؛ سپس بدون نیاز به دانستن آیدی‌ها، تنوع جدید بسازید.</p>
            <input type="text" class="regular-text ltr" id="tlscp-var-product-code" placeholder="ProductCode">
            <button class="button" id="tlscp-var-load">بارگذاری گزینه‌ها</button>
            <div id="tlscp-var-load-result"></div>
            <table class="form-table" role="presentation" id="tlscp-var-form" style="display:none">
                <tr><th>رنگ / وزن (variationId)</th><td><select id="tlscp-var-variation" class="regular-text"></select></td></tr>
                <tr><th>گارانتی (guaranteeId)</th><td><select id="tlscp-var-guarantee" class="regular-text"></select></td></tr>
            </table>
            <p id="tlscp-var-actions" style="display:none"><button class="button button-primary" id="tlscp-var-create">ساخت تنوع</button></p>
            <div id="tlscp-var-create-result"></div>
        </div>
        <?php $this->footer();
    }

    public function page_catalog() {
        $this->header('مرورگر کاتالوگ تکنولایف');
        ?>
        <div class="tlscp-panel">
            <p>کاتالوگ فروشنده را از تکنولایف مرور کنید و محصولات را با ووکامرس تطبیق دهید. سیستم بر اساس SKU (= ProductCode) و سپس نام، محصول ووکامرس متناظر را پیشنهاد می‌دهد.</p>
            <input type="text" class="regular-text" id="tlscp-cat-search" placeholder="جستجو (اختیاری)">
            <button class="button" id="tlscp-cat-load">بارگذاری کاتالوگ</button>
            <span id="tlscp-cat-pager"></span>
            <div id="tlscp-cat-result"></div>
        </div>
        <?php $this->footer();
    }

    public function page_health() {
        $this->header('تشخیص سلامت');
        $opts = $this->opts();
        $secret_plain = TLSCP_Crypto::reveal_secret($opts);
        $key_len = $secret_plain !== '' ? TLSCP_Crypto::decoded_key_length($secret_plain, $opts['secret_is_base64'] === 'yes') : 0;
        $stored_secret = isset($opts['secret_key']) ? (string) $opts['secret_key'] : '';
        $is_encrypted = strpos($stored_secret, 'enc::') === 0;
        $checks = array(
            array('OpenSSL در PHP', function_exists('openssl_encrypt'), function_exists('openssl_encrypt') ? 'فعال' : 'غیرفعال'),
            array('WooCommerce', class_exists('WooCommerce'), class_exists('WooCommerce') ? (defined('WC_VERSION') ? WC_VERSION : 'فعال') : 'نصب نیست'),
            array('کلید API', !empty($opts['api_key']), !empty($opts['api_key']) ? 'تنظیم شده' : 'خالی'),
            array('Secret (۱۶ بایت)', $key_len === 16, $key_len === 16 ? 'معتبر' : ('نامعتبر: ' . $key_len . ' بایت')),
            array('رمزنگاری Secret در DB', $is_encrypted, $is_encrypted ? 'رمزنگاری‌شده' : 'متن ساده (legacy)'),
            array('Action Scheduler', function_exists('as_enqueue_async_action'), function_exists('as_enqueue_async_action') ? 'در دسترس' : 'در دسترس نیست (fallback به wp-cron)'),
            array('کران ۱۵ دقیقه‌ای', (bool) wp_next_scheduled('tlscp_cron_sync'), wp_next_scheduled('tlscp_cron_sync') ? wp_date('Y-m-d H:i', wp_next_scheduled('tlscp_cron_sync')) : 'زمان‌بندی نشده'),
            array('کران فعال', $opts['cron_enabled'] === 'yes', $opts['cron_enabled'] === 'yes' ? 'بله' : 'خیر'),
        );
        ?>
        <div class="tlscp-panel">
            <table class="widefat striped"><thead><tr><th>بررسی</th><th>وضعیت</th><th>جزئیات</th></tr></thead><tbody>
            <?php foreach ($checks as $c): ?>
                <tr><td><?php echo esc_html($c[0]); ?></td><td><?php echo $c[1] ? '<span class="tlscp-badge tlscp-ok">سالم</span>' : '<span class="tlscp-badge tlscp-error">بررسی شود</span>'; ?></td><td class="ltr"><?php echo esc_html($c[2]); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
            <p>
                <button class="button" id="tlscp-test-connection">تست اتصال API</button>
                <button class="button" id="tlscp-queue-sync">صف‌بندی همگام‌سازی کامل (پس‌زمینه)</button>
            </p>
            <div id="tlscp-ajax-result"></div>
        </div>
        <?php $this->footer();
    }

    // ---- Bulk actions ----
    public function register_bulk_actions($actions) {
        $actions['tlscp_bulk_connect'] = 'تکنولایف: اتصال (با SKU)';
        $actions['tlscp_bulk_sync'] = 'تکنولایف: همگام‌سازی قیمت و موجودی';
        $actions['tlscp_bulk_hide'] = 'تکنولایف: مخفی‌کردن';
        $actions['tlscp_bulk_show'] = 'تکنولایف: نمایش';
        return $actions;
    }

    public function handle_bulk_actions($redirect, $action, $ids) {
        if (strpos($action, 'tlscp_bulk_') !== 0) { return $redirect; }
        $ids = array_map('absint', (array) $ids);
        $done = 0;
        foreach ($ids as $pid) {
            if ($action === 'tlscp_bulk_connect') {
                $product = wc_get_product($pid);
                $sku = $product ? $product->get_sku() : '';
                if ($sku) { update_post_meta($pid, '_tlscp_product_code', $sku); update_post_meta($pid, '_tlscp_enabled', 'yes'); $done++; }
            } elseif ($action === 'tlscp_bulk_sync') {
                $r = $this->sync->sync_product($pid, true); if (!empty($r['success'])) { $done++; }
            } elseif ($action === 'tlscp_bulk_hide') {
                $r = $this->sync->toggle_hide($pid, true); if (!empty($r['success'])) { $done++; }
            } elseif ($action === 'tlscp_bulk_show') {
                $r = $this->sync->toggle_hide($pid, false); if (!empty($r['success'])) { $done++; }
            }
        }
        return add_query_arg('tlscp_bulk_done', $done, $redirect);
    }

    public function bulk_action_notice() {
        if (!empty($_REQUEST['tlscp_bulk_done'])) {
            echo '<div class="notice notice-success is-dismissible"><p>تکنولایف: عملیات روی ' . absint($_REQUEST['tlscp_bulk_done']) . ' مورد انجام شد.</p></div>';
        }
    }

    public function register_dashboard_widget() {
        if (current_user_can('manage_woocommerce')) {
            wp_add_dashboard_widget('tlscp_dashboard_widget', 'تکنولایف — وضعیت', array($this, 'dashboard_widget'));
        }
    }

    public function dashboard_widget() {
        $connected = $this->count_connected_products();
        $losers = $this->count_buybox_losers();
        $orders = count($this->orders->recent_orders(20));
        $errors = count($this->logger->recent(20, 0));
        echo '<ul style="margin:0">';
        echo '<li>محصولات متصل: <strong>' . esc_html($connected) . '</strong></li>';
        echo '<li>بازنده بای‌باکس: <strong style="color:' . ($losers ? '#b42318' : '#006b2d') . '">' . esc_html($losers) . '</strong></li>';
        echo '<li>سفارش‌های اخیر: <strong>' . esc_html($orders) . '</strong></li>';
        echo '<li>خطاهای اخیر API: <strong style="color:' . ($errors ? '#b42318' : '#006b2d') . '">' . esc_html($errors) . '</strong></li>';
        echo '</ul>';
        echo '<p><a class="button button-small" href="' . esc_url(admin_url('admin.php?page=tlscp-dashboard')) . '">داشبورد افزونه</a> ';
        echo '<a class="button button-small" href="' . esc_url(admin_url('admin.php?page=tlscp-buybox')) . '">بای‌باکس</a></p>';
    }

    /**
     * خروجی تنظیمات به‌صورت JSON (Secret حذف می‌شود).
     */
    public function export_settings() {
        if (!current_user_can('manage_woocommerce')) { wp_die('دسترسی غیرمجاز'); }
        check_admin_referer('tlscp_export_settings');
        $opts = $this->opts();
        unset($opts['secret_key'], $opts['api_key']); // اطلاعات حساس صادر نمی‌شوند
        $json = wp_json_encode($opts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename=tlscp-settings-' . gmdate('Y-m-d') . '.json');
        echo $json;
        exit;
    }

    /**
     * ورود تنظیمات از فایل JSON (کلیدهای حساس و ساختار سفارش دست‌نخورده می‌مانند).
     */
    public function import_settings() {
        if (!current_user_can('manage_woocommerce')) { wp_die('دسترسی غیرمجاز'); }
        check_admin_referer('tlscp_import_settings');
        $redirect = add_query_arg(array('page' => 'tlscp-settings'), admin_url('admin.php'));
        if (empty($_FILES['settings_file']['tmp_name']) || !is_uploaded_file($_FILES['settings_file']['tmp_name'])) {
            wp_safe_redirect(add_query_arg('tlscp_import', 'nofile', $redirect)); exit;
        }
        $raw = file_get_contents($_FILES['settings_file']['tmp_name']);
        $incoming = json_decode($raw, true);
        if (!is_array($incoming)) {
            wp_safe_redirect(add_query_arg('tlscp_import', 'badjson', $redirect)); exit;
        }
        $current = $this->opts();
        // کلیدهای حساس و داده‌های اتصال از مقادیر فعلی حفظ می‌شوند.
        foreach (array('secret_key', 'api_key') as $keep) {
            if (isset($current[$keep])) { $incoming[$keep] = $current[$keep]; }
        }
        $merged = wp_parse_args($incoming, TLSCP_Installer::default_options());
        update_option(TLSCP_OPTION_KEY, $merged, false);
        wp_safe_redirect(add_query_arg('tlscp_import', 'ok', $redirect)); exit;
    }

    public function save_settings() {
        if (!current_user_can('manage_woocommerce')) { wp_die('دسترسی غیرمجاز'); }
        check_admin_referer('tlscp_save_settings');
        $opts = $this->opts();
        $section = isset($_POST['section']) ? sanitize_text_field(wp_unslash($_POST['section'])) : 'settings';

        $text_fields = array('api_base_url','api_key','encryption_payload_mode','global_markup_percent','price_unit','price_basis','reference_markup_percent','category_strategy','multi_category_strategy','price_source','rounding','out_of_range_strategy','leasing_percent','bnpl_percent','competitive_undercut_type','competitive_undercut_value','competitive_min_margin_percent','request_throttle_ms','request_max_retries','inventory_reserve','inventory_max_send','inventory_unmanaged_qty','leave_time','max_buy_per_order','orders_from_days','orders_max_pages','order_status','notify_email','notify_error_threshold','log_retention_days');
        foreach ($text_fields as $f) {
            if (isset($_POST[$f])) { $opts[$f] = sanitize_text_field(wp_unslash($_POST[$f])); }
        }
        // Secret به‌صورت رمزنگاری‌شده ذخیره می‌شود (at-rest). فقط هنگام تغییر مقدار.
        if (isset($_POST['secret_key'])) {
            $new_secret = trim((string) wp_unslash($_POST['secret_key']));
            if ($new_secret !== '') {
                $opts['secret_key'] = TLSCP_Crypto::encrypt_store($new_secret);
            }
        }
        if ($section === 'pricing') {
            foreach (array('sync_leasing_bnpl','enforce_leasing_range','enforce_bnpl_range','competitive_repricing') as $cb) {
                $opts[$cb] = isset($_POST[$cb]) ? 'yes' : 'no';
            }
        } else {
            foreach (array('secret_is_base64','auto_hide_zero_stock','auto_create_orders','cron_enabled','cron_sync_inventory','cron_sync_prices','cron_import_orders','cron_scan_buybox','auto_sync_on_save','reduce_stock_on_import','order_status_sync','buybox_alerts','idempotent_sync','realtime_sync','use_action_scheduler','notify_buybox_loss','remove_data_on_uninstall') as $cb) {
                $opts[$cb] = isset($_POST[$cb]) ? 'yes' : 'no';
            }
            if (isset($_POST['order_status_map']) && is_array($_POST['order_status_map'])) {
                $smap = array();
                foreach ($_POST['order_status_map'] as $tl => $wc) {
                    $wc = sanitize_text_field(wp_unslash($wc));
                    if ($wc !== '') { $smap[sanitize_text_field(wp_unslash($tl))] = $wc; }
                }
                $opts['order_status_map'] = $smap;
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

    // ---- امکانات جدید ----

    public function ajax_item_info() {
        $this->ajax_guard();
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        if (!$product_id) { wp_send_json(array('success' => false, 'message' => 'شناسه محصول نامعتبر است.')); }
        wp_send_json($this->sync->live_item_info($product_id));
    }

    public function ajax_scan_buybox() {
        $this->ajax_guard();
        wp_send_json($this->sync->scan_buybox(150));
    }

    public function ajax_promo_list() {
        $this->ajax_guard();
        $code = isset($_POST['product_code']) ? sanitize_text_field(wp_unslash($_POST['product_code'])) : '';
        wp_send_json($this->promotions ? $this->promotions->list_for_product($code) : array('success' => false, 'message' => 'سرویس پروموشن در دسترس نیست.'));
    }

    public function ajax_promo_items() {
        $this->ajax_guard();
        $code = isset($_POST['product_code']) ? sanitize_text_field(wp_unslash($_POST['product_code'])) : '';
        $res = $this->api->product_items($code);
        if (empty($res['success'])) { wp_send_json(array('success' => false, 'message' => $res['message'])); }
        $items = (isset($res['data']['data']) && is_array($res['data']['data'])) ? $res['data']['data'] : array();
        wp_send_json(array('success' => true, 'items' => $items));
    }

    public function ajax_promo_apply() {
        $this->ajax_guard();
        if (!$this->promotions) { wp_send_json(array('success' => false, 'message' => 'سرویس پروموشن در دسترس نیست.')); }
        $codes = isset($_POST['seller_item_codes']) ? (array) wp_unslash($_POST['seller_item_codes']) : array();
        $codes = array_map('sanitize_text_field', $codes);
        $args = array(
            'count' => isset($_POST['count']) ? absint($_POST['count']) : 1,
            'discountedPercent' => isset($_POST['discountedPercent']) ? sanitize_text_field(wp_unslash($_POST['discountedPercent'])) : '',
            'discountedPrice' => isset($_POST['discountedPrice']) ? sanitize_text_field(wp_unslash($_POST['discountedPrice'])) : '',
            'marketingGroup' => isset($_POST['marketingGroup']) ? sanitize_text_field(wp_unslash($_POST['marketingGroup'])) : '',
            'startDate' => isset($_POST['startDate']) ? sanitize_text_field(wp_unslash($_POST['startDate'])) : '',
            'endDate' => isset($_POST['endDate']) ? sanitize_text_field(wp_unslash($_POST['endDate'])) : '',
        );
        wp_send_json($this->promotions->apply_bulk($codes, $args));
    }

    public function ajax_var_options() {
        $this->ajax_guard();
        $code = isset($_POST['product_code']) ? sanitize_text_field(wp_unslash($_POST['product_code'])) : '';
        if ($code === '') { wp_send_json(array('success' => false, 'message' => 'کد محصول وارد نشده است.')); }
        $var = $this->api->variations($code);
        $gar = $this->api->guarantees($code);
        if (empty($var['success']) && empty($gar['success'])) {
            wp_send_json(array('success' => false, 'message' => $var['message'] ?: $gar['message']));
        }
        $variations = (isset($var['data']['data']) && is_array($var['data']['data'])) ? $var['data']['data'] : array();
        $guarantees = (isset($gar['data']['data']) && is_array($gar['data']['data'])) ? $gar['data']['data'] : array();
        wp_send_json(array('success' => true, 'variations' => $variations, 'guarantees' => $guarantees));
    }

    public function ajax_create_variation() {
        $this->ajax_guard();
        $body = array(
            'productCode' => isset($_POST['product_code']) ? sanitize_text_field(wp_unslash($_POST['product_code'])) : '',
            'variationId' => isset($_POST['variation_id']) ? sanitize_text_field(wp_unslash($_POST['variation_id'])) : '',
            'guaranteeId' => isset($_POST['guarantee_id']) ? sanitize_text_field(wp_unslash($_POST['guarantee_id'])) : '',
        );
        if ($body['productCode'] === '' || $body['variationId'] === '' || $body['guaranteeId'] === '') {
            wp_send_json(array('success' => false, 'message' => 'کد محصول، تنوع و گارانتی هر سه الزامی هستند.'));
        }
        $res = $this->api->create_variation($body);
        wp_send_json(array('success' => !empty($res['success']), 'message' => $res['message'], 'status' => $res['status']));
    }

    public function ajax_catalog_browse() {
        $this->ajax_guard();
        if (!$this->catalog) { wp_send_json(array('success' => false, 'message' => 'سرویس کاتالوگ در دسترس نیست.')); }
        $query = array(
            'page' => isset($_POST['page']) ? absint($_POST['page']) : 1,
            'limit' => 20,
        );
        $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
        if ($search !== '') { $query['search'] = $search; }
        wp_send_json($this->catalog->browse($query));
    }

    public function ajax_catalog_link() {
        $this->ajax_guard();
        if (!$this->catalog) { wp_send_json(array('success' => false, 'message' => 'سرویس کاتالوگ در دسترس نیست.')); }
        $wc_id = isset($_POST['wc_product_id']) ? absint($_POST['wc_product_id']) : 0;
        $code = isset($_POST['product_code']) ? sanitize_text_field(wp_unslash($_POST['product_code'])) : '';
        wp_send_json($this->catalog->link($wc_id, $code));
    }

    public function ajax_price_preview() {
        $this->ajax_guard();
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        if (!$product_id) { wp_send_json(array('success' => false, 'message' => 'شناسه محصول نامعتبر است.')); }
        $remote = $this->sync->get_remote_item_info($product_id);
        wp_send_json($this->pricing->preview($product_id, is_array($remote) ? $remote : array()));
    }

    public function ajax_toggle_hide() {
        $this->ajax_guard();
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $hide = !empty($_POST['hide']);
        wp_send_json($this->sync->toggle_hide($product_id, $hide));
    }

    public function ajax_queue_sync() {
        $this->ajax_guard();
        if (!$this->queue) { wp_send_json(array('success' => false, 'message' => 'صف در دسترس نیست.')); }
        $res = $this->queue->enqueue_full_sync(!empty($_POST['force']));
        $res['success'] = true;
        wp_send_json($res);
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

    private function count_buybox_losers() {
        $q = new WP_Query(array('post_type' => array('product','product_variation'), 'post_status' => 'any', 'posts_per_page' => 1, 'meta_query' => array(array('key' => '_tlscp_buybox_winner', 'value' => 'no'))));
        return (int) $q->found_posts;
    }
}
