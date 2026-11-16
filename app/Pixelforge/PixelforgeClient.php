<?php

namespace App\Pixelforge;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Thin HTTP wrapper. It throws on every failure and never retries by itself:
 * retrying is the caller's decision (see SubmitGeneration).
 */
class PixelforgeClient
{
    /**
     * @param  array{prompt: string, size: string, quality: string, reference_images: list<string>, metadata: array<string, string>}  $params
     * @return array<string, mixed> the generation object
     */
    public function createGeneration(array $params, string $idempotencyKey): array
    {
        return $this->send(fn () => $this->http()
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('/generations', $params));
    }

    /** @return array<string, mixed> the generation object */
    public function getGeneration(string $id): array
    {
        return $this->send(fn () => $this->http()->get('/generations/'.$id));
    }

    /** @return array<string, mixed> the usage object for this API key, per UTC day */
    public function usage(string $startDate, string $endDate): array
    {
        return $this->send(fn () => $this->http()->get('/usage', [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]));
    }

    /**
     * Current prices and token rules. Rates can change, so they are read, not
     * hardcoded: cached for a minute, and the last good copy is kept in case
     * Pixelforge is down when someone opens the form.
     */
    public function pricing(): Pricing
    {
        $data = Cache::remember('pixelforge.pricing', 60, function () {
            try {
                $pricing = $this->send(fn () => $this->http()->get('/pricing'));
            } catch (PixelforgeException $e) {
                return Cache::get('pixelforge.pricing.last_good') ?? throw $e;
            }
            Cache::forever('pixelforge.pricing.last_good', $pricing);

            return $pricing;
        });

        return Pricing::fromArray($data);
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(config('services.pixelforge.base_url'))
            ->withToken((string) config('services.pixelforge.api_key'))
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(15);
    }

    /** @return array<string, mixed> */
    private function send(callable $request): array
    {
        try {
            /** @var Response $response */
            $response = $request();
        } catch (ConnectionException $e) {
            throw PixelforgeException::network($e->getMessage());
        }

        if ($response->successful()) {
            return $response->json();
        }

        $retryAfter = $response->header('Retry-After');

        throw new PixelforgeException(
            status: $response->status(),
            errorCode: (string) ($response->json('error.code') ?? 'http_'.$response->status()),
            message: (string) ($response->json('error.message') ?? 'Pixelforge returned '.$response->status()),
            retryAfter: is_numeric($retryAfter) ? (int) $retryAfter : null,
        );
    }
}
