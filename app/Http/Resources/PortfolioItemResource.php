<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\PortfolioItem */
class PortfolioItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isAdmin = $request->is('api/*/admin/*');

        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'alt' => $this->meta_description ?: $this->title,
            'thumb_url' => $this->thumbUrl(),
            'image_url' => $this->imageUrl(),
            'width' => $this->image_width,
            'height' => $this->image_height,
            'categories' => $this->whenLoaded('categories', fn () => $this->categories->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'slug' => $c->slug,
            ])->values()),
            'description' => $this->description, // sanitised HTML
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'external_url' => $this->external_url,
            'sort_order' => $this->sort_order,
            'is_published' => $this->when($isAdmin, $this->is_published),
            'published_at' => $this->published_at?->toIso8601String(),
        ];
    }
}
