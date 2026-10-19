<?php

namespace Tests\Feature\ImportExport;

use App\Enums\ItemSource;
use App\Enums\ItemStatus;
use App\Jobs\FetchItemContent;
use App\Models\Item;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * tests/Fixtures/pocket_export.csv has, in order: an archived item with
 * two tags; an unread item; one whose title is just its URL; a duplicate
 * of the first (different case and tracking parameters, extra tag); a
 * javascript: URL; an empty URL; and an item with a repeated tag.
 */
class PocketImportTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): string
    {
        return base_path('tests/Fixtures/pocket_export.csv');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_the_command_imports_dates_tags_and_status(): void
    {
        $this->artisan('docket:import-pocket', ['file' => $this->fixture()])
            ->expectsOutputToContain('Imported 4 new links; 1 already saved; 2 skipped as invalid. 4 queued for extraction.')
            ->assertSuccessful();

        $this->assertSame(4, Item::query()->count());

        $first = Item::query()->where('normalized_url', 'https://www.example.com/the-web-we-lost')->sole();
        $this->assertSame('The Web We Lost', $first->title);
        $this->assertSame(ItemStatus::Archived, $first->status);
        $this->assertSame(ItemSource::Pocket, $first->source);
        $this->assertSame(1356998400, $first->added_at?->getTimestamp());
        $this->assertSame(1356998400, $first->archived_at?->getTimestamp());
        // Tags from both rows for the same link.
        $this->assertSame(['essays', 'history', 'web'], $first->tags->pluck('name')->all());

        $second = Item::query()->where('url', 'https://blog.example.org/slow-reading')->sole();
        $this->assertSame('Slow Reading, Fast Living', $second->title);
        $this->assertSame(ItemStatus::Unread, $second->status);
        $this->assertSame(['reading'], $second->tags->pluck('name')->all());

        // Pocket uses the URL as the title for pages it never parsed.
        $this->assertNull(Item::query()->where('url', 'https://news.example.net/untitled-link')->sole()->title);

        $tagged = Item::query()->where('url', 'https://example.co.uk/tags')->sole();
        $this->assertSame(['Laravel', 'PHP'], $tagged->tags->pluck('name')->all());
    }

    public function test_extraction_is_queued_at_a_gentle_rate(): void
    {
        config(['docket.import.spacing_seconds' => 15]);

        $this->artisan('docket:import-pocket', ['file' => $this->fixture()])->assertSuccessful();

        $delays = [];
        Queue::assertPushed(FetchItemContent::class, function (FetchItemContent $job) use (&$delays): bool {
            $delays[] = (int) round(now()->diffInSeconds($job->delay, false));

            return true;
        });

        sort($delays);
        $this->assertSame([0, 15, 30, 45], $delays);
    }

    public function test_importing_the_same_file_twice_changes_nothing(): void
    {
        $this->artisan('docket:import-pocket', ['file' => $this->fixture()])->assertSuccessful();

        $snapshot = fn (): array => Item::query()->with('tags')->orderBy('id')->get()
            ->map(fn (Item $item): array => [
                $item->normalized_url,
                $item->title,
                $item->status->value,
                $item->added_at?->getTimestamp(),
                $item->tags->pluck('name')->all(),
            ])->all();

        $before = $snapshot();

        $this->artisan('docket:import-pocket', ['file' => $this->fixture()])
            ->expectsOutputToContain('Imported 0 new links; 5 already saved; 2 skipped as invalid. 0 queued for extraction.')
            ->assertSuccessful();

        $this->assertSame($before, $snapshot());
        $this->assertSame(6, Tag::query()->count());
        Queue::assertPushed(FetchItemContent::class, 4);
    }

    public function test_links_already_saved_keep_their_state_and_gain_tags(): void
    {
        $existing = Item::factory()->favourite()->create([
            'url' => 'https://blog.example.org/slow-reading',
            'normalized_url' => 'https://blog.example.org/slow-reading',
            'title' => 'My title',
            'added_at' => now()->subDay(),
        ]);

        $this->artisan('docket:import-pocket', ['file' => $this->fixture()])->assertSuccessful();

        $existing->refresh();
        $this->assertSame('My title', $existing->title);
        $this->assertTrue($existing->is_favourite);
        $this->assertTrue($existing->added_at->isYesterday());
        $this->assertSame(['reading'], $existing->tags->pluck('name')->all());
    }

    public function test_uploading_the_csv_in_the_browser(): void
    {
        $file = UploadedFile::fake()->createWithContent('part_000000.csv', (string) file_get_contents($this->fixture()));

        $this->actingAs(User::factory()->create())
            ->post('/import', ['file' => $file])
            ->assertRedirect('/import')
            ->assertSessionHas('status', 'Imported 4 new links; 1 already saved; 2 skipped as invalid. 4 queued for extraction.');

        $this->assertSame(4, Item::query()->count());
    }

    public function test_a_csv_without_a_url_column_is_rejected(): void
    {
        $file = UploadedFile::fake()->createWithContent('wrong.csv', "name,link\nFoo,https://example.com/\n");

        $this->actingAs(User::factory()->create())
            ->from('/import')
            ->post('/import', ['file' => $file])
            ->assertRedirect('/import')
            ->assertSessionHasErrors('file');

        $this->assertSame(0, Item::query()->count());

        $this->artisan('docket:import-pocket', ['file' => '/no/such/file.csv'])->assertFailed();
    }

    public function test_a_byte_order_mark_and_header_case_are_tolerated(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pocket');
        file_put_contents($path, "\u{FEFF}Title,URL,Time_Added,Tags,Status\nHello,https://example.com/bom,1700000000,,unread\n");

        try {
            $this->artisan('docket:import-pocket', ['file' => $path])->assertSuccessful();
        } finally {
            @unlink($path);
        }

        $this->assertSame('Hello', Item::query()->sole()->title);
    }
}
