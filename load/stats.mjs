/** Nearest-rank percentile of an unsorted list of numbers; null for an empty list. */
export function percentile(values, p) {
    if (values.length === 0) {
        return null;
    }

    const sorted = [...values].sort((a, b) => a - b);
    const rank = Math.max(1, Math.ceil((p / 100) * sorted.length));

    return sorted[rank - 1];
}

const round = (value) =>
    value === null ? null : Math.round(value * 100) / 100;

/**
 * p50 and p95 use successful (HTTP 200) requests only; failed requests are counted and their latency is
 * reported separately, so errors never flatter or distort the percentiles.
 */
export function summarise({
    latencies,
    errorLatencies = [],
    sessionLost = 0,
    seconds,
    p95TargetMs,
}) {
    const p95 = percentile(latencies, 95);
    const total = latencies.length + errorLatencies.length;

    return {
        requests: total,
        ok_requests: latencies.length,
        errors: errorLatencies.length,
        session_lost: sessionLost,
        seconds: round(seconds),
        request_rate: round(seconds > 0 ? total / seconds : 0),
        p50_ms: round(percentile(latencies, 50)),
        p95_ms: round(p95),
        error_p95_ms: round(percentile(errorLatencies, 95)),
        p95_target_ms: p95TargetMs,
        p95_within_target: p95 === null ? null : p95 <= p95TargetMs,
    };
}
