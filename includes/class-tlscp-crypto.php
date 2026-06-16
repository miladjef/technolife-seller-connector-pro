<?php
if (!defined('ABSPATH')) { exit; }

/**
 * تولید هدر encrypted-secret دقیقاً مطابق مستند رسمی تکنولایف.
 *
 * مرجع (مستند API):
 *   - الگوریتم: AES-128-GCM  | اندازه کلید: 128 بیت (۱۶ بایت)
 *   - IV: ۱۲ بایت تصادفی  | Auth Tag: ۱۶ بایت
 *   - کلید = base64_decode(secret)
 *   - خروجی = base64(iv) ":" base64(tag) ":" base64(ciphertext)
 *   - plaintext = «آدرس اندپوینت» مورد نظر
 */
class TLSCP_Crypto {

    /**
     * طول کلید واقعی پس از decode را برمی‌گرداند تا پنل بتواند صحت Secret را بسنجد.
     * مقدار صحیح برای AES-128 دقیقاً 16 است.
     */
    public static function decoded_key_length($secret, $secret_is_base64 = true) {
        $secret = trim((string) $secret);
        if ($secret === '') {
            return 0;
        }
        if ($secret_is_base64) {
            $decoded = base64_decode($secret, true);
            if ($decoded !== false) {
                return strlen($decoded);
            }
            return -1; // base64 نامعتبر
        }
        return strlen($secret);
    }

    /**
     * آیا Secret تنظیم‌شده برای AES-128 معتبر است؟ (دقیقاً ۱۶ بایت)
     */
    public static function key_is_valid($secret, $secret_is_base64 = true) {
        return self::decoded_key_length($secret, $secret_is_base64) === 16;
    }

    /**
     * کلید ۱۶ بایتی نهایی را با همان منطق مستند استخراج می‌کند.
     * - حالت base64 و طول دقیقاً ۱۶ بایت → همان کلید (مسیر صحیح مستند)
     * - secret خام دقیقاً ۱۶ بایت → همان کلید
     * - در غیر این صورت → اشتقاق sha256 (فقط برای جلوگیری از کرش؛ با سرور مطابقت نخواهد داشت)
     */
    private static function derive_key($secret, $secret_is_base64) {
        if ($secret_is_base64) {
            $decoded = base64_decode($secret, true);
            if ($decoded !== false && strlen($decoded) === 16) {
                return $decoded;
            }
        }
        if (strlen($secret) === 16) {
            return $secret;
        }
        return substr(hash('sha256', $secret, true), 0, 16);
    }

    public static function encrypted_secret($secret, $payload = '', $secret_is_base64 = true) {
        $secret = trim((string) $secret);
        if ($secret === '' || !function_exists('openssl_encrypt')) {
            return '';
        }

        $key = self::derive_key($secret, $secret_is_base64);
        if (strlen($key) !== 16) {
            return '';
        }

        $iv  = function_exists('random_bytes') ? random_bytes(12) : openssl_random_pseudo_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt((string) $payload, 'aes-128-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ciphertext === false) {
            return '';
        }

        return base64_encode($iv) . ':' . base64_encode($tag) . ':' . base64_encode($ciphertext);
    }
}
