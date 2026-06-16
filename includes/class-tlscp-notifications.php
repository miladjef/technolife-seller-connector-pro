<?php
if (!defined('ABSPATH')) { exit; }

/**
 * اعلان‌های ایمیلی: باخت بای‌باکس و افزایش خطاهای API.
 */
class TLSCP_Notifications {

    private static function opts() {
        return wp_parse_args(get_option(TLSCP_OPTION_KEY, array()), TLSCP_Installer::default_options());
    }

    private static function recipient() {
        $opts = self::opts();
        $email = isset($opts['notify_email']) ? trim($opts['notify_email']) : '';
        if ($email === '' || !is_email($email)) {
            $email = get_option('admin_email');
        }
        return $email;
    }

    /**
     * اعلان نتیجه‌ی اسکن بای‌باکس در صورت وجود بازنده.
     */
    public static function buybox_report($stats) {
        $opts = self::opts();
        if (!isset($opts['notify_buybox_loss']) || $opts['notify_buybox_loss'] !== 'yes') {
            return false;
        }
        $losers = isset($stats['losers']) ? (int) $stats['losers'] : 0;
        if ($losers < 1) {
            return false;
        }
        $lines = array();
        $lines[] = 'تعداد بازنده‌های بای‌باکس: ' . $losers;
        if (!empty($stats['losers_list']) && is_array($stats['losers_list'])) {
            foreach (array_slice($stats['losers_list'], 0, 30) as $l) {
                $lines[] = sprintf('- %s | کد: %s | قیمت من: %s | قیمت برنده: %s',
                    isset($l['title']) ? $l['title'] : '',
                    isset($l['seller_item_code']) ? $l['seller_item_code'] : '',
                    isset($l['my_price']) ? number_format((float) $l['my_price']) : '-',
                    isset($l['winner_price']) ? number_format((float) $l['winner_price']) : '-'
                );
            }
        }
        $subject = '[تکنولایف] هشدار بای‌باکس: ' . $losers . ' محصول بازنده';
        return wp_mail(self::recipient(), $subject, implode("\n", $lines));
    }

    /**
     * اعلان در صورت عبور تعداد خطاهای اخیر از آستانه.
     */
    public static function error_threshold($logger) {
        $opts = self::opts();
        $threshold = isset($opts['notify_error_threshold']) ? absint($opts['notify_error_threshold']) : 0;
        if ($threshold < 1) {
            return false;
        }
        $failed = count($logger->recent(100, 0));
        if ($failed < $threshold) {
            return false;
        }
        $subject = '[تکنولایف] هشدار خطا: ' . $failed . ' عملیات ناموفق اخیر';
        $body = 'تعداد عملیات ناموفق اخیر API به ' . $failed . ' رسید (آستانه: ' . $threshold . '). برای جزئیات به صفحه لاگ‌ها مراجعه کنید.';
        return wp_mail(self::recipient(), $subject, $body);
    }
}
