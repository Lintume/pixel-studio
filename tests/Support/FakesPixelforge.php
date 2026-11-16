<?php

namespace Tests\Support;

use App\Billing\Nanos;
use App\Enums\CreditTransactionType;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

trait FakesPixelforge
{
    protected string $webhookSecret = 'whsec_test';

    protected function setUpFakesPixelforge(): void
    {
        config([
            'services.pixelforge.base_url' => 'https://pixelforge.test/v1',
            'services.pixelforge.api_key' => 'pf_test',
            'services.pixelforge.webhook_secret' => $this->webhookSecret,
        ]);
    }

    /** GET /v1/pricing exactly as published in the docs. */
    protected function pricing(): array
    {
        return [
            'object' => 'pricing',
            'currency' => 'usd',
            'unit' => 'per_1m_tokens',
            'rates' => self::RATES,
            'tokens' => [
                'characters_per_text_token' => 4,
                'text_token_variance' => 0.15,
                'per_reference_image' => 765,
                'output' => [
                    'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                    'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                    'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
                ],
                'reasoning' => [
                    'low' => ['min' => 0, 'max' => 500],
                    'medium' => ['min' => 200, 'max' => 2000],
                    'high' => ['min' => 1000, 'max' => 8000],
                ],
            ],
        ];
    }

    protected const RATES = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    /** The docs' succeeded example: costs exactly $0.305510000 at these rates. */
    protected const USAGE = [
        'input_text_tokens' => 12,
        'input_image_tokens' => 765,
        'output_tokens' => 6450,
        'reasoning_tokens' => 3184,
        'total_tokens' => 10411,
    ];

    protected function userWithCredits(int $usd = 10): User
    {
        $user = User::factory()->create();
        $user->creditTransactions()->create([
            'type' => CreditTransactionType::Purchase,
            'amount_nanos' => $usd * Nanos::PER_DOLLAR,
        ]);

        return $user;
    }

    /** A generation as StartGeneration leaves it: pending, with its hold booked. */
    protected function pendingGeneration(User $user, int $maxNanos = 500_000_000): Generation
    {
        $generation = $user->generations()->create([
            'status' => GenerationStatus::Pending,
            'idempotency_key' => (string) Str::uuid(),
            'prompt' => 'A lighthouse',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => [],
            'estimated_min_nanos' => 100_000_000,
            'estimated_max_nanos' => $maxNanos,
        ]);
        $user->creditTransactions()->create([
            'generation_id' => $generation->id,
            'type' => CreditTransactionType::Hold,
            'amount_nanos' => -$maxNanos,
        ]);

        return $generation;
    }

    /** A Pixelforge generation object for one of our generations. */
    protected function providerObject(Generation $generation, string $status, array $overrides = []): array
    {
        return array_merge([
            'id' => 'gen_'.$generation->id,
            'object' => 'generation',
            'status' => $status,
            'prompt' => $generation->prompt,
            'size' => $generation->size,
            'quality' => $generation->quality,
            'reference_images' => [],
            'metadata' => ['generation_id' => $generation->id],
            'image_url' => $status === 'succeeded' ? 'https://pixelforge.test/images/x.png?signature=abc' : null,
            'image_url_expires_at' => $status === 'succeeded' ? '2026-11-17T14:03:29Z' : null,
            'usage' => $status === 'succeeded' ? self::USAGE : null,
            'rates' => self::RATES,
            'error' => $status === 'failed' ? ['code' => 'generation_failed', 'message' => 'No image.'] : null,
            'created_at' => '2026-11-16T14:03:11Z',
            'started_at' => in_array($status, ['processing', 'succeeded', 'failed'], true) ? '2026-11-16T14:03:13Z' : null,
            'completed_at' => in_array($status, ['succeeded', 'failed'], true) ? '2026-11-16T14:03:29Z' : null,
        ], $overrides);
    }

    protected function postWebhook(array $object, ?string $eventId = null, ?int $timestamp = null, ?string $secret = null): TestResponse
    {
        $event = [
            'id' => $eventId ?? 'evt_'.Str::random(20),
            'object' => 'event',
            'type' => 'generation.'.$object['status'],
            'created_at' => now()->toIso8601ZuluString(),
            'data' => ['object' => $object],
        ];
        $body = json_encode($event);
        $timestamp ??= time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret ?? $this->webhookSecret);

        return $this->call('POST', '/webhooks/pixelforge', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], $body);
    }
}
