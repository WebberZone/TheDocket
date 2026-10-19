<?php

namespace Tests\Feature;

use App\Enums\FetchStatus;
use App\Jobs\FetchItemContent;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_tools_page_lists_the_available_tools(): void
    {
        $response = $this->actingAs(User::factory()->create())->get('/tools');

        $response->assertOk();
        $response->assertSee('Maintenance tools');
        $response->assertSee('Clear cache');
        $response->assertSee('Fetch failed and pending items');
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
    }

    public function test_unknown_tool_returns_404_rather_than_running_anything(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/tools/rm-rf-everything')
            ->assertNotFound();
    }

    public function test_refetch_tool_queues_failed_and_pending_items_only(): void
    {
        Queue::fake();

        $failed = Item::factory()->failed()->create();
        $pending = Item::factory()->create();
        Item::factory()->fetched()->create();

        $this->actingAs(User::factory()->create())
            ->post('/tools/refetch')
            ->assertRedirect(route('tools.index'));

        Queue::assertPushed(FetchItemContent::class, 2);
        Queue::assertPushed(FetchItemContent::class, fn (FetchItemContent $job) => $job->item->is($failed));
        Queue::assertPushed(FetchItemContent::class, fn (FetchItemContent $job) => $job->item->is($pending));

        $this->assertSame(FetchStatus::Pending, $failed->refresh()->fetch_status);
        $this->assertNull($failed->fetch_error);
    }

    public function test_refetch_command_targets_one_item_or_everything(): void
    {
        Queue::fake();

        $item = Item::factory()->fetched()->create();
        Item::factory()->fetched()->create();

        $this->artisan('docket:refetch', ['item' => $item->id])
            ->expectsOutputToContain("Queued item {$item->id}")
            ->assertSuccessful();

        Queue::assertPushed(FetchItemContent::class, 1);

        $this->artisan('docket:refetch', ['--all' => true])
            ->expectsOutputToContain('Queued 2 item(s)')
            ->assertSuccessful();

        $this->artisan('docket:refetch', ['item' => 999999])
            ->expectsOutputToContain('Item 999999 not found.')
            ->assertFailed();
    }
}
