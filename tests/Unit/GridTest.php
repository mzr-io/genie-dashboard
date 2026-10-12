<?php

use App\Platform\Layout\Grid;

// Story 4.4: the pure 12-column grid kernel.
it('snaps a width to the nearest preset within the block limits and keeps the rectangle in the grid', function (array $in, array $expected) {
    expect(Grid::clamp(...$in))->toBe($expected);
})->with([
    'already valid' => [[2, 4, 4, 'medium'], ['col' => 2, 'row' => 4, 'width' => 4, 'height' => 'medium']],
    'width snaps up' => [[0, 0, 5, 'small', 3, 12], ['col' => 0, 'row' => 0, 'width' => 4, 'height' => 'small']],
    'tie takes the smaller' => [[0, 0, 5, 'small', 3, 12], ['col' => 0, 'row' => 0, 'width' => 4, 'height' => 'small']],
    'over max' => [[0, 0, 12, 'small', 3, 6], ['col' => 0, 'row' => 0, 'width' => 6, 'height' => 'small']],
    'under min' => [[0, 0, 1, 'small', 4, 12], ['col' => 0, 'row' => 0, 'width' => 4, 'height' => 'small']],
    'column pushed inside' => [[10, 0, 6, 'small'], ['col' => 6, 'row' => 0, 'width' => 6, 'height' => 'small']],
    'negative row' => [[-3, -1, 3, 'small'], ['col' => 0, 'row' => 0, 'width' => 3, 'height' => 'small']],
    'height above max' => [[0, 0, 3, 'large', 3, 12, 'small', 'medium'], ['col' => 0, 'row' => 0, 'width' => 3, 'height' => 'medium']],
    'height below min' => [[0, 0, 3, 'small', 3, 12, 'medium', 'large'], ['col' => 0, 'row' => 0, 'width' => 3, 'height' => 'medium']],
    'unknown height' => [[0, 0, 3, 'huge'], ['col' => 0, 'row' => 0, 'width' => 3, 'height' => 'small']],
]);

it('finds the first free slot, left to right then top to bottom, and falls below everything when nothing fits', function () {
    $item = fn (int $col, int $row, int $width, string $height) => compact('col', 'row', 'width', 'height');

    expect(Grid::nextFreeSlot([], 3, 'small'))->toBe(['col' => 0, 'row' => 0])
        ->and(Grid::nextFreeSlot([$item(0, 0, 3, 'small')], 3, 'small'))->toBe(['col' => 3, 'row' => 0])
        ->and(Grid::nextFreeSlot([$item(0, 0, 12, 'small')], 3, 'small'))->toBe(['col' => 0, 'row' => 2])
        ->and(Grid::nextFreeSlot([$item(0, 0, 6, 'medium'), $item(6, 0, 6, 'small')], 6, 'small'))->toBe(['col' => 6, 'row' => 2])
        ->and(Grid::nextFreeSlot([$item(0, 0, 12, 'large')], 12, 'medium'))->toBe(['col' => 0, 'row' => 9]);
});

// Story 4.9: the conformance suite of the Layout kernel (deterministic, never overlapping, never moving what is placed).
it('places a long run of Blocks without an overlap, one after another, whatever their sizes', function () {
    $sizes = [[3, 'small'], [4, 'medium'], [6, 'small'], [3, 'large'], [12, 'small'], [8, 'medium'], [3, 'small'], [4, 'small'], [6, 'large'], [3, 'medium']];
    $placed = [];

    foreach (array_merge($sizes, $sizes) as [$width, $height]) {
        $slot = Grid::nextFreeSlot($placed, $width, $height);
        $new = ['col' => $slot['col'], 'row' => $slot['row'], 'width' => $width, 'height' => $height];

        expect($new['col'] + $new['width'])->toBeLessThanOrEqual(Grid::COLUMNS);

        foreach ($placed as $other) {
            $overlap = $new['col'] < $other['col'] + $other['width'] && $other['col'] < $new['col'] + $new['width']
                && $new['row'] < $other['row'] + Grid::HEIGHTS[$other['height']] && $other['row'] < $new['row'] + Grid::HEIGHTS[$new['height']];

            expect($overlap)->toBeFalse();
        }

        $placed[] = $new;
    }

    expect($placed)->toHaveCount(20);
});

it('is deterministic and leaves the input alone', function () {
    $items = [['col' => 0, 'row' => 0, 'width' => 6, 'height' => 'medium'], ['col' => 6, 'row' => 2, 'width' => 3, 'height' => 'small']];
    $copy = $items;

    expect(Grid::nextFreeSlot($items, 4, 'small'))->toBe(Grid::nextFreeSlot($items, 4, 'small'))
        ->and($items)->toBe($copy)
        ->and(Grid::clamp(11, 3, 12, 'large', 3, 6, 'small', 'medium'))->toBe(Grid::clamp(11, 3, 12, 'large', 3, 6, 'small', 'medium'));
});
