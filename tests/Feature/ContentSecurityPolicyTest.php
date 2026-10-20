<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ContentSecurityPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function policy(TestResponse $response): string
    {
        $policy = $response->headers->get('Content-Security-Policy');
        $this->assertIsString($policy, 'No Content-Security-Policy header.');

        return $policy;
    }

    private function nonce(TestResponse $response): string
    {
        $this->assertSame(1, preg_match("/'nonce-([^']+)'/", $this->policy($response), $match));

        return $match[1];
    }

    /**
     * @return array<string, string> directive => sources
     */
    private function directives(TestResponse $response): array
    {
        $directives = [];

        foreach (explode(';', $this->policy($response)) as $directive) {
            [$name, $sources] = array_pad(explode(' ', trim($directive), 2), 2, '');
            $directives[$name] = $sources;
        }

        return $directives;
    }

    public function test_pages_send_a_strict_policy(): void
    {
        $directives = $this->directives($this->actingAs(User::factory()->create())->get('/'));

        $this->assertSame("'none'", $directives['default-src']);
        $this->assertStringStartsWith("'self' 'nonce-", $directives['script-src']);
        $this->assertStringNotContainsString('unsafe-inline', $directives['script-src']);
        $this->assertStringNotContainsString('unsafe-eval', $directives['script-src']);
        $this->assertStringNotContainsString('unsafe-inline', $directives['style-src']);
        $this->assertSame("'self'", $directives['form-action']);
        $this->assertSame("'self'", $directives['frame-ancestors']);
        $this->assertSame("'none'", $directives['base-uri']);
        $this->assertSame("'self' https: data:", $directives['img-src']);
    }

    public function test_the_nonce_is_fresh_on_every_request_and_on_every_script(): void
    {
        $user = User::factory()->create();

        $first = $this->actingAs($user)->get('/');
        $second = $this->actingAs($user)->get('/');

        $nonce = $this->nonce($first);
        $this->assertNotSame($nonce, $this->nonce($second));
        $this->assertGreaterThanOrEqual(16, strlen($nonce));

        preg_match_all('/<script\b[^>]*>/i', (string) $first->getContent(), $scripts);
        $this->assertNotEmpty($scripts[0]);

        foreach ($scripts[0] as $tag) {
            $this->assertStringContainsString('nonce="'.$nonce.'"', $tag, "Script without the nonce: {$tag}");
        }
    }

    public function test_no_page_relies_on_inline_handlers_or_style_attributes(): void
    {
        // Either would be blocked by the policy, so a page using one is
        // silently broken in the browser.
        Queue::fake();
        $user = User::factory()->create();
        $tag = Tag::factory()->create(['name' => 'reading', 'slug' => 'reading']);
        $item = Item::factory()->fetched()->favourite()->create();
        $item->tags()->attach($tag);
        Item::factory()->failed()->archived()->create();
        $user->createToken('Shortcut');

        $pages = [
            '/', '/archive', '/favourites', '/search?q=the', '/tags', "/tags/{$tag->id}",
            "/items/{$item->id}", '/import', '/settings', '/tools', '/profile',
            '/save?url='.urlencode('https://example.com/new'),
        ];

        foreach ($pages as $page) {
            $this->assertCompliant($this->actingAs($user)->get($page), $page);
        }

        $this->app['auth']->forgetGuards();
        $this->assertCompliant($this->get('/login'), '/login');
    }

    private function assertCompliant(TestResponse $response, string $page): void
    {
        $response->assertOk();
        $html = (string) $response->getContent();
        $nonce = $this->nonce($response);

        $this->assertDoesNotMatchRegularExpression('/<[^>]*\son[a-z]+\s*=/i', $html, "{$page} has an inline event handler.");
        $this->assertDoesNotMatchRegularExpression('/<[^>]*\sstyle\s*=/i', $html, "{$page} has an inline style attribute.");

        preg_match_all('/<script\b[^>]*>/i', $html, $scripts);
        foreach ($scripts[0] as $tag) {
            $this->assertStringContainsString('nonce="'.$nonce.'"', $tag, "{$page} has a script without the nonce.");
        }
    }

    public function test_the_installer_is_covered_and_its_styles_carry_the_nonce(): void
    {
        $envDir = sys_get_temp_dir().'/docket-csp-test-'.Str::random(8);
        mkdir($envDir);
        copy(base_path('.env.example'), $envDir.'/.env.example');
        $this->app->useEnvironmentPath($envDir);

        try {
            $response = $this->get('/install')->assertOk();
        } finally {
            @unlink($envDir.'/.env.example');
            @rmdir($envDir);
        }

        $this->assertStringContainsString('<style nonce="'.$this->nonce($response).'">', (string) $response->getContent());
    }

    public function test_stored_images_keep_their_own_tighter_policy(): void
    {
        Storage::fake(Item::DISK);
        $item = Item::factory()->create();
        $filename = sha1('x').'.png';
        Storage::disk(Item::DISK)->put("items/{$item->id}/{$filename}", 'png');

        $this->actingAs(User::factory()->create())
            ->get(route('items.image', ['item' => $item, 'filename' => $filename]))
            ->assertOk()
            ->assertHeader('Content-Security-Policy', "default-src 'none'");
    }

    public function test_the_vite_dev_server_is_allowed_while_it_runs(): void
    {
        $hot = tempnam(sys_get_temp_dir(), 'hot');
        file_put_contents($hot, 'http://localhost:5173');
        Vite::useHotFile($hot);

        try {
            $directives = $this->directives($this->get('/login'));
        } finally {
            @unlink($hot);
        }

        $this->assertStringContainsString('http://localhost:5173', $directives['script-src']);
        $this->assertStringContainsString('ws://localhost:5173', $directives['connect-src']);
        $this->assertStringContainsString("'unsafe-inline'", $directives['style-src']);
        $this->assertStringNotContainsString('unsafe-inline', $directives['script-src']);
    }
}
