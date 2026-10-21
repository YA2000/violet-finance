<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;

    protected $guarded = ['id'];
    protected $hidden = ['password', 'remember_token', 'signature'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'last_login_at' => 'datetime', 'password' => 'hashed',
                'active' => 'boolean', 'is_super' => 'boolean'];
    }

    public function individual() { return $this->belongsTo(Individual::class); }
    public function roles() { return $this->belongsToMany(Role::class); }
    public function alerts() { return $this->hasMany(Alert::class)->latest('id'); }
    public function holder() { return $this->hasOne(AdvanceHolder::class); }

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
