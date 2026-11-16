<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FakesPixelforge;
use Tests\TestCase;

class SyncAndReportTest extends TestCase
{
    use FakesPixelforge, RefreshDatabase;

    public function test_generations_whose_webhooks_never_came_are_polled(): void
    {
        $user = $this->userWithCredits(10);
        $generation = $this->pendingGeneration($user);
        $generation->update(['status' => GenerationStatus::Processing, 'provider_id' => 'gen_x']);
        $generation->newQuery()->whereKey($generation->id)->update(['updated_at' => now()->subMinutes(5)]);

        Http::fake(['pixelforge.test/v1/generations/gen_x' => Http::response($this->providerObject($generation, 'succeeded', ['id' => 'gen_x']))]);

        $this->artisan('generations:sync')->assertSuccessful();

        $this->assertSame(GenerationStatus::Succeeded, $generation->refresh()->status);
        $this->assertSame(10_000_000_000 - 381_887_500, $user->balanceNanos());
    }

    public function test_the_report_matches_pixelforges_usage_per_completion_day(): void
    {
        $this->travelTo('2026-11-16 18:00:00');
        config(['services.admin_emails' => ['admin@example.com']]);
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $generation = $this->pendingGeneration($this->userWithCredits());
        // Created yesterday, completed today: it belongs to today, as at Pixelforge.
        $this->postWebhook($this->providerObject($generation, 'succeeded', ['created_at' => '2026-11-15T23:59:50Z', 'completed_at' => '2026-11-16T00:00:10Z']));

        Http::fake(['pixelforge.test/v1/usage*' => Http::response(['object' => 'usage', 'data' => [
            ['date' => '2026-11-15', 'generations' => 0, 'cost_usd' => '0.000000000'],
            ['date' => '2026-11-16', 'generations' => 1, 'cost_usd' => '0.305510000'],
        ]])]);

        $this->actingAs($admin)->get('/admin/report')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('AdminReport')
            ->where('rows.0.date', '2026-11-16')
            ->where('rows.0.chargedNanos', 381_887_500)
            ->where('rows.0.providerCostNanos', 305_510_000)
            ->where('reconciliation.0.matches', true)
            ->count('reconciliation', 1));
    }

    public function test_only_operators_can_see_the_report(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/report')->assertForbidden();
    }
}
