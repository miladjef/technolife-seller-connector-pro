<?php
if (!defined('ABSPATH')) { exit; }

class TLSCP_Orders {
    private $api;
    private $logger;

    public function __construct($api, $logger) {
        $this->api = $api;
        $this->logger = $logger;
    }

    public function table() {
        global $wpdb;
        return $wpdb->prefix . 'tlscp_orders';
    }

    public function import_recent($days = null, $limit = 100) {
        $opts = wp_parse_args(get_option(TLSCP_OPTION_KEY, array()), TLSCP_Installer::default_options());
        $days = $days === null ? absint($opts['orders_from_days']) : absint($days);
        $start = gmdate('Y-m-d\TH:i:s\Z', time() - ($days * DAY_IN_SECONDS));
        $query = array('page' => 1, 'limit' => min(100, absint($limit)), 'orderStartDate' => $start);
        $list = $this->api->orders($query);
        if (!$list['success']) {
            return $list;
        }
        $items = isset($list['data']['data']) && is_array($list['data']['data']) ? $list['data']['data'] : array();
        $stats = array('success' => true, 'imported' => 0, 'created' => 0, 'updated' => 0, 'failed' => 0, 'items' => array());
        foreach ($items as $item) {
            if (empty($item['code'])) { continue; }
            $detail = $this->api->order_details($item['code']);
            if (!$detail['success']) { $stats['failed']++; continue; }
            $saved = $this->save_remote_order($detail['data']);
            if ($saved['success']) {
                $stats['imported']++;
                if (!empty($saved['created'])) { $stats['created']++; } else { $stats['updated']++; }
            } else {
                $stats['failed']++;
            }
            $stats['items'][] = $saved;
        }
        return $stats;
    }

    public function save_remote_order($data) {
        global $wpdb;
        if (empty($data['orderCode'])) {
            return array('success' => false, 'message' => 'کد سفارش در پاسخ API وجود ندارد.');
        }
        $opts = wp_parse_args(get_option(TLSCP_OPTION_KEY, array()), TLSCP_Installer::default_options());
        $order_code = sanitize_text_field($data['orderCode']);
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table()} WHERE order_code = %s", $order_code), ARRAY_A);
        $wc_order_id = $existing && !empty($existing['wc_order_id']) ? absint($existing['wc_order_id']) : 0;
        $created = false;

        if ($opts['auto_create_orders'] === 'yes' && function_exists('wc_create_order')) {
            $wc_order_id = $this->create_or_update_wc_order($data, $wc_order_id);
            $created = !$existing;
        }

