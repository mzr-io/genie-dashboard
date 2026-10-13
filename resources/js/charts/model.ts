// The chart model (Story 5.8b): everything about a line or area plot that needs no browser. A Block Type's Renderer hands `useChart` a
// `ChartInput` of decimal strings and already-formatted text; this module judges it (styles, legend, the generated label, the keyboard model, the
// tooltip text) and makes the ECharts option. Numbers become floats only here, for position: every text a reader sees is formatted by the caller from
// the decimal string, so a lexeme beyond double precision is shown exactly.

export type ChartSeries = {
    key: string;
    name: string;
    // One decimal string per x position, null where a row has no value (a gap, never 0).
    values: (string | null)[];
};

export type ChartInput = {
    kind: 'line' | 'area';
    // The axis labels, already formatted in the viewer's locale.
    x: string[];
    series: ChartSeries[];
    // "Jan to Dec 2026": the range the generated label names.
    range: string;
    // The exact formatted decimal (tooltip, announcement, label), and the compact axis tick ("$40k").
    format: (value: string) => string;
    compact: (value: string) => string;
    target?: { value: string; label: string } | null;
    // The x position of the highlighted point (first series).
    highlight?: number | null;
    // Direct label at the line end (default on).
    directLabels?: boolean;
};

export const DASHES = [
    'solid',
    'dashed',
    'dotted',
    'dash-dot',
    'long-dash',
    'dotted-fine',
] as const;

// Spoken names for the legend list: marker and dash, never colour alone.
export const DASH_NAMES = [
    'solid line',
    'dashed line',
    'dotted line',
    'dash-dot line',
    'long-dash line',
    'fine-dotted line',
];

export const MARKERS = [
    'circle',
    'rect',
    'triangle',
    'diamond',
    'path://M-1,-1L1,1M-1,1L1,-1',
    'emptyCircle',
] as const;
export const MARKER_NAMES = [
    'circle markers',
    'square markers',
    'triangle markers',
    'diamond markers',
    'cross markers',
    'hollow circle markers',
];
export const MARKER_GLYPHS = ['●', '■', '▲', '◆', '✕', '○'];

export const MAX_SERIES = 6;
// Up to this many points every point carries its marker; beyond it only the line end does.
export const MARKER_POINTS = 24;

// ECharts dash arrays for the six styles.
const DASH_ARRAYS: (number[] | null)[] = [
    null,
    [8, 4],
    [2, 4],
    [8, 4, 2, 4],
    [14, 5],
    [1, 3],
];

export function styleOf(index: number): {
    dash: number[] | null;
    marker: string;
    dashName: string;
    markerName: string;
    glyph: string;
} {
    const at = Math.min(Math.max(index, 0), MAX_SERIES - 1);

    return {
        dash: DASH_ARRAYS[at],
        marker: MARKERS[at],
        dashName: DASH_NAMES[at],
        markerName: MARKER_NAMES[at],
        glyph: MARKER_GLYPHS[at],
    };
}

// "Revenue, solid line, circle markers": the legend names marker and dash.
export function legendEntries(
    series: ChartSeries[],
): { key: string; glyph: string; text: string }[] {
    return series.slice(0, MAX_SERIES).map((entry, index) => {
        const style = styleOf(index);

        return {
            key: entry.key,
            glyph: style.glyph,
            text: `${entry.name}, ${style.dashName}, ${style.markerName}`,
        };
    });
}

const DECIMAL = /^([+-]?)(\d*)\.?(\d*)(?:[eE]([+-]?\d+))?$/;

// An exact comparison of two decimal strings: -1, 0 or 1, null when either is not a decimal.
export function compareDecimal(a: string, b: string): -1 | 0 | 1 | null {
    const x = parts(a);
    const y = parts(b);

    if (x === null || y === null) {
        return null;
    }

    const places = Math.max(x.places, y.places);
    const left = x.n * 10n ** BigInt(places - x.places);
    const right = y.n * 10n ** BigInt(places - y.places);

    return left === right ? 0 : left < right ? -1 : 1;
}

function parts(value: string): { n: bigint; places: number } | null {
    const match = DECIMAL.exec(value.trim());

    if (match === null || match[2] + match[3] === '') {
        return null;
    }

    const exponent = Number(match[4] ?? 0);

    if (!Number.isInteger(exponent) || Math.abs(exponent) > 400) {
        return null;
    }

    let n = BigInt(match[2] + match[3]);
    let places = match[3].length - exponent;

    if (places < 0) {
        n *= 10n ** BigInt(-places);
        places = 0;
    }

    return { n: match[1] === '-' ? -n : n, places };
}

// A float for position only; null for a gap.
export function position(value: string | null): number | null {
    if (value === null || parts(value) === null) {
        return null;
    }

    const number = Number(value);

    return Number.isFinite(number) ? number : null;
}

