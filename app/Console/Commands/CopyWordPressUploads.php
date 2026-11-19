<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Finder\Finder;

/**
 * Copies the old wp-content/uploads images into storage/app/public/wp-uploads,
 * which is where jackychee_data.sql expects them.
 *
 *  - ONLY real images are copied (checked by content). Any .php or other file that may have
 *    been planted in the old uploads folder is skipped and listed.
 *  - Non-ASCII file names (e.g. 大S.jpg) are renamed to ASCII-safe names (u5927S.jpg), the same
 *    rule used to generate jackychee_data.sql. Names garbled by zip tools ("#U5927S.jpg") map
 *    to the same result.
 *
 *   php artisan wp:copy-uploads /home/user/public_html/jackychee/wp-content/uploads
 */
class CopyWordPressUploads extends Command
{
    protected $signature = 'wp:copy-uploads {source? : Old wp-content/uploads folder (defaults to WP_UPLOADS_PATH)}';

    protected $description = 'Copy old WordPress upload images into storage/app/public/wp-uploads (images only)';

    public static function safePath(string $path): string
    {
        $path = preg_replace_callback('/#U([0-9a-fA-F]{4})/', fn ($m) => 'u'.strtolower($m[1]), $path) ?? $path;

        return preg_replace_callback('/[^\x00-\x7F]/u', fn ($m) => sprintf('u%04x', mb_ord($m[0], 'UTF-8')), $path) ?? $path;
    }

    public function handle(): int
    {
        $source = $this->argument('source') ?: config('jackychee.wordpress.uploads_path');
        if (! $source || ! is_dir($source)) {
            $this->error('Source folder not found.');

            return self::FAILURE;
        }

        $disk = Storage::disk('public');
        $copied = $existing = 0;
        $rejected = [];

        $files = (new Finder)->files()->in($source)->ignoreDotFiles(false);
        $bar = $this->output->createProgressBar();

        foreach ($files as $file) {
            $bar->advance();
            $relative = str_replace('\\', '/', $file->getRelativePathname());
            $ext = strtolower($file->getExtension());

            $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)
                && ($info = @getimagesize($file->getRealPath())) !== false
                && in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true);

            if (! $isImage) {
                $rejected[] = $relative;

                continue;
            }

            $target = 'wp-uploads/'.self::safePath($relative);
            if ($disk->exists($target) && $disk->size($target) === $file->getSize()) {
                $existing++;

                continue;
            }

            $disk->put($target, file_get_contents($file->getRealPath()));
            $copied++;
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Copied {$copied} images ({$existing} already there).");

        if ($rejected !== []) {
            $this->warn(count($rejected).' file(s) were NOT copied because they are not images:');
            foreach (array_slice($rejected, 0, 50) as $r) {
                $this->line("  - {$r}");
            }
            $this->comment('If any of these are .php files, the old site was infected - do not reuse them.');
        }

        return self::SUCCESS;
    }
}