        $row = array(
            'order_code' => $order_code,
            'wc_order_id' => $wc_order_id,
            'trace_number' => isset($data['traceNumber']) ? sanitize_text_field($data['traceNumber']) : '',
            'status' => isset($data['status']) ? sanitize_text_field($data['status']) : '',
            'total_price' => isset($data['totalPrice']) ? (float) $data['totalPrice'] : 0,
            'order_date' => !empty($data['orderDate']) ? get_date_from_gmt(gmdate('Y-m-d H:i:s', strtotime($data['orderDate']))) : null,
            'raw_data' => wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => current_time('mysql'),
        );
        if ($existing) {
            $wpdb->update($this->table(), $row, array('order_code' => $order_code));
        } else {
            $row['created_at'] = current_time('mysql');
            $wpdb->insert($this->table(), $row);
        }
        return array('success' => true, 'order_code' => $order_code, 'wc_order_id' => $wc_order_id, 'created' => $created);
    }

    private function create_or_update_wc_order($data, $wc_order_id = 0) {
        $opts = wp_parse_args(get_option(TLSCP_OPTION_KEY, array()), TLSCP_Installer::default_options());
        $order = $wc_order_id ? wc_get_order($wc_order_id) : false;
        if (!$order) {
            $order = wc_create_order(array('status' => str_replace('wc-', '', $opts['order_status'])));
        }
        if (!$order) { return 0; }

        $order->update_meta_data('_tlscp_order_code', sanitize_text_field($data['orderCode']));
        $order->update_meta_data('_tlscp_trace_number', isset($data['traceNumber']) ? sanitize_text_field($data['traceNumber']) : '');
        $order->update_meta_data('_tlscp_status', isset($data['status']) ? sanitize_text_field($data['status']) : '');
        $order->update_meta_data('_tlscp_raw', wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $address = isset($data['address']) && is_array($data['address']) ? $data['address'] : array();
        $receiver = isset($data['receiver']) ? sanitize_text_field($data['receiver']) : 'Technolife Customer';
        $order->set_billing_first_name($receiver);
        $order->set_billing_city(isset($address['city']) ? sanitize_text_field($address['city']) : '');
        $order->set_billing_state(isset($address['province']) ? sanitize_text_field($address['province']) : '');
        $order->set_billing_postcode(isset($address['postalCode']) ? sanitize_text_field($address['postalCode']) : '');
        $order->set_billing_address_1(isset($address['postalAddress']) ? sanitize_text_field($address['postalAddress']) : '');
        $order->set_shipping_first_name($receiver);
        $order->set_shipping_city($order->get_billing_city());
        $order->set_shipping_state($order->get_billing_state());
        $order->set_shipping_postcode($order->get_billing_postcode());
        $order->set_shipping_address_1($order->get_billing_address_1());

        if (!$wc_order_id && !empty($data['products']) && is_array($data['products'])) {
            foreach ($data['products'] as $p) {
                $product_id = $this->find_product_by_seller_item_code(isset($p['sellerItemCode']) ? $p['sellerItemCode'] : '');
                $qty = isset($p['count']) ? max(1, absint($p['count'])) : 1;
                $final_price = isset($p['finalPrice']) ? (float) $p['finalPrice'] : (isset($p['price']) ? (float) $p['price'] : 0);
                if ($product_id) {
                    $wc_product = wc_get_product($product_id);
                    if ($wc_product) {
                        $item_id = $order->add_product($wc_product, $qty, array('subtotal' => $final_price * $qty, 'total' => $final_price * $qty));
                        if ($item_id) {
                            wc_add_order_item_meta($item_id, '_tlscp_seller_item_code', sanitize_text_field($p['sellerItemCode']));
                            wc_add_order_item_meta($item_id, '_tlscp_product_code', sanitize_text_field($p['productCode']));
                        }
                    }
                } else {
                    $item = new WC_Order_Item_Product();
                    $item->set_name(isset($p['title']) ? sanitize_text_field($p['title']) : 'Technolife Product');
                    $item->set_quantity($qty);
                    $item->set_subtotal($final_price * $qty);
                    $item->set_total($final_price * $qty);
                    $item->add_meta_data('_tlscp_seller_item_code', isset($p['sellerItemCode']) ? sanitize_text_field($p['sellerItemCode']) : '');
                    $item->add_meta_data('_tlscp_product_code', isset($p['productCode']) ? sanitize_text_field($p['productCode']) : '');
                    $order->add_item($item);
                }
            }
        }

        $order->calculate_totals();
        $order->save();
        return $order->get_id();
    }

    private function find_product_by_seller_item_code($seller_item_code) {
        $seller_item_code = sanitize_text_field($seller_item_code);
        if ($seller_item_code === '') { return 0; }
        $q = new WP_Query(array(
            'post_type' => 'product',
            'post_status' => 'any',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_key' => '_tlscp_seller_item_code',
            'meta_value' => $seller_item_code,
            'no_found_rows' => true,
        ));
        return !empty($q->posts[0]) ? absint($q->posts[0]) : 0;
    }

    public function recent_orders($limit = 50) {
        global $wpdb;
        $limit = max(1, min(500, absint($limit)));
        return $wpdb->get_results("SELECT * FROM {$this->table()} ORDER BY id DESC LIMIT {$limit}", ARRAY_A);
    }
}