// Where the first series is lowest and highest and whether it ended above where it began, by exact comparison.
export function extremes(values: (string | null)[]): {
    low: number;
    high: number;
    trend: 'up' | 'down' | 'flat';
} | null {
    let low = -1;
    let high = -1;
    let first = -1;
    let last = -1;

    values.forEach((value, index) => {
        if (value === null || parts(value) === null) {
            return;
        }

        first = first === -1 ? index : first;
        last = index;
        low =
            low === -1 || compareDecimal(value, values[low] as string) === -1
                ? index
                : low;
        high =
            high === -1 || compareDecimal(value, values[high] as string) === 1
                ? index
                : high;
    });

    if (first === -1) {
        return null;
    }

    const order = compareDecimal(
        values[last] as string,
        values[first] as string,
    );

    return {
        low,
        high,
        trend: order === 1 ? 'up' : order === -1 ? 'down' : 'flat',
    };
}

// "Line chart, Revenue, Jan to Dec 2026. Lowest $10.2k in Jan, highest $40.1k in Dec, trend up. Target $35k": regenerated whenever the input is.
export function chartLabel(input: ChartInput): string {
    const kind = input.kind === 'area' ? 'Area chart' : 'Line chart';
    const names = input.series.map((entry) => entry.name).join(', ');
    const parts: string[] = [
        `${kind}, ${names === '' ? 'no series' : names}${input.range === '' ? '' : `, ${input.range}`}.`,
    ];
    const first = input.series[0];
    const shape = first === undefined ? null : extremes(first.values);

    if (first !== undefined && shape !== null) {
        const at = (index: number): string =>
            `${input.format(first.values[index] as string)} in ${input.x[index] ?? ''}`;

        parts.push(
            `Lowest ${at(shape.low)}, highest ${at(shape.high)}, trend ${shape.trend}.`,
        );
    }

    if (input.target) {
        parts.push(
            `Target ${input.format(input.target.value)}${input.target.label === '' ? '' : `, ${input.target.label}`}.`,
        );
    }

    return parts.join(' ');
}

export type FocusState = { series: number; point: number } | null;

export type KeyResult = {
    state: FocusState;
    // Esc: the plot leaves point mode.
    left: boolean;
    handled: boolean;
};

// The keyboard model: the plot is one tab stop; ← → step the points, Home and End go to the ends, ↑ ↓ switch series, Esc leaves point mode.
export function reduceKey(
    state: FocusState,
    key: string,
    seriesCount: number,
    pointCount: number,
): KeyResult {
    const none = { state, left: false, handled: false };

    if (seriesCount < 1 || pointCount < 1) {
        return none;
    }

    if (key === 'Escape') {
        return { state: null, left: state !== null, handled: state !== null };
    }

    const at = state ?? { series: 0, point: 0 };
    const clamp = (n: number, max: number): number =>
        Math.min(Math.max(n, 0), max);

    switch (key) {
        case 'ArrowRight':
            return {
                state: {
                    ...at,
                    point:
                        state === null
                            ? 0
                            : clamp(at.point + 1, pointCount - 1),
                },
                left: false,
                handled: true,
            };
        case 'ArrowLeft':
            return {
                state: {
                    ...at,
                    point:
                        state === null
                            ? 0
                            : clamp(at.point - 1, pointCount - 1),
                },
                left: false,
                handled: true,
            };
        case 'Home':
            return { state: { ...at, point: 0 }, left: false, handled: true };
        case 'End':
            return {
                state: { ...at, point: pointCount - 1 },
                left: false,
                handled: true,
            };
        case 'ArrowUp':
            return {
                state: { ...at, series: clamp(at.series - 1, seriesCount - 1) },
                left: false,
                handled: true,
            };
        case 'ArrowDown':
            return {
                state: { ...at, series: clamp(at.series + 1, seriesCount - 1) },
                left: false,
                handled: true,
            };
        default:
            return none;
    }
}

// "Revenue, March, $21.4k": the polite announcement of the focused point.
export function announce(input: ChartInput, focus: FocusState): string {
    if (focus === null) {
        return '';
    }

    const series = input.series[focus.series];
    const value = series?.values[focus.point] ?? null;

    if (series === undefined) {
        return '';
    }

    return `${series.name}, ${input.x[focus.point] ?? ''}, ${value === null ? 'no value' : input.format(value)}`;
}

