<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tax rate
    |--------------------------------------------------------------------------
    |
    | The store applies a flat 4% tax on profit before tax (client formula:
    | Net Profit − Expenses − 4% Tax = Total Net Profit). Override via the
    | FINANCE_TAX_RATE environment variable when the rate changes.
    |
    */

    'tax_rate' => (float) env('FINANCE_TAX_RATE', 0.04),

];
