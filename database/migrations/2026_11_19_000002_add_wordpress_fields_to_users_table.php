<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // WordPress users log in with "username or email" - keep both working
            $table->string('username', 60)->nullable()->unique()->after('name');
            // Only "admin" users may sign in to the admin panel
            $table->string('role', 20)->default('admin')->after('password');
            $table->unsignedBigInteger('wp_user_id')->nullable()->unique()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropUnique(['wp_user_id']);
            $table->dropColumn(['username', 'role', 'wp_user_id']);
        });
    }
};
