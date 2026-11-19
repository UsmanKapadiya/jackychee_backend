<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PortfolioItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        // multipart/form-data sends booleans and arrays as strings
        if ($this->has('is_published')) {
            $this->merge(['is_published' => filter_var($this->input('is_published'), FILTER_VALIDATE_BOOLEAN)]);
        }
        if (is_string($this->input('category_ids'))) {
            $ids = array_filter(explode(',', $this->input('category_ids')), 'strlen');
            $this->merge(['category_ids' => array_map('intval', $ids)]);
        }
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post') && ! $this->route('item');
        $maxKb = (int) config('jackychee.images.max_upload_kb');

        return [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:190', 'alpha_dash:ascii'],
            'description' => ['nullable', 'string', 'max:100000'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'external_url' => ['nullable', 'url:http,https', 'max:2048'],
            'sort_order' => ['nullable', 'integer', 'between:-100000,100000'],
            'is_published' => ['sometimes', 'boolean'],
            'category_ids' => ['nullable', 'array', 'max:50'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
            // SVG is intentionally NOT allowed (it can carry JavaScript)
            'image' => [$creating ? 'required' : 'nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'mimetypes:image/jpeg,image/png,image/webp,image/gif', "max:{$maxKb}", 'dimensions:max_width=12000,max_height=12000'],
        ];
    }
}
