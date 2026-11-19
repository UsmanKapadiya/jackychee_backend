<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    /** Only these keys can ever be stored or exposed. */
    public const array KEYS = ['site_name', 'site_tagline', 'footer_text', 'logo_path', 'seo_description'];

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'value'];

    public static function allAsArray(): array
    {
        return Cache::remember('site_settings', 3600, function (): array {
            $stored = static::query()->whereIn('key', self::KEYS)->pluck('value', 'key')->all();

            return array_merge(array_fill_keys(self::KEYS, null), $stored);
        });
    }

    public static function put(string $key, ?string $value): void
    {
        if (! in_array($key, self::KEYS, true)) {
            throw new \InvalidArgumentException("Unknown setting [{$key}]");
        }

        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget('site_settings');
    }
}
