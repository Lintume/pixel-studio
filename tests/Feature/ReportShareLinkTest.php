<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FakesPixelforge;
use Tests\TestCase;

class ReportShareLinkTest extends TestCase
{
    use FakesPixelforge, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-16 18:00:00');
        Http::fake(['pixelforge.test/v1/usage*' => Http::response(['object' => 'usage', 'data' => []])]);
    }

    public function test_a_signed_link_opens_the_report_without_an_account(): void
    {
        $this->get($this->link('2026-12-16'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('AdminReport')
                ->where('sharedUntil', '2026-12-16'));
    }

    public function test_the_link_stops_working_after_its_last_day(): void
    {
        $link = $this->link('2026-12-16');
        $this->travelTo('2026-12-17 00:00:01');

        $this->get($link)->assertForbidden();
    }

    public function test_an_edited_link_is_refused(): void
    {
        // Pushing the expiry out breaks the signature.
        $link = str_replace('expires=', 'expires=9', $this->link('2026-12-16'));

        $this->get($link)->assertForbidden();
    }

    public function test_the_signature_does_not_open_any_other_page(): void
    {
        $query = parse_url($this->link('2026-12-16'), PHP_URL_QUERY);

        $this->get('/studio?'.$query)->assertRedirect('/login');
    }

    public function test_without_a_link_guests_are_sent_to_log_in_and_users_need_to_be_operators(): void
    {
        $this->get('/admin/report')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/admin/report')->assertForbidden();
    }

    public function test_the_command_prints_a_working_link_on_the_requested_host(): void
    {
        Artisan::call('report:share-link', ['--until' => '2026-12-16', '--base' => 'https://pixel.example']);
        $link = trim(strtok(Artisan::output(), "\n"));

        $this->assertStringStartsWith('https://pixel.example/admin/report?expires=', $link);
        $this->get(str_replace('https://pixel.example', '', $link))->assertOk();
    }

    private function link(string $until): string
    {
        return URL::temporarySignedRoute('admin.report', now()->parse($until)->endOfDay(), absolute: false);
    }
}
