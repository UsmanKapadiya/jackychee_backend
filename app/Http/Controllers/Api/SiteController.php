<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Page;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class SiteController extends Controller
{
    /** Everything the React layout needs: name, logo, footer, filter categories and menu pages. */
    public function show(): JsonResponse
    {
        $settings = Setting::allAsArray();

        $categories = Category::query()
            ->whereHas('portfolioItems', fn ($q) => $q->where('is_published', true))
            ->ordered()
            ->get(['id', 'name', 'slug']);

        $menu = Page::query()->published()->where('show_in_menu', true)
            ->orderBy('sort_order')->orderBy('title')
            ->get(['title', 'slug']);

        return response()->json([
            'data' => [
                'name' => $settings['site_name'] ?: config('app.name'),
                'tagline' => $settings['site_tagline'],
                'footer_text' => $settings['footer_text'],
                'seo_description' => $settings['seo_description'],
                'logo_url' => $settings['logo_path'] ? Storage::disk('public')->url($settings['logo_path']) : null,
                'categories' => $categories,
                'menu' => $menu,
            ],
        ])->setPublic()->setMaxAge(300);
    }
}
