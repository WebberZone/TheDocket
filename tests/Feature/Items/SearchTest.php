<?php

namespace Tests\Feature\Items;

use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SQLite FTS5 over title, site name and article text, kept in sync by
 * triggers on the items table.
 */
class SearchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_finds_items_by_title_site_and_article_text(): void
    {
        Item::factory()->create(['title' => 'Gardening for beginners']);
        Item::factory()->fetched('Sourdough needs patience and a warm kitchen.')->create(['title' => 'Bread notes']);
        Item::factory()->create(['title' => 'Unrelated', 'site_name' => 'The Allotment Gazette']);

        $this->actingAs($this->user)->get('/search?q=gardening')->assertSee('Gardening for beginners')->assertDontSee('Bread notes');
        $this->actingAs($this->user)->get('/search?q=sourdough')->assertSee('Bread notes')->assertDontSee('Gardening');
        $this->actingAs($this->user)->get('/search?q=allotment')->assertSee('Unrelated');
    }

    public function test_matches_word_prefixes_and_requires_every_word(): void
    {
        Item::factory()->create(['title' => 'Programming in Laravel']);
        Item::factory()->create(['title' => 'Programming in Rust']);

        $this->actingAs($this->user)->get('/search?q=progr')
            ->assertSee('Programming in Laravel')
            ->assertSee('Programming in Rust');

        $this->actingAs($this->user)->get('/search?q=programming+laravel')
            ->assertSee('Programming in Laravel')
            ->assertDontSee('Programming in Rust');
    }

    public function test_ignores_accents_and_case(): void
    {
        Item::factory()->create(['title' => 'Café Society']);

        $this->actingAs($this->user)->get('/search?q=CAFE')->assertSee('Café Society');
    }

    public function test_the_index_follows_updates_and_deletes(): void
    {
        $item = Item::factory()->create(['title' => 'Before the edit']);

        $item->update(['title' => 'After the edit']);
        $this->actingAs($this->user)->get('/search?q=before')->assertDontSee('After the edit');
        $this->actingAs($this->user)->get('/search?q=after')->assertSee('After the edit');

        // A bulk query-builder write skips model events; the triggers still see it.
        DB::table('items')->where('id', $item->id)->update(['content_text' => 'zeppelin']);
        $this->actingAs($this->user)->get('/search?q=zeppelin')->assertSee('After the edit');

        $item->delete();
        $this->assertSame(0, (int) DB::table('items_fts')->whereRaw('items_fts MATCH ?', ['zeppelin'])->count());
    }

    public function test_search_within_a_list_stays_in_that_list(): void
    {
        Item::factory()->create(['title' => 'Kettle unread']);
        Item::factory()->archived()->create(['title' => 'Kettle archived']);

        $this->actingAs($this->user)->get('/archive?q=kettle')
            ->assertSee('Kettle archived')
            ->assertDontSee('Kettle unread');

        $this->actingAs($this->user)->get('/search?q=kettle')
            ->assertSee('Kettle archived')
            ->assertSee('Kettle unread');
    }

    public function test_title_matches_rank_above_body_matches(): void
    {
        Item::factory()->fetched('This article mentions owls once, in passing, among many other birds and trees.')->create(['title' => 'Birds of the hedgerow']);
        Item::factory()->create(['title' => 'Owls']);

        $this->actingAs($this->user)->get('/search?q=owls')->assertSeeInOrder(['Owls', 'Birds of the hedgerow']);
    }

    public function test_awkward_input_never_breaks_the_query(): void
    {
        Item::factory()->create(['title' => 'C++ for "experts"']);

        foreach (['"', 'AND', 'NOT OR', '*', 'c++', 'experts"', '(', 'title:experts', "'; DROP TABLE items; --"] as $q) {
            $this->actingAs($this->user)->get('/search?q='.urlencode($q))->assertOk();
        }

        $this->assertSame(1, Item::query()->count());
    }
}
