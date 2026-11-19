<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class Slug
{
    /**
     * Build an ASCII, URL-safe slug that is unique in the model's table.
     *
     * @param  class-string<Model>  $model
     */
    public static function unique(string $model, string $source, ?int $ignoreId = null, string $fallback = 'item'): string
    {
        $base = Str::limit(Str::slug(Str::ascii($source)), 180, '');
        if ($base === '') {
            $base = $fallback.'-'.Str::lower(Str::random(6));
        }

        $slug = $base;
        $i = 2;
        while ($model::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
