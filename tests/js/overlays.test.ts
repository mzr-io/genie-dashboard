// @vitest-environment happy-dom
import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { createPinia, getActivePinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick } from 'vue';
import ConfirmDialog from '../../resources/js/components/ConfirmDialog.vue';
import ToastRegion from '../../resources/js/components/ToastRegion.vue';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetTitle,
} from '../../resources/js/components/ui/sheet';
import OverlayGallery from './fixtures/OverlayGallery.vue';
import { overlayFixtureLabels as f } from './fixtures/overlayLabels';
import {
    announce,
    announceDebounced,
    initAnnouncer,
    resetAnnouncer,
} from '../../resources/js/lib/announce';
import { createCatalogue } from '../../resources/js/lib/i18n';
import en from '../../resources/js/locales/en';
import { overlayLabels } from '../../resources/js/locales/labels';
import { setConnectionForTest } from '../../resources/js/composables/useConnection';
import { useConnectionAnnouncements } from '../../resources/js/composables/useConnection';
import {
    ACTION_TOAST_MS,
    PLAIN_TOAST_MS,
    useToasts,
} from '../../resources/js/stores/toasts';

let wrapper: VueWrapper | null = null;

async function settle(): Promise<void> {
    await nextTick();
    await nextTick();
}

async function pause(ms = 10): Promise<void> {
    await new Promise((resolve) => setTimeout(resolve, ms));
    await settle();
}

function gallery(): VueWrapper {
    const pinia = createPinia();

    setActivePinia(pinia);
    wrapper = mount(OverlayGallery, {
        attachTo: document.body,
        global: { plugins: [pinia, createCatalogue()] },
    });

    return wrapper;
}

function $(selector: string): HTMLElement {
    const el = document.querySelector<HTMLElement>(selector);

    if (!el) {
        throw new Error(`nothing matches ${selector}`);
    }

    return el;
}

function key(el: Element, name: string): void {
    el.dispatchEvent(
        new KeyboardEvent('keydown', { key: name, bubbles: true }),
    );
}

function text(channel: string): string {
    return document
        .querySelector(`[data-announcer="${channel}"]`)!
        .textContent!.trim();
}

function byText(selector: string, label: string): HTMLElement {
    const el = [...document.querySelectorAll<HTMLElement>(selector)].find((e) =>
        e.textContent?.includes(label),
    );

    if (!el) {
        throw new Error(`no ${selector} with "${label}"`);
    }

    return el;
}

beforeEach(() => {
    setConnectionForTest(true);
});

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    resetAnnouncer();
    document.body.innerHTML = '';
    vi.useRealTimers();
    setConnectionForTest(true);
});

describe('shared announcer', () => {
    it('creates the regions before the first message and sets text on a later tick', async () => {
        initAnnouncer();
        expect(document.querySelectorAll('[data-announcer]')).toHaveLength(3);

        announce('Saved', 'polite');
        expect(text('polite')).toBe('');
        await pause();
        expect(text('polite')).toBe('Saved');
    });

    it('maps the policies to roles: polite and once are status, assertive is alert', async () => {
        announce('a', 'polite');
        announce('b', 'once', 'k');
        announce('c', 'assertive');
        await pause();

        expect($('[data-announcer="polite"]').getAttribute('role')).toBe(
            'status',
        );
        expect($('[data-announcer="once"]').getAttribute('role')).toBe(
            'status',
        );
        expect($('[data-announcer="assertive"]').getAttribute('role')).toBe(
            'alert',
        );
        expect(text('assertive')).toBe('c');
    });

    it('speaks a once message one time per key until reset, and never speaks never', async () => {
        expect(announce('Hello', 'once', 'k')).toBe(true);
        expect(announce('Hello again', 'once', 'k')).toBe(false);
        expect(announce('Nope', 'never')).toBe(false);
        await pause();
        expect(text('once')).toBe('Hello');
        expect(document.body.textContent).not.toContain('Nope');
    });

    it('debounces a burst into the last message', async () => {
        vi.useFakeTimers();
        announceDebounced('count', 'one', 500);
        announceDebounced('count', 'two', 500);
        vi.advanceTimersByTime(499);
        expect(document.querySelector('[data-announcer]')).toBeNull();
        vi.advanceTimersByTime(1);
        vi.advanceTimersByTime(5);
        expect(text('polite')).toBe('two');
    });
});

