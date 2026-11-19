<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Credits (ADR-0011)
|--------------------------------------------------------------------------
|
| Credits are US dollars. Purchases are fake (no payment provider), but go through
| the same ledger as real money would. Amounts are decimal strings, never floats.
|
*/

return [

    'packs' => [
        ['id' => 'starter', 'label' => 'Starter', 'amount' => '5.00'],
        ['id' => 'standard', 'label' => 'Standard', 'amount' => '10.00'],
        ['id' => 'plus', 'label' => 'Plus', 'amount' => '25.00'],
    ],

];
