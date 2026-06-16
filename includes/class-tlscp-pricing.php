<?php
if (!defined('ABSPATH')) { exit; }

class TLSCP_Pricing {
    public function options() {
        return wp_parse_args(get_option(TLSCP_OPTION_KEY, array()), TLSCP_Installer::default_options());
    }

    public function get_base_price($product_id, $remote_info = array()) {
        $opts = $this->options();
        // مبنای قیمت: قیمت مرجع تکنولایف به‌جای قیمت ووکامرس (در صورت انتخاب).
        if (isset($opts['price_basis']) && $opts['price_basis'] === 'reference' && is_array($remote_info)) {
            $ref = null;
            if (isset($remote_info['referencePrice'])) {
                $ref = (float) $remote_info['referencePrice'];
            }
            if ($ref !== null && $ref > 0) {
                // قیمت مرجع به ریال است؛ آن را به واحد فروشگاه برمی‌گردانیم تا منطق ضریب واحد یکدست بماند.
                $mult = $this->price_unit_multiplier();
                return $mult > 0 ? ($ref / $mult) : $ref;
            }
            // اگر قیمت مرجع نبود، به قیمت ووکامرس برمی‌گردیم.
        }
        return $this->wc_base_price($product_id);
    }

    private function wc_base_price($product_id) {
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
        $opts = $this->options();
        $base = $this->get_base_price($product_id, $remote_info);
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

        // درصد افزایش: اگر مبنا «قیمت مرجع» باشد از درصد اختصاصی مرجع استفاده می‌شود.
        if (isset($opts['price_basis']) && $opts['price_basis'] === 'reference') {
            $percent = (float) $opts['reference_markup_percent'];
        } else {
            $percent = $this->calculate_markup_percent($product_id);
        }
        $price = $base + ($base * $percent / 100);

        // تبدیل به ریال: اگر واحد فروشگاه «تومان» باشد، در ۱۰ ضرب می‌شود تا با بازه‌ی ریالی تکنولایف هم‌واحد شود.
        $price = $price * $this->price_unit_multiplier();

        // گرد کردن در واحد ریال و سپس بررسی بازه‌ی مجاز (که از تکنولایف به ریال دریافت می‌شود).
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

    /**
     * ضریب تبدیل واحد قیمت فروشگاه به ریال.
     * 'rial' → 1   |   'toman' → 10
     */
    public function price_unit_multiplier() {
        $opts = $this->options();
        $unit = isset($opts['price_unit']) ? $opts['price_unit'] : 'rial';
        return $unit === 'toman' ? 10 : 1;
    }

    public function price_payload($product_id, $remote_info = array()) {
        $opts = $this->options();
        $calc = $this->calculate_price($product_id, $remote_info);
        if ($calc['range_status'] === 'blocked') {
            return array('success' => false, 'message' => $calc['range_message'], 'calc' => $calc);
        }

        $cash_price = (float) $calc['final_price'];

        // قیمت‌گذاری رقابتی برای بردن بای‌باکس (اختیاری، با کف امن).
        $notes = array();
        if (isset($opts['competitive_repricing']) && $opts['competitive_repricing'] === 'yes') {
            $comp = $this->competitive_price($cash_price, $remote_info, $opts);
            if ($comp['applied']) {
                $cash_price = $comp['price'];
                $notes[] = $comp['message'];
            }
        }

        // قیمت‌های ریالی باید عدد صحیح باشند (مطابق نمونه‌های مستند).
        $payload = array('cash' => array('price' => (int) round($cash_price)));

        if ($opts['sync_leasing_bnpl'] === 'yes') {
            $leasing_percent = (float) $opts['leasing_percent'];
            $bnpl_percent = (float) $opts['bnpl_percent'];
            $leasing_price = $this->round_price($cash_price + ($cash_price * $leasing_percent / 100));
            $bnpl_price = $this->round_price($cash_price + ($cash_price * $bnpl_percent / 100));

            $leasing_res = $this->resolve_channel_price('leasing', $leasing_price, $remote_info, $opts);
            if ($leasing_res['send']) {
                $payload['leasing'] = array('price' => (int) round($leasing_res['price']));
            }
            if ($leasing_res['note']) { $notes[] = $leasing_res['note']; }

            $bnpl_res = $this->resolve_channel_price('bnpl', $bnpl_price, $remote_info, $opts);
            if ($bnpl_res['send']) {
                $payload['bnpl'] = array('price' => (int) round($bnpl_res['price']));
            }
            if ($bnpl_res['note']) { $notes[] = $bnpl_res['note']; }
        }

        $calc['final_price'] = (int) round($cash_price);
        $calc['notes'] = $notes;
        return array('success' => true, 'payload' => $payload, 'calc' => $calc);
    }

    /**
     * بررسی بازه‌ی مجاز یک کانال اقساطی/اعتباری و تصمیم برای ارسال/اصلاح/حذف.
     */
    private function resolve_channel_price($channel, $price, $remote_info, $opts) {
        $enforce = ($channel === 'leasing')
            ? (!isset($opts['enforce_leasing_range']) || $opts['enforce_leasing_range'] === 'yes')
            : (!isset($opts['enforce_bnpl_range']) || $opts['enforce_bnpl_range'] === 'yes');
        if (!$enforce) {
            return array('send' => true, 'price' => $price, 'note' => '');
        }
        $range = $this->extract_range($remote_info, $channel);
        $res = $this->apply_range_strategy($price, $range);
        $label = $channel === 'leasing' ? 'اقساطی' : 'اعتباری';
        if ($res['status'] === 'blocked') {
            // کانال اختیاری است؛ به‌جای بلاک کل عملیات، این کانال ارسال نمی‌شود.
            return array('send' => false, 'price' => $price, 'note' => 'قیمت ' . $label . ' خارج از بازه بود و ارسال نشد.');
        }
        if ($res['status'] === 'clamped') {
            return array('send' => true, 'price' => $res['price'], 'note' => 'قیمت ' . $label . ' به حد مجاز اصلاح شد.');
        }
        return array('send' => true, 'price' => $res['price'], 'note' => '');
    }

    /**
     * محاسبه‌ی قیمت رقابتی برای بردن بای‌باکس با رعایت کف امن.
     */
    private function competitive_price($normal_price, $remote_info, $opts) {
        $result = array('applied' => false, 'price' => $normal_price, 'message' => '');
        if (!is_array($remote_info)) { return $result; }
        $is_winner = !empty($remote_info['isWinnerOfBuyBox']);
        $winner_price = isset($remote_info['buyBoxWinnerPrice']) ? (float) $remote_info['buyBoxWinnerPrice'] : 0;
        if ($is_winner || $winner_price <= 0) {
            return $result; // اگر برنده‌ایم یا قیمت برنده نامعتبر است، کاری نمی‌کنیم.
        }
        // کف مجاز API
        $cash_range = $this->extract_range($remote_info, 'cash');
        $api_floor = ($cash_range['min'] !== null && $cash_range['min'] > 0) ? $cash_range['min'] : 0;
        // کف حاشیه‌ی سود سفارشی (درصدی از قیمت نرمال)
        $margin = (float) $opts['competitive_min_margin_percent'];
        $margin_floor = $normal_price * (1 - $margin / 100);
        $floor = max($api_floor, $margin_floor, 0);

        // قیمت هدف: کمی زیر قیمت برنده
        $type = isset($opts['competitive_undercut_type']) ? $opts['competitive_undercut_type'] : 'amount';
        $value = (float) $opts['competitive_undercut_value'];
        $target = $type === 'percent' ? ($winner_price * (1 - $value / 100)) : ($winner_price - $value);

        // هرگز بالاتر از قیمت نرمال و هرگز پایین‌تر از کف
        $final = min($normal_price, max($floor, $target));
        if ($final <= 0 || $final >= $normal_price) {
            return $result; // اگر قیمت رقابتی بهتر از نرمال نشد، بی‌خیال.
        }
        $result['applied'] = true;
        $result['price'] = $final;
        $result['message'] = 'قیمت رقابتی بای‌باکس اعمال شد (هدف زیر ' . number_format($winner_price) . ').';
        return $result;
    }

    /**
     * پیش‌نمایش محاسبه‌ی قیمت بدون ارسال (dry-run).
     */
    public function preview($product_id, $remote_info = array()) {
        $opts = $this->options();
        $res = $this->price_payload($product_id, $remote_info);
        $calc = isset($res['calc']) ? $res['calc'] : array();
        return array(
            'success' => !empty($res['success']),
            'message' => isset($res['message']) ? $res['message'] : '',
            'price_basis' => isset($opts['price_basis']) ? $opts['price_basis'] : 'wc',
            'price_unit' => isset($opts['price_unit']) ? $opts['price_unit'] : 'rial',
            'base_price' => isset($calc['base_price']) ? $calc['base_price'] : null,
            'markup_percent' => isset($calc['markup_percent']) ? $calc['markup_percent'] : null,
            'calculated_price' => isset($calc['calculated_price']) ? $calc['calculated_price'] : null,
            'final_price' => isset($calc['final_price']) ? $calc['final_price'] : null,
            'range' => isset($calc['range']) ? $calc['range'] : null,
            'range_status' => isset($calc['range_status']) ? $calc['range_status'] : null,
            'notes' => isset($calc['notes']) ? $calc['notes'] : array(),
            'payload' => isset($res['payload']) ? $res['payload'] : null,
        );
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
        return $this->extract_range($remote_info, 'cash');
    }

    /**
     * استخراج بازه‌ی مجاز یک کانال قیمت (cash | leasing | bnpl) از اطلاعات تنوع یا محصول.
     */
    private function extract_range($remote_info, $channel = 'cash') {
        $range = array('min' => null, 'max' => null);
        if (!is_array($remote_info)) {
            return $range;
        }
        // سطح محصول: cashMinTolerance / leasingMaxTolerance / ...
        $minKey = $channel . 'MinTolerance';
        $maxKey = $channel . 'MaxTolerance';
        if (isset($remote_info[$minKey])) { $range['min'] = (float) $remote_info[$minKey]; }
        if (isset($remote_info[$maxKey])) { $range['max'] = (float) $remote_info[$maxKey]; }
        // سطح تنوع: cash.minPrice/maxPrice (PricingInfo)
        if (isset($remote_info[$channel]) && is_array($remote_info[$channel])) {
            if (isset($remote_info[$channel]['minPrice'])) { $range['min'] = (float) $remote_info[$channel]['minPrice']; }
            if (isset($remote_info[$channel]['maxPrice'])) { $range['max'] = (float) $remote_info[$channel]['maxPrice']; }
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
