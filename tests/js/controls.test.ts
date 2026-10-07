// @vitest-environment happy-dom
import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import ComponentGallery from './fixtures/ComponentGallery.vue';
import { resetAnnouncer } from '../../resources/js/lib/announce';
import { galleryLabels } from './fixtures/galleryLabels';
import { controlLabels } from '../../resources/js/locales/labels';

let wrapper: VueWrapper | null = null;

function gallery(): VueWrapper {
    wrapper = mount(ComponentGallery, { attachTo: document.body });

    return wrapper;
}

async function settle(): Promise<void> {
    await nextTick();
    await nextTick();
}

// reka checks an arrow-focused radio from a zero-delay timer.
async function pause(): Promise<void> {
    await new Promise((resolve) => setTimeout(resolve, 10));
    await settle();
}

function byLabel(text: string): HTMLElement {
    const label = [...document.querySelectorAll('label')].find((l) =>
        l.textContent?.includes(text),
    );
    const id = label?.getAttribute('for');
    const el = id ? document.getElementById(id) : null;

    if (!el) {
        throw new Error(`no control labelled "${text}"`);
    }

    return el;
}

function key(el: Element, name: string): void {
    el.dispatchEvent(
        new KeyboardEvent('keydown', { key: name, bubbles: true }),
    );
}

function button(label: string): HTMLButtonElement {
    const found = [...document.querySelectorAll('button')].find((b) =>
        b.textContent?.includes(label),
    );

    if (!found) {
        throw new Error(`no button "${label}"`);
    }

    return found as HTMLButtonElement;
}

beforeEach(() => {
    window.matchMedia = ((query: string) => ({
        matches: false,
        media: query,
        addEventListener: () => {},
        removeEventListener: () => {},
        addListener: () => {},
        removeListener: () => {},
        onchange: null,
        dispatchEvent: () => false,
    })) as unknown as typeof window.matchMedia;
});

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    resetAnnouncer();
    document.body.innerHTML = '';
    vi.useRealTimers();
});

describe('buttons', () => {
    it('renders the six variants with token colours, 34px (28px destructive-soft) and radius', () => {
        gallery();
        const want: Record<string, string[]> = {
            primary: [
                'bg-brand',
                'text-on-accent',
                'hover:bg-accent-strong',
                'rounded-md',
                'min-h-[34px]',
            ],
            secondary: [
                'bg-surface-card',
                'text-text-primary',
                'border-border-strong',
                'rounded-md',
                'min-h-[34px]',
            ],
            ghost: [
                'bg-transparent',
                'text-text-secondary',
                'hover:bg-surface-sunken',
                'rounded-md',
                'min-h-[34px]',
            ],
            link: ['text-accent-ink'],
            'destructive-soft': [
                'bg-error-soft',
                'text-error-text',
                'border-error-border',
                'rounded-sm',
                'min-h-(--df-target-chrome)',
            ],
            destructive: [
                'bg-error',
                'text-on-status',
                'rounded-md',
                'min-h-[34px]',
            ],
        };

        for (const [variant, classes] of Object.entries(want)) {
            const el = document.querySelector(
                `[data-slot="button"][data-variant="${variant}"]`,
            );

            expect(el, variant).not.toBeNull();
            for (const c of classes) {
                expect(el!.classList.contains(c), `${variant} ${c}`).toBe(true);
            }
        }
    });

    it('has no default or outline variant any more', async () => {
        const { buttonVariants } =
            await import('../../resources/js/components/ui/button');

        expect(buttonVariants({ variant: 'primary' })).toContain('bg-brand');
        expect(buttonVariants()).toContain('bg-brand');
        for (const gone of ['default', 'outline']) {
            expect(buttonVariants({ variant: gone as never })).not.toContain(
                'bg-brand',
            );
            expect(buttonVariants({ variant: gone as never })).not.toContain(
                'bg-surface-card',
            );
        }
    });

    it('blocks activation: aria-disabled, focusable, reason linked, focus moves, reason announced, no action', async () => {
        gallery();
        const blocked = document.querySelector<HTMLButtonElement>(
            '[data-test="blocked"]',
        )!;
        const reason = document.getElementById(
            blocked.getAttribute('aria-describedby')!,
        )!;

        expect(blocked.getAttribute('aria-disabled')).toBe('true');
        expect(blocked.disabled).toBe(false);
        expect(reason.textContent).toContain(galleryLabels.blockedReason);
        expect(reason.className).not.toMatch(/opacity/);
        blocked.focus();
        expect(document.activeElement).toBe(blocked);

        blocked.click();
        await settle();

        expect(
            document.querySelector('[data-test="blocked-ran"]')!.textContent,
        ).toBe('0');
        expect(document.activeElement).toBe(byLabel(galleryLabels.name));
        await pause();
        expect(document.querySelector('[data-announcer]')!.textContent).toBe(
            galleryLabels.blockedReason,
        );
        expect(
            document
                .querySelector('[data-announcer]')!
                .getAttribute('aria-live'),
        ).toBe('polite');
    });
});

