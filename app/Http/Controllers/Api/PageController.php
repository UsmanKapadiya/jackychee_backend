<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Page;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class PageController extends Controller
{
    /** GET /api/v1/pages/{slug} - content is already sanitised when saved */
    public function show(string $slug): JsonResponse
    {
        $page = Page::query()->published()->where('slug', $slug)->firstOrFail();
        $data = $page->only(['title', 'slug', 'content', 'social_icons', 'contact_info', 'extra_content', 'show_title']);
        $legacyStorageUrl = 'https://api.jackychee.com/storage/';
        $storageUrl = rtrim(Storage::disk('public')->url('/'), '/').'/';

        foreach (['content', 'social_icons', 'contact_info', 'extra_content'] as $field) {
            if (is_string($data[$field])) {
                $data[$field] = str_replace($legacyStorageUrl, $storageUrl, $data[$field]);
            }
        }

        return response()->json([
            'data' => $data,
        ]);
    }
}
