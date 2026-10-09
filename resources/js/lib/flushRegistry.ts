// The flush registry (Story 3.5): a page that holds unsaved work registers a function here, and the session-expiry warning calls every
// one when it opens and again a few seconds before the session ends, so the work reaches the server (or is kept for the next sign-in)
// while the session still lives. A flush resolves true when the work is safe: saved, kept for sign-in, or there was nothing to keep.
//
//   const stop = registerFlush(async () => ...);
//   onBeforeUnmount(stop);
export type Flush = () => boolean | Promise<boolean>;

const registry = new Set<Flush>();

// Returns the function that unregisters the flush.
export function registerFlush(flush: Flush): () => void {
    registry.add(flush);

    return () => {
        registry.delete(flush);
    };
}

// Runs every registered flush. True only when all of them say their work is safe; a flush that throws counts as not safe.
export async function runFlushes(): Promise<boolean> {
    const results = await Promise.all(
        [...registry].map(async (flush) => {
            try {
                return await flush();
            } catch {
                return false;
            }
        }),
    );

    return results.every(Boolean);
}