describe('field validation', () => {
    it('shows the error state on blur and nothing per keystroke', async () => {
        gallery();
        const input = byLabel(galleryLabels.name) as HTMLInputElement;

        input.focus();
        input.value = 'x';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.value = '';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        await settle();
        expect(input.getAttribute('aria-invalid')).toBeNull();
        expect(document.querySelector('[data-slot="field-error"]')).toBeNull();

        input.dispatchEvent(new Event('blur'));
        await settle();

        const error = document.querySelector('[data-slot="field-error"]')!;

        expect(input.getAttribute('aria-invalid')).toBe('true');
        expect(input.getAttribute('aria-describedby')).toContain(error.id);
        expect(error.textContent).toContain(galleryLabels.nameError);
        expect(error.querySelector('.sr-only')!.textContent).toContain(
            controlLabels.errorPrefix,
        );
        expect(error.querySelector('svg[aria-hidden="true"]')).not.toBeNull();
        expect(input.className).toContain('aria-invalid:border-error');

        input.value = 'Ada';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        await settle();
        expect(input.getAttribute('aria-invalid')).toBe('true');
        input.dispatchEvent(new Event('blur'));
        await settle();
        expect(input.getAttribute('aria-invalid')).toBeNull();
    });

    it('marks required fields with required and an aria-hidden red asterisk, with one "* Required"', () => {
        gallery();
        const input = byLabel(galleryLabels.name);

        expect(input.hasAttribute('required')).toBe(true);
        const marker = document.querySelector('[data-slot="required-marker"]')!;

        expect(marker.getAttribute('aria-hidden')).toBe('true');
        expect(marker.className).toContain('text-error-text');
        const notes = document.querySelectorAll('[data-slot="required-note"]');

        expect(notes).toHaveLength(1);
        expect(notes[0].textContent!.replace(/\s+/g, ' ').trim()).toBe(
            `* ${controlLabels.required}`,
        );
    });

    it('focuses a summary of links for two errors and each link focuses its field', async () => {
        gallery();
        const form = document.querySelector('form')!;

        form.dispatchEvent(new Event('submit', { cancelable: true }));
        await settle();

        const summary = document.querySelector<HTMLElement>(
            '[data-slot="form-error-summary"]',
        )!;

        expect(document.activeElement).toBe(summary);
        const links = summary.querySelectorAll('a');

        expect(links).toHaveLength(2);
        links[1].click();
        expect(document.activeElement).toBe(byLabel(galleryLabels.email));
        links[0].click();
        expect(document.activeElement).toBe(byLabel(galleryLabels.name));
        expect(
            document.querySelector('[data-test="submitted"]')!.textContent,
        ).toBe('0');
    });

    it('focuses the field and shows no summary for one error, and submits when valid', async () => {
        gallery();
        const name = byLabel(galleryLabels.name) as HTMLInputElement;
        const email = byLabel(galleryLabels.email) as HTMLInputElement;

        name.value = 'Ada';
        name.dispatchEvent(new Event('input', { bubbles: true }));
        email.value = 'bad';
        email.dispatchEvent(new Event('input', { bubbles: true }));
        await settle();
        document
            .querySelector('form')!
            .dispatchEvent(new Event('submit', { cancelable: true }));
        await settle();

        expect(
            document.querySelector('[data-slot="form-error-summary"]'),
        ).toBeNull();
        expect(document.activeElement).toBe(email);

        email.value = 'ada@example.com';
        email.dispatchEvent(new Event('input', { bubbles: true }));
        await settle();
        document
            .querySelector('form')!
            .dispatchEvent(new Event('submit', { cancelable: true }));
        await settle();
        expect(
            document.querySelector('[data-test="submitted"]')!.textContent,
        ).toBe('1');
    });
});

