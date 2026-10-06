// Minimal polite live region (UX-DR-22, 272). One hidden node is created on first use and
// reused, so a blocked control can speak its reason. Story 1.9 extends this with assertive
// announcements, queueing and the connection and toast regions.
let region: HTMLElement | null = null;

function ensureRegion(): HTMLElement | null {
    if (typeof document === 'undefined') {
        return null;
    }

    if (region && region.isConnected) {
        return region;
    }

    region = document.createElement('div');
    region.setAttribute('role', 'status');
    region.setAttribute('aria-live', 'polite');
    region.setAttribute('aria-atomic', 'true');
    region.setAttribute('data-announcer', '');
    region.className = 'sr-only';
    document.body.appendChild(region);

    return region;
}

export function announce(message: string): void {
    const node = ensureRegion();

    if (!node) {
        return;
    }

    // Repeating the same text is only re-read when the node content changes.
    node.textContent = node.textContent === message ? `${message} ` : message;
}

export function resetAnnouncer(): void {
    region?.remove();
    region = null;
}
