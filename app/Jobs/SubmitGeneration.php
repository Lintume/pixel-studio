<?php

namespace App\Jobs;

use App\Actions\ApplyProviderState;
use App\Actions\RejectGeneration;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * POST /v1/generations for one saved generation.
 *
 * Two kinds of "not now" get different patience. Pixelforge's limit of three
 * generations at once is a queue, not a fault: wait for a slot for up to 30
 * minutes. Overloads, rate limits and network errors are faults: retry a few
 * times (seconds apart), then tell the user, rather than leave a spinner up for
 * half an hour. Anything else (blocked prompt, validation, daily quota) fails
 * at once. None of these ever cost the user anything.
 *
 * Every attempt sends the generation's own Idempotency-Key, so a retry after a
 * timeout can never create (or bill) a second image: Pixelforge hands back the
 * one it already has.
 */
class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    /** error_code of a generation we stopped trying to submit. */
    public const GAVE_UP = 'not_submitted';

    /** Bounded by time instead: see retryUntil(). */
    public int $tries = 0;

    /** Attempts at an overload, rate limit or network error before giving up. */
    public const MAX_FAULT_ATTEMPTS = 5;

    public function __construct(public readonly string $generationId) {}

    /** How long to wait for a free concurrency slot. */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(30);
    }

    public function handle(PixelforgeClient $pixelforge, ApplyProviderState $apply, RejectGeneration $reject): void
    {
        $generation = Generation::query()->find($this->generationId);

        if ($generation === null || $generation->status !== GenerationStatus::Pending) {
            return; // already accepted, e.g. a webhook beat this job's response
        }

        try {
            $object = $pixelforge->createGeneration(array_filter([
                'prompt' => $generation->prompt,
                'size' => $generation->size,
                'quality' => $generation->quality,
                'reference_images' => $generation->reference_images ?: null,
                'metadata' => ['generation_id' => $generation->id],
            ]), $generation->idempotency_key);
        } catch (PixelforgeException $e) {
            $waitForSlot = $e->errorCode === 'concurrency_limit_exceeded';

            if ($waitForSlot || ($e->isRetryable() && $this->attempts() < self::MAX_FAULT_ATTEMPTS)) {
                $this->release($this->delay($e));

                return;
            }

            $reject($generation, $e->errorCode, $e->getMessage());

            return;
        }

        // The response may be older than a webhook already applied; that's fine.
        $apply($generation, $object);
    }

    /** Waited 30 minutes for a slot without getting one: release the credits. */
    public function failed(?Throwable $e): void
    {
        $generation = Generation::query()->find($this->generationId);

        if ($generation !== null) {
            app(RejectGeneration::class)($generation, self::GAVE_UP, 'No free generation slot at Pixelforge.');
        }
    }

    /**
     * Pixelforge's Retry-After when it gives one; otherwise back off
     * exponentially (2, 4, 8 … 60 s), since every retry counts against the
     * per-minute limit.
     */
    private function delay(PixelforgeException $e): int
    {
        return $e->retryAfter ?? min(60, 2 ** min($this->attempts(), 6));
    }
}
