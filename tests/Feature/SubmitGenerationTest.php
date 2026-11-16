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

    public function test_an_overload_is_retried_after_retry_after(): void
    {
        $generation = $this->pendingGeneration($this->userWithCredits());
        $this->fakeOverload();

        $this->runJob($generation->id)->assertReleased(2);

        $this->assertSame(GenerationStatus::Pending, $generation->refresh()->status);
    }

    public function test_after_a_few_overloads_it_gives_up_and_gives_the_credits_back(): void
    {
        $user = $this->userWithCredits(10);
        $generation = $this->pendingGeneration($user);
        $this->fakeOverload();

        $this->runJob($generation->id, attempt: SubmitGeneration::MAX_FAULT_ATTEMPTS)->assertNotReleased();

        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('overloaded', $generation->error_code);
        $this->assertSame(10_000_000_000, $user->balanceNanos());
    }

    public function test_waiting_for_a_concurrency_slot_is_not_a_fault_and_keeps_waiting(): void
    {
        $generation = $this->pendingGeneration($this->userWithCredits());
        Http::fake(['pixelforge.test/v1/generations' => Http::response(
            ['error' => ['type' => 'rate_limit_error', 'code' => 'concurrency_limit_exceeded', 'message' => 'Busy.']], 429, ['Retry-After' => '5'],
        )]);

        $this->runJob($generation->id, attempt: 40)->assertReleased(5);
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

    public function test_a_late_success_overrules_our_give_up_and_is_charged(): void
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

    private function runJob(string $generationId, int $attempt = 1): SubmitGeneration
    {
        $job = (new SubmitGeneration($generationId))->withFakeQueueInteractions();
        $job->job->attempts = $attempt;
        app()->call([$job, 'handle']);

        return $job;
    }

    private function fakeOverload(): void
    {
        Http::fake(['pixelforge.test/v1/generations' => Http::response(
            ['error' => ['type' => 'api_error', 'code' => 'overloaded', 'message' => 'Busy.']], 503, ['Retry-After' => '2'],
        )]);
    }
}
