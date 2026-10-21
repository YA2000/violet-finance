<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** In-app notification (the bell). */
class Alert extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['read_at' => 'datetime'];

    public function user() { return $this->belongsTo(User::class); }

    /** Notify users (ids, User models or a permission key matched against roles). */
    public static function send($to, string $title, ?string $body = null, ?string $url = null, string $icon = 'fa-bell', ?int $except = null): void
    {
        $users = is_string($to) ? User::where('active', true)->get()->filter(fn ($u) => $u->hasPerm($to)) : collect(is_iterable($to) ? $to : [$to]);
        $seen = [];
        foreach ($users as $u) {
            $id = $u instanceof User ? $u->id : (int) $u;
            if (!$id || $id === $except || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            static::create(['user_id' => $id, 'title' => $title, 'body' => $body, 'url' => $url, 'icon' => $icon]);
        }
    }
}
