<?php

namespace Tests\Feature\Items;

use App\Enums\ItemSource;
use App\Jobs\FetchItemContent;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SaveFromBookmarkletAndShareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_the_bookmarklet_saves_the_page_and_shows_a_confirmation(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/save?url='.urlencode('https://example.com/article').'&title='.urlencode('An article | Example'))
            ->assertOk()
            ->assertSee('Saved to Docket')
            ->assertSee('An article | Example');

        $item = Item::query()->sole();
        $this->assertSame(ItemSource::Bookmarklet, $item->source);
        $this->assertSame('An article | Example', $item->title);

        Queue::assertPushed(FetchItemContent::class);
    }

    public function test_the_bookmarklet_reports_a_link_that_is_already_saved(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/save?url='.urlencode('https://example.com/article'));

        $this->actingAs($user)
            ->get('/save?url='.urlencode('https://example.com/article#section-2'))
            ->assertOk()
            ->assertSee('Already in Docket');

        $this->assertSame(1, Item::query()->count());
    }

    public function test_the_bookmarklet_rejects_pages_it_cannot_save(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/save?url='.urlencode('chrome://settings'))
            ->assertStatus(422)
            ->assertSee('Not saved');

        $this->assertSame(0, Item::query()->count());
    }

    public function test_the_bookmarklet_sends_guests_to_log_in_and_back(): void
    {
        $url = '/save?url='.urlencode('https://example.com/article');

        $this->get($url)->assertRedirect('/login');

        $user = User::factory()->create(['password' => 'password']);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect($url);
    }

    public function test_android_share_with_the_link_in_the_text_field(): void
    {
        // Most Android apps put the link in "text", often after a headline.
        $this->actingAs(User::factory()->create())
            ->get('/share?title='.urlencode('Great read').'&text='.urlencode('Have a look at this: https://example.com/story?id=7. Worth it'))
            ->assertRedirect('/')
            ->assertSessionHas('status', 'Saved. The article is being fetched.');

        $item = Item::query()->sole();
        $this->assertSame('https://example.com/story?id=7', $item->url);
        $this->assertSame(ItemSource::Share, $item->source);
        $this->assertSame('Great read', $item->title);
    }

    public function test_android_share_with_the_link_in_the_url_field(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/share?url='.urlencode('https://example.com/direct'))
            ->assertRedirect('/');

        $this->assertSame('https://example.com/direct', Item::query()->sole()->url);
    }

    public function test_android_share_without_a_link_saves_nothing(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/share?text='.urlencode('just some words'))
            ->assertRedirect('/')
            ->assertSessionHas('error');

        $this->assertSame(0, Item::query()->count());
    }

    public function test_the_manifest_registers_the_share_target(): void
    {
        $manifest = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true);

        $this->assertSame('/share', $manifest['share_target']['action']);
        $this->assertSame('GET', $manifest['share_target']['method']);
        $this->assertSame(['title' => 'title', 'text' => 'text', 'url' => 'url'], $manifest['share_target']['params']);
        $this->assertSame(url('/share'), route('save.share'));
    }
}