describe('destructive dialog', () => {
    it('is an alertdialog with title, impact, Cancel focused and the object in the button', async () => {
        gallery();
        $('#invoker').focus();
        $('#invoker').click();
        await pause();

        const dialog = $('[role="alertdialog"]');
        const title = document.getElementById(
            dialog.getAttribute('aria-labelledby')!,
        )!;
        const impact = document.getElementById(
            dialog.getAttribute('aria-describedby')!,
        )!;

        expect(title.textContent).toContain(f.confirmTitle);
        expect(impact.textContent).toContain(f.confirmImpact);
        expect(document.activeElement?.textContent).toContain(
            overlayLabels.cancel,
        );
        expect(
            byText('[role="alertdialog"] button', f.objectName).textContent,
        ).toContain(`Delete ${f.objectName}`);
    });

    it('cancels on Esc and returns focus to the invoker', async () => {
        const w = gallery();
        $('#invoker').focus();
        $('#invoker').click();
        await pause();

        key($('[role="alertdialog"]'), 'Escape');
        await pause(30);

        expect(document.querySelector('[role="alertdialog"]')).toBeNull();
        expect(document.activeElement).toBe($('#invoker'));
        expect($('[data-test="events"]').textContent).toContain('cancel');
        w.unmount();
        wrapper = null;
    });

    it('returns focus to the row when the invoker is gone', async () => {
        gallery();
        $('#invoker').focus();
        $('#invoker').click();
        await pause();

        byText('[role="alertdialog"] button', f.objectName).click();
        await pause(30);

        expect(document.getElementById('invoker')).toBeNull();
        expect(document.activeElement).toBe($('#row-1'));
    });
});

describe('unsaved changes dialog', () => {
    it('offers Save, Discard changes and Keep editing with focus on Keep editing', async () => {
        gallery();
        $('#unsaved-invoker').click();
        await pause();

        const dialog = $('[role="dialog"]');

        expect(dialog.textContent).toContain(en['unsaved-changes']);
        expect(dialog.textContent).toContain(overlayLabels.save);
        expect(dialog.textContent).toContain(overlayLabels.discardChanges);
        expect(document.activeElement?.textContent).toContain(
            overlayLabels.keepEditing,
        );
    });

    it('refuses a second dialog while one is open and keeps the first', async () => {
        gallery();
        $('#unsaved-invoker').click();
        await pause();
        document.getElementById('second-invoker')!.click();
        await pause();

        expect($('[data-test="events"]').textContent).toContain('refused');
        expect(document.querySelectorAll('[role="dialog"]')).toHaveLength(1);
        expect(document.querySelector('[role="alertdialog"]')).toBeNull();
    });
});

