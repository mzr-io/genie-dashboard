<?php

$composer = fn () => json_decode(file_get_contents(base_path('composer.json')), true);

it('requires PHP 8.5 and the mandatory extensions', function () use ($composer) {
    $require = $composer()['require'];

    expect($require['php'])->toBe('^8.5')
        ->and($require)->toHaveKeys(['ext-bcmath', 'ext-uv', 'ext-opentelemetry']);
});

it('declares the AR-1 package manifest', function () use ($composer) {
    $c = $composer();

    expect($c['require'])->toMatchArray([
        'laravel/horizon' => '^5',
        'laravel/reverb' => '^1',
        'laravel/sanctum' => '^4.0',
        'opis/json-schema' => '^2.6',
        'open-telemetry/sdk' => '^1.15',
        'open-telemetry/opentelemetry-auto-laravel' => '^1.9',
    ])->and($c['require-dev'])->toMatchArray([
        'pestphp/pest' => '^5',
        'pestphp/pest-plugin-laravel' => '^5',
        'symfony/json-path' => '^8.1',
    ]);
});

it('does not depend on PHPUnit directly, Chisel or Sail', function () use ($composer) {
    $all = array_merge($composer()['require'], $composer()['require-dev']);

    expect($all)->not->toHaveKeys(['phpunit/phpunit', 'laravel/chisel', 'laravel/sail']);
});

it('declares the frontend manifest', function () {
    $p = json_decode(file_get_contents(base_path('package.json')), true);
    $deps = $p['dependencies'];

    expect($deps)->toHaveKeys(['pinia', 'vue-i18n', 'echarts', 'vue-echarts', 'reka-ui']);
    expect($deps['pinia'])->toStartWith('^4')
        ->and($deps['vue-i18n'])->toStartWith('^11')
        ->and($deps['echarts'])->toStartWith('^6.1')
        ->and($deps['vue-echarts'])->toStartWith('^8.3');
});

it('is named Dashflow', function () {
    expect(file_get_contents(base_path('.env.example')))->toContain('APP_NAME=Dashflow');
});

it('has the required PHP extensions loaded', function () {
    foreach (['bcmath', 'uv', 'opentelemetry'] as $ext) {
        expect(extension_loaded($ext))->toBeTrue("ext-{$ext} missing");
    }
});

it('has no Teams scaffold', function () {
    $uris = collect(app('router')->getRoutes()->getRoutes())->map->uri()->implode("\n");

    expect(class_exists('App\\Models\\Team'))->toBeFalse()
        ->and($uris)->not->toContain('teams');
});
