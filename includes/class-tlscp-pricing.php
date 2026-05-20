<?php
if (!defined('ABSPATH')) { exit; }

class TLSCP_Pricing {
    public function options() {
        return wp_parse_args(get_option(TLSCP_OPTION_KEY, array()), TLSCP_Installer::default_options());
    }

    public function get_base_price($product_id) {
        $product = wc_get_product($product_id);
        if (!$product) {
            return 0;
        }

        $opts = $this->options();
        $price = 0;
        if ($opts['price_source'] === 'regular') {
            $price = $product->get_regular_price();
        } elseif ($opts['price_source'] === 'current') {
            $price = $product->get_price();
        } else {
            $sale = $product->get_sale_price();
            $price = ($sale !== '' && $sale !== null) ? $sale : $product->get_regular_price();
            if ($price === '' || $price === null) {
                $price = $product->get_price();
            }
        }

        if (($price === '' || $price === null) && $product->is_type('variation')) {
            $parent = wc_get_product($product->get_parent_id());
            if ($parent) {
                $price = $parent->get_price();
            }
        }

        return (float) wc_format_decimal($price, wc_get_price_decimals());
    }

    public function calculate_markup_percent($product_id) {
        $opts = $this->options();
        $global = (float) $opts['global_markup_percent'];
        $category_rules = isset($opts['category_rules']) && is_array($opts['category_rules']) ? $opts['category_rules'] : array();
        $term_source_id = $this->category_source_product_id($product_id);
        $term_ids = wp_get_post_terms($term_source_id, 'product_cat', array('fields' => 'ids'));
        if (is_wp_error($term_ids) || empty($term_ids)) {
            return $global;
        }

        $matches = array();
        foreach ($term_ids as $term_id) {
            if (isset($category_rules[$term_id]) && $category_rules[$term_id] !== '') {
                $matches[] = (float) $category_rules[$term_id];
            }
        }

        if (empty($matches)) {
            return $global;
        }

        $strategy = isset($opts['multi_category_strategy']) ? $opts['multi_category_strategy'] : 'highest';
        $category_percent = ($strategy === 'lowest') ? min($matches) : max($matches);

        if (isset($opts['category_strategy']) && $opts['category_strategy'] === 'add_to_global') {
            return $global + $category_percent;
        }
        return $category_percent;
    }

    public function calculate_price($product_id, $remote_info = array()) {
        $base = $this->get_base_price($product_id);
        if ($base <= 0) {
            return array(
                'base_price' => $base,
                'markup_percent' => 0,
                'calculated_price' => 0,
                'final_price' => 0,
                'range_status' => 'blocked',
                'range_message' => 'قیمت مبنای ووکامرس صفر یا نامعتبر است.',
                'range' => array('min' => null, 'max' => null),
            );
        }

        $percent = $this->calculate_markup_percent($product_id);
        $price = $base + ($base * $percent / 100);
        $price = $this->round_price($price);

        $range = $this->extract_cash_range($remote_info);
        $range_result = $this->apply_range_strategy($price, $range);

        return array(
            'base_price' => $base,
            'markup_percent' => $percent,
            'calculated_price' => $price,
            'final_price' => $range_result['price'],
            'range_status' => $range_result['status'],
            'range_message' => $range_result['message'],
            'range' => $range,
        );
    }

    public function price_payload($product_id, $remote_info = array()) {
        $opts = $this->options();
        $calc = $this->calculate_price($product_id, $remote_info);
        if ($calc['range_status'] === 'blocked') {
            return array('success' => false, 'message' => $calc['range_message'], 'calc' => $calc);
        }

        $cash = array('price' => (float) $calc['final_price']);
        $payload = array('cash' => $cash);
        if ($opts['sync_leasing_bnpl'] === 'yes') {
            $leasing_percent = (float) $opts['leasing_percent'];
            $bnpl_percent = (float) $opts['bnpl_percent'];
            $payload['leasing'] = array('price' => (float) $this->round_price($calc['final_price'] + ($calc['final_price'] * $leasing_percent / 100)));
            $payload['bnpl'] = array('price' => (float) $this->round_price($calc['final_price'] + ($calc['final_price'] * $bnpl_percent / 100)));
        }
        return array('success' => true, 'payload' => $payload, 'calc' => $calc);
    }

    public function round_price($price) {
        $opts = $this->options();
        $mode = isset($opts['rounding']) ? $opts['rounding'] : 'none';
        $price = (float) $price;
        if ($mode === '1000') {
            return ceil($price / 1000) * 1000;
        }
        if ($mode === '10000') {
            return ceil($price / 10000) * 10000;
        }
        if ($mode === '100000') {
            return ceil($price / 100000) * 100000;
        }
        return round($price);
    }

    private function category_source_product_id($product_id) {
        $product = wc_get_product($product_id);
        if ($product && $product->is_type('variation') && $product->get_parent_id()) {
            return $product->get_parent_id();
        }
        return $product_id;
    }

    private function extract_cash_range($remote_info) {
        $range = array('min' => null, 'max' => null);
        if (!is_array($remote_info)) {
            return $range;
        }
        if (isset($remote_info['cashMinTolerance'])) { $range['min'] = (float) $remote_info['cashMinTolerance']; }
        if (isset($remote_info['cashMaxTolerance'])) { $range['max'] = (float) $remote_info['cashMaxTolerance']; }
        if (isset($remote_info['cash']) && is_array($remote_info['cash'])) {
            if (isset($remote_info['cash']['minPrice'])) { $range['min'] = (float) $remote_info['cash']['minPrice']; }
            if (isset($remote_info['cash']['maxPrice'])) { $range['max'] = (float) $remote_info['cash']['maxPrice']; }
        }
        return $range;
    }

    private function apply_range_strategy($price, $range) {
        $opts = $this->options();
        $min = isset($range['min']) ? $range['min'] : null;
        $max = isset($range['max']) ? $range['max'] : null;
        $strategy = isset($opts['out_of_range_strategy']) ? $opts['out_of_range_strategy'] : 'block';
        $original = $price;

        if ($min !== null && $min > 0 && $price < $min) {
            if ($strategy === 'clamp') {
                return array('price' => $min, 'status' => 'clamped', 'message' => 'قیمت کمتر از حد مجاز بود و به حداقل مجاز اصلاح شد.');
            }
            return array('price' => $price, 'status' => 'blocked', 'message' => 'قیمت محاسبه‌شده کمتر از حداقل مجاز تکنولایف است.');
        }
        if ($max !== null && $max > 0 && $price > $max) {
            if ($strategy === 'clamp') {
                return array('price' => $max, 'status' => 'clamped', 'message' => 'قیمت بیشتر از حد مجاز بود و به حداکثر مجاز اصلاح شد.');
            }
            return array('price' => $price, 'status' => 'blocked', 'message' => 'قیمت محاسبه‌شده بیشتر از حداکثر مجاز تکنولایف است.');
        }
        return array('price' => $original, 'status' => 'ok', 'message' => 'قیمت در بازه مجاز است.');
    }
}
