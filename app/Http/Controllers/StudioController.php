<?php

namespace App\Http\Controllers;

use App\Actions\StartGeneration;
use App\Billing\CostCalculator;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class StudioController extends Controller
{
    public function __construct(private readonly PixelforgeClient $pixelforge) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $pricing = $this->pixelforge->pricing();

        $generations = $user->generations()->latest()->limit(50)->get();

        return Inertia::render('Studio', [
            'sizes' => $pricing->sizes(),
            'qualities' => $pricing->qualities(),
            'balanceNanos' => $user->balanceNanos(),
            'generations' => $generations->map(fn (Generation $g) => [
                'id' => $g->id,
                'status' => $g->status,
                'prompt' => $g->prompt,
                'size' => $g->size,
                'quality' => $g->quality,
                'referenceImages' => $g->reference_images,
                'estimatedMinNanos' => $g->estimated_min_nanos,
                'estimatedMaxNanos' => $g->estimated_max_nanos,
                'chargedNanos' => $g->charged_nanos,
                'errorCode' => $g->error_code,
                'imageUrl' => $g->image_url,
                'imageExpired' => $g->image_url_expires_at?->isPast() ?? false,
                'createdAt' => $g->created_at->toIso8601String(),
            ]),
        ]);
    }

    /** The price range for what's in the form, as the user types. */
    public function estimate(Request $request, CostCalculator $costs): JsonResponse
    {
        $input = $this->validateGeneration($request);

        $estimate = $costs->estimateUserPrice(
            $this->pixelforge->pricing(),
            $input['prompt'],
            $input['size'],
            $input['quality'],
            count($input['reference_images']),
        );

        return response()->json(['minNanos' => $estimate['min'], 'maxNanos' => $estimate['max']]);
    }

    public function store(Request $request, StartGeneration $start): RedirectResponse
    {
        $start($request->user(), $this->validateGeneration($request));

        return to_route('studio');
    }

    /**
     * Mirrors Pixelforge's own rules, so a request it would reject with 422 is
     * caught here, with the error next to the field.
     *
     * @return array{prompt: string, size: string, quality: string, reference_images: list<string>}
     */
    private function validateGeneration(Request $request): array
    {
        $pricing = $this->pixelforge->pricing();

        $input = $request->validate([
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['required', Rule::in($pricing->sizes())],
            'quality' => ['required', Rule::in($pricing->qualities())],
            'reference_images' => ['array', 'max:4'],
            'reference_images.*' => ['required', 'url:http,https', 'max:2048'],
        ]);

        $input['reference_images'] = array_values($input['reference_images'] ?? []);

        return $input;
    }
}
