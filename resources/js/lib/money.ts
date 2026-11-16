// Money arrives from the server as integer nanodollars ($1 = 1e9).
// Integers up to 2^53 are exact in JS, i.e. up to ~$9 million.

const NANOS_PER_DOLLAR = 1_000_000_000;

/** "$0.0517": what people read. Rounded for display only, never for maths. */
export function formatUsd(nanos: number, decimals = 4): string {
    const dollars = nanos / NANOS_PER_DOLLAR;
    const smallest = 10 ** -decimals;
    // Don't let a real, tiny amount look like zero.
    if (nanos > 0 && dollars < smallest) {
        return '<$' + smallest.toFixed(decimals);
    }
    return (dollars < 0 ? '−$' : '$') + Math.abs(dollars).toFixed(decimals);
}

/** "$0.051725000": the exact amount, e.g. for a tooltip. */
export function exactUsd(nanos: number): string {
    const abs = Math.abs(nanos);
    const whole = Math.floor(abs / NANOS_PER_DOLLAR);
    const fraction = String(abs % NANOS_PER_DOLLAR).padStart(9, '0');
    return (nanos < 0 ? '−$' : '$') + whole + '.' + fraction;
}
