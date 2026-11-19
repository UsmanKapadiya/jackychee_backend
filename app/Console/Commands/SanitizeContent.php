<?php

namespace App\Console\Commands;

use App\Models\Page;
use App\Models\PortfolioItem;
use App\Models\Setting;
use App\Services\HtmlSanitizer;
use Illuminate\Console\Command;

/**
 * Runs every stored HTML field through the server-side HTML sanitizer.
 * Run once after importing jackychee_data.sql (or any SQL import that bypassed the API).
 */
class SanitizeContent extends Command
{
    protected $signature = 'content:sanitize';

    protected $description = 'Sanitise all stored HTML (pages, portfolio descriptions, footer) against XSS';

    public function handle(HtmlSanitizer $sanitizer): int
    {
        $changed = 0;

        foreach (Page::query()->cursor() as $page) {
            foreach (['content', 'social_icons', 'contact_info', 'extra_content'] as $field) {
                $page->{$field} = $sanitizer->clean($page->{$field});
            }
            if ($page->isDirty()) {
                $page->save();
                $changed++;
            }
        }

        foreach (PortfolioItem::query()->whereNotNull('description')->cursor() as $item) {
            $item->description = $sanitizer->clean($item->description);
            if ($item->isDirty()) {
                $item->save();
                $changed++;
            }
        }

        $footer = Setting::allAsArray()['footer_text'];
        $clean = $sanitizer->clean($footer);
        if ($clean !== $footer) {
            Setting::put('footer_text', $clean);
            $changed++;
        }

        $this->info("Sanitised. {$changed} record(s) changed.");

        return self::SUCCESS;
    }
}
