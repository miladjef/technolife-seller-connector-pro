<?php
if (!defined('ABSPATH')) { exit; }

class TLSCP_API_Client {
    private $logger;

    public function __construct($logger) {
        $this->logger = $logger;
    }

    public function options() {
        $defaults = TLSCP_Installer::default_options();
        $opts = get_option(TLSCP_OPTION_KEY, array());
        return wp_parse_args(is_array($opts) ? $opts : array(), $defaults);
    }

    public function request($method, $path, $args = array(), $log_action = 'api_request', $retry_payload = null) {
        $opts = $this->options();
        $base = untrailingslashit((string) $opts['api_base_url']);
        $path = '/' . ltrim((string) $path, '/');
        $method = strtoupper((string) $method);

        if (empty($opts['api_key']) || empty($opts['secret_key'])) {
            $message = 'API Key یا Secret Key تنظیم نشده است.';
            $log_id = $this->logger->add(array(
                'action' => $log_action,
                'method' => $method,
                'endpoint' => $path,
                'object_type' => isset($args['object_type']) ? $args['object_type'] : '',
                'object_id' => isset($args['object_id']) ? $args['object_id'] : '',
                'http_status' => 0,
                'success' => 0,
                'message' => $message,
                'request_body' => null,
                'response_body' => null,
                'retry_payload' => $retry_payload,
            ));
            return array('success' => false, 'status' => 0, 'data' => null, 'raw' => '', 'message' => $message, 'log_id' => $log_id);
        }

        $query = isset($args['query']) && is_array($args['query']) ? $args['query'] : array();
        $filtered_query = array_filter($query, function($v) { return $v !== '' && $v !== null; });
        $url = $base . $path;
        if (!empty($filtered_query)) {
            $url = add_query_arg($filtered_query, $url);
        }

        $body = array_key_exists('body', $args) ? $args['body'] : null;
        $payload_for_secret = $this->secret_payload($opts, $base, $path, $url, $body, $filtered_query);
        $encrypted_secret = TLSCP_Crypto::encrypted_secret($opts['secret_key'], $payload_for_secret, $opts['secret_is_base64'] === 'yes');

        if ($encrypted_secret === '') {
            $message = 'ساخت encrypted-secret ناموفق بود. Secret Key یا OpenSSL را بررسی کنید.';
            $log_id = $this->logger->add(array(
                'action' => $log_action,
                'method' => $method,
                'endpoint' => $path,
                'object_type' => isset($args['object_type']) ? $args['object_type'] : '',
                'object_id' => isset($args['object_id']) ? $args['object_id'] : '',
                'http_status' => 0,
                'success' => 0,
                'message' => $message,
                'request_body' => array('query' => $filtered_query, 'body' => $body),
                'response_body' => null,
                'retry_payload' => $retry_payload,
            ));
            return array('success' => false, 'status' => 0, 'data' => null, 'raw' => '', 'message' => $message, 'log_id' => $log_id);
        }

        $headers = array(
            'Authorization' => 'Bearer ' . trim((string) $opts['api_key']),
            'encrypted-secret' => $encrypted_secret,
            'Accept' => 'application/json',
        );

        $request_args = array(
            'method' => $method,
            'timeout' => 45,
            'redirection' => 3,
            'headers' => $headers,
        );

        if ($body !== null) {
            $headers['Content-Type'] = 'application/json; charset=utf-8';
            $request_args['headers'] = $headers;
            $request_args['body'] = wp_json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $response = wp_remote_request($url, $request_args);
        $status = 0;
        $decoded = null;
        $raw = '';
        $success = false;
        $message = '';

        if (is_wp_error($response)) {
            $message = $response->get_error_message();
        } else {
            $status = (int) wp_remote_retrieve_response_code($response);
            $raw = (string) wp_remote_retrieve_body($response);
            $decoded = json_decode($raw, true);
            if ($decoded === null && $raw !== '') {
                $decoded = array('raw' => $raw);
            }
            $success = $status >= 200 && $status < 300;
            if (is_array($decoded) && isset($decoded['message'])) {
                $message = is_array($decoded['message']) ? implode(' | ', $decoded['message']) : (string) $decoded['message'];
            } else {
                $message = $success ? 'OK' : 'HTTP ' . $status;
            }
        }

        $log_id = $this->logger->add(array(
            'action' => $log_action,
            'method' => $method,
            'endpoint' => $path,
            'object_type' => isset($args['object_type']) ? $args['object_type'] : '',
            'object_id' => isset($args['object_id']) ? $args['object_id'] : '',
            'http_status' => $status,
            'success' => $success ? 1 : 0,
            'message' => $message,
            'request_body' => array('query' => $filtered_query, 'body' => $body, 'secret_payload_mode' => isset($opts['encryption_payload_mode']) ? $opts['encryption_payload_mode'] : 'path'),
            'response_body' => $decoded !== null ? $decoded : $raw,
            'retry_payload' => $retry_payload,
        ));

        return array(
            'success' => $success,
            'status' => $status,
            'data' => $decoded,
            'raw' => $raw,
            'message' => $message,
            'log_id' => $log_id,
        );
    }

    private function secret_payload($opts, $base, $path, $url, $body, $query) {
        $mode = isset($opts['encryption_payload_mode']) ? $opts['encryption_payload_mode'] : 'path';
        $body_json = $body === null ? '' : wp_json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $query_string = !empty($query) ? ('?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986)) : '';

        if ($mode === 'path_query') {
            return $path . $query_string;
        }
        if ($mode === 'path_body') {
            return $path . '|' . wp_json_encode(array('query' => $query, 'body' => $body), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if ($mode === 'body') {
            return $body_json;
        }
        if ($mode === 'full_url') {
            return untrailingslashit($base) . $path;
        }
        if ($mode === 'full_url_query') {
            return $url;
        }
        if ($mode === 'empty') {
            return '';
        }
        return $path;
    }

    public function test_connection() {
        return $this->request('GET', '/v1/products', array('query' => array('page' => 1, 'limit' => 1)), 'test_connection');
    }

    public function products($query = array()) {
        return $this->request('GET', '/v1/products', array('query' => $query), 'get_products');
    }

    public function product_items($product_code) {
        return $this->request('GET', '/v1/products/' . rawurlencode($product_code) . '/items', array('object_type' => 'product', 'object_id' => $product_code), 'get_product_items');
    }

    public function variations($product_code) {
        return $this->request('GET', '/v1/products/' . rawurlencode($product_code) . '/variation', array('object_type' => 'product', 'object_id' => $product_code), 'get_variations');
    }

    public function guarantees($product_code) {
        return $this->request('GET', '/v1/products/' . rawurlencode($product_code) . '/guarantees', array('object_type' => 'product', 'object_id' => $product_code), 'get_guarantees');
    }

    public function update_inventory($seller_item_code, $body, $retry_payload = null) {
        return $this->request('PATCH', '/v1/products/' . rawurlencode($seller_item_code) . '/info', array('body' => $body, 'object_type' => 'seller_item', 'object_id' => $seller_item_code), 'update_inventory', $retry_payload);
    }

    public function update_price($seller_item_code, $body, $retry_payload = null) {
        return $this->request('PATCH', '/v1/pricing/' . rawurlencode($seller_item_code) . '/info', array('body' => $body, 'object_type' => 'seller_item', 'object_id' => $seller_item_code), 'update_price', $retry_payload);
    }

    public function hide_item($seller_item_code) {
        return $this->request('PATCH', '/v1/products/' . rawurlencode($seller_item_code) . '/hide', array('object_type' => 'seller_item', 'object_id' => $seller_item_code), 'hide_item');
    }

    public function show_item($seller_item_code) {
        return $this->request('PATCH', '/v1/products/' . rawurlencode($seller_item_code) . '/show', array('object_type' => 'seller_item', 'object_id' => $seller_item_code), 'show_item');
    }

    public function promotion_list($product_code) {
        return $this->request('GET', '/v1/promotion/' . rawurlencode($product_code) . '/list', array('object_type' => 'product', 'object_id' => $product_code), 'promotion_list');
    }

    public function update_promotion($seller_item_code, $body) {
        return $this->request('PUT', '/v1/promotion/' . rawurlencode($seller_item_code) . '/info', array('body' => $body, 'object_type' => 'seller_item', 'object_id' => $seller_item_code), 'update_promotion');
    }

    public function orders($query = array()) {
        return $this->request('GET', '/v1/orders/sbs', array('query' => $query), 'get_orders');
    }

    public function order_details($order_code) {
        return $this->request('GET', '/v1/orders/sbs/' . rawurlencode($order_code), array('object_type' => 'order', 'object_id' => $order_code), 'get_order_details');
    }
}
