<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class Setting extends Model
{
    protected $fillable = ['key', 'value', 'group', 'encrypted'];

    protected $casts = [
        'encrypted' => 'boolean',
    ];

    public function getValueAttribute(?string $raw = null): ?string
    {
        if ($raw && $this->encrypted) {
            return Crypt::decryptString($raw);
        }

        return $raw;
    }

    public function setValueAttribute(?string $value): void
    {
        $this->attributes['value'] = $this->encrypted && $value
            ? Crypt::encryptString($value)
            : $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = static::where('key', $key)->first();

        return $setting?->value ?? $default;
    }

    public static function set(string $key, ?string $value, string $group = 'general', bool $encrypted = false): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'group' => $group, 'encrypted' => $encrypted]
        );
    }
}
