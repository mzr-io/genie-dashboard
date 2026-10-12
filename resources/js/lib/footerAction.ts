// A Block's footer action target (Story 4.6; FR-34, UX-DR-98): an external http(s) URL template with `{user.*}` tokens, or an in-app route. The tokens
// are filled in the browser from the session profile and percent-encoded for their position; the scheme and host stay literal, so a profile value can
// never change where the link goes. Anything that is not http(s) or an in-app path is never a link.
export type FooterProfile = Record<string, string | null | undefined>;

export type FooterLink = {
    href: string;
    // An external link opens in a new tab and shows ↗; an in-app route stays in the tab.
    external: boolean;
};

const TOKEN = /\{user\.([a-z_]+)\}/g;
const HAS_TOKEN = /\{user\.[a-z_]+\}/;

export function resolveFooterLink(
    template: string,
    profile: FooterProfile,
): FooterLink | null {
    const text = template.trim();

    // An in-app route: one leading slash, no scheme, no protocol-relative form.
    if (
        text.startsWith('/') &&
        !text.startsWith('//') &&
        !/[\\\s]/.test(text)
    ) {
        return { href: text, external: false };
    }

    let url: URL;

    try {
        // The scheme and host come from the template with its tokens still in it, so a token can only ever land after them.
        url = new URL(text.replace(TOKEN, 'x'));
    } catch {
        return null;
    }

    if (url.protocol !== 'https:' && url.protocol !== 'http:') {
        return null;
    }

    if (url.username !== '' || url.password !== '') {
        return null;
    }

    const authorityEnd = text.indexOf('/', text.indexOf('//') + 2);
    const head = authorityEnd === -1 ? text : text.slice(0, authorityEnd);

    // A token in the scheme or host is refused: it would let a value pick the destination.
    if (HAS_TOKEN.test(head)) {
        return null;
    }

    const filled = text.replace(TOKEN, (_match, key: string) => {
        const value = profile[key];

        return encodeURIComponent(typeof value === 'string' ? value : '');
    });

    try {
        const resolved = new URL(filled);

        return resolved.protocol === 'https:' || resolved.protocol === 'http:'
            ? { href: resolved.href, external: true }
            : null;
    } catch {
        return null;
    }
}
