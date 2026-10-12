<?php

namespace App\Platform\Layout;

/**
 * The 12-column dashboard grid (Story 4.4; AD-14), a pure kernel shared by Dashboards, Templates and the `next_free_slot` hint. A rectangle is
 * `col` (0 to 11), `row` (60 px units, from 0), `width` in columns (a preset) and `height` (a preset that is a whole number of 60 px units). Clamping
 * never pushes a neighbour: it only brings one rectangle into the limits it was given.
 */
final class Grid
{
    public const COLUMNS = 12;

    /** The width presets, in columns. */
    public const WIDTHS = [3, 4, 6, 8, 12];

    /** The height presets and their size in 60 px units: Small 120 px, Medium 360 px, Large 540 px. */
    public const HEIGHTS = ['small' => 2, 'medium' => 6, 'large' => 9];

    /**
     * Brings a rectangle into the grid and into the Block's own size limits: the width becomes the nearest preset within `[minWidth, maxWidth]`
     * (the smaller one on a tie), the height the nearest preset within `[minHeight, maxHeight]`, the column keeps the rectangle inside the grid
     * and the row is not negative.
     *
     * @return array{col: int, row: int, width: int, height: string}
     */
    public static function clamp(int $col, int $row, int $width, string $height, int $minWidth = 3, int $maxWidth = self::COLUMNS, string $minHeight = 'small', string $maxHeight = 'large'): array
    {
        $allowed = array_values(array_filter(self::WIDTHS, fn (int $w): bool => $w >= $minWidth && $w <= $maxWidth)) ?: self::WIDTHS;
        $snapped = $allowed[0];

        foreach ($allowed as $candidate) {
            if (abs($candidate - $width) < abs($snapped - $width)) {
                $snapped = $candidate;
            }
        }

        $heights = array_keys(self::HEIGHTS);
        $from = max(0, (int) array_search($minHeight, $heights, true));
        $to = (int) array_search($maxHeight, $heights, true);
        $at = array_search($height, $heights, true);
        $at = $at === false ? $from : $at;
        $at = min(max($at, $from), max($from, $to));

        return ['col' => min(max($col, 0), self::COLUMNS - $snapped), 'row' => max($row, 0), 'width' => $snapped, 'height' => $heights[$at]];
    }

    /**
     * The first free place for a rectangle of this size, scanning rows from the top and columns from the left; below everything when no gap fits.
     *
     * @param  list<array{col: int, row: int, width: int, height: string}>  $occupied
     * @return array{col: int, row: int}
     */
    public static function nextFreeSlot(array $occupied, int $width, string $height): array
    {
        $width = min(max($width, 1), self::COLUMNS);
        $units = self::HEIGHTS[$height] ?? self::HEIGHTS['small'];
        $bottom = 0;

        foreach ($occupied as $item) {
            $bottom = max($bottom, $item['row'] + (self::HEIGHTS[$item['height']] ?? self::HEIGHTS['small']));
        }

        for ($row = 0; $row <= $bottom; $row++) {
            for ($col = 0; $col + $width <= self::COLUMNS; $col++) {
                if (! self::overlaps($occupied, $col, $row, $width, $units)) {
                    return ['col' => $col, 'row' => $row];
                }
            }
        }

        return ['col' => 0, 'row' => $bottom];
    }

    /**
     * Whether a rectangle lies inside the grid and overlaps none of the occupied ones.
     *
     * @param  list<array{col: int, row: int, width: int, height: string}>  $occupied
     */
    public static function fits(array $occupied, int $col, int $row, int $width, string $height): bool
    {
        $units = self::HEIGHTS[$height] ?? self::HEIGHTS['small'];

        return $col >= 0 && $row >= 0 && $width >= 1 && $col + $width <= self::COLUMNS && ! self::overlaps($occupied, $col, $row, $width, $units);
    }

    /** @param  list<array{col: int, row: int, width: int, height: string}>  $occupied */
    private static function overlaps(array $occupied, int $col, int $row, int $width, int $units): bool
    {
        foreach ($occupied as $item) {
            $itemUnits = self::HEIGHTS[$item['height']] ?? self::HEIGHTS['small'];

            if ($col < $item['col'] + $item['width'] && $item['col'] < $col + $width && $row < $item['row'] + $itemUnits && $item['row'] < $row + $units) {
                return true;
            }
        }

        return false;
    }
}
