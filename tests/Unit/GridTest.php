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
