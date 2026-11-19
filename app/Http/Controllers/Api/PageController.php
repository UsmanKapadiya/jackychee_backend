<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Page;
use Illuminate\Http\JsonResponse;

class PageController extends Controller
{
    /** GET /api/v1/pages/{slug} - content is already sanitised when saved */
    public function show(string $slug): JsonResponse
    {
        $page = Page::query()->published()->where('slug', $slug)->firstOrFail();

        return response()->json([
            'data' => $page->only(['title', 'slug', 'content', 'social_icons', 'contact_info', 'extra_content', 'show_title']),
        ]);
    }
}
