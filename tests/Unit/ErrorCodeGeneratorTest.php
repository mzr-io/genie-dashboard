<?php

use App\Support\ErrorCodes\ErrorCodeGenerator;

enum GoodCodes: string
{
    case Missing = 'blocks.draft_exists';
    case Other = 'blocks.not_found';
}

enum BadCodes: string
{
    case Wrong = 'results.draft_exists';
}

enum LooseCodes: string
{
    case Camel = 'blocks.DraftExists';
}

it('keeps the committed TypeScript enum in step with every module\'s ErrorCode', function () {
    $root = dirname(__DIR__, 2);
    $generator = new ErrorCodeGenerator;
    $expected = $generator->render(ErrorCodeGenerator::discover($root.'/app'));

    expect(file_get_contents($root.'/'.ErrorCodeGenerator::OUTPUT))->toBe($expected, 'resources/js/types/error-codes.ts is stale: run php artisan dashflow:error-codes');
});

it('discovers the kernel and module enums, each code starting with its owner', function () {
    $enums = ErrorCodeGenerator::discover(dirname(__DIR__, 2).'/app');

    expect($enums)->toContain('App\Platform\Contracts\ErrorCode')->toContain('App\Modules\Access\Contracts\ErrorCode');
    foreach ($enums as $enum) {
        expect((new ErrorCodeGenerator)->codes([$enum]))->not->toBeEmpty();
    }
});

it('fails a code that does not start with its owning module name', function () {
    expect(fn () => (new ErrorCodeGenerator)->codes(['blocks' => BadCodes::class]))
        ->toThrow(InvalidArgumentException::class, 'results.draft_exists');
});

it('fails a code that is not snake_case and renders a good one', function () {
    expect(fn () => (new ErrorCodeGenerator)->codes(['blocks' => LooseCodes::class]))->toThrow(InvalidArgumentException::class);

    expect((new ErrorCodeGenerator)->render(['blocks' => GoodCodes::class]))
        ->toContain("BlocksDraftExists = 'blocks.draft_exists',");
});
