<?php

use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\Artisan;

// sudo -u <site user> php artisan violet:2fa-reset someone@violetsyria.org   (last resort when the only administrator lost the phone)
Artisan::command('violet:2fa-reset {email}', function (string $email) {
    $u = User::where('email', $email)->first();
    if (!$u) {
        $this->error('No user with this e-mail.');
        return 1;
    }
    $u->forceFill(['two_factor_secret' => null, 'two_factor_recovery' => null, 'two_factor_confirmed_at' => null])->save();
    Audit::log('2fa', $u, 'Two-factor sign-in reset from the server console');
    $this->info('Two-factor sign-in is off for ' . $u->name . '.');
})->purpose('Turn off two-factor sign-in for one user');

// php artisan violet:audit-verify   -> checks that no audit row was removed or changed
Artisan::command('violet:audit-verify', function () {
    $bad = Audit::verify();
    $bad ? $this->error("The audit trail is broken at row $bad.") : $this->info('The audit trail is intact.');
    return $bad ? 1 : 0;
})->purpose('Check the audit trail chain');

// php artisan violet:expenses-rebuild   -> rebuilds the expense lines from every saved form
Artisan::command('violet:expenses-rebuild', function () {
    $n = \App\Services\Expenses::rebuild();
    $this->info("$n expense lines written.");
})->purpose('Rebuild expense_lines from the saved forms');
