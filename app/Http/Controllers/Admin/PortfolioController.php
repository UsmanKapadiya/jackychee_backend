<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\PortfolioItemRequest;
use App\Http\Resources\PortfolioItemResource;
use App\Models\PortfolioItem;
use App\Services\HtmlSanitizer;
use App\Services\ImageService;
use App\Support\Slug;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class PortfolioController extends Controller
{
    public function __construct(
        private readonly ImageService $images,
        private readonly HtmlSanitizer $sanitizer,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'between:1,500'],
        ]);

        $items = PortfolioItem::query()
            ->with('categories:id,name,slug')
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where('title', 'like', '%'.addcslashes($s, '%_\\').'%'))
            ->when($filters['category_id'] ?? null, fn ($q, $id) => $q->whereHas('categories', fn ($c) => $c->whereKey($id)))
            ->ordered()
            ->paginate($filters['per_page'] ?? 60);

        return PortfolioItemResource::collection($items);
    }

    public function show(PortfolioItem $item): PortfolioItemResource
    {
        return new PortfolioItemResource($item->load('categories:id,name,slug'));
    }

    public function store(PortfolioItemRequest $request): JsonResponse
    {
        $data = $request->validated();

        $item = DB::transaction(function () use ($request, $data) {
            $item = new PortfolioItem($this->fill($data));
            $item->slug = Slug::unique(PortfolioItem::class, $data['slug'] ?? $data['title']);
            $item->published_at = now();
            $item->sort_order = $data['sort_order'] ?? ((int) PortfolioItem::max('sort_order') + 1);
            $item->fill($this->images->storeUpload($request->file('image')));
            $item->save();
            $item->categories()->sync($data['category_ids'] ?? []);

            return $item;
        });

        return (new PortfolioItemResource($item->load('categories:id,name,slug')))
            ->response()->setStatusCode(201);
    }

    public function update(PortfolioItemRequest $request, PortfolioItem $item): PortfolioItemResource
    {
        $data = $request->validated();
        $oldPaths = null;

        DB::transaction(function () use ($request, $data, $item, &$oldPaths) {
            $item->fill($this->fill($data));

            if (! empty($data['slug']) && $data['slug'] !== $item->getOriginal('slug')) {
                $item->slug = Slug::unique(PortfolioItem::class, $data['slug'], $item->id);
            }
            if (array_key_exists('sort_order', $data) && $data['sort_order'] !== null) {
                $item->sort_order = $data['sort_order'];
            }
            if ($request->hasFile('image')) {
                $oldPaths = [$item->image_path, $item->thumb_path];
                $item->fill($this->images->storeUpload($request->file('image')));
            }

            $item->save();
            if ($request->has('category_ids')) {
                $item->categories()->sync($data['category_ids'] ?? []);
            }
        });

        if ($oldPaths) {
            $this->images->delete(...$oldPaths);
        }

        return new PortfolioItemResource($item->load('categories:id,name,slug'));
    }

    public function destroy(PortfolioItem $item): JsonResponse
    {
        $paths = [$item->image_path, $item->thumb_path];
        $item->delete();
        $this->images->delete(...$paths);

        return response()->json(null, 204);
    }

    /** Drag & drop ordering from the admin: { "ids": [5, 2, 9, ...] } */
    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'max:5000'],
            'ids.*' => ['integer', 'distinct', 'exists:portfolio_items,id'],
        ]);

        DB::transaction(function () use ($data) {
            foreach ($data['ids'] as $position => $id) {
                PortfolioItem::query()->whereKey($id)->update(['sort_order' => $position + 1]);
            }
        });

        return response()->json(['message' => 'Order saved']);
    }

    private function fill(array $data): array
    {
        $fields = array_intersect_key($data, array_flip([
            'title', 'meta_title', 'meta_description', 'external_url', 'is_published',
        ]));

        if (array_key_exists('description', $data)) {
            $fields['description'] = $this->sanitizer->clean($data['description']);
        }

        return $fields;
    }
}