describe('toasts', () => {
    function store() {
        return useToasts();
    }

    it('keeps an action toast for at least 10 s, pauses on hover and while focus is inside', () => {
        vi.useFakeTimers();
        setActivePinia(createPinia());
        const toasts = store();
        const id = toasts.add({
            kind: 'action',
            message: 'Block added',
            actions: [{ label: 'Undo', run: () => undefined }],
        });

        vi.advanceTimersByTime(ACTION_TOAST_MS - 1);
        expect(toasts.items).toHaveLength(1);

        toasts.hold(id, 'hover');
        vi.advanceTimersByTime(ACTION_TOAST_MS * 3);
        expect(toasts.items).toHaveLength(1);

        toasts.hold(id, 'focus');
        toasts.release(id, 'hover');
        vi.advanceTimersByTime(ACTION_TOAST_MS * 3);
        expect(toasts.items).toHaveLength(1);

        toasts.release(id, 'focus');
        vi.advanceTimersByTime(ACTION_TOAST_MS);
        expect(toasts.items).toHaveLength(0);
    });

    it('keeps a plain success toast at least 5 s', () => {
        vi.useFakeTimers();
        setActivePinia(createPinia());
        const toasts = store();

        toasts.add({ kind: 'success', message: en.saved });
        vi.advanceTimersByTime(PLAIN_TOAST_MS - 1);
        expect(toasts.items).toHaveLength(1);
        vi.advanceTimersByTime(1);
        expect(toasts.items).toHaveLength(0);
    });

    it('announces an action toast politely naming the Undo route', async () => {
        gallery();
        useToasts().add({
            kind: 'action',
            message: 'Revenue added',
            announce: 'Revenue added. Undo with Ctrl+Z.',
        });
        await pause();

        expect(text('polite')).toBe('Revenue added. Undo with Ctrl+Z.');
    });

    it('renders an error toast as role=alert with an error icon that never auto-dismisses', async () => {
        vi.useFakeTimers();
        gallery();
        useToasts().add({ kind: 'rollback', message: 'We could not add it.' });
        await settle();

        const toast = $('[data-slot="toast"]');

        expect(toast.getAttribute('role')).toBe('alert');
        expect(toast.querySelector('[data-icon="error"]')).not.toBeNull();
        vi.advanceTimersByTime(10 * 60 * 1000);
        await settle();
        expect(document.querySelector('[data-slot="toast"]')).not.toBeNull();
    });

    it('sits in a region named Notifications with a 28px named Dismiss button', async () => {
        gallery();
        useToasts().add({ kind: 'success', message: en.saved });
        await settle();

        const region = $('[data-slot="toast-region"]');
        const dismiss = $('[data-slot="toast-dismiss"]');

        expect(region.getAttribute('role')).toBe('region');
        expect(region.getAttribute('aria-label')).toBe('Notifications');
        expect(dismiss.textContent).toContain('Dismiss notification');
        expect(dismiss.className).toContain('size-(--df-target-chrome)');

        dismiss.click();
        await settle();
        expect(document.querySelector('[data-slot="toast"]')).toBeNull();
    });

    it('runs the action callback and removes the toast', async () => {
        gallery();
        const run = vi.fn();

        useToasts().add({
            kind: 'action',
            message: 'x',
            actions: [{ label: 'Undo', run }],
        });
        await settle();
        $('[data-slot="toast-action"]').click();
        await settle();

        expect(run).toHaveBeenCalledOnce();
        expect(useToasts().items).toHaveLength(0);
    });

    it('shows "Go to notifications" while a toast shows and focuses the region', async () => {
        gallery();
        expect(document.body.textContent).not.toContain('Go to notifications');
        useToasts().add({ kind: 'info', message: 'hello' });
        await settle();

        byText('a', 'Go to notifications').click();
        expect(document.activeElement).toBe($('#notifications'));
    });
});

describe('overlay sheet', () => {
    it('is a modal dialog with a 45% scrim, a 44px close button and the toast stack above the footer', async () => {
        gallery();
        $('#sheet-invoker').click();
        await pause();

        const sheet = $('[data-slot="sheet-content"]');
        const close = $('[data-slot="sheet-close"]');

        expect(sheet.getAttribute('role')).toBe('dialog');
        expect($('[data-slot="sheet-overlay"]').className).toContain(
            'bg-scrim/45',
        );
        expect(close.className).toContain('size-(--df-target-touch)');
        expect(close.textContent).toContain('Close');

        useToasts().add({ kind: 'error', message: 'Failed' });
        await settle();

        const regions = document.querySelectorAll('[data-slot="toast-region"]');
        const footer = $('[data-slot="sheet-footer"]');

        expect(regions).toHaveLength(1);
        expect(sheet.contains(regions[0]!)).toBe(true);
        expect(regions[0]!.getAttribute('aria-label')).toBe('Notifications');
        // The footer is ordered last, so the stack shows above it.
        expect(footer.className).toContain('order-last');
    });
});

