<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Page extends Model
{
    use HasFactory;

    protected $fillable = [
        'title', 'slug', 'content', 'social_icons', 'contact_info', 'extra_content',
        'show_title', 'show_in_menu', 'sort_order', 'is_published', 'wp_post_id',
    ];

    protected $hidden = ['wp_post_id'];

    protected function casts(): array
    {
        return [
            'show_title' => 'boolean',
            'show_in_menu' => 'boolean',
            'is_published' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }
}