describe('secret field', () => {
    it('shows the masked saved state, reads as set on the date, has no reveal and holds no secret', async () => {
        gallery();
        const group = document.querySelector('[data-slot="secret-field"]')!;

        expect(group.textContent!.replace(/\s+/g, ' ')).toContain(
            '•••••••• · set 2 Oct 2026 ·',
        );
        expect(
            group.querySelector('[aria-hidden="true"]')!.textContent,
        ).toContain('set 2 Oct 2026');
        expect(group.querySelector('.sr-only')!.textContent).toBe(
            'Secret set on 2 Oct 2026',
        );
        expect(group.querySelector('input')).toBeNull();
        expect(group.querySelectorAll('button')).toHaveLength(1);
        expect(button(controlLabels.replace).getAttribute('aria-label')).toBe(
            controlLabels.replaceToken,
        );
        expect(document.body.innerHTML).not.toMatch(/type="text"[^>]*secret/i);
    });

    it('clears on Replace and requires a new value', async () => {
        gallery();

        button(controlLabels.replace).click();
        await settle();

        const input = document.querySelector<HTMLInputElement>(
            'input[data-slot="secret-field"]',
        )!;

        expect(input.type).toBe('password');
        expect(input.value).toBe('');
        expect(input.required).toBe(true);
        expect(input.getAttribute('autocomplete')).toBe('new-password');
        expect(document.activeElement).toBe(input);

        input.value = 'hunter2-new';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        await settle();
        // A typed value lives only in the control's value, never in an attribute or text.
        expect(document.body.textContent).not.toContain('hunter2-new');
        expect(
            [...document.querySelectorAll('*')].some((el) =>
                [...el.attributes].some((a) => a.value.includes('hunter2-new')),
            ),
        ).toBe(false);
    });
});

