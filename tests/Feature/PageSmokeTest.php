<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Renders every page once so a Blade error can't hide from the suite.
 */
class PageSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_pages_render(): void
    {
        $user = User::factory()->create();
        $tag = Tag::factory()->create(['name' => 'reading', 'slug' => 'reading']);

        $fetched = Item::factory()->fetched()->favourite()->create();
        $fetched->tags()->attach($tag);
        $pending = Item::factory()->create();
        $failed = Item::factory()->failed()->archived()->create();

        $pages = [
            '/',
            '/?sort=oldest',
            '/?sort=longest',
            '/archive',
            '/favourites',
            '/search?q=the',
            '/search?q=the&sort=newest',
            '/search',
            '/tags',
            "/tags/{$tag->id}",
            "/items/{$fetched->id}",
            "/items/{$pending->id}",
            "/items/{$failed->id}",
            '/import',
            '/settings',
            '/tools',
            '/profile',
        ];

        foreach ($pages as $page) {
            $this->actingAs($user)->get($page)->assertOk();
        }
    }

    public function test_guests_are_sent_to_the_login_screen(): void
    {
        foreach (['/', '/archive', '/favourites', '/tags', '/import', '/settings', '/export/csv'] as $page) {
            $this->get($page)->assertRedirect('/login');
        }
    }
}
