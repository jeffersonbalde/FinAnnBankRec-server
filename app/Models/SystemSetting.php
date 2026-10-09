<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Small system-wide settings kept in the database (so they survive a redeploy,
 * unlike a file on the app server's disk).
 */
class SystemSetting extends Model
{
    protected $fillable = ['key', 'value'];

    public static function read(string $key, ?string $default = null): ?string
    {
        return static::query()->where('key', $key)->value('value') ?? $default;
    }

    public static function write(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