// The tooltip's text: every piece is encoded with the library's own `encodeHTML` (passed in), so a name or a value from the data is text, never markup.
export function tooltipText(
    input: ChartInput,
    point: number,
    encode: (text: string) => string,
    series: number | null = null,
): string {
    const rows = input.series
        .map((entry, index) => ({ entry, index }))
        .filter(({ index }) => series === null || index === series)
        .map(({ entry, index }) => {
            const value = entry.values[point] ?? null;

            return `<div>${encode(styleOf(index).glyph)} ${encode(entry.name)}: <b>${encode(value === null ? '—' : input.format(value))}</b></div>`;
        });

    return `<div>${encode(input.x[point] ?? '')}</div>${rows.join('')}`;
}

export type ChartPalette = {
    strokes: string[];
    grid: string;
    text: string;
    focus: string;
    // The card's own surface: the ring of a highlighted point and the end of an area's gradient.
    surface: string;
};

// The ECharts option: horizontal gridlines only, compact Y ticks, a 2px line with a monotone curve and no markers at rest for one series, the six
// dash and marker styles for several, the gradient fill under series 1 for an area, a target line and the highlighted point.
export function chartOption(
    input: ChartInput,
    palette: ChartPalette,
    encode: (text: string) => string,
): Record<string, unknown> {
    const many = input.series.length > 1;
    const points = input.x.length;
    const series = input.series.slice(0, MAX_SERIES).map((entry, index) => {
        const style = styleOf(index);
        const color =
            palette.strokes[index] ?? palette.strokes[0] ?? palette.text;
        const everyPoint = many && points <= MARKER_POINTS;
        const data = entry.values.map((value, at) => {
            const isEnd = at === lastIndex(entry.values);
            const marked =
                (many && !everyPoint && isEnd) ||
                (input.highlight === at && index === 0);

            return {
                value: position(value),
                ...(marked
                    ? {
                          symbol:
                              input.highlight === at && index === 0
                                  ? 'circle'
                                  : style.marker,
                          symbolSize:
                              input.highlight === at && index === 0 ? 10 : 7,
                          ...(input.highlight === at && index === 0
                              ? {
                                    itemStyle: {
                                        color: palette.surface,
                                        borderColor: color,
                                        borderWidth: 2,
                                    },
                                }
                              : {}),
                      }
                    : {}),
            };
        });

        return {
            type: 'line',
            name: entry.name,
            smooth: 'monotone',
            connectNulls: false,
            symbol: everyPoint ? style.marker : 'none',
            symbolSize: 7,
            data,
            lineStyle: {
                width: 2,
                color,
                ...(style.dash === null ? {} : { type: style.dash }),
            },
            itemStyle: { color },
            emphasis: { focus: 'series' },
            ...(input.kind === 'area' && index === 0
                ? {
                      areaStyle: {
                          color: {
                              type: 'linear',
                              x: 0,
                              y: 0,
                              x2: 0,
                              y2: 1,
                              colorStops: [
                                  { offset: 0, color },
                                  { offset: 1, color: palette.surface },
                              ],
                          },
                          opacity: 0.25,
                      },
                  }
                : {}),
            ...(many && input.directLabels !== false
                ? {
                      endLabel: {
                          show: true,
                          color: palette.text,
                          formatter: () => entry.name,
                      },
                  }
                : {}),
            ...(index === 0 && input.target
                ? {
                      markLine: {
                          silent: true,
                          symbol: 'none',
                          lineStyle: {
                              type: 'dashed',
                              color: palette.text,
                              width: 1,
                          },
                          label: {
                              formatter: () =>
                                  input.target?.label ||
                                  input.format(input.target?.value ?? ''),
                          },
                          data: [{ yAxis: position(input.target.value) ?? 0 }],
                      },
                  }
                : {}),
        };
    });

    return {
        animation: false,
        grid: {
            left: 8,
            right: many ? 72 : 16,
            top: 16,
            bottom: 8,
            containLabel: true,
        },
        tooltip: {
            trigger: 'axis',
            confine: true,
            position: 'top',
            formatter: (params: unknown) => {
                const list = Array.isArray(params) ? params : [params];
                const at = Number(
                    (list[0] as { dataIndex?: number })?.dataIndex ?? 0,
                );

                return tooltipText(input, at, encode);
            },
        },
        xAxis: {
            type: 'category',
            data: input.x,
            boundaryGap: false,
            axisLabel: { color: palette.text, hideOverlap: true },
            axisLine: { lineStyle: { color: palette.grid } },
            splitLine: { show: false },
        },
        yAxis: {
            type: 'value',
            scale: true,
            axisLabel: {
                color: palette.text,
                formatter: (value: number) => input.compact(String(value)),
            },
            splitLine: { show: true, lineStyle: { color: palette.grid } },
        },
        series,
    };
}

function lastIndex(values: (string | null)[]): number {
    for (let at = values.length - 1; at >= 0; at--) {
        if (values[at] !== null) {
            return at;
        }
    }

    return -1;
}
