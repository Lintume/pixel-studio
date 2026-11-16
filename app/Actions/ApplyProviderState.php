<?php

namespace App\Actions;

use App\Billing\CostCalculator;
use App\Enums\CreditTransactionType;
use App\Enums\GenerationStatus;
use App\Models\CreditTransaction;
use App\Models\Generation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The one place a generation's state changes to match Pixelforge, whoever
 * brought the news: the POST response, a webhook or a poll.
 *
 * Every source can be late, duplicated or out of order, so this only ever moves
 * a generation forward, and the money for it is booked inside the same
 * transaction as the status that justifies it. Applying the same object twice
 * is a no-op.
 */
class ApplyProviderState
{
    public function __construct(private readonly CostCalculator $costs) {}

    /** @param  array<string, mixed>  $object  a Pixelforge generation object */
    public function __invoke(Generation $generation, array $object): Generation
    {
        return DB::transaction(function () use ($generation, $object) {
            $generation = Generation::query()->whereKey($generation->getKey())->lockForUpdate()->firstOrFail();
            $incoming = GenerationStatus::from($object['status']);
            // A failure we recorded without ever hearing from Pixelforge (we gave
            // up submitting) was our own conclusion. If Pixelforge took the
            // request after all, its word on what happened and what it billed
            // is the one that counts.
            $failedWithoutPixelforge = $generation->status === GenerationStatus::Failed && $generation->provider_id === null;

            // Facts that never change once known; safe to fill from any event.
            $generation->provider_id ??= $object['id'];
            $generation->rates ??= $object['rates'] ?? null;
            $generation->provider_created_at ??= $this->time($object['created_at'] ?? null);
            $generation->started_at ??= $this->time($object['started_at'] ?? null);

            if (! $failedWithoutPixelforge && $incoming->rank() <= $generation->status->rank()) {
                $generation->save();

                return $generation;
            }

            $generation->status = $incoming;

            if ($incoming !== GenerationStatus::Failed) {
                // Clears the error left by a give-up that Pixelforge overruled.
                $generation->error_code = null;
                $generation->error_message = null;
            }

            if ($incoming === GenerationStatus::Succeeded) {
                $this->succeed($generation, $object);
            } elseif ($incoming === GenerationStatus::Failed) {
                $this->fail($generation, $object);
            }

            $generation->save();

            return $generation;
        });
    }

    /** @param  array<string, mixed>  $object */
    private function succeed(Generation $generation, array $object): void
    {
        $providerCost = $this->costs->providerCost($object['usage'], $object['rates']);

        $generation->fill([
            'error_code' => null,
            'error_message' => null,
            'usage' => $object['usage'],
            'rates' => $object['rates'],
            'provider_cost_nanos' => $providerCost,
            'charged_nanos' => $this->costs->userPrice($providerCost),
            'image_url' => $object['image_url'],
            'image_url_expires_at' => $this->time($object['image_url_expires_at'] ?? null),
            'completed_at' => $this->time($object['completed_at']),
        ]);

        $this->book($generation, CreditTransactionType::Charge, -$generation->charged_nanos);
        $this->book($generation, CreditTransactionType::Release, $generation->estimated_max_nanos);
    }

    /** @param  array<string, mixed>  $object */
    private function fail(Generation $generation, array $object): void
    {
        // Failed generations are never billed, by Pixelforge or by us.
        $generation->fill([
            'error_code' => $object['error']['code'] ?? 'generation_failed',
            'error_message' => $object['error']['message'] ?? null,
            'completed_at' => $this->time($object['completed_at'] ?? null),
        ]);

        $this->book($generation, CreditTransactionType::Release, $generation->estimated_max_nanos);
    }

    /**
     * At most one row per (generation, type): the unique index makes a double
     * charge impossible even if this code had a bug, and firstOrCreate makes
     * re-booking after a give-up harmless.
     */
    private function book(Generation $generation, CreditTransactionType $type, int $amount): void
    {
        CreditTransaction::query()->firstOrCreate(
            ['generation_id' => $generation->id, 'type' => $type],
            ['user_id' => $generation->user_id, 'amount_nanos' => $amount],
        );
    }

    private function time(?string $value): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value)->utc();
    }
}
