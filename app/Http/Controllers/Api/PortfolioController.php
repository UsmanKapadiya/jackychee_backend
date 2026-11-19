<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PortfolioItemResource;
use App\Models\PortfolioItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PortfolioController extends Controller
{
    /** GET /api/v1/portfolio?category=advertising */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'category' => ['nullable', 'string', 'max:140', 'alpha_dash'],
        ]);

        $items = PortfolioItem::query()
            ->published()
            ->with('categories:id,name,slug')
            ->when($validated['category'] ?? null, fn ($q, $slug) => $q->whereHas('categories', fn ($c) => $c->where('slug', $slug)))
            ->ordered()
            ->get();

        return PortfolioItemResource::collection($items);
    }

    /** GET /api/v1/portfolio/{slug} - detail + previous / next navigation */
    public function show(string $slug): JsonResponse
    {
        $item = PortfolioItem::query()->published()->with('categories:id,name,slug')
            ->where('slug', $slug)->firstOrFail();

        $ordered = PortfolioItem::query()->published()->ordered()->pluck('slug')->values();
        $pos = $ordered->search($item->slug);

        return response()->json([
            'data' => (new PortfolioItemResource($item))->resolve(request()),
            'prev' => $pos > 0 ? $ordered[$pos - 1] : null,
            'next' => $pos !== false && $pos < $ordered->count() - 1 ? $ordered[$pos + 1] : null,
        ]);
    }
}
