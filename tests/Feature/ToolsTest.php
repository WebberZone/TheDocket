<?php

namespace Tests\Feature;

use App\Jobs\ExtractReceiptData;
use App\Models\Expense;
use App\Models\Receipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ToolsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>|null  $ocrJson
     */
    private function makeReceipt(?array $ocrJson = null): Receipt
    {
        $expense = Expense::factory()->create();

        return $expense->receipts()->create(
            [
                'path' => "receipts/{$expense->id}/receipt.jpg",
                'original_filename' => 'receipt.jpg',
                'content_type' => 'image/jpeg',
                'byte_size' => 100,
                'ocr_json' => $ocrJson,
            ]
        );
    }

    public function test_tools_page_lists_the_available_tools(): void
    {
        $response = $this->actingAs(User::factory()->create())->get('/tools');

        $response->assertOk();
        $response->assertSee('Maintenance tools');
        $response->assertSee('Clear cache');
        $response->assertSee('Re-queue missing OCR');
        $response->assertSee('Run backup now');
    }

    public function test_tools_page_requires_authentication(): void
    {
        $this->get('/tools')->assertRedirect('/login');
        $this->post('/tools/clear-cache')->assertRedirect('/login');
    }

    public function test_running_a_tool_flashes_its_console_output(): void
    {
        $response = $this->actingAs(User::factory()->create())->post('/tools/clear-cache');

        $response->assertRedirect(route('tools.index'));
        $response->assertSessionHas('tool_ran', 'Clear cache');
        $response->assertSessionHas('tool_exit_code', 0);
        $response->assertSessionHas('tool_output');

        $this->followingRedirects()
            ->actingAs(User::factory()->create())
            ->post('/tools/clear-cache')
            ->assertSee('Clear cache')
            ->assertSee('Clearing cached bootstrap files');
    }

    public function test_rebuild_caches_flashes_output(): void
    {
        $response = $this->actingAs(User::factory()->create())->post('/tools/optimize');

        $response->assertRedirect();
        $response->assertSessionHas('tool_ran', 'Rebuild caches');
        $response->assertSessionHas('tool_exit_code', 0);
        $response->assertSessionHas('tool_output');
    }

    public function test_unknown_tool_returns_404_rather_than_running_anything(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/tools/rm-rf-everything')
            ->assertNotFound();
    }

    public function test_ocr_tool_queues_jobs_for_receipts_without_results(): void
    {
        Queue::fake();

        $pending = $this->makeReceipt();
        $this->makeReceipt(['supplier' => 'Tesco']);

        $this->actingAs(User::factory()->create())
            ->post('/tools/ocr-requeue')
            ->assertRedirect(route('tools.index'));

        Queue::assertPushed(ExtractReceiptData::class, 1);
        Queue::assertPushed(
            ExtractReceiptData::class,
            fn (ExtractReceiptData $job) => $job->receipt->is($pending)
        );
    }

    public function test_docket_ocr_command_requeues_all_receipts_with_the_all_option(): void
    {
        Queue::fake();

        $this->makeReceipt();
        $this->makeReceipt(['supplier' => 'Tesco']);

        $this->artisan('docket:ocr', ['--all' => true])
            ->expectsOutputToContain('Queued OCR for all 2 receipt(s).')
            ->assertSuccessful();

        Queue::assertPushed(ExtractReceiptData::class, 2);
    }

    public function test_docket_ocr_command_targets_a_single_receipt_by_id(): void
    {
        Queue::fake();

        $receipt = $this->makeReceipt(['supplier' => 'Tesco']);

        $this->artisan('docket:ocr', ['receipt' => $receipt->id])
            ->expectsOutputToContain("Queued OCR for receipt {$receipt->id}")
            ->assertSuccessful();

        Queue::assertPushed(
            ExtractReceiptData::class,
            fn (ExtractReceiptData $job) => $job->receipt->is($receipt)
        );
    }

    public function test_docket_ocr_command_fails_cleanly_for_a_missing_receipt(): void
    {
        Queue::fake();

        $this->artisan('docket:ocr', ['receipt' => 999999])
            ->expectsOutputToContain('Receipt 999999 not found.')
            ->assertFailed();

        Queue::assertNothingPushed();
    }
}
