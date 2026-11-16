<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\Totp;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    private function userWith2fa(): array
    {
        $secret = Totp::secret();
        $u = User::create(['name' => 'Test', 'email' => 't@violet.local', 'password' => bcrypt('Password-123'), 'role' => 'admin', 'active' => true]);
        $u->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();
        return [$u, $secret];
    }

    public function test_password_alone_is_not_enough(): void
    {
        [, $secret] = $this->userWith2fa();
        $this->post('/login', ['email' => 't@violet.local', 'password' => 'Password-123'])->assertRedirect(route('login.2fa'));
        $this->assertGuest();
        $this->post('/login/code', ['code' => '000000'])->assertSessionHasErrors();
        $this->assertGuest();
        $this->post('/login/code', ['code' => Totp::code($secret)]);
        $this->assertAuthenticated();
    }

    public function test_a_recovery_code_works_once(): void
    {
        [$u] = $this->userWith2fa();
        $codes = $u->newRecoveryCodes();
        $this->post('/login', ['email' => 't@violet.local', 'password' => 'Password-123']);
        $this->post('/login/code', ['code' => $codes[0]]);
        $this->assertAuthenticated();
        $this->assertFalse($u->fresh()->checkTwoFactor($codes[0]));
    }

    public function test_required_roles_are_sent_to_set_it_up(): void
    {
        Setting::put('two_factor_required', '1');
        $this->actingAs($this->admin())->get('/')->assertRedirect(route('account'));
        $this->actingAs($this->admin())->get('/account')->assertOk();
    }
}
