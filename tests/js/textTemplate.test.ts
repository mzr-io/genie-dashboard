import { describe, expect, it } from 'vitest';
import {
    expandKpiPayload,
    expandTemplate,
} from '../../resources/js/lib/textTemplate';

// Story 5.4: the wire form of a Text Template becomes the viewer's text (FR-26, NFR-11, AD-33).
const profile = { name: 'Ada Lovelace', email: 'a&b@x.com' };
const ctx = { profile, locale: 'en-US' };

describe('expandTemplate', () => {
    it("writes numbers and dates in the viewer's locale", () => {
        expect(
            expandTemplate('{#12|integer} requests need your attention', ctx),
        ).toBe('12 requests need your attention');
        expect(
            expandTemplate(
                '{@2026-05-01|MMM d} – {@2026-05-31|MMM d, yyyy}',
                ctx,
            ),
        ).toBe('May 1 – May 31, 2026');
        expect(
            expandTemplate('{@2026-05-01|MMM d} – {@2026-05-31|MMM d, yyyy}', {
                ...ctx,
                locale: 'de-DE',
            }),
        ).toBe('1. Mai – 31. Mai 2026');
        expect(expandTemplate('{#1234567.5|number}', ctx)).toBe('1,234,567.5');
        expect(
            expandTemplate('{#1234567.5|number}', { ...ctx, locale: 'de-DE' }),
        ).toBe('1.234.567,5');
    });

    it('keeps the digits of a number no float can hold', () => {
        expect(expandTemplate('{#12345678901234567.89|number}', ctx)).toBe(
            '12,345,678,901,234,567.89',
        );
    });

    it("formats currency with the Block's code, percent as a figure, and compact", () => {
        expect(
            expandTemplate('{#284680.5|currency}', {
                ...ctx,
                currencyCode: 'USD',
            }),
        ).toBe('$284,680.50');
        expect(expandTemplate('{#284680.5|currency}', ctx)).toBe('284,680.5');
        expect(expandTemplate('{#14.2|percent}', ctx)).toBe('14.2%');
        expect(expandTemplate('{#1500000|compact}', ctx)).toBe('1.5M');
    });

    it("fills the viewer's own values, so two viewers of one cached result read their own", () => {
        const cached = 'Hello {user.name}';

        expect(expandTemplate(cached, { profile: { name: 'Ada' } })).toBe(
            'Hello Ada',
        );
        expect(expandTemplate(cached, { profile: { name: 'Grace' } })).toBe(
            'Hello Grace',
        );
    });

    it('shows the empty display for a null value or a missing profile value, never 0 or undefined', () => {
        expect(expandTemplate('A {-} B', ctx)).toBe('A — B');
        expect(expandTemplate('A {-} B', { ...ctx, emptyDisplay: 'n/a' })).toBe(
            'A n/a B',
        );
        expect(expandTemplate('Hi {user.email}', { profile: {} })).toBe('Hi —');
        expect(
            expandTemplate('Hi {user.name}', { profile: { name: null } }),
        ).toBe('Hi —');
    });

    it('reads doubled braces as literal braces and treats anything else as text', () => {
        expect(expandTemplate('{{user.name}} {{#9|currency}}', ctx)).toBe(
            '{user.name} {#9|currency}',
        );
        expect(
            expandTemplate(
                '<img src=x onerror=alert(1)> {nope} {user.Name}',
                ctx,
            ),
        ).toBe('<img src=x onerror=alert(1)> {nope} {user.Name}');
    });

    it('leaves a plain string as it is', () => {
        expect(expandTemplate('Revenue overview', ctx)).toBe(
            'Revenue overview',
        );
    });
});

describe('expandKpiPayload', () => {
    const base = {
        state: 'normal',
        title: 'Hi {user.name}',
        subtitle: null,
        icon: null,
        footerAction: null,
        kpiLabel: '{#12|integer} open',
        headline: {
            available: true,
            value: '10',
            format: { kind: 'currency', currencyCode: 'EUR' },
        },
        comparison: null,
        comparisonLabel: null,
        secondaryNote: '{{x}}',
        statusBadge: {
            text: 'On {@2026-05-01|MMM d}',
            color: 'info',
            icon: null,
        },
        emptyDisplay: null,
    } as never;

    it("expands each text slot and the badge text, with the Block's own currency", () => {
        const out = expandKpiPayload(
            base,
            profile,
            'en-US',
        ) as unknown as Record<string, unknown>;

        expect(out.title).toBe('Hi Ada Lovelace');
        expect(out.kpiLabel).toBe('12 open');
        expect(out.secondaryNote).toBe('{x}');
        expect((out.statusBadge as { text: string }).text).toBe('On May 1');
    });

    it('returns the same object when no text carries a brace', () => {
        const plain = {
            ...(base as object),
            title: 'Plain',
            kpiLabel: 'Plain',
            secondaryNote: null,
            statusBadge: null,
        } as never;

        expect(expandKpiPayload(plain, profile)).toBe(plain);
    });
});
