<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Page;
use App\Models\PortfolioItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_portfolio_list_is_ordered_and_filterable(): void
    {
        $adv = Category::factory()->create(['slug' => 'advertising']);
        $celeb = Category::factory()->create(['slug' => 'celebrity']);

        $b = PortfolioItem::factory()->create(['sort_order' => 2]);
        $a = PortfolioItem::factory()->create(['sort_order' => 1]);
        PortfolioItem::factory()->create(['is_published' => false]);

        $a->categories()->attach($adv);
        $b->categories()->attach($celeb);

        $this->getJson('/api/v1/portfolio')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $a->id);

        $this->getJson('/api/v1/portfolio?category=celebrity')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $b->id);
    }

    public function test_unpublished_item_is_not_found(): void
    {
        $item = PortfolioItem::factory()->create(['is_published' => false]);

        $this->getJson("/api/v1/portfolio/{$item->slug}")->assertNotFound();
    }

    public function test_site_and_page_endpoints(): void
    {
        Page::create(['title' => 'Contact', 'slug' => 'contact', 'content' => '<p>Hi</p>']);

        $this->getJson('/api/v1/site')->assertOk()->assertJsonPath('data.menu.0.slug', 'contact');
        $this->getJson('/api/v1/pages/contact')->assertOk()->assertJsonPath('data.title', 'Contact');
    }

    public function test_page_response_uses_current_host_for_legacy_storage_image_urls(): void
    {
        Page::create([
            'title' => 'Contact',
            'slug' => 'contact',
            'social_icons' => '<img src="https://api.jackychee.com/storage/wp-uploads/2016/10/social-fb.png">',
            'contact_info' => '<a href="https://api.jackychee.com/about">About</a>',
        ]);

        $this->getJson('/api/v1/pages/contact')
            ->assertOk()
            ->assertJsonPath(
                'data.social_icons',
                '<img src="'.rtrim(Storage::disk('public')->url('/'), '/').'/wp-uploads/2016/10/social-fb.png">'
            )
            ->assertJsonPath('data.contact_info', '<a href="https://api.jackychee.com/about">About</a>');
    }

    public function test_security_headers_are_sent(): void
    {
        $this->getJson('/api/v1/site')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_bad_category_input_is_rejected(): void
    {
        $this->getJson('/api/v1/portfolio?category='.urlencode("x' OR 1=1 --"))->assertUnprocessable();
    }
}
