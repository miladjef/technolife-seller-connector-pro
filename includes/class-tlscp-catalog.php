<?php
if (!defined('ABSPATH')) { exit; }

/**
 * مرورگر کاتالوگ تکنولایف و نگاشت نیمه‌خودکار به محصولات ووکامرس.
 *
 * منبع: GET /v1/products → ProductData { count, products: [ProductInfo] }
 */
class TLSCP_Catalog {
    private $api;

    public function __construct($api) {
        $this->api = $api;
    }

    /**
     * دریافت یک صفحه از کاتالوگ تکنولایف به همراه پیشنهاد محصول ووکامرس متناظر.
     */
    public function browse($query = array()) {
        $defaults = array('page' => 1, 'limit' => 20);
        $query = wp_parse_args($query, $defaults);
        $query['limit'] = max(1, min(100, absint($query['limit'])));
        $query['page'] = max(1, absint($query['page']));

        $res = $this->api->products($query);
        if (empty($res['success'])) {
            return array('success' => false, 'message' => $res['message'], 'items' => array(), 'count' => 0);
        }
        $data = is_array($res['data']) ? $res['data'] : array();
        $products = (isset($data['products']) && is_array($data['products'])) ? $data['products'] : array();
        $count = isset($data['count']) ? absint($data['count']) : count($products);

        $items = array();
        foreach ($products as $p) {
            $code = isset($p['code']) ? (string) $p['code'] : '';
            $match = $this->suggest_match($p);
            $items[] = array(
                'code' => $code,
                'title' => isset($p['title']) ? $p['title'] : '',
                'brand' => isset($p['brand']) ? $p['brand'] : '',
                'category' => isset($p['category']) ? $p['category'] : '',
                'totalStock' => isset($p['totalStock']) ? $p['totalStock'] : null,
                'totalAvailable' => isset($p['totalAvailable']) ? $p['totalAvailable'] : null,
                'referencePrice' => isset($p['referencePrice']) ? $p['referencePrice'] : null,
                'match_id' => $match ? $match['id'] : 0,
                'match_title' => $match ? $match['title'] : '',
                'match_reason' => $match ? $match['reason'] : '',
                'linked' => $code !== '' ? $this->already_linked($code) : 0,
            );
        }
        return array('success' => true, 'items' => $items, 'count' => $count, 'page' => $query['page'], 'limit' => $query['limit']);
    }

    /**
     * پیشنهاد محصول ووکامرس متناظر بر اساس SKU سپس عنوان.
     */
    private function suggest_match($p) {
        $code = isset($p['code']) ? (string) $p['code'] : '';
        $title = isset($p['title']) ? (string) $p['title'] : '';

        if ($code !== '' && function_exists('wc_get_product_id_by_sku')) {
            $id = wc_get_product_id_by_sku($code);
            if ($id) {
                return array('id' => $id, 'title' => get_the_title($id), 'reason' => 'SKU = ProductCode');
            }
        }
        if ($title !== '') {
            $q = new WP_Query(array(
                'post_type' => 'product',
                'post_status' => 'publish',
                'posts_per_page' => 1,
                's' => $title,
                'fields' => 'ids',
                'no_found_rows' => true,
            ));
            if (!empty($q->posts[0])) {
                return array('id' => (int) $q->posts[0], 'title' => get_the_title($q->posts[0]), 'reason' => 'تطبیق نام');
            }
        }
        return null;
    }

    private function already_linked($code) {
        $q = new WP_Query(array(
            'post_type' => array('product', 'product_variation'),
            'post_status' => 'any',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_key' => '_tlscp_product_code',
            'meta_value' => $code,
            'no_found_rows' => true,
        ));
        return !empty($q->posts[0]) ? (int) $q->posts[0] : 0;
    }

    /**
     * اتصال یک محصول ووکامرس به کد محصول تکنولایف.
     * اگر فقط یک تنوع فروشنده وجود داشته باشد، کد آن نیز خودکار تنظیم می‌شود.
     */
    public function link($wc_product_id, $product_code) {
        $wc_product_id = absint($wc_product_id);
        $product_code = sanitize_text_field($product_code);
        if (!$wc_product_id || $product_code === '' || !wc_get_product($wc_product_id)) {
            return array('success' => false, 'message' => 'محصول یا کد نامعتبر است.');
        }
        update_post_meta($wc_product_id, '_tlscp_product_code', $product_code);
        update_post_meta($wc_product_id, '_tlscp_enabled', 'yes');

        $seller_code = '';
        $items = $this->api->product_items($product_code);
        if (!empty($items['success']) && isset($items['data']['data']) && is_array($items['data']['data'])) {
            $list = $items['data']['data'];
            if (count($list) === 1 && isset($list[0]['code'])) {
                $seller_code = (string) $list[0]['code'];
                update_post_meta($wc_product_id, '_tlscp_seller_item_code', $seller_code);
            }
        }
        return array(
            'success' => true,
            'message' => 'اتصال انجام شد.' . ($seller_code ? ' SellerItemCode خودکار تنظیم شد: ' . $seller_code : ' (چند تنوع دارد؛ SellerItemCode را دستی انتخاب کنید.)'),
            'wc_product_id' => $wc_product_id,
            'seller_item_code' => $seller_code,
        );
    }
}
