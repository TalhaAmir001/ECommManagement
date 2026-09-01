<?php

/*
|--------------------------------------------------------------------------
| Currency helpers
|--------------------------------------------------------------------------
|
| These helpers centralize the currency symbol / code used everywhere in
| the app. The defaults are Pakistani Rupees (₨ / PKR), and the values
| can be overridden via APP_CURRENCY_SYMBOL and APP_CURRENCY_CODE in the
| .env file without touching any view.
|
| Prefer these helpers over hard-coding "₨" or "PKR" in views and
| controllers so the currency can be swapped in one place.
|
*/

if (! function_exists('currency_symbol')) {
    /**
     * The currency symbol used in money displays (e.g. "₨").
     */
    function currency_symbol(): string
    {
        return (string) config('app.currency_symbol', '₨');
    }
}

if (! function_exists('currency_code')) {
    /**
     * The ISO 4217 currency code used for stored values (e.g. "PKR").
     */
    function currency_code(): string
    {
        return (string) config('app.currency_code', 'PKR');
    }
}

if (! function_exists('format_money')) {
    /**
     * Format a money amount with the configured currency symbol and
     * thousands separators.
     *
     *   format_money(1234.5)   => "₨1,234.50"
     *   format_money(1234.5,0) => "₨1,235"
     *   format_money(null)     => "—"
     */
    function format_money(float|int|null $amount, int $decimals = 2): string
    {
        if ($amount === null) {
            return '—';
        }

        return currency_symbol().number_format((float) $amount, $decimals);
    }
}

if (! function_exists('compact_money')) {
    /**
     * Format a money amount in a compact, human-friendly form. Useful
     * for chart axis labels where space is tight.
     *
     *   compact_money(1_500)        => "₨1.5k"
     *   compact_money(15_000)        => "₨15k"
     *   compact_money(2_500_000)     => "₨2.5m"
     *   compact_money(42)            => "₨42"
     */
    function compact_money(float|int $amount): string
    {
        $abs = abs($amount);

        if ($abs >= 1_000_000) {
            return currency_symbol().number_format($amount / 1_000_000, 1).'m';
        }

        if ($abs >= 10_000) {
            return currency_symbol().number_format($amount / 1_000, 0).'k';
        }

        if ($abs >= 1_000) {
            return currency_symbol().number_format($amount / 1_000, 1).'k';
        }

        return currency_symbol().number_format($amount, 0);
    }
}