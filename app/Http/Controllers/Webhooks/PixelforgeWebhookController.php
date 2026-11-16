<?php

namespace App\Http\Controllers\Webhooks;

use App\Actions\ApplyProviderState;
use App\Models\Generation;
use App\Models\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pixelforge delivers at least once, in any order, sometimes before our POST has
 * returned. So: verify, deduplicate by event id, and let ApplyProviderState
 * decide whether the event still moves anything.
 *
 * The work is a few row updates, fast enough to do inline and answer well within
 * Pixelforge's 10 seconds. Doing it inline also means a 2xx is only sent once
 * the change is committed: if anything throws, the event is not marked seen and
 * Pixelforge retries it.
 */
class PixelforgeWebhookController
{
    public function __invoke(Request $request, ApplyProviderState $apply): Response
    {
        $payload = $request->getContent();

        if (! $this->hasValidSignature($payload, (string) $request->header('Pixelforge-Signature'))) {
            return response('Invalid signature.', 400);
        }

        $event = json_decode($payload, true);

        if (! is_array($event) || ! isset($event['id'], $event['type'])) {
            return response('Malformed event.', 400);
        }

        DB::transaction(function () use ($event, $apply) {
            $object = $event['data']['object'] ?? null;

            $isNew = WebhookEvent::query()->insertOrIgnore([
                'id' => $event['id'],
                'type' => $event['type'],
                'provider_generation_id' => $object['id'] ?? null,
                'payload' => json_encode($event),
                'created_at' => now(),
                'updated_at' => now(),
            ]) === 1;

            if (! $isNew || ! str_starts_with($event['type'], 'generation.') || $object === null) {
                return; // a redelivery, or a ping
            }

            // metadata.generation_id finds the row even if this event beat the
            // POST response that would have told us Pixelforge's id.
            $generation = Generation::query()
                ->where('id', $object['metadata']['generation_id'] ?? '')
                ->orWhere('provider_id', $object['id'])
                ->first();

            if ($generation === null) {
                Log::warning('Pixelforge webhook for an unknown generation', ['event' => $event['id'], 'generation' => $object['id']]);

                return;
            }

            $apply($generation, $object);
        });

        return response()->noContent();
    }

    /** t=<unix time>,v1=<hex HMAC-SHA256 of "<t>.<raw body>">, as in the Pixelforge docs. */
    private function hasValidSignature(string $payload, string $header): bool
    {
        $parts = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            $parts[trim($key)] = trim($value);
        }

        $timestamp = (int) ($parts['t'] ?? 0);
        $secret = (string) config('services.pixelforge.webhook_secret');

        if ($secret === '' || abs(time() - $timestamp) > config('services.pixelforge.webhook_tolerance')) {
            return false; // unconfigured, or an old (replayed) delivery
        }

        return hash_equals(hash_hmac('sha256', $timestamp.'.'.$payload, $secret), $parts['v1'] ?? '');
    }
}
