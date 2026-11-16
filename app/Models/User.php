<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;

    protected $guarded = ['id'];
    protected $hidden = ['password', 'remember_token', 'signature', 'two_factor_secret', 'two_factor_recovery'];

    /** Permissions that require two-factor sign-in (when Settings → Security says so). */
    public const SENSITIVE = ['users.manage', 'roles.manage', 'settings.manage', 'documents.manage', 'numbers.manage', 'receipts.manage', 'payments.pay', 'forms.design'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'last_login_at' => 'datetime', 'password' => 'hashed',
                'active' => 'boolean', 'is_super' => 'boolean', 'two_factor_confirmed_at' => 'datetime',
                'two_factor_secret' => 'encrypted', 'two_factor_recovery' => 'encrypted:array'];
    }

    public function individual() { return $this->belongsTo(Individual::class); }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret;
    }

    /** Must turn on two-factor sign-in before using the system. */
    public function needsTwoFactor(): bool
    {
        if ($this->hasTwoFactor() || Setting::get('two_factor_required', '1') === '0') {
            return false;
        }
        return $this->isAdmin() || collect(static::SENSITIVE)->contains(fn ($p) => $this->hasPerm($p));
    }

    /** A code from the app, or one of the recovery codes (each works once). */
    public function checkTwoFactor(string $code): bool
    {
        if (!$this->two_factor_secret) {
            return false;
        }
        if (\App\Support\Totp::verify($this->two_factor_secret, $code)) {
            return true;
        }
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
        $left = $this->two_factor_recovery ?? [];
        foreach ($left as $i => $h) {
            if (strlen($code) === 10 && password_verify($code, $h)) {
                unset($left[$i]);
                $this->forceFill(['two_factor_recovery' => array_values($left)])->save();
                return true;
            }
        }
        return false;
    }

    /** New recovery codes: shown once, kept hashed. */
    public function newRecoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $codes[] = strtoupper(\Illuminate\Support\Str::random(10));
        }
        $this->forceFill(['two_factor_recovery' => array_map(fn ($c) => password_hash($c, PASSWORD_DEFAULT), $codes)])->save();
        return $codes;
    }
    public function roles() { return $this->belongsToMany(Role::class); }
    public function alerts() { return $this->hasMany(Alert::class)->latest('id'); }

    protected ?array $permCache = null;

    /** Super administrator: everything, including deleting anything. */
    public function isAdmin(): bool
    {
        return (bool) $this->is_super;
    }

    public function permissions(): array
    {
        if ($this->permCache === null) {
            $this->permCache = [];
            foreach ($this->roles as $r) {
                $this->permCache = array_merge($this->permCache, $r->permissions ?? []);
            }
            // forms every user may fill (builder: "Every user can fill this form")
            foreach (\App\Support\Perms::openForms() as $code) {
                $this->permCache[] = 'form.' . $code . '.create';
            }
            $this->permCache = array_values(array_unique($this->permCache));
        }
        return $this->permCache;
    }

    public function hasPerm(string $ability): bool
    {
        return $this->isAdmin() || \App\Support\Perms::matches($this->permissions(), $ability);
    }

    /** Any permission starting with a prefix (e.g. any master data screen). */
    public function hasAnyPerm(string $pattern): bool
    {
        if ($this->isAdmin()) {
            return true;
        }
        foreach ($this->permissions() as $g) {
            if ($g === '*' || fnmatch($pattern, $g) || (str_contains($g, '*') && fnmatch($g, str_replace('*', 'x', $pattern)))) {
                return true;
            }
        }
        return false;
    }

    public function roleNames(): string
    {
        return $this->isAdmin() ? 'Super administrator' : ($this->roles->pluck('name')->join(', ') ?: 'No role');
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name));
        return strtoupper(mb_substr($parts[0] ?? '', 0, 1) . mb_substr($parts[1] ?? '', 0, 1));
    }
}
