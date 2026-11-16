<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakesPixelforge;
use Tests\TestCase;

class StartGenerationTest extends TestCase
{
    use FakesPixelforge, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['pixelforge.test/v1/pricing' => Http::response($this->pricing())]);
        Queue::fake();
    }

    public function test_it_holds_the_most_the_image_can_cost_and_queues_the_submission(): void
    {
        $user = $this->userWithCredits(10);

        $this->actingAs($user)->post('/generations', [
            'prompt' => str_repeat('a', 400),
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png'],
        ])->assertRedirect('/studio');

        $generation = Generation::sole();
        $this->assertSame(GenerationStatus::Pending, $generation->status);
        // The docs' maximum, $0.373875, plus 25%.
        $this->assertSame(467_343_750, $generation->estimated_max_nanos);
        $this->assertSame(10_000_000_000 - 467_343_750, $user->balanceNanos());
        Queue::assertPushed(SubmitGeneration::class, fn ($job) => $job->generationId === $generation->id);
    }

    public function test_it_refuses_when_the_balance_cannot_cover_the_maximum(): void
    {
        $user = User::factory()->create(); // no credits

        $this->actingAs($user)->post('/generations', [
            'prompt' => 'A lighthouse',
            'size' => '1024x1024',
            'quality' => 'low',
        ])->assertSessionHasErrors('credits');

        $this->assertSame(0, Generation::count());
        Queue::assertNothingPushed();
    }

    public function test_it_rejects_what_pixelforge_would_reject(): void
    {
        $user = $this->userWithCredits();

        $this->actingAs($user)->post('/generations', [
            'prompt' => str_repeat('a', 4001),
            'size' => '800x600',
            'quality' => 'ultra',
            'reference_images' => ['ftp://example.com/a.png', 'x', 'x', 'x', 'x'],
        ])->assertSessionHasErrors(['prompt', 'size', 'quality', 'reference_images']);

        $this->assertSame(0, Generation::count());
    }

    public function test_the_estimate_endpoint_returns_the_users_price_range(): void
    {
        $this->actingAs($this->userWithCredits())
            ->getJson('/studio/estimate?'.http_build_query(['prompt' => str_repeat('a', 400), 'size' => '1536x1024', 'quality' => 'high', 'reference_images' => ['https://e.com/a.png', 'https://e.com/b.png']]))
            ->assertOk()
            ->assertJson(['maxNanos' => 467_343_750]);
    }
}
