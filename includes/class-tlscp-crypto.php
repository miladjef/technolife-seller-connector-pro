<?php
if (!defined('ABSPATH')) { exit; }

class TLSCP_Crypto {
    public static function encrypted_secret($secret, $payload = '', $secret_is_base64 = true) {
        $secret = trim((string) $secret);
        if ($secret === '') {
            return '';
        }

        if ($secret_is_base64) {
            $key = base64_decode($secret, true);
            if ($key === false) {
                $key = substr(hash('sha256', $secret, true), 0, 16);
            }
        } else {
            $key = $secret;
        }

        if (strlen($key) !== 16) {
            $key = substr(hash('sha256', $key, true), 0, 16);
        }

        $iv = function_exists('random_bytes') ? random_bytes(12) : openssl_random_pseudo_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt((string) $payload, 'aes-128-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ciphertext === false) {
            return '';
        }

        return base64_encode($iv) . ':' . base64_encode($tag) . ':' . base64_encode($ciphertext);
    }
}
