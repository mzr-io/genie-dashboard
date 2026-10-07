/** Copies text to the clipboard. Resolves false, never throws, when the browser refuses. */
export async function copyText(text: string): Promise<boolean> {
    try {
        await navigator.clipboard.writeText(text);

        return true;
    } catch {
        return false;
    }
}
