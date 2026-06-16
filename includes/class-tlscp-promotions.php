<?php
if (!defined('ABSPATH')) { exit; }

/**
 * مدیریت پروموشن و تخفیف زمان‌دار تکنولایف.
 *
 * مرجع مستند:
 *  - GET  /v1/promotion/{productCode}/list  → { data: [ { id, name } ] }
 *  - PUT  /v1/promotion/{sellerItemCode}/info  body = DiscountInfoInput:
 *        count (الزامی), discountedPercent (اختیاری، اولویت بالاتر),
 *        discountedPrice (اختیاری، ریال), marketingGroup (الزامی),
 *        startDate / endDate (ISO-8601 UTC، الزامی)
 */
class TLSCP_Promotions {
    private $api;
    private $pricing;
    private $logger;

    public function __construct($api, $pricing, $logger) {
        $this->api = $api;
        $this->pricing = $pricing;
        $this->logger = $logger;
    }

    /**
     * لیست پروموشن‌های یک کد محصول (با کش کوتاه‌مدت برای کاهش فراخوانی).
     */
    public function list_for_product($product_code, $use_cache = true) {
        $product_code = trim((string) $product_code);
        if ($product_code === '') {
            return array('success' => false, 'message' => 'کد محصول وارد نشده است.', 'items' => array());
        }
        $cache_key = 'tlscp_promo_' . md5($product_code);
        if ($use_cache) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                return array('success' => true, 'items' => $cached, 'cached' => true);
            }
        }
        $res = $this->api->promotion_list($product_code);
        if (!$res['success']) {
            return array('success' => false, 'message' => $res['message'], 'items' => array());
        }
        $items = (isset($res['data']['data']) && is_array($res['data']['data'])) ? $res['data']['data'] : array();
        set_transient($cache_key, $items, 15 * MINUTE_IN_SECONDS);
        return array('success' => true, 'items' => $items, 'cached' => false);
    }

    /**
     * اعتبارسنجی و آماده‌سازی بدنه‌ی DiscountInfoInput.
     */
    public function build_payload($args) {
        $count = isset($args['count']) ? max(1, absint($args['count'])) : 0;
        $marketing_group = isset($args['marketingGroup']) ? sanitize_text_field($args['marketingGroup']) : '';
        $percent = isset($args['discountedPercent']) && $args['discountedPercent'] !== '' ? (float) $args['discountedPercent'] : null;
        $price = isset($args['discountedPrice']) && $args['discountedPrice'] !== '' ? (float) $args['discountedPrice'] : null;
        $start = isset($args['startDate']) ? $this->to_iso_utc($args['startDate']) : '';
        $end = isset($args['endDate']) ? $this->to_iso_utc($args['endDate']) : '';

        if ($count < 1) {
            return array('success' => false, 'message' => 'تعداد کالا برای تخفیف باید حداقل ۱ باشد.');
        }
        if ($marketing_group === '') {
            return array('success' => false, 'message' => 'پروموشن (marketingGroup) انتخاب نشده است.');
        }
        if ($start === '' || $end === '') {
            return array('success' => false, 'message' => 'تاریخ شروع و پایان تخفیف نامعتبر است.');
        }
        if ($percent === null && $price === null) {
            return array('success' => false, 'message' => 'حداقل یکی از «درصد تخفیف» یا «قیمت تخفیف» را وارد کنید.');
        }

        $payload = array(
            'count' => $count,
            'marketingGroup' => $marketing_group,
            'startDate' => $start,
            'endDate' => $end,
        );
        if ($percent !== null) {
            $payload['discountedPercent'] = $percent; // اولویت بالاتر طبق مستند
        }
        if ($price !== null) {
            // قیمت تخفیف به ریال؛ اگر واحد فروشگاه تومان است هم‌واحد می‌شود و به عدد صحیح تبدیل می‌شود.
            $payload['discountedPrice'] = (int) round($price * $this->pricing->price_unit_multiplier());
        }
        return array('success' => true, 'payload' => $payload);
    }

    /**
     * اعمال تخفیف روی یک تنوع فروشنده.
     */
    public function apply($seller_item_code, $args) {
        $seller_item_code = trim((string) $seller_item_code);
        if ($seller_item_code === '') {
            return array('success' => false, 'message' => 'کد تنوع فروشنده وارد نشده است.');
        }
        $built = $this->build_payload($args);
        if (!$built['success']) {
            return $built;
        }
        $res = $this->api->update_promotion($seller_item_code, $built['payload']);
        return array(
            'success' => !empty($res['success']),
            'message' => $res['message'],
            'seller_item_code' => $seller_item_code,
            'payload' => $built['payload'],
        );
    }

    /**
     * اعمال تخفیف روی چند تنوع فروشنده با یک تنظیم مشترک.
     */
    public function apply_bulk($seller_item_codes, $args) {
        $built = $this->build_payload($args);
        if (!$built['success']) {
            return array('success' => false, 'message' => $built['message'], 'items' => array());
        }
        $items = array();
        $ok = 0; $fail = 0;
        foreach ((array) $seller_item_codes as $code) {
            $code = sanitize_text_field($code);
            if ($code === '') { continue; }
            $res = $this->api->update_promotion($code, $built['payload']);
            $success = !empty($res['success']);
            $success ? $ok++ : $fail++;
            $items[] = array('seller_item_code' => $code, 'success' => $success, 'message' => $res['message']);
        }
        return array('success' => $fail === 0, 'ok' => $ok, 'failed' => $fail, 'items' => $items, 'payload' => $built['payload']);
    }

    /**
     * تبدیل datetime محلی سایت یا ISO به ISO-8601 UTC (با Z).
     */
    public function to_iso_utc($value) {
        $value = trim((string) $value);
        if ($value === '') { return ''; }
        // فرمت datetime-local مرورگر: 2025-07-09T07:43  → به فاصله‌دار تبدیل می‌کنیم
        $normalized = str_replace('T', ' ', $value);
        $normalized = preg_replace('/Z$/', '', $normalized);
        // اگر ثانیه ندارد اضافه می‌کنیم
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $normalized)) {
            $normalized .= ':00';
        }
        // get_gmt_from_date ورودی را به وقت سایت در نظر گرفته و به UTC تبدیل می‌کند
        $gmt = get_gmt_from_date($normalized, 'Y-m-d\TH:i:s\Z');
        if ($gmt) {
            return $gmt;
        }
        $ts = strtotime($value);
        return $ts ? gmdate('Y-m-d\TH:i:s\Z', $ts) : '';
    }
}
