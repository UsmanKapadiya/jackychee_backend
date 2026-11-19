<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_routes_require_a_token(): void
    {
        $this->getJson('/api/v1/admin/portfolio')->assertUnauthorized();
        $this->postJson('/api/v1/admin/pages', ['title' => 'x'])->assertUnauthorized();
    }

    public function test_login_returns_token_and_wrong_password_fails(): void
    {
        User::factory()->create(['email' => 'admin@example.com', 'password' => 'Secret-Pass-123!']);

        $this->postJson('/api/v1/admin/login', ['email' => 'admin@example.com', 'password' => 'nope'])
            ->assertUnprocessable();

        $this->postJson('/api/v1/admin/login', ['email' => 'admin@example.com', 'password' => 'Secret-Pass-123!'])
            ->assertOk()
            ->assertJsonStructure(['token', 'expires_at', 'user' => ['id', 'email']]);
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/admin/login', ['email' => 'a@b.com', 'password' => 'x']);
        }

        $this->postJson('/api/v1/admin/login', ['email' => 'a@b.com', 'password' => 'x'])->assertStatus(429);
    }

    public function test_page_html_is_sanitised(): void
    {
        $token = User::factory()->create()->createToken('t')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/admin/pages', [
            'title' => 'About',
            'content' => '<p onclick="alert(1)">Hello</p><script>alert(1)</script><a href="javascript:alert(1)">x</a>',
        ])->assertCreated()
            ->assertJsonPath('data.content', fn (string $html) => ! str_contains($html, '<script')
                && ! str_contains($html, 'onclick')
                && ! str_contains($html, 'javascript:'));
    }

    public function test_php_file_disguised_as_image_is_rejected(): void
    {
        Storage::fake('public');
        $token = User::factory()->create()->createToken('t')->plainTextToken;

        $evil = UploadedFile::fake()->createWithContent('shell.php.jpg', '<?php system($_GET["c"]); ?>');

        $this->withToken($token)->post('/api/v1/admin/portfolio', [
            'title' => 'Evil', 'image' => $evil,
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('image');
    }

    public function test_svg_upload_is_rejected(): void
    {
        Storage::fake('public');
        $token = User::factory()->create()->createToken('t')->plainTextToken;

        $svg = UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $this->withToken($token)->post('/api/v1/admin/portfolio', [
            'title' => 'Svg', 'image' => $svg,
        ], ['Accept' => 'application/json'])->assertUnprocessable();
    }
}
