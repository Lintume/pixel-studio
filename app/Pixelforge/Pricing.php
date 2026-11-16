<?php

namespace App\Pixelforge;

/**
 * GET /v1/pricing, as published. Rates are dollars per 1M tokens, as strings.
 */
final class Pricing
{
    /**
     * @param  array<string, string>  $rates
     * @param  array<string, mixed>  $tokens
     */
    public function __construct(
        public readonly array $rates,
        public readonly array $tokens,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self($data['rates'], $data['tokens']);
    }

    /** @return list<string> */
    public function sizes(): array
    {
        return array_keys($this->tokens['output']['medium']);
    }

    /** @return list<string> */
    public function qualities(): array
    {
        return array_keys($this->tokens['output']);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['rates' => $this->rates, 'tokens' => $this->tokens];
    }
}
