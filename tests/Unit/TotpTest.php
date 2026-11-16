<?php

namespace Tests\Unit;

use App\Support\Totp;
use PHPUnit\Framework\TestCase;

/** The six-digit codes must match Google Authenticator / Microsoft Authenticator (RFC 6238). */
class TotpTest extends TestCase
{
    public function test_matches_the_rfc_test_vector(): void
    {
        $secret = Totp::base32('12345678901234567890');
        $this->assertSame('287082', Totp::code($secret, 59));
    }

    public function test_accepts_the_neighbour_window_only(): void
    {
        $s = Totp::secret();
        $now = 1_800_000_000;
        $this->assertTrue(Totp::verify($s, Totp::code($s, $now - 30), $now));
        $this->assertTrue(Totp::verify($s, Totp::code($s, $now + 30), $now));
        $this->assertFalse(Totp::verify($s, Totp::code($s, $now - 120), $now));
        $this->assertFalse(Totp::verify($s, 'abc', $now));
    }

    public function test_base32_round_trip(): void
    {
        $bin = random_bytes(20);
        $this->assertSame($bin, Totp::unbase32(Totp::base32($bin)));
    }
}
