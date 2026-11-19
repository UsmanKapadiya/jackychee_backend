<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // WordPress taxonomy "portfolio_category" (URL slug: /portfolio-type/...)
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->unsignedBigInteger('wp_term_id')->nullable()->unique();
            $table->timestamps();
        });

        // WordPress custom post type "portfolio" (Easy Portfolio plugin)
        Schema::create('portfolio_items', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();          // sanitised HTML (post_content)
            $table->string('meta_title')->nullable();         // _ep_metatitle
            $table->string('meta_description', 500)->nullable(); // _ep_metadesc (also image alt)
            $table->string('external_url', 2048)->nullable(); // _ep_portfoliourl
            $table->string('image_path')->nullable();         // full / lightbox image
            $table->string('thumb_path')->nullable();         // grid thumbnail
            $table->unsignedInteger('image_width')->nullable();
            $table->unsignedInteger('image_height')->nullable();
            $table->integer('sort_order')->default(0)->index(); // menu_order (Post Types Order plugin)
            $table->boolean('is_published')->default(true)->index();
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('wp_post_id')->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('category_portfolio_item', function (Blueprint $table) {
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('portfolio_item_id')->constrained()->cascadeOnDelete();
            $table->primary(['category_id', 'portfolio_item_id']);
        });

        // WordPress pages (About, Contact ...) incl. ACF fields social_icons / contact_info / portfolio
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->longText('content')->nullable();
            $table->text('social_icons')->nullable();
            $table->text('contact_info')->nullable();
            $table->text('extra_content')->nullable();
            $table->boolean('show_title')->default(true);  // "Toggle the Title" plugin
            $table->boolean('show_in_menu')->default(true);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_published')->default(true)->index();
            $table->unsignedBigInteger('wp_post_id')->nullable()->unique();
            $table->timestamps();
        });

        // Site options: blogname, blogdescription, footer text, logo ...
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('pages');
        Schema::dropIfExists('category_portfolio_item');
        Schema::dropIfExists('portfolio_items');
        Schema::dropIfExists('categories');
    }
};
