<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

class PortfolioItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'title', 'slug', 'description', 'meta_title', 'meta_description', 'external_url',
        'image_path', 'thumb_path', 'image_width', 'image_height',
        'sort_order', 'is_published', 'published_at', 'wp_post_id',
    ];

    protected $hidden = ['wp_post_id'];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'published_at' => 'datetime',
            'sort_order' => 'integer',
            'image_width' => 'integer',
            'image_height' => 'integer',
        ];
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    /** Same order the WordPress site used: menu_order, then post date (ASC). */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('published_at')->orderBy('id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk(config('jackychee.images.disk'))->url($this->image_path) : null;
    }

    public function thumbUrl(): ?string
    {
        $path = $this->thumb_path ?: $this->image_path;

        return $path ? Storage::disk(config('jackychee.images.disk'))->url($path) : null;
    }
}
