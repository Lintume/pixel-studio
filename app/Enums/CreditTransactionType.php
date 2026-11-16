<?php

namespace App\Enums;

enum CreditTransactionType: string
{
    /** + credits bought. */
    case Purchase = 'purchase';
    /** − the most a generation can cost, reserved when it is submitted. */
    case Hold = 'hold';
    /** + the hold given back once the generation is final. */
    case Release = 'release';
    /** − what a succeeded generation actually cost the user. */
    case Charge = 'charge';
}
