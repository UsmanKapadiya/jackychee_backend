<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\HtmlSanitizer;
use App\Services\ImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SettingController extends Controller
{
    public function show(): JsonResponse
    {
        $settings = Setting::allAsArray();
        $settings['logo_url'] = $settings['logo_path'] ? Storage::disk('public')->url($settings['logo_path']) : null;

        return response()->json(['data' => $settings]);
    }

    public function update(Request $request, HtmlSanitizer $sanitizer): JsonResponse
    {
        $data = $request->validate([
            'site_name' => ['required', 'string', 'max:120'],
            'site_tagline' => ['nullable', 'string', 'max:255'],
            'footer_text' => ['nullable', 'string', 'max:5000'],
            'seo_description' => ['nullable', 'string', 'max:500'],
        ]);

        foreach ($data as $key => $value) {
            Setting::put($key, $key === 'footer_text' ? $sanitizer->clean($value) : $value);
        }

        return $this->show();
    }

    public function uploadLogo(Request $request, ImageService $images): JsonResponse
    {
        $request->validate([
            'logo' => ['required', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048', 'dimensions:max_width=4000,max_height=4000'],
        ]);

        $old = Setting::allAsArray()['logo_path'];
        $stored = $images->storeFromPath($request->file('logo')->getRealPath());
        $images->delete($stored['thumb_path'] !== $stored['image_path'] ? $stored['thumb_path'] : null);
        Setting::put('logo_path', $stored['image_path']);
        $images->delete($old);

        return $this->show();
    }
}
