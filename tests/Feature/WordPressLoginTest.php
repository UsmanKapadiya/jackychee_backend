<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WordPressLoginTest extends TestCase
{
    use RefreshDatabase;

    /** phpass hash of "Wp-Password-1", exactly as WordPress stores it */
    private const string WP_HASH = '$P$BQ7rT9xZaM0cP1r4CKTlxIkrh2Cr25.';

    private function insertWordPressUser(string $role = 'admin'): int
    {
        return DB::table('users')->insertGetId([
            'name' => 'jackychee', 'username' => 'jackychee', 'email' => 'owner@example.com',
            'password' => self::WP_HASH, 'role' => $role, 'wp_user_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_wordpress_user_can_log_in_with_username_and_hash_is_upgraded(): void
    {
        $id = $this->insertWordPressUser();

        $this->postJson('/api/v1/admin/login', ['login' => 'jackychee', 'password' => 'Wp-Password-1'])
            ->assertOk()->assertJsonStructure(['token']);

        $this->assertStringStartsWith('$2y$', DB::table('users')->where('id', $id)->value('password'));

        // still works after the upgrade, now by e-mail
        $this->postJson('/api/v1/admin/login', ['login' => 'owner@example.com', 'password' => 'Wp-Password-1'])->assertOk();
    }

    public function test_wrong_password_for_wordpress_user_fails(): void
    {
        $this->insertWordPressUser();

        $this->postJson('/api/v1/admin/login', ['login' => 'jackychee', 'password' => 'nope'])->assertUnprocessable();
    }

    public function test_non_admin_role_is_refused(): void
    {
        $this->insertWordPressUser('subscriber');

        $this->postJson('/api/v1/admin/login', ['login' => 'jackychee', 'password' => 'Wp-Password-1'])
            ->assertUnprocessable();
    }
}
