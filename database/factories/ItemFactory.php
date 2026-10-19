<?php

namespace Database\Factories;

use App\Enums\FetchStatus;
use App\Enums\ItemStatus;
use App\Models\Item;
use App\Services\UrlNormaliser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
{
    public function definition(): array
    {
        $url = 'https://'.fake()->unique()->domainName().'/'.fake()->slug();

        return [
            'url' => $url,
            'normalized_url' => app(UrlNormaliser::class)->normalise($url),
            'title' => fake()->sentence(6),
            'status' => ItemStatus::Unread,
            'fetch_status' => FetchStatus::Pending,
            'added_at' => fake()->dateTimeBetween('-1 year'),
        ];
    }

    /**
     * An item whose article has been fetched and extracted.
     */
    public function fetched(?string $text = null): static
    {
        return $this->state(function () use ($text): array {
            $text ??= fake()->paragraphs(4, true);
            $words = str_word_count($text);

            return [
                'site_name' => fake()->company(),
                'excerpt' => mb_substr($text, 0, 160),
                'content_html' => '<p>'.e($text).'</p>',
                'content_text' => $text,
                'word_count' => $words,
                'reading_time' => max(1, (int) ceil($words / 230)),
                'fetch_status' => FetchStatus::Fetched,
                'fetched_at' => now(),
            ];
        });
    }

    public function archived(): static
    {
        return $this->state(fn (): array => [
            'status' => ItemStatus::Archived,
            'archived_at' => now(),
        ]);
    }

    public function favourite(): static
    {
        return $this->state(fn (): array => [
            'is_favourite' => true,
            'favourited_at' => now(),
        ]);
    }

    public function failed(string $error = 'Connection timed out'): static
    {
        return $this->state(fn (): array => [
            'fetch_status' => FetchStatus::Failed,
            'fetch_error' => $error,
            'fetch_attempts' => 1,
        ]);
    }
}
