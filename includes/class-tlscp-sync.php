<?php
if (!defined('ABSPATH')) { exit; }

class TLSCP_Sync {
    private $api;
    private $pricing;
    private $logger;

    public function __construct($api, $pricing, $logger) {
        $this->api = $api;
        $this->pricing = $pricing;
        $this->logger = $logger;
    }

    public function sync_product($product_id) {
        $product_id = absint($product_id);
        if (!$product_id || !wc_get_product($product_id)) {
            return array('success' => false, 'message' => 'محصول ووکامرس پیدا نشد.');
        }
        $price = $this->sync_price($product_id);
        $stock = $this->sync_inventory($product_id);
        return array('price' => $price, 'inventory' => $stock, 'success' => (!empty($price['success']) && !empty($stock['success'])));
    }

    public function sync_price($product_id) {
        $seller_item_code = $this->get_tlscp_meta($product_id, '_tlscp_seller_item_code', false);
        if (!$seller_item_code) {
            return $this->product_error($product_id, 'کد تنوع فروشنده برای محصول/تنوع تنظیم نشده است.');
        }

        $remote_info = $this->get_remote_item_info($product_id);
        $payload_result = $this->pricing->price_payload($product_id, $remote_info);
        if (!$payload_result['success']) {
            return $this->product_error($product_id, $payload_result['message']);
        }

        $retry = array('type' => 'sync_price', 'product_id' => $product_id);
        $result = $this->api->update_price($seller_item_code, $payload_result['payload'], $retry);
        if ($result['success']) {
            update_post_meta($product_id, '_tlscp_last_price', $payload_result['calc']['final_price']);
            update_post_meta($product_id, '_tlscp_last_sync', current_time('mysql'));
            delete_post_meta($product_id, '_tlscp_last_error');
        } else {
            update_post_meta($product_id, '_tlscp_last_error', $result['message']);
        }
        $result['calc'] = $payload_result['calc'];
        return $result;
    }

    public function sync_inventory($product_id) {
        $seller_item_code = $this->get_tlscp_meta($product_id, '_tlscp_seller_item_code', false);
        if (!$seller_item_code) {
            return $this->product_error($product_id, 'کد تنوع فروشنده برای محصول/تنوع تنظیم نشده است.');
        }
        $product = wc_get_product($product_id);
        if (!$product) {
            return $this->product_error($product_id, 'محصول ووکامرس پیدا نشد.');
        }
        $opts = wp_parse_args(get_option(TLSCP_OPTION_KEY, array()), TLSCP_Installer::default_options());
        $stock_quantity = $this->get_wc_stock_quantity($product);
        $available = max(0, $stock_quantity - absint($opts['inventory_reserve']));
        if ($opts['inventory_max_send'] !== '') {
            $available = min($available, absint($opts['inventory_max_send']));
        }

        $sales_code = $this->get_tlscp_meta($product_id, '_tlscp_sales_code', true);
        $body = array(
            'available' => (int) $available,
            'leaveTime' => absint($opts['leave_time']),
            'maxBuyPerOrder' => max(1, absint($opts['max_buy_per_order'])),
        );
        if ($sales_code !== '') {
            $body['SalesCode'] = $sales_code;
        }

        $retry = array('type' => 'sync_inventory', 'product_id' => $product_id);
        $result = $this->api->update_inventory($seller_item_code, $body, $retry);
        if ($result['success']) {
            update_post_meta($product_id, '_tlscp_last_stock', $available);
            update_post_meta($product_id, '_tlscp_last_sync', current_time('mysql'));
            delete_post_meta($product_id, '_tlscp_last_error');
            if ($opts['auto_hide_zero_stock'] === 'yes') {
                if ($available <= 0) {
                    $hide = $this->api->hide_item($seller_item_code);
                    update_post_meta($product_id, '_tlscp_hidden', !empty($hide['success']) ? 'yes' : 'hide_failed');
                } else {
                    $show = $this->api->show_item($seller_item_code);
                    update_post_meta($product_id, '_tlscp_hidden', !empty($show['success']) ? 'no' : 'show_failed');
                }
            }
        } else {
            update_post_meta($product_id, '_tlscp_last_error', $result['message']);
        }
        return $result;
    }

