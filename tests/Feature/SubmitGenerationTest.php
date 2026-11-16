<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakesPixelforge;
use Tests\TestCase;

class SubmitGenerationTest extends TestCase
{
    use FakesPixelforge, RefreshDatabase;

    public function test_it_submits_with_the_generations_idempotency_key_and_id_in_metadata(): void
    {
        $user = $this->userWithCredits();
        $generation = $this->pendingGeneration($user);
        Http::fake(['pixelforge.test/v1/generations' => Http::response($this->providerObject($generation, 'queued'), 202)]);

        $this->runJob($generation->id);

        Http::assertSent(fn (Request $r) => $r->hasHeader('Idempotency-Key', $generation->idempotency_key)
            && $r['metadata'] === ['generation_id' => $generation->id]
            && ! array_key_exists('reference_images', $r->data())); // empty array not sent
        $generation->refresh();
        $this->assertSame(GenerationStatus::Queued, $generation->status);
        $this->assertSame('gen_'.$generation->id, $generation->provider_id);
    }

    public function test_overload_and_rate_limits_are_retried_after_retry_after(): void
    {
        $generation = $this->pendingGeneration($this->userWithCredits());
        Http::fake(['pixelforge.test/v1/generations' => Http::response(
            ['error' => ['type' => 'api_error', 'code' => 'overloaded', 'message' => 'Busy.']], 503, ['Retry-After' => '2'],
        )]);

        $job = $this->runJob($generation->id);

        $job->assertReleased(2);
        $this->assertSame(GenerationStatus::Pending, $generation->refresh()->status);
    }

    public function test_a_network_failure_is_retried_too(): void
    {
        $generation = $this->pendingGeneration($this->userWithCredits());
        Http::fake(['pixelforge.test/v1/generations' => Http::failedConnection()]);

        $this->runJob($generation->id)->assertReleased();
    }

    public function test_a_blocked_prompt_fails_and_gives_the_credits_back(): void
    {
        $user = $this->userWithCredits(10);
        $generation = $this->pendingGeneration($user);
        Http::fake(['pixelforge.test/v1/generations' => Http::response(
            ['error' => ['type' => 'invalid_request_error', 'code' => 'content_policy_violation', 'message' => 'Rejected.']], 400,
        )]);

        $this->runJob($generation->id)->assertNotReleased();

        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('content_policy_violation', $generation->error_code);
        $this->assertSame(10_000_000_000, $user->balanceNanos());
    }

    public function test_giving_up_releases_the_hold_but_a_late_success_is_still_charged(): void
    {
        $user = $this->userWithCredits(10);
        $generation = $this->pendingGeneration($user);

        (new SubmitGeneration($generation->id))->failed(null);
        $this->assertSame(GenerationStatus::Failed, $generation->refresh()->status);
        $this->assertSame(10_000_000_000, $user->balanceNanos());

        // Pixelforge had taken the request after all, and billed it.
        $this->postWebhook($this->providerObject($generation, 'succeeded'))->assertNoContent();

        $generation->refresh();
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertNull($generation->error_code);
        $this->assertSame(10_000_000_000 - 381_887_500, $user->balanceNanos());
    }

    private function runJob(string $generationId): SubmitGeneration
    {
        $job = (new SubmitGeneration($generationId))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        return $job;
    }
}
