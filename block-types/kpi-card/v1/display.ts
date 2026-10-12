import {
    formatCurrency,
    formatDate,
    formatDuration,
    formatNumber,
    formatRelativeTime,
} from '@/lib/format';
import type { FormatDescriptor } from './payload';

// Applies a format descriptor to a decimal string (Story 3.2). All `Intl` work is in `@/lib/format`; the value is never a JS number.

// Beyond this a decimal is not shifted or formatted: it would allocate a huge string.
const MAX_EXPONENT = 1000;

const PLAIN = /^([+-]?)(\d*)\.?(\d*)(?:[eE]([+-]?\d+))?$/;

// Moves the decimal point of a plain decimal string by `places` (negative = left) without leaving the string.
export function shiftDecimal(value: string, places: number): string {
    const match = PLAIN.exec(value.trim());

    if (match === null) {
        return value;
    }

    const [, sign, whole, fraction, exponent] = match;

    // No digits, or an exponent too large to shift: not a number to show.
    if (
        whole + fraction === '' ||
        Math.abs(Number(exponent ?? 0)) > MAX_EXPONENT
    ) {
        return '';
    }

    const digits = whole + fraction;
    const point = whole.length + places + Number(exponent ?? 0);
    let out: string;

    if (point <= 0) {
        out = `0.${'0'.repeat(-point)}${digits}`;
    } else if (point >= digits.length) {
        out = digits + '0'.repeat(point - digits.length);
    } else {
        out = `${digits.slice(0, point)}.${digits.slice(point)}`;
    }

    return sign + out;
}

// The decimal string without its sign: the arrow carries the direction.
export function magnitude(value: string): string {
    return value.trim().replace(/^[+-]/, '');
}

export function formatMeasure(
    value: string,
    descriptor: FormatDescriptor,
    locale: string,
): string {
    const places =
        descriptor.decimalPlaces === null
            ? {}
            : {
                  minimumFractionDigits: descriptor.decimalPlaces,
                  maximumFractionDigits: descriptor.decimalPlaces,
              };
    const grouping = { useGrouping: descriptor.thousandsSeparator };
    const exponent = /[eE]([+-]?\d+)$/.exec(value.trim());

    if (exponent !== null && Math.abs(Number(exponent[1])) > MAX_EXPONENT) {
        return '';
    }

    let text: string;

    switch (descriptor.kind) {
        case 'currency':
            text =
                descriptor.currencyCode === null
                    ? formatNumber(value, { ...places, ...grouping }, locale)
                    : formatCurrency(
                          value,
                          descriptor.currencyCode,
                          {
                              ...places,
                              ...grouping,
                              currencyDisplay: descriptor.currencyDisplay,
                          },
                          locale,
                      );
            break;
        case 'percent':
            text = formatNumber(
                shiftDecimal(value, -2),
                { ...places, ...grouping, style: 'percent' },
                locale,
            );
            break;
        case 'compact':
            text = formatNumber(
                value,
                { ...grouping, notation: 'compact', ...places },
                locale,
            );
            break;
        case 'date':
            text = formatDate(
                value,
                { dateStyle: descriptor.datePattern },
                locale,
            );
            break;
        case 'relative-time':
            text = formatRelativeTime(value, new Date(), locale);
            break;
        case 'duration':
            text = formatDuration(value, locale);
            break;
        default:
            text = formatNumber(value, { ...places, ...grouping }, locale);
    }

    if (text === '') {
        return '';
    }

    return descriptor.unitsSuffix === null
        ? text
        : `${text} ${descriptor.unitsSuffix}`;
}