describe('listbox options', () => {
    it('keeps active and selected distinct', () => {
        gallery();
        const a = $('#opt-a');
        const b = $('#opt-b');
        const c = $('#opt-c');

        expect(a.classList.contains('listbox-option-active')).toBe(true);
        expect(a.classList.contains('listbox-option-selected')).toBe(false);
        expect(b.classList.contains('listbox-option-selected')).toBe(true);
        expect(b.classList.contains('listbox-option-active')).toBe(false);
        expect(a.getAttribute('aria-selected')).toBe('false');
        expect(b.getAttribute('aria-selected')).toBe('true');
        expect(c.classList.contains('listbox-option-active')).toBe(true);
        expect(c.classList.contains('listbox-option-selected')).toBe(true);
    });
});

describe('banners', () => {
    it('renders the five variants with an icon and a word', () => {
        gallery();
        const presets = [
            'reconnecting',
            'offline-editing',
            'api-changed',
            'live-shape-mismatch',
            'map-small-screen',
        ];

        for (const preset of presets) {
            const banner = $(`[data-preset="${preset}"]`);

            expect(banner.querySelector('svg')).not.toBeNull();
            expect(banner.querySelector('strong')!.textContent).toMatch(
                /Info|Warning|Error/,
            );
        }

        expect($('[data-preset="api-changed"]').textContent).toContain(
            'field revenue no longer present → Slot 2: Unavailable',
        );
        expect($('[data-preset="api-changed"]').className).toContain(
            'border-l-[3px]',
        );
        expect($('[data-preset="live-shape-mismatch"]').className).toContain(
            'bg-error-soft',
        );
        expect($('[data-preset="reconnecting"]').className).toContain(
            'bg-info-soft',
        );
    });

    it('dismisses a small-screen banner with a 28px button and moves focus to main', async () => {
        gallery();
        const banner = $('[data-preset="map-small-screen"]');
        const dismiss = banner.querySelector<HTMLElement>(
            '[data-slot="banner-dismiss"]',
        )!;

        expect(dismiss.className).toContain('size-(--df-target-chrome)');
        expect(dismiss.textContent).toContain('Dismiss');
        dismiss.focus();
        dismiss.click();
        await pause();

        expect(
            document.querySelector('[data-preset="map-small-screen"]'),
        ).toBeNull();
        expect(document.activeElement).toBe($('#main-content'));
    });

    it('does not block: a banner is plain content, not a modal', () => {
        gallery();
        const banner = $('[data-preset="map-small-screen"]');

        expect(banner.getAttribute('role')).toBeNull();
        expect(banner.getAttribute('aria-modal')).toBeNull();
    });
});

describe('offline editing', () => {
    it('shows the banner, blocks the save with its reason, and restores on reconnect', async () => {
        gallery();
        const save = $('#offline-save');

        expect(save.getAttribute('aria-disabled')).toBeNull();

        window.dispatchEvent(new Event('offline'));
        await settle();

        const banner = $(
            '[data-slot="banner-area"] [data-preset="offline-editing"]',
        );

        expect(banner.textContent).toContain(en['offline-editing']);
        expect(save.getAttribute('aria-disabled')).toBe('true');
        expect(
            document.getElementById('offline-reason')!.textContent,
        ).toContain(en['offline-editing']);

        save.click();
        await pause();
        expect($('[data-test="events"]').textContent).toBe('');
        expect(text('polite')).toBe(en['offline-editing']);

        window.dispatchEvent(new Event('online'));
        await settle();
        expect(save.getAttribute('aria-disabled')).toBeNull();
        expect(
            document.querySelector('[data-slot="banner-area"] [data-preset]'),
        ).toBeNull();
    });
});

