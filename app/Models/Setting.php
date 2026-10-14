<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['group', 'key', 'value', 'type'];

    protected $table = 'settings';

    /**
     * Retrieve a setting value with optional default.
     */
    public static function get(string $key, $default = null, string $group = 'general')
    {
        $setting = static::where('key', $key)->where('group', $group)->first();

        if (! $setting) {
            return $default;
        }

        return static::castValue($setting->value, $setting->type);
    }

    public static function set(string $key, $value, string $group = 'general', string $type = 'string'): void
    {
        static::updateOrCreate(
            ['key' => $key, 'group' => $group],
            ['value' => is_array($value) ? json_encode($value) : (string) $value, 'type' => $type]
        );
    }

    protected static function castValue($value, string $type)
    {
        return match ($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $value,
            'json' => json_decode($value, true),
            default => $value,
        };
    }
}
