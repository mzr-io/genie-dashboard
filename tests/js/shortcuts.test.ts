// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    clearShortcuts,
    dispatchShortcut,
    installShortcuts,
    registerShortcut,
    setSingleKeyShortcuts,
    singleKeyShortcutsEnabled,
} from '../../resources/js/lib/shortcuts';

function key(init: KeyboardEventInit & { key: string }, target?: HTMLElement) {
    const event = new KeyboardEvent('keydown', {
        bubbles: true,
        cancelable: true,
        ...init,
    });

    if (target) {
        Object.defineProperty(event, 'target', { value: target });
    }

    return event;
}

beforeEach(() => {
    clearShortcuts();
    setSingleKeyShortcuts(true);
    document.body.innerHTML = '';
});

afterEach(() => clearShortcuts());

describe('shortcut registry (UX-DR-269)', () => {
    it('runs single-key and modifier shortcuts while the switch is On, and defaults to On', () => {
        const add = vi.fn();
        const undo = vi.fn();
        registerShortcut({ id: 'add', key: 'a', run: add });
        registerShortcut({
            id: 'undo',
            key: 'z',
            modifiers: ['mod'],
            run: undo,
        });

        expect(singleKeyShortcutsEnabled()).toBe(true);
        expect(dispatchShortcut(key({ key: 'a' }))).toBe(true);
        expect(dispatchShortcut(key({ key: 'z', ctrlKey: true }))).toBe(true);
        expect(dispatchShortcut(key({ key: 'z', metaKey: true }))).toBe(true);
        expect(add).toHaveBeenCalledTimes(1);
        expect(undo).toHaveBeenCalledTimes(2);
    });

    it('disables single-key shortcuts when the switch is Off but keeps modifier shortcuts', () => {
        const add = vi.fn();
        const search = vi.fn();
        const undo = vi.fn();
        registerShortcut({ id: 'add', key: 'a', run: add });
        registerShortcut({ id: 'move', key: 'm', run: vi.fn() });
        registerShortcut({
            id: 'search',
            key: 'k',
            modifiers: ['mod'],
            run: search,
        });
        registerShortcut({
            id: 'undo',
            key: 'z',
            modifiers: ['mod'],
            run: undo,
        });
        registerShortcut({
            id: 'redo',
            key: 'z',
            modifiers: ['mod', 'shift'],
            run: vi.fn(),
        });

        setSingleKeyShortcuts(false);

        expect(dispatchShortcut(key({ key: 'a' }))).toBe(false);
        expect(dispatchShortcut(key({ key: 'm' }))).toBe(false);
        expect(add).not.toHaveBeenCalled();
        expect(dispatchShortcut(key({ key: 'k', ctrlKey: true }))).toBe(true);
        expect(dispatchShortcut(key({ key: 'z', metaKey: true }))).toBe(true);
        expect(search).toHaveBeenCalledTimes(1);
        expect(undo).toHaveBeenCalledTimes(1);

        setSingleKeyShortcuts(true);
        expect(dispatchShortcut(key({ key: 'a' }))).toBe(true);
    });

    it('does not intercept widget keys: nothing is registered for them and they pass through', () => {
        registerShortcut({ id: 'add', key: 'a', run: vi.fn() });
        setSingleKeyShortcuts(false);

        for (const widgetKey of [
            'ArrowDown',
            'ArrowUp',
            'Enter',
            ' ',
            'Escape',
            'Tab',
        ]) {
            expect(dispatchShortcut(key({ key: widgetKey }))).toBe(false);
        }
    });

    it('never fires a single-key shortcut while focus is in a text field', () => {
        const run = vi.fn();
        registerShortcut({ id: 'add', key: 'a', run });

        for (const html of [
            '<input type="text">',
            '<input>',
            '<textarea></textarea>',
            '<input type="search">',
            '<select></select>',
            '<div contenteditable="true"></div>',
        ]) {
            document.body.innerHTML = html;
            const field = document.body.firstElementChild as HTMLElement;
            Object.defineProperty(field, 'isContentEditable', {
                value: field.hasAttribute('contenteditable'),
                configurable: true,
            });

            expect(dispatchShortcut(key({ key: 'a' }, field)), html).toBe(
                false,
            );
        }

        document.body.innerHTML = '<button></button><input type="checkbox">';
        expect(
            dispatchShortcut(
                key(
                    { key: 'a' },
                    document.querySelector('button') as HTMLElement,
                ),
            ),
        ).toBe(true);
        expect(
            dispatchShortcut(
                key(
                    { key: 'a' },
                    document.querySelector('input') as HTMLElement,
                ),
            ),
        ).toBe(true);
        expect(run).toHaveBeenCalledTimes(2);
    });

    it('fires a scoped single-key shortcut only while its component has focus', () => {
        document.body.innerHTML =
            '<div id="drawer"><button id="row">Row</button></div><button id="outside">Out</button>';
        const run = vi.fn();
        registerShortcut({
            id: 'add',
            key: 'a',
            scope: () => document.getElementById('drawer'),
            run,
        });

        document.getElementById('outside')!.focus();
        expect(dispatchShortcut(key({ key: 'a' }))).toBe(false);

        document.getElementById('row')!.focus();
        expect(dispatchShortcut(key({ key: 'a' }))).toBe(true);
        expect(run).toHaveBeenCalledTimes(1);
    });

    it('matches the exact modifiers and ignores held keys, composition and handled events', () => {
        const plain = vi.fn();
        const modified = vi.fn();
        registerShortcut({ id: 'plain', key: 'a', run: plain });
        registerShortcut({
            id: 'modified',
            key: 'b',
            modifiers: ['mod'],
            run: modified,
        });

        expect(dispatchShortcut(key({ key: 'a', ctrlKey: true }))).toBe(false);
        expect(dispatchShortcut(key({ key: 'a', altKey: true }))).toBe(false);
        expect(dispatchShortcut(key({ key: 'b' }))).toBe(false);
        expect(dispatchShortcut(key({ key: 'a', repeat: true }))).toBe(false);
        expect(dispatchShortcut(key({ key: 'A' }))).toBe(true);

        const handled = key({ key: 'a' });
        handled.preventDefault();
        expect(dispatchShortcut(handled)).toBe(false);
        expect(plain).toHaveBeenCalledTimes(1);
        expect(modified).not.toHaveBeenCalled();
    });

    it('unregisters, and one listener drives the registry', () => {
        const run = vi.fn();
        const stopShortcut = registerShortcut({ id: 'add', key: 'a', run });
        const stopListener = installShortcuts();

        document.dispatchEvent(key({ key: 'a' }));
        expect(run).toHaveBeenCalledTimes(1);

        stopShortcut();
        document.dispatchEvent(key({ key: 'a' }));
        expect(run).toHaveBeenCalledTimes(1);

        registerShortcut({ id: 'add', key: 'a', run });
        stopListener();
        document.dispatchEvent(key({ key: 'a' }));
        expect(run).toHaveBeenCalledTimes(1);
    });

    it('ignores a keydown whose key is not a string (autofill)', () => {
        registerShortcut({ id: 'add', key: 'a', run: vi.fn() });
        const event = key({ key: 'a' });
        Object.defineProperty(event, 'key', { value: undefined });

        expect(dispatchShortcut(event)).toBe(false);
    });

    it('treats a textbox, combobox or searchbox role (and its children) as a text field', () => {
        const run = vi.fn();
        registerShortcut({ id: 'add', key: 'a', run });

        for (const role of ['textbox', 'combobox', 'searchbox']) {
            document.body.innerHTML = `<div role="${role}"><span id="inner">x</span></div>`;

            expect(
                dispatchShortcut(
                    key(
                        { key: 'a' },
                        document.getElementById('inner') as HTMLElement,
                    ),
                ),
                role,
            ).toBe(false);
        }

        expect(run).not.toHaveBeenCalled();
    });

    it('warns when an id is registered twice', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
        registerShortcut({ id: 'add', key: 'a', run: vi.fn() });
        expect(warn).not.toHaveBeenCalled();

        registerShortcut({ id: 'add', key: 'b', run: vi.fn() });
        expect(warn).toHaveBeenCalledTimes(1);
        warn.mockRestore();
    });
});
