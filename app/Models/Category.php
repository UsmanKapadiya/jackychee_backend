<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Category extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'sort_order', 'wp_term_id'];

    protected $hidden = ['wp_term_id'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public function portfolioItems(): BelongsToMany
    {
        return $this->belongsToMany(PortfolioItem::class);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
