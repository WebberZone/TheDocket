<?php

namespace Tests\Feature\Items;

use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_reader_shows_the_article_with_its_controls(): void
    {
        $item = Item::factory()->fetched('A quiet paragraph about reading.')->create([
            'title' => 'On reading',
            'url' => 'https://example.com/on-reading',
            'read_progress' => 0.42,
        ]);

        $this->actingAs(User::factory()->create())
            ->get("/items/{$item->id}")
            ->assertOk()
            ->assertSee('On reading')
            ->assertSee('<p>A quiet paragraph about reading.</p>', false)
            ->assertSee('href="https://example.com/on-reading"', false)
            ->assertSee('x-data="reader"', false)
            ->assertSee('data-progress-url="'.route('items.progress', $item).'"', false)
            ->assertSee('data-progress="0.42"', false)
            ->assertSee('Serif')
            ->assertSee('Sepia')
            ->assertSee('Dark');

        $this->assertNotNull($item->refresh()->last_read_at);
    }

    public function test_reading_progress_is_saved(): void
    {
        $item = Item::factory()->fetched()->create();
        $user = User::factory()->create();

        $this->actingAs($user)->post("/items/{$item->id}/progress", ['progress' => 0.6789])->assertNoContent();
        $this->assertEqualsWithDelta(0.6789, $item->refresh()->read_progress, 0.0001);

        $this->actingAs($user)->post("/items/{$item->id}/progress", ['progress' => 1.5])->assertSessionHasErrors('progress');
        $this->actingAs($user)->postJson("/items/{$item->id}/progress", ['progress' => 'half'])->assertUnprocessable();
        $this->assertEqualsWithDelta(0.6789, $item->refresh()->read_progress, 0.0001);
    }

    public function test_an_item_without_an_article_offers_the_original_and_a_retry(): void
    {
        $item = Item::factory()->failed('The site responded with HTTP 403.')->create(['url' => 'https://example.com/paywalled']);

        $this->actingAs(User::factory()->create())
            ->get("/items/{$item->id}")
            ->assertOk()
            ->assertSee('The site responded with HTTP 403.')
            ->assertSee('Open the original')
            ->assertSee('Try again');
    }
}
