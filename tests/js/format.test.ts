import { beforeEach, describe, expect, it } from 'vitest';
import {
    configureFormatting,
    currentFormatting,
    formatCurrency,
    formatDate,
    formatDateTime,
    formatBytes,
    formatNumber,
} from '../../resources/js/lib/format';

beforeEach(() => configureFormatting({ locale: 'en', timeZone: 'UTC' }));

describe('formatting helper', () => {
    it('formats numbers, currency and dates with the person locale and time zone', () => {
        const at = '2026-10-07T23:30:00Z';

        expect(formatNumber('1234567.891')).toBe('1,234,567.891');
        expect(formatCurrency('1234.5', 'USD')).toBe('$1,234.50');
        expect(formatDateTime(at)).toContain('11:30');
        expect(formatDate(at)).toBe('Oct 7, 2026');

        configureFormatting({ locale: 'de', timeZone: 'Asia/Dhaka' });

        expect(formatNumber('1234567.891')).toBe('1.234.567,891');
        expect(formatCurrency('1234.5', 'EUR').replace(/\u00a0/g, ' ')).toBe(
            '1.234,50 €',
        );
        // 23:30 UTC is already the next morning in Dhaka (UTC+6).
        expect(formatDate(at)).toBe('08.10.2026');
        expect(formatDateTime(at)).toContain('05:30');
    });

    it('keeps every digit of a decimal string (lossless numbers)', () => {
        expect(formatNumber('12345678901234567890.123456789')).toBe(
            '12,345,678,901,234,567,890.123456789',
        );
        expect(formatNumber('0.1000000000000000055511151231257827')).toBe(
            '0.1000000000000000055511151231257827',
        );
        expect(formatNumber(12345678901234567890n)).toBe(
            '12,345,678,901,234,567,890',
        );
        expect(formatNumber('-0.5')).toBe('-0.5');
    });

    it('returns an empty string for something that is not a number or a date', () => {
        for (const bad of [
            '',
            'abc',
            '1,5',
            'NaN',
            'Infinity',
            '1e',
            NaN,
            Infinity,
        ]) {
            expect(formatNumber(bad)).toBe('');
        }

        expect(formatDate('not a date')).toBe('');
        expect(formatCurrency('1', 'NOPE1')).toBe('');
    });

    it('falls back to the catalogue locale and the browser time zone for an unknown value', () => {
        configureFormatting({
            locale: 'not a locale!!',
            timeZone: 'Mars/Olympus',
        });

        expect(currentFormatting()).toEqual({
            locale: 'en',
            timeZone: undefined,
        });
        expect(formatNumber('1234.5')).toBe('1,234.5');

        configureFormatting({ locale: null, timeZone: null });
        expect(currentFormatting()).toEqual({
            locale: 'en',
            timeZone: undefined,
        });
    });

    it('keeps a date-only string on its own day whatever the time zone', () => {
        configureFormatting({ locale: 'en', timeZone: 'Pacific/Honolulu' });
        expect(formatDate('2026-10-07')).toBe('Oct 7, 2026');

        configureFormatting({ locale: 'en', timeZone: 'Pacific/Kiritimati' });
        expect(formatDate('2026-10-07')).toBe('Oct 7, 2026');

        // An instant still moves with the zone.
        configureFormatting({ locale: 'en', timeZone: 'Pacific/Honolulu' });
        expect(formatDate('2026-10-07T03:00:00Z')).toBe('Oct 6, 2026');
    });
});

describe('formatBytes', () => {
    it('carries to the next unit after rounding instead of showing 1,024 KB', () => {
        expect(formatBytes(1048575)).toBe('1 MB');
        expect(formatBytes(1048576)).toBe('1 MB');
        expect(formatBytes(1023)).toBe('1,023 B');
        expect(formatBytes(1536)).toBe('1.5 KB');
        expect(formatBytes(14_890_000)).toBe('14.2 MB');
    });
});
