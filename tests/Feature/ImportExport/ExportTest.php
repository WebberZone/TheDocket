<?php

namespace Tests\Feature\ImportExport;

use App\Models\Item;
use App\Models\User;
use App\Services\PocketImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_csv_export_has_pockets_shape(): void
    {
        $archived = Item::factory()->archived()->create([
            'title' => 'Comma, "quoted" title',
            'url' => 'https://example.com/a',
            'added_at' => Carbon::createFromTimestamp(1600000000),
        ]);
        $archived->attachTagNames(['one', 'two']);
        Item::factory()->create(['title' => 'Second', 'url' => 'https://example.com/b', 'added_at' => Carbon::createFromTimestamp(1700000000)]);

        $response = $this->actingAs(User::factory()->create())->get('/export/csv');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('attachment; filename=docket-export-', (string) $response->headers->get('Content-Disposition'));

        $rows = array_map(fn (string $line): array => str_getcsv($line, ',', '"', ''), array_filter(explode("\n", $response->streamedContent())));

        $this->assertSame(['title', 'url', 'time_added', 'tags', 'status'], $rows[0]);
        $this->assertSame(['Comma, "quoted" title', 'https://example.com/a', '1600000000', 'one|two', 'archive'], $rows[1]);
        $this->assertSame(['Second', 'https://example.com/b', '1700000000', '', 'unread'], $rows[2]);
    }

    public function test_csv_export_imports_back_unchanged(): void
    {
        Queue::fake();
        $item = Item::factory()->archived()->create(['url' => 'https://example.com/round-trip', 'added_at' => Carbon::createFromTimestamp(1650000000)]);
        $item->attachTagNames(['kept']);

        $csv = $this->actingAs(User::factory()->create())->get('/export/csv')->streamedContent();

        Item::query()->delete();

        $path = tempnam(sys_get_temp_dir(), 'export');
        file_put_contents($path, $csv);

        try {
            app(PocketImporter::class)->import($path);
        } finally {
            @unlink($path);
        }

        $restored = Item::query()->sole();
        $this->assertSame('https://example.com/round-trip', $restored->url);
        $this->assertSame(1650000000, $restored->added_at?->getTimestamp());
        $this->assertTrue($restored->isArchived());
        $this->assertSame(['kept'], $restored->tags->pluck('name')->all());
    }

    public function test_json_export_includes_everything(): void
    {
        $item = Item::factory()->fetched('Body text for the export.')->favourite()->create([
            'title' => 'Exported',
            'url' => 'https://example.com/json',
            'read_progress' => 0.5,
        ]);
        $item->attachTagNames(['json']);

        $response = $this->actingAs(User::factory()->create())->get('/export/json');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/json; charset=UTF-8');

        $data = json_decode($response->streamedContent(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('docket', $data['format']);
        $this->assertSame(1, $data['version']);
        $this->assertCount(1, $data['items']);

        $exported = $data['items'][0];
        $this->assertSame('Exported', $exported['title']);
        $this->assertSame('https://example.com/json', $exported['url']);
        $this->assertTrue($exported['is_favourite']);
        $this->assertSame(['json'], $exported['tags']);
        $this->assertSame('Body text for the export.', $exported['content_text']);
        $this->assertSame('<p>Body text for the export.</p>', $exported['content_html']);
        $this->assertSame(0.5, $exported['read_progress']);
        $this->assertSame('unread', $exported['status']);
    }

    public function test_an_empty_library_exports_valid_json(): void
    {
        $content = $this->actingAs(User::factory()->create())->get('/export/json')->streamedContent();

        $this->assertSame([], json_decode($content, true, flags: JSON_THROW_ON_ERROR)['items']);
    }
}
