<?php

namespace App\Console\Commands;

use App\Enums\FetchStatus;
use App\Jobs\FetchItemContent;
use App\Models\Item;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

#[Signature('docket:refetch
    {item? : ID of a single item to fetch again}
    {--all : Fetch every item again, including ones that already have their article}')]
#[Description('Queue extraction again for failed and pending items (or one item, or all)')]
class DocketRefetchCommand extends Command
{
    public function handle(): int
    {
        if ($this->argument('item') !== null) {
            $item = Item::query()->find($this->argument('item'));

            if ($item === null) {
                $this->components->error("Item {$this->argument('item')} not found.");

                return SymfonyCommand::FAILURE;
            }

            $this->queue($item);
            $this->components->info("Queued item {$item->id} ({$item->url}).");

            return SymfonyCommand::SUCCESS;
        }

        $query = Item::query()->when(
            ! $this->option('all'),
            fn (Builder $query) => $query->whereIn('fetch_status', [FetchStatus::Failed->value, FetchStatus::Pending->value])
        );

        $spacing = max(0, (int) settings('import.spacing_seconds'));
        $count = 0;

        foreach ($query->orderBy('id')->lazy() as $item) {
            $this->queue($item, $count * $spacing);
            $count++;
        }

        $this->components->info("Queued {$count} item(s) for extraction.");

        return SymfonyCommand::SUCCESS;
    }

    protected function queue(Item $item, int $delaySeconds = 0): void
    {
        $item->forceFill([
            'fetch_status' => FetchStatus::Pending,
            'fetch_error' => null,
            'fetch_attempts' => 0,
        ])->save();

        FetchItemContent::dispatch($item)->delay(now()->addSeconds($delaySeconds));
    }
}
