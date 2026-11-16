<?php

namespace App\Support;

/** Time-based one-time passwords (RFC 6238): the 6-digit codes of Google / Microsoft Authenticator. */
class Totp
{
    protected const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function secret(int $bytes = 20): string
    {
        return static::base32(random_bytes($bytes));
    }

    public static function uri(string $secret, string $account, string $issuer = 'Violet Finance'): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account) . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&digits=6&period=30';
    }

    public static function code(string $secret, ?int $time = null): string
    {
        $counter = intdiv($time ?? time(), 30);
        $hash = hash_hmac('sha1', pack('J', $counter), static::unbase32($secret), true);
        $o = ord($hash[19]) & 0x0F;
        $n = ((ord($hash[$o]) & 0x7F) << 24) | (ord($hash[$o + 1]) << 16) | (ord($hash[$o + 2]) << 8) | ord($hash[$o + 3]);
        return str_pad((string) ($n % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /** Accepts the code of the current 30 seconds and one step before or after (clock drift). */
    public static function verify(string $secret, string $code, ?int $time = null): bool
    {
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== 6) {
            return false;
        }
        $t = $time ?? time();
        foreach ([-30, 0, 30] as $d) {
            if (hash_equals(static::code($secret, $t + $d), $code)) {
                return true;
            }
        }
        return false;
    }

    public static function base32(string $bin): string
    {
        $bits = '';
        foreach (str_split($bin) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= static::B32[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    public static function unbase32(string $b32): string
    {
        $bits = '';
        foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32))) as $c) {
            $bits .= str_pad(decbin(strpos(static::B32, $c)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }
}
