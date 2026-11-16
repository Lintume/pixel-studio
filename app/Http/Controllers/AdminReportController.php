<?php

namespace App\Http\Controllers;

use App\Billing\Nanos;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Credit spend, provider cost and profit per UTC day and user, checked against
 * GET /v1/usage.
 *
 * Days are the day a generation COMPLETED, because that is the day Pixelforge
 * bills it on; grouping by creation day would make a generation started just
 * before midnight land on different days in the two reports.
 */
class AdminReportController extends Controller
{
    public function __invoke(Request $request, PixelforgeClient $pixelforge): Response
    {
        // Access is decided by OperatorReportAccess.
        $to = Carbon::now('UTC')->startOfDay();
        $from = $to->copy()->subDays(29);

        $generations = Generation::query()
            ->with('user:id,name,email')
            ->where('status', GenerationStatus::Succeeded)
            ->where('completed_at', '>=', $from)
            ->get(['id', 'user_id', 'completed_at', 'charged_nanos', 'provider_cost_nanos']);

        $rows = $generations
            ->groupBy(fn (Generation $g) => $g->completed_at->utc()->toDateString().'|'.$g->user_id)
            ->map(fn ($group) => [
                'date' => $group->first()->completed_at->utc()->toDateString(),
                'user' => $group->first()->user->email,
                'generations' => $group->count(),
                'chargedNanos' => (int) $group->sum('charged_nanos'),
                'providerCostNanos' => (int) $group->sum('provider_cost_nanos'),
            ])
            ->sortByDesc('date')
            ->values();

        $ourCostByDay = $rows->groupBy('date')->map(fn ($day) => (int) $day->sum('providerCostNanos'));

        $days = [];
        $usageError = null;

        try {
            $usage = $pixelforge->usage($from->toDateString(), $to->toDateString());

            foreach (array_reverse($usage['data']) as $day) {
                $ours = $ourCostByDay[$day['date']] ?? 0;
                $theirs = Nanos::fromUsd($day['cost_usd']);

                if ($ours === 0 && $theirs === 0) {
                    continue;
                }

                $days[] = [
                    'date' => $day['date'],
                    'ourProviderCostNanos' => $ours,
                    'pixelforgeCostNanos' => $theirs,
                    'pixelforgeGenerations' => $day['generations'],
                    'matches' => $ours === $theirs,
                ];
            }
        } catch (PixelforgeException $e) {
            $usageError = $e->getMessage();
        }

        return Inertia::render('AdminReport', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'rows' => $rows,
            'totals' => [
                'chargedNanos' => (int) $rows->sum('chargedNanos'),
                'providerCostNanos' => (int) $rows->sum('providerCostNanos'),
            ],
            'reconciliation' => $days,
            'usageError' => $usageError,
            // Opened through a shared link rather than an operator account.
            'sharedUntil' => $request->user() === null
                ? Carbon::createFromTimestamp((int) $request->query('expires'), 'UTC')->toDateString()
                : null,
        ]);
    }
}
