import { describe, expect, it } from 'vitest';
import { resolveFooterLink } from '../../resources/js/lib/footerAction';

// Story 4.6: a footer action's target. `{user.*}` tokens are filled in the browser and percent-encoded for their position; scheme and host stay literal;
// anything that is not http(s) or an in-app path is never a link.
describe('resolveFooterLink', () => {
    const profile = { email: 'ada+test@example.com', name: 'Ada L' };

    it('fills tokens from the profile, percent-encoded, and opens an external link in a new tab', () => {
        expect(
            resolveFooterLink(
                'https://erp.example.com/approvals?user={user.email}&n={user.name}',
                profile,
            ),
        ).toEqual({
            href: 'https://erp.example.com/approvals?user=ada%2Btest%40example.com&n=Ada%20L',
            external: true,
        });
    });

    it('encodes a value that tries to change the structure of the URL', () => {
        const link = resolveFooterLink(
            'https://erp.example.com/a?u={user.name}',
            {
                name: '../../evil?x=1#frag&y=2',
            },
        );

        expect(link?.href).toBe(
            'https://erp.example.com/a?u=..%2F..%2Fevil%3Fx%3D1%23frag%26y%3D2',
        );
    });

    it('never links a javascript:, data: or other non-http(s) URL', () => {
        for (const bad of [
            'javascript:alert(1)',
            'JaVaScRiPt:alert(1)',
            'data:text/html,<script>alert(1)</script>',
            'ftp://example.com/x',
            'file:///etc/passwd',
            '//evil.example.com/x',
            'mailto:a@example.com',
        ]) {
            expect(resolveFooterLink(bad, profile), bad).toBeNull();
        }
    });

    it('refuses a token in the scheme or the host and embedded credentials', () => {
        expect(
            resolveFooterLink('https://{user.name}.example.com/', profile),
        ).toBeNull();
        expect(resolveFooterLink('https://{user.name}/', profile)).toBeNull();
        expect(
            resolveFooterLink('https://user:pw@example.com/x', profile),
        ).toBeNull();
    });

    it('accepts an in-app route that stays in the tab', () => {
        expect(resolveFooterLink('/dashboards', profile)).toEqual({
            href: '/dashboards',
            external: false,
        });
        expect(resolveFooterLink('/a\\b', profile)).toBeNull();
    });

    it('fills a missing profile value as empty, never as "undefined"', () => {
        expect(
            resolveFooterLink('https://erp.example.com/?u={user.email}', {}),
        ).toEqual({ href: 'https://erp.example.com/?u=', external: true });
    });
});
