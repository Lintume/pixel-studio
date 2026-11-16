<?php

namespace Tests\Feature;

use App\Enums\CreditTransactionType;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\CreditTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakesPixelforge;
use Tests\TestCase;

class PixelforgeWebhookTest extends TestCase
{
    use FakesPixelforge, RefreshDatabase;

    public function test_unsigned_or_wrongly_signed_requests_are_rejected(): void
    {
        $generation = $this->pendingGeneration($this->userWithCredits());

        $this->postWebhook($this->providerObject($generation, 'succeeded'), secret: 'whsec_wrong')->assertStatus(400);
        $this->call('POST', '/webhooks/pixelforge', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertStatus(400);

        $this->assertSame(GenerationStatus::Pending, $generation->refresh()->status);
    }

    public function test_an_old_signature_is_rejected_as_a_replay(): void
    {
        $generation = $this->pendingGeneration($this->userWithCredits());

        $this->postWebhook($this->providerObject($generation, 'succeeded'), timestamp: time() - 301)->assertStatus(400);

        $this->assertSame(GenerationStatus::Pending, $generation->refresh()->status);
    }

    public function test_success_charges_the_actual_cost_plus_markup_and_returns_the_rest_of_the_hold(): void
    {
        $user = $this->userWithCredits(10);
        $generation = $this->pendingGeneration($user, maxNanos: 500_000_000);

        $this->postWebhook($this->providerObject($generation, 'succeeded'))->assertNoContent();

        $generation->refresh();
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame(305_510_000, $generation->provider_cost_nanos);
        $this->assertSame(381_887_500, $generation->charged_nanos);
        $this->assertSame('2026-11-16 14:03:29', $generation->completed_at->toDateTimeString());
        $this->assertSame(10_000_000_000 - 381_887_500, $user->balanceNanos());
    }

    public function test_the_same_event_delivered_twice_is_recorded_once(): void
    {
        $user = $this->userWithCredits(10);
        $generation = $this->pendingGeneration($user);
        $object = $this->providerObject($generation, 'succeeded');

        $this->postWebhook($object, 'evt_1')->assertNoContent();
        $this->postWebhook($object, 'evt_1')->assertNoContent();
        // Even a second event for the same transition changes nothing.
        $this->postWebhook($object, 'evt_2')->assertNoContent();

        $this->assertSame(1, CreditTransaction::where('type', CreditTransactionType::Charge)->count());
        $this->assertSame(10_000_000_000 - 381_887_500, $user->balanceNanos());
    }

    public function test_a_late_processing_event_never_moves_a_finished_generation_back(): void
    {
        $generation = $this->pendingGeneration($this->userWithCredits());

        $this->postWebhook($this->providerObject($generation, 'succeeded'));
        $this->postWebhook($this->providerObject($generation, 'processing'));
        $this->postWebhook($this->providerObject($generation, 'queued'));

        $this->assertSame(GenerationStatus::Succeeded, $generation->refresh()->status);
    }

    public function test_a_failed_generation_costs_nothing(): void
    {
        $user = $this->userWithCredits(10);
        $generation = $this->pendingGeneration($user);

        $this->postWebhook($this->providerObject($generation, 'processing'));
        $this->postWebhook($this->providerObject($generation, 'failed'))->assertNoContent();

        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('generation_failed', $generation->error_code);
        $this->assertNull($generation->charged_nanos);
        $this->assertSame(10_000_000_000, $user->balanceNanos());
    }

    public function test_a_webhook_that_beats_the_post_response_is_matched_by_metadata(): void
    {
        $generation = $this->pendingGeneration($this->userWithCredits());

        // Pixelforge's event arrives while our POST is still waiting for its 202.
        $this->postWebhook($this->providerObject($generation, 'processing'))->assertNoContent();
        $this->assertSame(GenerationStatus::Processing, $generation->refresh()->status);

        // Then the older 202 (status queued) comes back: it must not undo that.
        Http::fake(['pixelforge.test/v1/generations' => Http::response($this->providerObject($generation, 'queued'), 202)]);
        $generation->update(['status' => GenerationStatus::Pending]); // as the job saw it when it started
        $generation->update(['status' => GenerationStatus::Processing]);
        app()->call([new SubmitGeneration($generation->id), 'handle']);

        $this->assertSame(GenerationStatus::Processing, $generation->refresh()->status);
    }

    public function test_events_for_generations_we_do_not_know_are_acknowledged(): void
    {
        $generation = $this->pendingGeneration($this->userWithCredits());
        $object = $this->providerObject($generation, 'succeeded', ['id' => 'gen_other', 'metadata' => []]);
        $generation->delete();

        $this->postWebhook($object)->assertNoContent();
    }
}
