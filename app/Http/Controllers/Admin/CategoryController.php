<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Support\Slug;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Category::query()->withCount('portfolioItems')->ordered()->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $category = Category::create([
            'name' => $data['name'],
            'slug' => Slug::unique(Category::class, $data['slug'] ?? $data['name'], null, 'category'),
            'sort_order' => $data['sort_order'] ?? ((int) Category::max('sort_order') + 1),
        ]);

        return response()->json(['data' => $category], 201);
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        $data = $this->validated($request, $category);
        $category->name = $data['name'];
        if (! empty($data['slug']) && $data['slug'] !== $category->slug) {
            $category->slug = Slug::unique(Category::class, $data['slug'], $category->id, 'category');
        }
        if (isset($data['sort_order'])) {
            $category->sort_order = $data['sort_order'];
        }
        $category->save();

        return response()->json(['data' => $category]);
    }

    public function destroy(Category $category): JsonResponse
    {
        $category->delete(); // pivot rows cascade; portfolio items are kept

        return response()->json(null, 204);
    }

    private function validated(Request $request, ?Category $category = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:140', 'alpha_dash:ascii', Rule::unique('categories', 'slug')->ignore($category?->id)],
            'sort_order' => ['nullable', 'integer', 'between:0,100000'],
        ]);
    }
}
