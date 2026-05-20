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

        add_action('woocommerce_product_after_variable_attributes', array(__CLASS__, 'variation_fields'), 10, 3);
        add_action('woocommerce_save_product_variation', array(__CLASS__, 'save_variation_fields'), 10, 2);
    }

    public static function add_meta_box() {
        add_meta_box('tlscp_product_meta', 'اتصال تکنولایف', array(__CLASS__, 'render_meta_box'), 'product', 'side', 'high');
    }

    public static function render_meta_box($post) {
        wp_nonce_field('tlscp_save_product_meta', 'tlscp_product_nonce');
        $fields = self::get_fields($post->ID);
        ?>
        <p><label><input type="checkbox" name="tlscp_enabled" value="yes" <?php checked($fields['_tlscp_enabled'], 'yes'); ?>> فعال‌سازی اتصال برای این محصول</label></p>
        <p><label>کد محصول تکنولایف<br><input type="text" class="widefat" name="tlscp_product_code" value="<?php echo esc_attr($fields['_tlscp_product_code']); ?>" placeholder="productCode"></label></p>
        <p><label>کد تنوع فروشنده<br><input type="text" class="widefat" name="tlscp_seller_item_code" value="<?php echo esc_attr($fields['_tlscp_seller_item_code']); ?>" placeholder="sellerItemCode"></label></p>
        <p><label>کد فروشندگی<br><input type="text" class="widefat" name="tlscp_sales_code" value="<?php echo esc_attr($fields['_tlscp_sales_code']); ?>" placeholder="SalesCode"></label></p>
        <p class="description">برای محصولات متغیر، بهتر است کد تنوع فروشنده را روی خود variation وارد کنید. ProductCode می‌تواند روی محصول مادر باشد.</p>
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

    private static function get_fields($post_id) {
        $keys = array('_tlscp_enabled','_tlscp_product_code','_tlscp_seller_item_code','_tlscp_sales_code','_tlscp_last_price','_tlscp_last_stock','_tlscp_last_sync','_tlscp_last_error');
        $fields = array();
        foreach ($keys as $key) {
            $fields[$key] = get_post_meta($post_id, $key, true);
        }
        return $fields;
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

    public static function variation_fields($loop, $variation_data, $variation) {
        $variation_id = 0;
        if (is_object($variation) && isset($variation->ID)) {
            $variation_id = absint($variation->ID);
        } elseif (is_object($variation) && method_exists($variation, 'get_id')) {
            $variation_id = absint($variation->get_id());
        }
        if (!$variation_id) { return; }
        $fields = self::get_fields($variation_id);
        ?>
        <div class="form-row form-row-full tlscp-variation-box" style="border:1px solid #dcdcde;background:#fff;padding:10px;margin:10px 0;border-radius:8px;">
            <strong>اتصال تکنولایف برای این تنوع</strong>
            <p><label><input type="checkbox" name="tlscp_variation_enabled[<?php echo esc_attr($variation_id); ?>]" value="yes" <?php checked($fields['_tlscp_enabled'], 'yes'); ?>> فعال</label></p>
            <p class="form-row form-row-first"><label>ProductCode</label><input type="text" class="short" name="tlscp_variation_product_code[<?php echo esc_attr($variation_id); ?>]" value="<?php echo esc_attr($fields['_tlscp_product_code']); ?>" placeholder="اگر خالی باشد از محصول مادر خوانده می‌شود"></p>
            <p class="form-row form-row-last"><label>SellerItemCode</label><input type="text" class="short" name="tlscp_variation_seller_item_code[<?php echo esc_attr($variation_id); ?>]" value="<?php echo esc_attr($fields['_tlscp_seller_item_code']); ?>"></p>
            <p class="form-row form-row-first"><label>SalesCode</label><input type="text" class="short" name="tlscp_variation_sales_code[<?php echo esc_attr($variation_id); ?>]" value="<?php echo esc_attr($fields['_tlscp_sales_code']); ?>"></p>
            <p class="form-row form-row-last"><button type="button" class="button tlscp-sync-product" data-product-id="<?php echo esc_attr($variation_id); ?>">ارسال این تنوع</button></p>
            <div style="clear:both"></div>
            <?php if ($fields['_tlscp_last_error']) : ?><p class="tlscp-danger"><strong>خطا:</strong> <?php echo esc_html($fields['_tlscp_last_error']); ?></p><?php endif; ?>
        </div>
        <?php
    }

    public static function save_variation_fields($variation_id, $i) {
        if (!current_user_can('edit_product', wp_get_post_parent_id($variation_id))) { return; }

        update_post_meta($variation_id, '_tlscp_enabled', isset($_POST['tlscp_variation_enabled'][$variation_id]) ? 'yes' : 'no');

        $fields = array(
            '_tlscp_product_code' => 'tlscp_variation_product_code',
            '_tlscp_seller_item_code' => 'tlscp_variation_seller_item_code',
            '_tlscp_sales_code' => 'tlscp_variation_sales_code',
        );
        foreach ($fields as $meta_key => $post_key) {
            $value = '';
            if (isset($_POST[$post_key][$variation_id])) {
                $value = sanitize_text_field(wp_unslash($_POST[$post_key][$variation_id]));
            }
            update_post_meta($variation_id, $meta_key, $value);
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
