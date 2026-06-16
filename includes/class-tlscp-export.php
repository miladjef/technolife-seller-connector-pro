<?php
if (!defined('ABSPATH')) { exit; }

class TLSCP_Export {
    public static function download_csv($filename, $headers, $rows) {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('دسترسی غیرمجاز');
        }
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        fputcsv($out, $headers);
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }

    public static function export_products() {
        $q = new WP_Query(array('post_type' => array('product', 'product_variation'), 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids'));
        $rows = array();
        foreach ($q->posts as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product) { continue; }
            $rows[] = array(
                $product_id,
                $product->get_name(),
                $product->get_sku(),
                get_post_meta($product_id, '_tlscp_product_code', true),
                get_post_meta($product_id, '_tlscp_seller_item_code', true),
                get_post_meta($product_id, '_tlscp_sales_code', true),
                $product->get_price(),
                $product->get_stock_quantity(),
                get_post_meta($product_id, '_tlscp_last_price', true),
                get_post_meta($product_id, '_tlscp_last_stock', true),
                get_post_meta($product_id, '_tlscp_last_error', true),
            );
        }
        self::download_csv('technolife-products-' . gmdate('Y-m-d') . '.csv', array('ID','Name','SKU','ProductCode','SellerItemCode','SalesCode','WooPrice','WooStock','LastTLPrice','LastTLStock','LastError'), $rows);
    }

    public static function export_logs($logger) {
        $logs = $logger->recent(500);
        $rows = array();
        foreach ($logs as $log) {
            $rows[] = array($log['id'], $log['created_at'], $log['action'], $log['method'], $log['endpoint'], $log['object_type'], $log['object_id'], $log['http_status'], $log['success'], $log['message']);
        }
        self::download_csv('technolife-logs-' . gmdate('Y-m-d') . '.csv', array('ID','CreatedAt','Action','Method','Endpoint','ObjectType','ObjectID','HTTP','Success','Message'), $rows);
    }

    public static function export_orders($orders) {
        $items = $orders->recent_orders(500);
        $rows = array();
        foreach ($items as $o) {
            $rows[] = array($o['order_code'], $o['wc_order_id'], $o['trace_number'], $o['status'], $o['total_price'], $o['order_date'], $o['updated_at']);
        }
        self::download_csv('technolife-orders-' . gmdate('Y-m-d') . '.csv', array('OrderCode','WCOrderID','TraceNumber','Status','TotalPrice','OrderDate','UpdatedAt'), $rows);
    }
}
