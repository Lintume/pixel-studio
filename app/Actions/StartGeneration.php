<?php

namespace App\Actions;

use App\Billing\CostCalculator;
use App\Enums\CreditTransactionType;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\PixelforgeClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Saves a generation and reserves the most it can cost, then hands the actual
 * request to Pixelforge to a queued job.
 *
 * Holding the maximum (not the estimate) is what makes "block generating when the
 * user can't afford it" true: the final price can never exceed the hold, so a
 * balance never goes negative however many generations run at once.
 */
class StartGeneration
{
    public function __construct(
        private readonly PixelforgeClient $pixelforge,
        private readonly CostCalculator $costs,
    ) {}

    /**
     * @param  array{prompt: string, size: string, quality: string, reference_images: list<string>}  $input
     */
    public function __invoke(User $user, array $input): Generation
    {
        $estimate = $this->costs->estimateUserPrice(
            $this->pixelforge->pricing(),
            $input['prompt'],
            $input['size'],
            $input['quality'],
            count($input['reference_images']),
        );

        return DB::transaction(function () use ($user, $input, $estimate) {
            // One spend at a time per user, so two tabs can't both pass the check.
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            if ($user->balanceNanos() < $estimate['max']) {
                throw ValidationException::withMessages([
                    'credits' => 'Not enough credits: this image can cost up to the top of its estimate. Buy credits to continue.',
                ]);
            }

            $generation = $user->generations()->create([
                'status' => GenerationStatus::Pending,
                'idempotency_key' => (string) Str::uuid(),
                'prompt' => $input['prompt'],
                'size' => $input['size'],
                'quality' => $input['quality'],
                'reference_images' => $input['reference_images'],
                'estimated_min_nanos' => $estimate['min'],
                'estimated_max_nanos' => $estimate['max'],
            ]);

            $user->creditTransactions()->create([
                'generation_id' => $generation->id,
                'type' => CreditTransactionType::Hold,
                'amount_nanos' => -$estimate['max'],
            ]);

            SubmitGeneration::dispatch($generation->id)->afterCommit();

            return $generation;
        });
    }
}
