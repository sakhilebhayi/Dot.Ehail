// Money from integer minor units, the same text as the server's MoneyFormatter (D-109): "R1 250.50", a narrow
// no-break space between thousands, U+2212 for minus. Display only: no arithmetic on amounts happens in the browser
// except summing components the server already priced (to check they add up).

const EXPONENTS = { JPY: 0, KRW: 0, UGX: 0, RWF: 0, XOF: 0, XAF: 0, BHD: 3, KWD: 3, OMR: 3, TND: 3 };
const SYMBOLS = { ZAR: 'R', USD: '$', EUR: '€', GBP: '£', NAD: 'N$', BWP: 'P', KES: 'KSh', NGN: '₦', GHS: 'GH₵', LSL: 'L', SZL: 'E' };
const THOUSANDS = ' ';

export function exponent(currency = 'ZAR') {
    return EXPONENTS[String(currency).toUpperCase()] ?? 2;
}

export function formatMinor(minor, currency = 'ZAR', { signed = false, whole = false } = {}) {
    const value = Number.isSafeInteger(minor) ? minor : Math.trunc(Number(minor) || 0);
    const code = String(currency || 'ZAR').toUpperCase();
    const exp = exponent(code);
    const negative = value < 0;
    const absolute = Math.abs(value);
    const divisor = 10 ** exp;
    const major = Math.floor(absolute / divisor);
    const fraction = absolute % divisor;
    let number = String(major).replace(/\B(?=(\d{3})+(?!\d))/g, THOUSANDS);

    if (exp > 0 && !(whole && fraction === 0)) {
        number += '.' + String(fraction).padStart(exp, '0');
    }

    const sign = negative ? '−' : signed && value > 0 ? '+' : '';

    return sign + (SYMBOLS[code] ?? code + ' ') + number;
}

/** What a screen reader should say: "minus R5.00" rather than a dash. */
export function spokenMinor(minor, currency = 'ZAR') {
    const text = formatMinor(Math.abs(minor), currency).replaceAll(THOUSANDS, '');

    return (minor < 0 ? 'minus ' : '') + text;
}

/**
 * Reads what a person typed as an amount ("R 12.50", "12,5", "1 250") into integer minor units without floats.
 * Returns null when it is not a plain amount.
 */
export function parseAmountToMinor(text, currency = 'ZAR') {
    const exp = exponent(currency);
    const cleaned = String(text ?? '').replace(/[R$€£\s  ]/gi, '').replace(',', '.');
    if (!/^\d+(\.\d+)?$/.test(cleaned)) return null;
    const [whole, fraction = ''] = cleaned.split('.');
    if (fraction.length > exp) return null;
    const minor = Number(whole) * 10 ** exp + Number((fraction + '0'.repeat(exp)).slice(0, exp) || 0);
    return Number.isSafeInteger(minor) ? minor : null;
}
