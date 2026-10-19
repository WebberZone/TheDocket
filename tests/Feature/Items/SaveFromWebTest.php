<?php

namespace Tests\Feature\Items;

use App\Enums\ItemSource;
use App\Enums\ItemStatus;
use App\Jobs\FetchItemContent;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Pasting a link into the app, and the de-duplication every save path
 * shares (it all goes through ItemSaver).
 */
class SaveFromWebTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_pasting_a_link_saves_it_and_queues_extraction(): void
    {
        $this->actingAs(User::factory()->create())
            ->from('/')
            ->post('/items', ['url' => 'https://example.com/post?utm_source=feed', 'tags' => 'reading, Later'])
            ->assertRedirect('/')
            ->assertSessionHas('status', 'Saved. The article is being fetched.');

        $item = Item::query()->sole();

        $this->assertSame('https://example.com/post?utm_source=feed', $item->url);
        $this->assertSame('https://example.com/post', $item->normalized_url);
        $this->assertSame(ItemSource::Web, $item->source);
        $this->assertSame(ItemStatus::Unread, $item->status);
        $this->assertNotNull($item->added_at);
        $this->assertSame(['Later', 'reading'], $item->tags->pluck('name')->all());

        Queue::assertPushed(FetchItemContent::class, fn (FetchItemContent $job) => $job->item->is($item));
    }

    public function test_a_link_without_a_scheme_is_saved_as_https(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/items', ['url' => 'example.com/no-scheme']);

        $this->assertSame('https://example.com/no-scheme', Item::query()->sole()->url);
    }

    public function test_saving_the_same_link_again_does_not_duplicate_it(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/items', ['url' => 'https://Example.com:443/a/?b=2&a=1#comments']);

        $item = Item::query()->sole();
        $item->archive();
        $item->forceFill(['added_at' => now()->subYear()])->save();

        $this->actingAs($user)
            ->post('/items', ['url' => 'https://example.com/a/?a=1&b=2&fbclid=abc', 'tags' => 'again'])
            ->assertSessionHas('status', 'Already saved; moved it back to the top of your list.');

        $this->assertSame(1, Item::query()->count());

        $item->refresh();
        $this->assertSame(ItemStatus::Unread, $item->status, 'Saving again moves it back to unread, as Pocket did.');
        $this->assertTrue($item->added_at->isToday());
        $this->assertSame(['again'], $item->tags->pluck('name')->all());

        Queue::assertPushed(FetchItemContent::class, 1);
    }

    public function test_invalid_links_are_rejected_with_a_message(): void
    {
        $user = User::factory()->create();

        foreach (['javascript:alert(1)', 'ftp://example.com/file', 'not a url at all', ''] as $url) {
            $this->actingAs($user)
                ->from('/')
                ->post('/items', ['url' => $url])
                ->assertRedirect('/')
                ->assertSessionHasErrors('url');
        }

        $this->assertSame(0, Item::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_guests_cannot_save(): void
    {
        $this->post('/items', ['url' => 'https://example.com/'])->assertRedirect('/login');

        $this->assertSame(0, Item::query()->count());
    }
}
