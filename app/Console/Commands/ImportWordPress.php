<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Page;
use App\Models\PortfolioItem;
use App\Models\Setting;
use App\Models\User;
use App\Services\HtmlSanitizer;
use App\Services\ImageService;
use App\Support\Slug;
use App\Support\WordPressContent;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Imports the old WordPress site (Easy Portfolio + ACF + pages + options) into the new tables.
 *
 *   php artisan wp:import                 # everything
 *   php artisan wp:import --skip-images   # data only (fast test run)
 *   php artisan wp:import --refresh-images
 *
 * Safe to run more than once: rows are matched on their WordPress IDs.
 */
class ImportWordPress extends Command
{
    protected $signature = 'wp:import
        {--skip-images : Do not copy / resize images}
        {--refresh-images : Re-import images even if an item already has one}
        {--skip-users : Do not import WordPress user accounts}';

    protected $description = 'Import portfolio items, categories, pages and settings from the old WordPress database';

    private Connection $wp;

    private string $p;

    private ?string $uploads;

    public function handle(ImageService $images, HtmlSanitizer $sanitizer): int
    {
        $this->p = (string) config('jackychee.wordpress.table_prefix', 'wp_');
        if (! preg_match('/^[A-Za-z0-9_]+$/', $this->p)) {
            $this->error('WP_TABLE_PREFIX may only contain letters, numbers and underscores.');

            return self::FAILURE;
        }

        $this->uploads = config('jackychee.wordpress.uploads_path');
        if (! $this->option('skip-images') && (! $this->uploads || ! is_dir($this->uploads))) {
            $this->error('WP_UPLOADS_PATH is not set or the folder does not exist. Use --skip-images to import data only.');

            return self::FAILURE;
        }

        try {
            $this->wp = DB::connection('wordpress');
            $this->wp->getPdo();
        } catch (Throwable $e) {
            $this->error('Cannot connect to the WordPress database: '.$e->getMessage());

            return self::FAILURE;
        }

        $termMap = $this->importCategories();
        $this->importPortfolio($termMap, $images, $sanitizer);
        $this->importPages($sanitizer);
        $this->importSettings($images, $sanitizer);
        if (! $this->option('skip-users')) {
            $this->importUsers();
        }

        $this->newLine();
        $this->info('WordPress import finished.');
        $this->line('Portfolio items: '.PortfolioItem::count().' | Categories: '.Category::count().' | Pages: '.Page::count());

        return self::SUCCESS;
    }

