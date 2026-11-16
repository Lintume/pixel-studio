<?php

namespace App\Actions;

use App\Enums\CreditTransactionType;
use App\Enums\GenerationStatus;
use App\Models\CreditTransaction;
use App\Models\Generation;
use Illuminate\Support\Facades\DB;

/**
 * Pixelforge never accepted this generation (blocked prompt, daily quota, or we
 * could not reach it). Nothing was created there, so nothing is billed here.
 */
class RejectGeneration
{
    public function __invoke(Generation $generation, string $code, string $message): void
    {
        DB::transaction(function () use ($generation, $code, $message) {
            $generation = Generation::query()->whereKey($generation->getKey())->lockForUpdate()->firstOrFail();

            if ($generation->status !== GenerationStatus::Pending) {
                return; // Pixelforge accepted it meanwhile; its events decide
            }

            $generation->update([
                'status' => GenerationStatus::Failed,
                'error_code' => $code,
                'error_message' => $message,
            ]);

            CreditTransaction::query()->firstOrCreate(
                ['generation_id' => $generation->id, 'type' => CreditTransactionType::Release],
                ['user_id' => $generation->user_id, 'amount_nanos' => $generation->estimated_max_nanos],
            );
        });
    }
}