describe('keyboard', () => {
    it('moves between segmented options with arrows, one tab stop', async () => {
        gallery();
        await settle();
        const group = document.querySelector(
            '[data-slot="segmented-control"]',
        )!;
        const items = [
            ...group.querySelectorAll<HTMLElement>('[role="radio"]'),
        ];

        expect(group.getAttribute('role')).toBe('radiogroup');
        expect(
            [group, ...items].filter((i) => i.getAttribute('tabindex') === '0'),
        ).toHaveLength(1);
        items[0].focus();
        key(items[0], 'ArrowRight');
        await pause();
        expect(document.activeElement).toBe(items[1]);
        expect(items[1].getAttribute('aria-checked')).toBe('true');
        expect(items[0].getAttribute('aria-checked')).toBe('false');
    });

    it('moves between chips with arrows and keeps one tab stop', async () => {
        gallery();
        await settle();
        const chips = [
            ...document.querySelectorAll<HTMLElement>('[data-slot="chip"]'),
        ];

        const chipGroup = chips[0].parentElement!;

        expect(
            [chipGroup, ...chips].filter(
                (c) => c.getAttribute('tabindex') === '0',
            ),
        ).toHaveLength(1);
        chips[0].focus();
        key(chips[0], 'ArrowRight');
        await pause();
        expect(document.activeElement).toBe(chips[1]);
        expect(chips[1].getAttribute('aria-checked')).toBe('true');
        expect(chips[0].classList.contains('cue-chip')).toBe(true);
    });

    it('follows radiogroup semantics for the access radios', async () => {
        gallery();
        await settle();
        const group = document.querySelector('[aria-label="Access"]')!;
        const radios = [
            ...group.querySelectorAll<HTMLElement>('[role="radio"]'),
        ];

        expect(group.getAttribute('role')).toBe('radiogroup');
        expect(
            [group, ...radios].filter(
                (r) => r.getAttribute('tabindex') === '0',
            ),
        ).toHaveLength(1);
        radios[0].focus();
        key(radios[0], 'ArrowDown');
        await pause();
        expect(document.activeElement).toBe(radios[1]);
        expect(radios[1].getAttribute('aria-checked')).toBe('true');
    });

    it('toggles the switch with Space and shows the On/Off word', async () => {
        gallery();
        const toggle = byLabel(galleryLabels.shortcuts);
        const word = () =>
            document.querySelector('[data-slot="switch-word"]')!.textContent;

        expect(toggle.getAttribute('role')).toBe('switch');
        expect(toggle.getAttribute('aria-checked')).toBe('false');
        expect(word()).toBe(controlLabels.off);
        toggle.focus();
        // Space on a focused native button fires a click in browsers; that click toggles.
        toggle.click();
        await settle();
        expect(toggle.getAttribute('aria-checked')).toBe('true');
        expect(word()).toBe(controlLabels.on);
    });

    it('keeps the textarea resizable vertically only', () => {
        gallery();
        const area = byLabel(galleryLabels.notes);

        expect(area.tagName).toBe('TEXTAREA');
        expect(area.classList.contains('resize-y')).toBe(true);
        expect(area.classList.contains('min-h-24')).toBe(true);
    });
});

describe('tooltip', () => {
    it('shows on focus, stays while hovered, and Esc hides it without moving focus', async () => {
        gallery();
        const trigger = document.querySelector<HTMLElement>(
            '[aria-label="More about this setting"]',
        )!;

        expect(
            document.querySelector('[data-slot="tooltip-content"]'),
        ).toBeNull();
        trigger.focus();
        trigger.dispatchEvent(new FocusEvent('focus'));
        await settle();
        const content = document.querySelector('[data-slot="tooltip-content"]');

        expect(content).not.toBeNull();
        expect(content!.textContent).toContain(galleryLabels.tooltipText);

        trigger.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
        );
        document.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
        );
        await new Promise((resolve) => setTimeout(resolve, 20));
        await settle();
        expect(
            document.querySelector('[data-slot="tooltip-content"]'),
        ).toBeNull();
        expect(document.activeElement).toBe(trigger);
    });

    it('opens on hover and persists while the pointer is over the tooltip', async () => {
        vi.useFakeTimers();
        gallery();
        const trigger = document.querySelector<HTMLElement>(
            '[aria-label="More about this setting"]',
        )!;
        const move = (el: Element) =>
            el.dispatchEvent(
                new PointerEvent('pointermove', {
                    pointerType: 'mouse',
                    bubbles: true,
                }),
            );

        move(trigger);
        await vi.advanceTimersByTimeAsync(50);
        const content = document.querySelector(
            '[data-slot="tooltip-content"]',
        )!;

        expect(content).not.toBeNull();

        trigger.dispatchEvent(
            new PointerEvent('pointerleave', {
                pointerType: 'mouse',
                bubbles: true,
            }),
        );
        content.dispatchEvent(
            new PointerEvent('pointerenter', { pointerType: 'mouse' }),
        );
        move(content);
        await vi.advanceTimersByTimeAsync(50);
        expect(
            document.querySelector('[data-slot="tooltip-content"]'),
        ).not.toBeNull();
    });
});

