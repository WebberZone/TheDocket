<?php

namespace Tests\Feature\Items;

use App\Enums\FetchStatus;
use App\Enums\ItemStatus;
use App\Jobs\FetchItemContent;
use App\Models\Item;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OrganisingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_the_home_page_is_the_unread_list(): void
    {
        $unread = Item::factory()->create(['title' => 'Still to read']);
        Item::factory()->archived()->create(['title' => 'Done with this']);

        $this->actingAs($this->user)->get('/')
            ->assertOk()
            ->assertSee($unread->title)
            ->assertDontSee('Done with this');
    }

    public function test_archive_and_favourites_lists(): void
    {
        Item::factory()->create(['title' => 'Unread one']);
        Item::factory()->archived()->create(['title' => 'Archived one']);
        Item::factory()->archived()->favourite()->create(['title' => 'Archived favourite']);

        $this->actingAs($this->user)->get('/archive')
            ->assertSee('Archived one')
            ->assertSee('Archived favourite')
            ->assertDontSee('Unread one');

        $this->actingAs($this->user)->get('/favourites')
            ->assertSee('Archived favourite')
            ->assertDontSee('Archived one')
            ->assertDontSee('Unread one');
    }

    public function test_lists_sort_by_newest_oldest_or_longest(): void
    {
        Item::factory()->create(['title' => 'Piece Bravo', 'added_at' => now()->subDays(2), 'word_count' => 9000]);
        Item::factory()->create(['title' => 'Piece Charlie', 'added_at' => now()->subDay(), 'word_count' => 100]);
        Item::factory()->create(['title' => 'Piece Alpha', 'added_at' => now()->subDays(3), 'word_count' => 500]);

        $this->actingAs($this->user)->get('/')->assertSeeInOrder(['Piece Charlie', 'Piece Bravo', 'Piece Alpha']);
        $this->actingAs($this->user)->get('/?sort=oldest')->assertSeeInOrder(['Piece Alpha', 'Piece Bravo', 'Piece Charlie']);
        $this->actingAs($this->user)->get('/?sort=longest')->assertSeeInOrder(['Piece Bravo', 'Piece Alpha', 'Piece Charlie']);
        $this->actingAs($this->user)->get('/?sort=nonsense')->assertSeeInOrder(['Piece Charlie', 'Piece Bravo', 'Piece Alpha']);
    }

    public function test_archive_unarchive_favourite_and_unfavourite(): void
    {
        $item = Item::factory()->create();

        $this->actingAs($this->user)->from('/')->post("/items/{$item->id}/archive")->assertRedirect('/');
        $this->assertSame(ItemStatus::Archived, $item->refresh()->status);
        $this->assertNotNull($item->archived_at);

        $this->actingAs($this->user)->from('/archive')->delete("/items/{$item->id}/archive")->assertRedirect('/archive');
        $this->assertSame(ItemStatus::Unread, $item->refresh()->status);
        $this->assertNull($item->archived_at);

        $this->actingAs($this->user)->postJson("/items/{$item->id}/favourite")
            ->assertOk()
            ->assertJsonPath('is_favourite', true);
        $this->assertTrue($item->refresh()->is_favourite);

        $this->actingAs($this->user)->deleteJson("/items/{$item->id}/favourite")->assertJsonPath('is_favourite', false);
        $this->assertFalse($item->refresh()->is_favourite);
        $this->assertNull($item->favourited_at);
    }

    public function test_archiving_from_the_reader_returns_to_the_list(): void
    {
        $item = Item::factory()->create();

        $this->actingAs($this->user)
            ->post("/items/{$item->id}/archive", ['then' => 'list'])
            ->assertRedirect(route('items.index'));
    }

    public function test_tags_are_added_in_the_reader_and_listed(): void
    {
        $item = Item::factory()->create();

        $this->actingAs($this->user)
            ->patch("/items/{$item->id}", ['title' => 'Renamed', 'tags' => 'Laravel, php, laravel, '])
            ->assertSessionHasNoErrors();

        $item->refresh();
        $this->assertSame('Renamed', $item->title);
        $this->assertSame(['Laravel', 'php'], $item->tags->pluck('name')->all());

        $this->actingAs($this->user)->get('/tags/laravel')->assertOk()->assertSee('Renamed');
        $this->actingAs($this->user)->get('/tags')->assertOk()->assertSee('Laravel')->assertSee('1 item');

        // Clearing the field removes every tag (the tags themselves stay).
        $this->actingAs($this->user)->patch("/items/{$item->id}", ['tags' => '']);
        $this->assertCount(0, $item->refresh()->tags);
        $this->assertSame(2, Tag::query()->count());
    }

    public function test_tags_can_be_renamed_merged_and_deleted(): void
    {
        $a = Item::factory()->create();
        $b = Item::factory()->create();
        $a->attachTagNames(['js']);
        $b->attachTagNames(['JavaScript']);

        $js = Tag::query()->where('slug', 'js')->sole();
        $this->actingAs($this->user)->patch("/tags/{$js->slug}", ['name' => 'javascript'])->assertRedirect('/tags');

        $this->assertModelMissing($js);
        $merged = Tag::query()->where('slug', 'javascript')->sole();
        $this->assertSame(2, $merged->items()->count());

        $this->actingAs($this->user)->patch("/tags/{$merged->slug}", ['name' => 'Web dev']);
        $this->assertSame('Web dev', $merged->refresh()->name);
        $this->assertSame('web-dev', $merged->slug);

        $this->actingAs($this->user)->delete('/tags/web-dev')->assertRedirect('/tags');
        $this->assertSame(0, Tag::query()->count());
        $this->assertSame(2, Item::query()->count());
    }

    public function test_bulk_archive_favourite_tag_and_retry(): void
    {
        Queue::fake();
        $items = Item::factory()->count(3)->failed()->create();
        $ids = $items->pluck('id')->take(2)->all();

        foreach (['archive', 'favourite', 'retry'] as $action) {
            $this->actingAs($this->user)->from('/')
                ->post('/items/bulk', ['action' => $action, 'ids' => $ids])
                ->assertRedirect('/')
                ->assertSessionHasNoErrors();
        }

        $this->actingAs($this->user)->post('/items/bulk', ['action' => 'tag', 'ids' => $ids, 'tags' => 'batch, weekend']);

        foreach ($items as $index => $item) {
            $item->refresh();
            $selected = $index < 2;

            $this->assertSame($selected ? ItemStatus::Archived : ItemStatus::Unread, $item->status);
            $this->assertSame($selected, $item->is_favourite);
            $this->assertSame($selected ? FetchStatus::Pending : FetchStatus::Failed, $item->fetch_status);
            $this->assertSame($selected ? ['batch', 'weekend'] : [], $item->tags->pluck('name')->all());
        }

        Queue::assertPushed(FetchItemContent::class, 2);

        $this->actingAs($this->user)->post('/items/bulk', ['action' => 'unarchive', 'ids' => $ids]);
        $this->assertSame(0, Item::query()->archived()->count());
    }

    public function test_bulk_delete_removes_items_and_their_files(): void
    {
        Storage::fake(Item::DISK);
        $doomed = Item::factory()->create();
        $kept = Item::factory()->create();
        Storage::disk(Item::DISK)->put("items/{$doomed->id}/".sha1('a').'.png', 'x');

        $this->actingAs($this->user)
            ->post('/items/bulk', ['action' => 'delete', 'ids' => [$doomed->id]])
            ->assertSessionHas('status', 'Delete: 1 item.');

        $this->assertModelMissing($doomed);
        $this->assertModelExists($kept);
        Storage::disk(Item::DISK)->assertMissing("items/{$doomed->id}");
    }

    public function test_bulk_actions_need_a_valid_action_and_a_selection(): void
    {
        $item = Item::factory()->create();

        $this->actingAs($this->user)->from('/')->post('/items/bulk', ['action' => 'archive'])
            ->assertSessionHasErrors('ids');
        $this->actingAs($this->user)->from('/')->post('/items/bulk', ['action' => 'drop-tables', 'ids' => [$item->id]])
            ->assertSessionHasErrors('action');
        $this->actingAs($this->user)->from('/')->post('/items/bulk', ['action' => 'tag', 'ids' => [$item->id]])
            ->assertSessionHasErrors('tags');

        $this->assertSame(ItemStatus::Unread, $item->refresh()->status);
    }

    public function test_deleting_a_single_item(): void
    {
        $item = Item::factory()->create();

        $this->actingAs($this->user)->from('/archive')->delete("/items/{$item->id}")->assertRedirect('/archive');
        $this->assertModelMissing($item);

        // From the reader there is nothing to go back to, so it's the list.
        $other = Item::factory()->create();
        $this->actingAs($this->user)->from("/items/{$other->id}")->delete("/items/{$other->id}")->assertRedirect('/');
    }
}
