<?php

namespace Tests\Feature\Items;

use App\Enums\ItemSource;
use App\Jobs\FetchItemContent;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * POST /api/items with a personal access token: the iOS Shortcut path.
 */
class ApiItemsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    private function token(): string
    {
        return User::factory()->create()->createToken('Shortcut')->plainTextToken;
    }

    public function test_requests_without_a_token_are_refused(): void
    {
        $this->postJson('/api/items', ['url' => 'https://example.com/'])
            ->assertUnauthorized();

        $this->assertSame(0, Item::query()->count());
    }

    public function test_requests_with_a_wrong_token_are_refused(): void
    {
        $this->token();

        $this->withToken('1|not-the-right-token')
            ->postJson('/api/items', ['url' => 'https://example.com/'])
            ->assertUnauthorized();

        $this->assertSame(0, Item::query()->count());
    }

    public function test_saves_a_link_with_title_and_tags(): void
    {
        $response = $this->withToken($this->token())
            ->postJson('/api/items', [
                'url' => 'https://example.com/long-read',
                'title' => 'A long read',
                'tags' => ['weekend', 'essays'],
            ]);

        $response->assertCreated()
            ->assertJsonPath('created', true)
            ->assertJsonPath('data.url', 'https://example.com/long-read')
            ->assertJsonPath('data.title', 'A long read')
            ->assertJsonPath('data.status', 'unread')
            ->assertJsonPath('data.tags', ['essays', 'weekend']);

        $item = Item::query()->sole();
        $this->assertSame(ItemSource::Api, $item->source);
        $response->assertJsonPath('data.web_url', route('items.show', $item));

        Queue::assertPushed(FetchItemContent::class);
    }

    public function test_tags_may_be_a_comma_separated_string(): void
    {
        $this->withToken($this->token())
            ->postJson('/api/items', ['url' => 'https://example.com/', 'tags' => 'one, two,,one'])
            ->assertCreated()
            ->assertJsonPath('data.tags', ['one', 'two']);
    }

    public function test_an_existing_link_returns_200_and_is_not_duplicated(): void
    {
        $token = $this->token();

        $this->withToken($token)->postJson('/api/items', ['url' => 'https://example.com/a?utm_medium=share'])->assertCreated();

        $this->withToken($token)
            ->postJson('/api/items', ['url' => 'https://EXAMPLE.com/a'])
            ->assertOk()
            ->assertJsonPath('created', false);

        $this->assertSame(1, Item::query()->count());
    }

    public function test_invalid_input_gets_a_422_with_errors(): void
    {
        $token = $this->token();

        $this->withToken($token)->postJson('/api/items', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('url');

        $this->withToken($token)->postJson('/api/items', ['url' => 'file:///etc/passwd'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('url');

        $this->withToken($token)->postJson('/api/items', ['url' => 'https://example.com/', 'tags' => 42])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tags');

        $this->assertSame(0, Item::query()->count());
    }

    public function test_tokens_are_created_and_revoked_from_settings(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/settings/tokens', ['name' => 'iPhone']);
        $response->assertRedirect('/settings');

        $plain = session('plain_token');
        $this->assertIsString($plain);
        $this->assertSame(1, $user->tokens()->count());

        // Shown once, on the page right after creation.
        $this->actingAs($user)->get('/settings')->assertSee($plain);
        $this->actingAs($user)->get('/settings')->assertDontSee($plain);

        // Drop the logged-in session so only the token can authenticate.
        $this->app['auth']->forgetGuards();
        $this->withToken($plain)->postJson('/api/items', ['url' => 'https://example.com/'])->assertCreated();

        $tokenId = $user->tokens()->sole()->id;
        $this->app['auth']->forgetGuards();
        $this->actingAs($user)->delete("/settings/tokens/{$tokenId}")->assertRedirect('/settings');

        $this->assertSame(0, $user->tokens()->count());

        $this->app['auth']->forgetGuards();
        $this->withToken($plain)->postJson('/api/items', ['url' => 'https://example.com/other'])->assertUnauthorized();
    }
}