    /** @return array<int,int> wp term_taxonomy_id => category id */
    private function importCategories(): array
    {
        $this->components->task('Categories (portfolio_category)');

        $terms = $this->wp->table("{$this->p}terms as t")
            ->join("{$this->p}term_taxonomy as tt", 'tt.term_id', '=', 't.term_id')
            ->where('tt.taxonomy', 'portfolio_category')
            ->orderBy('t.name')
            ->get(['t.term_id', 't.name', 't.slug', 'tt.term_taxonomy_id']);

        $map = [];
        foreach ($terms->values() as $i => $term) {
            $category = Category::query()->firstOrNew(['wp_term_id' => $term->term_id]);
            $category->name = html_entity_decode($term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (! $category->exists) {
                $category->slug = Slug::unique(Category::class, rawurldecode($term->slug) ?: $category->name, null, 'category');
                $category->sort_order = $i + 1;
            }
            $category->save();
            $map[(int) $term->term_taxonomy_id] = $category->id;
        }

        return $map;
    }

    /** @param array<int,int> $termMap */
    private function importPortfolio(array $termMap, ImageService $images, HtmlSanitizer $sanitizer): void
    {
        $posts = $this->wp->table("{$this->p}posts")
            ->where('post_type', 'portfolio')
            ->whereIn('post_status', ['publish', 'draft', 'pending', 'private'])
            ->orderBy('menu_order')->orderBy('post_date')
            ->get(['ID', 'post_title', 'post_name', 'post_content', 'post_status', 'menu_order', 'post_date']);

        $this->info("Portfolio items found: {$posts->count()}");
        if ($posts->isEmpty()) {
            return;
        }

        $ids = $posts->pluck('ID')->all();
        $meta = $this->meta($ids, ['_ep_portfoliourl', '_ep_metatitle', '_ep_metadesc', '_thumbnail_id']);
        $files = $this->attachmentFiles($meta->pluck('_thumbnail_id')->filter()->all());

        $relations = $this->wp->table("{$this->p}term_relationships")
            ->whereIn('object_id', $ids)
            ->whereIn('term_taxonomy_id', array_keys($termMap) ?: [0])
            ->get(['object_id', 'term_taxonomy_id'])
            ->groupBy('object_id');

        $bar = $this->output->createProgressBar($posts->count());
        $failed = [];

        foreach ($posts as $post) {
            $m = $meta->get($post->ID, []);
            $item = PortfolioItem::query()->firstOrNew(['wp_post_id' => $post->ID]);

            $title = html_entity_decode((string) $post->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $item->fill([
                'title' => $title !== '' ? $title : 'Untitled',
                'description' => $sanitizer->clean(WordPressContent::convert($post->post_content, $this->uploads)),
                'meta_title' => $m['_ep_metatitle'] ?? null,
                'meta_description' => isset($m['_ep_metadesc']) ? mb_substr($m['_ep_metadesc'], 0, 500) : null,
                'external_url' => $this->safeUrl($m['_ep_portfoliourl'] ?? null),
                'sort_order' => (int) $post->menu_order,
                'is_published' => $post->post_status === 'publish',
                'published_at' => Carbon::parse($post->post_date),
            ]);
            if (! $item->exists) {
                $item->slug = Slug::unique(PortfolioItem::class, rawurldecode((string) $post->post_name) ?: $item->title);
            }

            $file = isset($m['_thumbnail_id']) ? ($files[(int) $m['_thumbnail_id']] ?? null) : null;
            $needsImage = ! $item->image_path || $this->option('refresh-images');
            if ($file && $needsImage && ! $this->option('skip-images')) {
                try {
                    $source = $this->uploadFile($file);
                    $old = [$item->image_path, $item->thumb_path];
                    $item->fill($images->storeFromPath($source));
                    $images->delete(...$old);
                } catch (Throwable $e) {
                    $failed[] = "#{$post->ID} {$file}: {$e->getMessage()}";
                }
            }

            $item->save();

            $catIds = collect($relations->get($post->ID, []))
                ->map(fn ($r) => $termMap[(int) $r->term_taxonomy_id] ?? null)
                ->filter()->unique()->values()->all();
            $item->categories()->sync($catIds);

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        foreach ($failed as $msg) {
            $this->warn("Image skipped: {$msg}");
        }
    }

    private function importPages(HtmlSanitizer $sanitizer): void
    {
        $this->components->task('Pages + ACF fields');

        $frontPageId = (int) $this->wpOption('page_on_front');
        $pages = $this->wp->table("{$this->p}posts")
            ->where('post_type', 'page')->where('post_status', 'publish')
            ->get(['ID', 'post_title', 'post_name', 'post_content', 'menu_order']);

        if ($pages->isEmpty()) {
            return;
        }

        $meta = $this->meta($pages->pluck('ID')->all(), ['social_icons', 'contact_info', 'portfolio', 'toggle_page_title']);
        $menuOrder = $this->primaryMenuPageOrder();

        foreach ($pages as $wpPage) {
            $isFront = (int) $wpPage->ID === $frontPageId;
            // The front page is the portfolio grid itself. Pages that are not in the menu are
            // skipped because hackers often add hidden spam pages (pass them through the admin if needed).
            if ($isFront || ($menuOrder !== [] && ! array_key_exists((int) $wpPage->ID, $menuOrder))) {
                $this->warn("  skipped page #{$wpPage->ID} \"{$wpPage->post_title}\"".($isFront ? ' (front page)' : ' (not in menu)'));

                continue;
            }
            $m = $meta->get($wpPage->ID, []);
            $page = Page::query()->firstOrNew(['wp_post_id' => $wpPage->ID]);

            $page->fill([
                'title' => html_entity_decode((string) $wpPage->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?: 'Untitled',
                'content' => $sanitizer->clean(WordPressContent::convert($wpPage->post_content, $this->uploads)),
                'social_icons' => $sanitizer->clean(WordPressContent::convert($m['social_icons'] ?? null, $this->uploads)),
                'contact_info' => $sanitizer->clean(WordPressContent::convert($m['contact_info'] ?? null, $this->uploads)),
                'extra_content' => $sanitizer->clean(WordPressContent::convert($m['portfolio'] ?? null, $this->uploads)),
                // Toggle the Title plugin: "1" or empty = show, "0" = hide
                'show_title' => in_array((string) ($m['toggle_page_title'] ?? ''), ['', '1'], true),
                // The front page is the portfolio grid itself - it is not a menu page
                'show_in_menu' => ! $isFront && ($menuOrder === [] || array_key_exists((int) $wpPage->ID, $menuOrder)),
                'sort_order' => $menuOrder[(int) $wpPage->ID] ?? (int) $wpPage->menu_order,
                'is_published' => true,
            ]);
            if (! $page->exists) {
                $page->slug = Slug::unique(Page::class, rawurldecode((string) $wpPage->post_name) ?: $page->title, null, 'page');
            }
            $page->save();
        }
    }

    private function importSettings(ImageService $images, HtmlSanitizer $sanitizer): void
    {
        $this->components->task('Site settings (name, tagline, footer, logo)');

        Setting::put('site_name', html_entity_decode((string) $this->wpOption('blogname'), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?: 'Jacky Chee');
        Setting::put('site_tagline', html_entity_decode((string) $this->wpOption('blogdescription'), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?: null);

        $footer = $this->wpOption('theme_footer_text');
        if ($footer !== null) {
            Setting::put('footer_text', $sanitizer->clean(WordPressContent::convert($footer, $this->uploads)));
        }

        if ($this->option('skip-images')) {
            return;
        }

        // theme_mods_cheez is a PHP-serialized array. allowed_classes=false prevents object injection.
        $mods = $this->wpOption('theme_mods_cheez');
        $logoId = 0;
        if (is_string($mods) && $mods !== '') {
            $data = @unserialize($mods, ['allowed_classes' => false]);
            $logoId = is_array($data) ? (int) ($data['custom_logo'] ?? 0) : 0;
        }
        $logoFile = $logoId ? ($this->attachmentFiles([$logoId])[$logoId] ?? null) : null;
        if ($logoFile === null && is_file($this->uploads.'/2016/09/main-logo.png')) {
            $logoFile = '2016/09/main-logo.png';
        }

        if ($logoFile) {
            try {
                $stored = $images->storeFromPath($this->uploadFile($logoFile));
                $images->delete($stored['thumb_path'] !== $stored['image_path'] ? $stored['thumb_path'] : null);
                $old = Setting::allAsArray()['logo_path'];
                Setting::put('logo_path', $stored['image_path']);
                if ($old && $old !== $stored['image_path']) {
                    $images->delete($old);
                }
            } catch (Throwable $e) {
                $this->warn('Logo skipped: '.$e->getMessage());
            }
        }
    }

    /**
     * Copy WordPress administrators with their existing password hash (they keep working,
     * see App\Support\WordPressPassword). Accounts without a valid e-mail or with a fake
     * registration date are skipped - those are typical backdoor accounts created by malware.
     */
    private function importUsers(): void
    {
        $this->components->task('Users');

        $capKey = $this->p.'capabilities';
        $users = $this->wp->table("{$this->p}users as u")
            ->leftJoin("{$this->p}usermeta as m", fn ($j) => $j->on('m.user_id', '=', 'u.ID')->where('m.meta_key', $capKey))
            ->get(['u.ID', 'u.user_login', 'u.user_pass', 'u.user_email', 'u.display_name', 'u.user_registered', 'm.meta_value as caps']);

        foreach ($users as $wpUser) {
            $caps = @unserialize((string) $wpUser->caps, ['allowed_classes' => false]);
            $isAdmin = is_array($caps) && ! empty($caps['administrator']);
            $email = mb_strtolower(trim((string) $wpUser->user_email));
            $suspicious = ! filter_var($email, FILTER_VALIDATE_EMAIL)
                || (string) $wpUser->user_registered < '2000-01-01';

            if (! $isAdmin || $suspicious) {
                $this->warn("  skipped user #{$wpUser->ID} \"{$wpUser->user_login}\"".($suspicious ? ' (no e-mail / fake date - likely backdoor)' : ' (not an administrator)'));

                continue;
            }

            $user = User::query()->where('wp_user_id', $wpUser->ID)->first()
                ?? User::query()->where('email', $email)->first()
                ?? new User;

            $user->forceFill([
                'name' => html_entity_decode((string) $wpUser->display_name, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?: $wpUser->user_login,
                'username' => $wpUser->user_login,
                'email' => $email,
                'role' => 'admin',
                'wp_user_id' => $wpUser->ID,
            ]);
            // keep the WordPress hash exactly as it is (bypasses the "hashed" cast)
            $user->setRawAttributes(array_merge($user->getAttributes(), ['password' => $wpUser->user_pass]));
            $user->save();
            $this->line("  + user {$wpUser->user_login} <{$email}>");
        }
    }

    /* ---------------------------------------------------------------- helpers */

    /** @return Collection<int, array<string,string>> post_id => [meta_key => meta_value] */
    private function meta(array $postIds, array $keys): Collection
    {
        return $this->wp->table("{$this->p}postmeta")
            ->whereIn('post_id', $postIds)->whereIn('meta_key', $keys)
            ->get(['post_id', 'meta_key', 'meta_value'])
            ->groupBy('post_id')
            ->map(fn ($rows) => $rows->pluck('meta_value', 'meta_key')->all());
    }

    /** @return array<int,string> attachment id => relative upload path */
    private function attachmentFiles(array $attachmentIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $attachmentIds)));
        if ($ids === []) {
            return [];
        }

        return $this->wp->table("{$this->p}postmeta")
            ->whereIn('post_id', $ids)->where('meta_key', '_wp_attached_file')
            ->pluck('meta_value', 'post_id')
            ->mapWithKeys(fn ($v, $k) => [(int) $k => (string) $v])
            ->all();
    }

    /** @return array<int,int> page id => position in the primary nav menu */
    private function primaryMenuPageOrder(): array
    {
        $items = $this->wp->table("{$this->p}posts as p")
            ->join("{$this->p}postmeta as obj", fn ($j) => $j->on('obj.post_id', '=', 'p.ID')->where('obj.meta_key', '_menu_item_object'))
            ->join("{$this->p}postmeta as oid", fn ($j) => $j->on('oid.post_id', '=', 'p.ID')->where('oid.meta_key', '_menu_item_object_id'))
            ->where('p.post_type', 'nav_menu_item')->where('p.post_status', 'publish')
            ->where('obj.meta_value', 'page')
            ->orderBy('p.menu_order')
            ->get(['oid.meta_value as page_id', 'p.menu_order']);

        $order = [];
        foreach ($items as $row) {
            $order[(int) $row->page_id] ??= (int) $row->menu_order;
        }

        return $order;
    }

    private function wpOption(string $name): ?string
    {
        $value = $this->wp->table("{$this->p}options")->where('option_name', $name)->value('option_value');

        return $value === null ? null : (string) $value;
    }

    /** Resolve an upload path safely (no ../ escapes out of the uploads folder). */
    private function uploadFile(string $relative): string
    {
        $base = realpath((string) $this->uploads);
        $full = realpath($base.DIRECTORY_SEPARATOR.ltrim($relative, '/\\'));

        if ($base === false || $full === false || ! str_starts_with($full, $base) || ! is_file($full)) {
            throw new \RuntimeException('file not found in uploads folder');
        }

        return $full;
    }

    private function safeUrl(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true) ? $url : null;
    }
}