describe('dashboard connection', () => {
    function probe(): void {
        const pinia = createPinia();
        const Probe = {
            template: '<span />',
            setup() {
                useConnectionAnnouncements({
                    reconnecting: en.reconnecting,
                    backOnline: en['back-online'],
                });
            },
        };

        wrapper = mount(Probe, {
            attachTo: document.body,
            global: { plugins: [pinia] },
        });
        initAnnouncer();
    }

    function watchOnce(): string[] {
        const region = $('[data-announcer="once"]');
        const writes: string[] = [];

        new MutationObserver(() =>
            writes.push(region.textContent ?? ''),
        ).observe(region, {
            childList: true,
            characterData: true,
            subtree: true,
        });

        return writes;
    }

    it('announces each of a normal outage and recovery once', async () => {
        vi.useFakeTimers();
        probe();
        window.dispatchEvent(new Event('offline'));
        await settle();
        vi.advanceTimersByTime(2100);
        expect(text('once')).toBe(en.reconnecting);
        expect($('[data-announcer="once"]').getAttribute('role')).toBe(
            'status',
        );

        window.dispatchEvent(new Event('online'));
        await settle();
        vi.advanceTimersByTime(2100);
        expect(text('once')).toBe(en['back-online']);
    });

    it('stays silent for a flap inside the settle window', async () => {
        vi.useFakeTimers();
        probe();
        const writes = watchOnce();

        for (const type of ['offline', 'online', 'offline', 'online']) {
            window.dispatchEvent(new Event(type));
            await settle();
            vi.advanceTimersByTime(300);
        }

        vi.advanceTimersByTime(5000);
        await Promise.resolve();
        expect(text('once')).toBe('');
        expect(writes.length).toBeLessThanOrEqual(1);
    });
});

