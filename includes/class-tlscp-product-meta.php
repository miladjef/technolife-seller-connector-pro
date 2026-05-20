<?php
if (!defined('ABSPATH')) { exit; }

class TLSCP_Product_Meta {
    private static $sync;

    public static function init($sync) {
        self::$sync = $sync;
        add_action('add_meta_boxes', array(__CLASS__, 'add_meta_box'));
        add_action('save_post_product', array(__CLASS__, 'save_product_meta'), 20, 2);
        add_filter('manage_edit-product_columns', array(__CLASS__, 'add_product_columns'));
        add_action('manage_product_posts_custom_column', array(__CLASS__, 'render_product_column'), 10, 2);
    }

    public static function add_meta_box() {
        add_meta_box('tlscp_product_meta', 'اتصال تکنولایف', array(__CLASS__, 'render_meta_box'), 'product', 'side', 'high');
    }

    public static function render_meta_box($post) {
        wp_nonce_field('tlscp_save_product_meta', 'tlscp_product_nonce');
        $fields = array(
            '_tlscp_enabled' => get_post_meta($post->ID, '_tlscp_enabled', true),
            '_tlscp_product_code' => get_post_meta($post->ID, '_tlscp_product_code', true),
            '_tlscp_seller_item_code' => get_post_meta($post->ID, '_tlscp_seller_item_code', true),
            '_tlscp_sales_code' => get_post_meta($post->ID, '_tlscp_sales_code', true),
            '_tlscp_last_price' => get_post_meta($post->ID, '_tlscp_last_price', true),
            '_tlscp_last_stock' => get_post_meta($post->ID, '_tlscp_last_stock', true),
            '_tlscp_last_sync' => get_post_meta($post->ID, '_tlscp_last_sync', true),
            '_tlscp_last_error' => get_post_meta($post->ID, '_tlscp_last_error', true),
        );
        ?>
        <p><label><input type="checkbox" name="tlscp_enabled" value="yes" <?php checked($fields['_tlscp_enabled'], 'yes'); ?>> فعال‌سازی اتصال برای این محصول</label></p>
        <p><label>کد محصول تکنولایف<br><input type="text" class="widefat" name="tlscp_product_code" value="<?php echo esc_attr($fields['_tlscp_product_code']); ?>" placeholder="productCode"></label></p>
        <p><label>کد تنوع فروشنده<br><input type="text" class="widefat" name="tlscp_seller_item_code" value="<?php echo esc_attr($fields['_tlscp_seller_item_code']); ?>" placeholder="sellerItemCode"></label></p>
        <p><label>کد فروشندگی<br><input type="text" class="widefat" name="tlscp_sales_code" value="<?php echo esc_attr($fields['_tlscp_sales_code']); ?>" placeholder="SalesCode"></label></p>
        <hr>
        <p><strong>آخرین قیمت:</strong> <?php echo esc_html($fields['_tlscp_last_price'] ?: '-'); ?></p>
        <p><strong>آخرین موجودی:</strong> <?php echo esc_html($fields['_tlscp_last_stock'] ?: '-'); ?></p>
        <p><strong>آخرین همگام‌سازی:</strong> <?php echo esc_html($fields['_tlscp_last_sync'] ?: '-'); ?></p>
        <?php if ($fields['_tlscp_last_error']) : ?><p class="tlscp-danger"><strong>خطا:</strong> <?php echo esc_html($fields['_tlscp_last_error']); ?></p><?php endif; ?>
        <p>
            <button type="button" class="button button-primary tlscp-sync-product" data-product-id="<?php echo esc_attr($post->ID); ?>">ارسال قیمت و موجودی</button>
        </p>
        <?php
    }

    public static function save_product_meta($post_id, $post) {
        if (!isset($_POST['tlscp_product_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['tlscp_product_nonce'])), 'tlscp_save_product_meta')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) { return; }
        if (!current_user_can('edit_product', $post_id)) { return; }

        update_post_meta($post_id, '_tlscp_enabled', isset($_POST['tlscp_enabled']) ? 'yes' : 'no');
        update_post_meta($post_id, '_tlscp_product_code', isset($_POST['tlscp_product_code']) ? sanitize_text_field(wp_unslash($_POST['tlscp_product_code'])) : '');
        update_post_meta($post_id, '_tlscp_seller_item_code', isset($_POST['tlscp_seller_item_code']) ? sanitize_text_field(wp_unslash($_POST['tlscp_seller_item_code'])) : '');
        update_post_meta($post_id, '_tlscp_sales_code', isset($_POST['tlscp_sales_code']) ? sanitize_text_field(wp_unslash($_POST['tlscp_sales_code'])) : '');

        $opts = wp_parse_args(get_option(TLSCP_OPTION_KEY, array()), TLSCP_Installer::default_options());
        if ($opts['auto_sync_on_save'] === 'yes' && get_post_meta($post_id, '_tlscp_enabled', true) === 'yes' && self::$sync) {
            self::$sync->sync_product($post_id);
        }
    }

    public static function add_product_columns($columns) {
        $columns['tlscp_status'] = 'تکنولایف';
        return $columns;
    }

    public static function render_product_column($column, $post_id) {
        if ($column !== 'tlscp_status') { return; }
        $enabled = get_post_meta($post_id, '_tlscp_enabled', true);
        $seller = get_post_meta($post_id, '_tlscp_seller_item_code', true);
        if ($enabled === 'yes' && $seller) {
            echo '<span class="tlscp-badge tlscp-ok">متصل</span><br><small>' . esc_html($seller) . '</small>';
        } else {
            echo '<span class="tlscp-badge">متصل نیست</span>';
        }
    }
}
