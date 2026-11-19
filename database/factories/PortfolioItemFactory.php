<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<\App\Models\PortfolioItem> */
class PortfolioItemFactory extends Factory
{
    public function definition(): array
    {
        $title = fake()->unique()->sentence(3);

        return [
            'title' => $title,
            'slug' => Str::slug($title),
            'image_path' => 'portfolio/test/'.Str::random(10).'.jpg',
            'thumb_path' => 'portfolio/test/'.Str::random(10).'-thumb.jpg',
            'image_width' => 800,
            'image_height' => 1000,
            'sort_order' => fake()->numberBetween(1, 100),
            'is_published' => true,
            'published_at' => now(),
        ];
    }
}