describe('landmarks and skip link', () => {
    it('makes "Skip to content" the first focusable element and focuses main', async () => {
        gallery();
        const first = document.querySelector<HTMLElement>(
            'a[href], button, input, [tabindex="0"]',
        )!;

        expect(first.textContent).toContain('Skip to content');
        first.click();
        expect(document.activeElement).toBe($('main'));
    });

    it('does not animate toasts under reduced motion (CSS rule present)', async () => {
        const { readFileSync } = await import('node:fs');
        const css = readFileSync('resources/css/app.css', 'utf8');

        expect(css).toMatch(
            /prefers-reduced-motion: reduce\) \{\s*\.toast-enter-active,\s*\.toast-leave-active \{\s*transition: none;/,
        );
        expect(css).toContain("[data-slot='dialog-content']");
        expect(css).toContain("[data-slot='sheet-content']");
        expect(css).toContain('animation: none !important');
    });
});

function sheetOnly(): VueWrapper {
    const pinia = createPinia();

    setActivePinia(pinia);

    return mount(
        defineComponent({
            render: () =>
                h(Sheet, { open: true }, () =>
                    h(SheetContent, null, () => [
                        h(SheetTitle, null, () => 'T'),
                        h(SheetDescription, null, () => 'D'),
                        h(SheetFooter, null, () => 'F'),
                    ]),
                ),
        }),
        {
            attachTo: document.body,
            global: { plugins: [pinia, createCatalogue()] },
        },
    );
}

describe('every sheet hosts the toast region', () => {
    it('renders the stack inside a plain Sheet and the layout region steps aside', async () => {
        wrapper = sheetOnly();
        await pause();
        const outer = mount(ToastRegion, {
            attachTo: document.body,
            global: { plugins: [getActivePinia()!] },
        });

        useToasts().add({ kind: 'error', message: 'Nope' });
        await settle();

        const regions = document.querySelectorAll('[data-slot="toast-region"]');

        expect(regions).toHaveLength(1);
        expect($('[data-slot="sheet-content"]').contains(regions[0]!)).toBe(
            true,
        );
        outer.unmount();
    });
});

describe('confirm dialog events', () => {
    function confirmDialog(events: string[]): VueWrapper {
        const pinia = createPinia();

        setActivePinia(pinia);

        return mount(
            defineComponent({
                render: () =>
                    h(ConfirmDialog, {
                        open: true,
                        title: 'T',
                        description: 'D',
                        objectName: 'Thing',
                        onConfirm: () => events.push('confirm'),
                        onCancel: () => events.push('cancel'),
                    }),
            }),
            {
                attachTo: document.body,
                global: { plugins: [pinia, createCatalogue()] },
            },
        );
    }

    it('emits confirm and not cancel when the destructive button is used', async () => {
        const events: string[] = [];

        wrapper = confirmDialog(events);
        await pause();
        byText('[role="alertdialog"] button', 'Thing').click();
        await pause(30);

        expect(events).toEqual(['confirm']);
    });

    it('emits cancel on Esc and on Cancel', async () => {
        const events: string[] = [];

        wrapper = confirmDialog(events);
        await pause();
        key($('[role="alertdialog"]'), 'Escape');
        await pause(30);
        expect(events).toEqual(['cancel']);

        wrapper.unmount();
        document.body.innerHTML = '';
        events.length = 0;
        wrapper = confirmDialog(events);
        await pause();
        byText('[role="alertdialog"] button', 'Cancel').click();
        await pause(30);
        expect(events).toEqual(['cancel']);
    });
});

describe('toast focus and input', () => {
    it('moves focus to the next toast Dismiss, then the previous element, then main', async () => {
        gallery();
        const before = $('#unsaved-invoker');

        useToasts().add({ kind: 'error', message: 'one' });
        useToasts().add({ kind: 'error', message: 'two' });
        await settle();

        const dismisses = document.querySelectorAll<HTMLElement>(
            '[data-slot="toast-dismiss"]',
        );

        before.focus();
        dismisses[0]!.focus();
        dismisses[0]!.click();
        await settle();
        const remaining = $('[data-slot="toast-dismiss"]');

        expect(document.activeElement).toBe(remaining);

        remaining.click();
        await settle();
        expect(document.activeElement).toBe(before);

        useToasts().add({ kind: 'error', message: 'three' });
        await settle();
        $('[data-slot="toast-dismiss"]').focus();
        before.remove();
        $('[data-slot="toast-dismiss"]').click();
        await settle();
        expect(document.activeElement).toBe($('#main-content'));
    });

    it('holds on mouse hover only, not on touch', async () => {
        vi.useFakeTimers();
        gallery();
        const id = useToasts().add({ kind: 'success', message: 'hi' });

        await settle();
        const toast = $('[data-slot="toast"]');
        const touch = new Event('pointerenter');

        Object.defineProperty(touch, 'pointerType', { value: 'touch' });
        toast.dispatchEvent(touch);
        vi.advanceTimersByTime(PLAIN_TOAST_MS);
        expect(useToasts().items.find((t) => t.id === id)).toBeUndefined();

        const id2 = useToasts().add({ kind: 'success', message: 'again' });

        await settle();
        const mouse = new Event('pointerenter');

        Object.defineProperty(mouse, 'pointerType', { value: 'mouse' });
        $('[data-slot="toast"]').dispatchEvent(mouse);
        vi.advanceTimersByTime(PLAIN_TOAST_MS * 3);
        expect(useToasts().items.find((t) => t.id === id2)).toBeDefined();
        $('[data-slot="toast"]').dispatchEvent(new Event('pointerleave'));
        vi.advanceTimersByTime(PLAIN_TOAST_MS);
        expect(useToasts().items).toHaveLength(0);
    });

    it('dismisses the toast even when an action throws, and keys actions by index', async () => {
        gallery();
        useToasts().add({
            kind: 'action',
            message: 'x',
            actions: [
                {
                    label: 'Same',
                    run: () => {
                        throw new Error('boom');
                    },
                },
                { label: 'Same', run: () => undefined },
            ],
        });
        await settle();

        const buttons = document.querySelectorAll<HTMLElement>(
            '[data-slot="toast-action"]',
        );

        expect(buttons).toHaveLength(2);
        try {
            buttons[0]!.click();
        } catch {
            // The action's error still surfaces; the toast must go regardless.
        }
        await settle();
        expect(useToasts().items).toHaveLength(0);
    });
});
