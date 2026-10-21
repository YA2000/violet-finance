<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FormType extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['schema' => 'array', 'active' => 'boolean'];

    public const GROUPS = ['AP', 'Treasury'];

    public function slots() { return $this->hasMany(FormSlot::class)->orderBy('sequence'); }
    public function roles() { return $this->hasMany(FormRole::class)->orderBy('sequence')->orderBy('id'); }
    public function prints() { return $this->hasMany(FormPrint::class)->orderBy('sequence'); }
    public function fields() { return $this->hasMany(FormField::class)->orderBy('sequence')->orderBy('id'); }
    public function fieldConfigs() { return $this->hasMany(FormFieldConfig::class); }
    public function versions() { return $this->hasMany(FormVersion::class)->latest('id'); }

    protected static array $byCode = [];

    public static function byCode(string $code): ?self
    {
        return static::$byCode[$code] ??= static::where('code', $code)->first();
    }

    public static function flush(): void
    {
        static::$byCode = [];
        \App\Forms\FormRegistry::flush();
    }

    protected static function booted(): void
    {
        static::saved(fn () => static::flush());
        static::deleted(fn () => static::flush());
    }

    public function definition(): \App\Forms\DynamicForm
    {
        return \App\Forms\FormRegistry::get($this->code);
    }
}
