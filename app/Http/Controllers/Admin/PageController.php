<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Services\HtmlSanitizer;
use App\Support\Slug;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PageController extends Controller
{
    private const array HTML_FIELDS = ['content', 'social_icons', 'contact_info', 'extra_content'];

    public function __construct(private readonly HtmlSanitizer $sanitizer) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Page::query()->orderBy('sort_order')->orderBy('title')
                ->get(['id', 'title', 'slug', 'show_in_menu', 'is_published', 'sort_order', 'updated_at']),
        ]);
    }

    public function show(Page $page): JsonResponse
    {
        return response()->json(['data' => $page]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $page = new Page($this->clean($data));
        $page->slug = Slug::unique(Page::class, $data['slug'] ?? $data['title'], null, 'page');
        $page->save();

        return response()->json(['data' => $page], 201);
    }

    public function update(Request $request, Page $page): JsonResponse
    {
        $data = $this->validated($request);
        $page->fill($this->clean($data));
        if (! empty($data['slug']) && $data['slug'] !== $page->getOriginal('slug')) {
            $page->slug = Slug::unique(Page::class, $data['slug'], $page->id, 'page');
        }
        $page->save();

        return response()->json(['data' => $page]);
    }

    public function destroy(Page $page): JsonResponse
    {
        $page->delete();

        return response()->json(null, 204);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:190', 'alpha_dash:ascii'],
            'content' => ['nullable', 'string', 'max:300000'],
            'social_icons' => ['nullable', 'string', 'max:50000'],
            'contact_info' => ['nullable', 'string', 'max:50000'],
            'extra_content' => ['nullable', 'string', 'max:100000'],
            'show_title' => ['sometimes', 'boolean'],
            'show_in_menu' => ['sometimes', 'boolean'],
            'is_published' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'between:0,100000'],
        ]);
    }

    private function clean(array $data): array
    {
        unset($data['slug']);
        foreach (self::HTML_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = $this->sanitizer->clean($data[$field]);
            }
        }
        if (array_key_exists('sort_order', $data) && $data['sort_order'] === null) {
            unset($data['sort_order']);
        }

        return $data;
    }
}