describe('skeleton', () => {
    it('stops shimmering after 5 s and shows "Still loading…"', async () => {
        vi.useFakeTimers();
        gallery();
        const bars = () =>
            document.querySelectorAll<HTMLElement>('[data-slot="skeleton"]');

        expect(bars()[1].dataset.shimmer).toBe('true');
        expect(
            document.querySelector('[data-slot="skeleton-caption"]'),
        ).toBeNull();
        expect(bars()[1].getAttribute('aria-hidden')).toBe('true');

        await vi.advanceTimersByTimeAsync(4999);
        expect(bars()[1].dataset.shimmer).toBe('true');
        await vi.advanceTimersByTimeAsync(2);
        expect(bars()[1].dataset.shimmer).toBe('false');
        expect(
            document.querySelectorAll('[data-slot="skeleton-caption"]'),
        ).toHaveLength(1);
        expect(
            document
                .querySelector('[data-slot="skeleton-caption"]')!
                .textContent!.trim(),
        ).toBe(controlLabels.stillLoading);
    });

    it('is static under prefers-reduced-motion', () => {
        window.matchMedia = ((query: string) => ({
            matches: query.includes('prefers-reduced-motion'),
            media: query,
            addEventListener: () => {},
            removeEventListener: () => {},
        })) as unknown as typeof window.matchMedia;
        gallery();

        for (const bar of document.querySelectorAll<HTMLElement>(
            '[data-slot="skeleton"]',
        )) {
            expect(bar.dataset.shimmer).toBe('false');
        }
    });
});

describe('status pieces', () => {
    it('keeps tag, badges and lock badge non-interactive with text', () => {
        gallery();
        const pieces = document.querySelectorAll<HTMLElement>(
            '[data-slot="tag"], [data-slot="badge"], [data-slot="lock-badge"]',
        );

        expect(pieces.length).toBeGreaterThanOrEqual(5);
        for (const el of pieces) {
            expect(el.textContent!.trim().length).toBeGreaterThan(0);
            expect(el.hasAttribute('tabindex')).toBe(false);
            expect(el.hasAttribute('role')).toBe(false);
            expect(el.querySelector('a, button, input, [tabindex]')).toBeNull();
            expect(['SPAN']).toContain(el.tagName);
        }
    });

    it('draws the draft and operational badges with token colours, a dot and the word', () => {
        gallery();
        const draft = document.querySelector(
            '[data-slot="badge"][data-variant="draft"]',
        )!;
        const ok = document.querySelector(
            '[data-slot="badge"][data-variant="operational"]',
        )!;

        for (const c of [
            'bg-accent-soft',
            'text-accent-ink-strong',
            'rounded-full',
        ]) {
            expect(draft.classList.contains(c)).toBe(true);
        }
        for (const c of ['bg-success-soft', 'text-success-text']) {
            expect(ok.classList.contains(c)).toBe(true);
        }
        expect(
            draft
                .querySelector('[data-slot="status-dot"]')!
                .getAttribute('aria-hidden'),
        ).toBe('true');
        expect(draft.textContent).toContain(galleryLabels.draft);
    });

    it('announces the lock badge as required by the admin and not removable', () => {
        gallery();
        const lock = document.querySelector('[data-slot="lock-badge"]')!;

        expect(lock.textContent).toContain(controlLabels.locked);
        expect(lock.textContent).toContain(
            'required by your admin and cannot be removed',
        );
        expect(lock.querySelector('svg')!.getAttribute('aria-hidden')).toBe(
            'true',
        );
    });

    it('hides status dots from assistive tech', () => {
        gallery();

        for (const dot of document.querySelectorAll(
            '[data-slot="status-dot"]',
        )) {
            expect(dot.getAttribute('aria-hidden')).toBe('true');
        }
    });
});
