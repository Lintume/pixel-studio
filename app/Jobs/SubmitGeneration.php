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
 * POST /v1/generations for one saved generation, retrying until Pixelforge
 * accepts or definitively refuses it.
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

    /** Retries are bounded by time, not count: see retryUntil(). */
    public int $tries = 0;

    public function __construct(public readonly string $generationId) {}

    /**
     * Concurrency waits can be long when many images are in flight, so give
     * Pixelforge 30 minutes before telling the user it didn't work out.
     */
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
            if ($e->isRetryable()) {
                $this->release($this->delay($e));

                return;
            }

            $reject($generation, $e->errorCode, $e->getMessage());

            return;
        }

        // The response may be older than a webhook already applied; that's fine.
        $apply($generation, $object);
    }

    /** Ran out of time: release the credits. A late event can still settle it. */
    public function failed(?Throwable $e): void
    {
        $generation = Generation::query()->find($this->generationId);

        if ($generation !== null) {
            app(RejectGeneration::class)($generation, self::GAVE_UP, 'Pixelforge could not be reached.');
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
