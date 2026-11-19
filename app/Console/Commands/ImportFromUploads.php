<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\PortfolioItem;
use App\Services\ImageService;
use App\Support\Slug;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * FALLBACK importer for when the WordPress database (SQL dump) is not available.
 *
 * Builds portfolio items straight from the original image files in wp-content/uploads,
 * using the naming convention of this site:  021_adv_Ocean-Park.jpg
 *     021  -> sort order
 *     adv  -> category code (adv / celeb / fnb / per, see config/jackychee.php)
 *     Ocean-Park -> title
 *
 * WordPress' resized copies (e.g. -150x150.jpg) are ignored.
 *
 *   php artisan wp:import-uploads            # only files with a known category code
 *   php artisan wp:import-uploads --all      # also files like 0076_Darlie_... (no category)
 */
class ImportFromUploads extends Command
{
    protected $signature = 'wp:import-uploads
        {path? : Uploads folder (defaults to WP_UPLOADS_PATH)}
        {--all : Also import images without a known category code (they are saved unpublished)}';

    protected $description = 'Create portfolio items from the original image files (use when no WordPress DB dump exists)';

    public function handle(ImageService $images): int
    {
        $path = $this->argument('path') ?: config('jackychee.wordpress.uploads_path');
        if (! $path || ! is_dir($path)) {
            $this->error('Uploads folder not found. Pass it as an argument or set WP_UPLOADS_PATH.');

            return self::FAILURE;
        }

        $codes = config('jackychee.filename_categories');
        $categories = [];
        $i = 0;
        foreach ($codes as $code => $name) {
            $categories[$code] = Category::query()->firstOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'sort_order' => ++$i]
            );
        }

        $finder = (new Finder)->files()->in($path)
            ->name('/\.(jpe?g|png|webp|gif)$/i')
            ->notName('/-\d+x\d+\.(jpe?g|png|webp|gif)$/i')
            ->sortByName();

        $all = iterator_to_array($finder, false);
        $names = array_flip(array_map(fn ($f) => $f->getRelativePathname(), $all));
        $created = 0;
        $skipped = 0;

        foreach ($all as $file) {
            $filename = $file->getFilename();

            // "...-re-1.jpg" is WordPress' copy of an already uploaded "...-re.jpg"
            $withoutCopySuffix = preg_replace('/-\d+(\.[a-z]+)$/i', '$1', $file->getRelativePathname());
            if ($withoutCopySuffix !== $file->getRelativePathname() && isset($names[$withoutCopySuffix])) {
                $skipped++;

                continue;
            }

            if (! preg_match('/^(\d+)[a-z]?_([A-Za-z]+)[_\-]?(.*)\.[a-z]+$/i', $filename, $m)) {
                $skipped++; // logos, dummy images etc.

                continue;
            }

            [, $number, $code, $rest] = $m;
            $code = strtolower($code);
            $known = isset($categories[$code]);

            if (! $known && ! $this->option('all')) {
                $skipped++;

                continue;
            }

            $title = $known ? $rest : $code.' '.$rest;
            $title = trim(preg_replace('/[\s_\-]+/', ' ', preg_replace('/(\.jpe?g|-re|_re)+$/i', '', $title) ?? '') ?? '');
            $title = $title !== '' ? $title : "Work {$number}";

            $sourceKey = 'uploads:'.$file->getRelativePathname();
            $item = PortfolioItem::query()->where('meta_title', $sourceKey)->first();
            if ($item) {
                continue; // already imported
            }

            try {
                $stored = $images->storeFromPath($file->getRealPath());
            } catch (Throwable $e) {
                $this->warn("Skipped {$filename}: {$e->getMessage()}");

                continue;
            }

            $item = PortfolioItem::create([
                'title' => $title,
                'slug' => Slug::unique(PortfolioItem::class, $title),
                'meta_title' => $sourceKey, // remembers the source file so re-runs do not duplicate
                'meta_description' => $title,
                'sort_order' => (int) $number,
                'is_published' => $known,
                'published_at' => now(),
                ...$stored,
            ]);

            if ($known) {
                $item->categories()->sync([$categories[$code]->id]);
            }

            $created++;
            $this->line("  + {$filename}");
        }

        $this->info("Created {$created} portfolio items ({$skipped} files skipped).");
        $this->comment('Check titles, categories and order in the admin panel - file names are only a best guess.');

        return self::SUCCESS;
    }
}
