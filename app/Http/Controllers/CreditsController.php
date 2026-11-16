<?php

namespace App\Http\Controllers;

use App\Billing\Nanos;
use App\Enums\CreditTransactionType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** A pretend checkout: no payment, the credits are simply added. */
class CreditsController extends Controller
{
    public const PACKS_USD = [1, 5, 20];

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['usd' => ['required', 'integer', 'in:'.implode(',', self::PACKS_USD)]]);

        $request->user()->creditTransactions()->create([
            'type' => CreditTransactionType::Purchase,
            'amount_nanos' => (int) $request->integer('usd') * Nanos::PER_DOLLAR,
        ]);

        return back();
    }
}
