<?php

namespace App\Support;

/**
 * Verifies passwords that were hashed by WordPress, so existing WordPress
 * users can log in to the new admin with their current password.
 *
 * Supported formats:
 *  - "$P$..." / "$H$..."  phpass portable hashes (WordPress up to 6.7)
 *  - "$wp$2y$..."         bcrypt over HMAC-SHA384 (WordPress 6.8+)
 *  - "$2y$..."            plain bcrypt (some WP plugins / password_hash)
 *  - 32 hex chars         very old MD5 hashes (WordPress before 2.5)
 *
 * After the first successful login the hash is replaced by a modern Laravel bcrypt hash
 * (see AuthController), so these legacy formats disappear over time.
 */
final class WordPressPassword
{
    private const string ITOA64 = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public static function isWordPressHash(?string $hash): bool
    {
        if ($hash === null || $hash === '') {
            return false;
        }

        return str_starts_with($hash, '$P$')
            || str_starts_with($hash, '$H$')
            || str_starts_with($hash, '$wp$')
            || (bool) preg_match('/^[a-f0-9]{32}$/i', $hash);
    }

    public static function check(string $password, string $hash): bool
    {
        // WordPress refuses passwords longer than 4096 bytes - so do we (DoS protection)
        if (strlen($password) > 4096) {
            return false;
        }

        if (str_starts_with($hash, '$wp$')) {
            $prehashed = base64_encode(hash_hmac('sha384', $password, 'wp-sha384', true));

            return password_verify($prehashed, substr($hash, 3));
        }

        if (str_starts_with($hash, '$P$') || str_starts_with($hash, '$H$')) {
            return hash_equals($hash, self::phpassCrypt($password, $hash));
        }

        if (preg_match('/^[a-f0-9]{32}$/i', $hash)) {
            return hash_equals(strtolower($hash), md5($password));
        }

        if (str_starts_with($hash, '$2y$') || str_starts_with($hash, '$2a$')) {
            return password_verify($password, $hash);
        }

        return false;
    }

    /** phpass "portable" algorithm (same as WordPress' class-phpass.php crypt_private). */
    private static function phpassCrypt(string $password, string $setting): string
    {
        $fail = '*0';
        if (str_starts_with($setting, $fail)) {
            $fail = '*1';
        }
        if (strlen($setting) !== 34) {
            return $fail;
        }

        $countLog2 = strpos(self::ITOA64, $setting[3]);
        if ($countLog2 === false || $countLog2 < 7 || $countLog2 > 30) {
            return $fail;
        }
        $count = 1 << $countLog2;

        $salt = substr($setting, 4, 8);
        if (strlen($salt) !== 8) {
            return $fail;
        }

        $hash = md5($salt.$password, true);
        do {
            $hash = md5($hash.$password, true);
        } while (--$count);

        return substr($setting, 0, 12).self::encode64($hash, 16);
    }

    private static function encode64(string $input, int $count): string
    {
        $output = '';
        $i = 0;
        do {
            $value = ord($input[$i++]);
            $output .= self::ITOA64[$value & 0x3F];
            if ($i < $count) {
                $value |= ord($input[$i]) << 8;
            }
            $output .= self::ITOA64[($value >> 6) & 0x3F];
            if ($i++ >= $count) {
                break;
            }
            if ($i < $count) {
                $value |= ord($input[$i]) << 16;
            }
            $output .= self::ITOA64[($value >> 12) & 0x3F];
            if ($i++ >= $count) {
                break;
            }
            $output .= self::ITOA64[($value >> 18) & 0x3F];
        } while ($i < $count);

        return $output;
    }
}