    public function sync_all_connected($price = true, $inventory = true, $limit = 50) {
        $query = new WP_Query(array(
            'post_type' => array('product', 'product_variation'),
            'post_status' => array('publish', 'draft', 'private'),
            'posts_per_page' => max(1, min(200, absint($limit))),
            'meta_query' => array(
                array('key' => '_tlscp_enabled', 'value' => 'yes'),
                array('key' => '_tlscp_seller_item_code', 'value' => '', 'compare' => '!='),
            ),
            'fields' => 'ids',
            'no_found_rows' => true,
        ));
        $done = array('success' => 0, 'failed' => 0, 'items' => array());
        foreach ($query->posts as $product_id) {
            $result = array('success' => true);
            if ($price) { $result = $this->sync_price($product_id); }
            if ($inventory) { $result2 = $this->sync_inventory($product_id); $result['success'] = !empty($result['success']) && !empty($result2['success']); $result['inventory'] = $result2; }
            if (!empty($result['success'])) { $done['success']++; } else { $done['failed']++; }
            $done['items'][] = array('product_id' => $product_id, 'result' => $result);
        }
        return $done;
    }

    public function get_remote_item_info($product_id) {
        $product_code = $this->get_tlscp_meta($product_id, '_tlscp_product_code', true);
        $seller_item_code = $this->get_tlscp_meta($product_id, '_tlscp_seller_item_code', false);
        if (!$product_code || !$seller_item_code) {
            return array();
        }
        $result = $this->api->product_items($product_code);
        if (!$result['success'] || empty($result['data']['data']) || !is_array($result['data']['data'])) {
            return array();
        }
        foreach ($result['data']['data'] as $item) {
            if (isset($item['code']) && (string) $item['code'] === (string) $seller_item_code) {
                update_post_meta($product_id, '_tlscp_remote_snapshot', wp_json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return $item;
            }
        }
        return array();
    }

    public function retry_log($log_id) {
        $log = $this->logger->get($log_id);
        if (!$log || empty($log['retry_payload'])) {
            return array('success' => false, 'message' => 'اطلاعات اجرای مجدد برای این لاگ وجود ندارد.');
        }
        $payload = json_decode($log['retry_payload'], true);
        if (!is_array($payload) || empty($payload['type'])) {
            return array('success' => false, 'message' => 'ساختار اجرای مجدد نامعتبر است.');
        }
        if ($payload['type'] === 'sync_price' && !empty($payload['product_id'])) {
            return $this->sync_price(absint($payload['product_id']));
        }
        if ($payload['type'] === 'sync_inventory' && !empty($payload['product_id'])) {
            return $this->sync_inventory(absint($payload['product_id']));
        }
        return array('success' => false, 'message' => 'نوع عملیات برای اجرای مجدد پشتیبانی نمی‌شود.');
    }

    private function get_tlscp_meta($product_id, $key, $fallback_to_parent = true) {
        $value = get_post_meta($product_id, $key, true);
        if ($value !== '' || !$fallback_to_parent) {
            return $value;
        }
        $product = wc_get_product($product_id);
        if ($product && $product->is_type('variation') && $product->get_parent_id()) {
            return get_post_meta($product->get_parent_id(), $key, true);
        }
        return $value;
    }

    private function get_wc_stock_quantity($product) {
        $qty = $product->get_stock_quantity();
        if ($qty !== null && $qty !== '') {
            return max(0, (int) $qty);
        }
        if ($product->is_type('variation') && $product->get_parent_id()) {
            $parent = wc_get_product($product->get_parent_id());
            if ($parent) {
                $parent_qty = $parent->get_stock_quantity();
                if ($parent_qty !== null && $parent_qty !== '') {
                    return max(0, (int) $parent_qty);
                }
            }
        }
        return $product->is_in_stock() ? 999 : 0;
    }

    private function product_error($product_id, $message) {
        update_post_meta($product_id, '_tlscp_last_error', $message);
        $this->logger->add(array('action' => 'product_sync_error', 'object_type' => 'product', 'object_id' => $product_id, 'success' => 0, 'message' => $message));
        return array('success' => false, 'message' => $message);
    }
}
