<?php

// Story 1.25: the load harness reads DASHFLOW_LOAD_* from the environment only. config/dashflow.php
// documents them as `pending_input` with no value, and the list matches load/config.mjs.
it('documents every load variable as pending_input with no value', function () {
    $load = config('dashflow.load');

    expect($load)->not->toBe([]);

    foreach ($load as $name => $setting) {
        expect($setting['pending_input'])->toBeTrue()
            ->and($setting['env'])->toMatch('/^DASHFLOW_LOAD_[A-Z0-9_]+$/')
            ->and($setting['value'])->toBeNull("load.{$name} must have no default");
    }
});

it('lists the same variables as load/config.mjs and .env.example', function () {
    $documented = array_column(config('dashflow.load'), 'env');

    preg_match_all("/env: '(DASHFLOW_LOAD_[A-Z0-9_]+)'/", (string) file_get_contents(base_path('load/config.mjs')), $harness);
    preg_match_all('/^# (DASHFLOW_LOAD_[A-Z0-9_]+)=/m', (string) file_get_contents(base_path('.env.example')), $example);

    expect($harness[1])->toEqualCanonicalizing($documented)
        ->and($example[1])->toEqualCanonicalizing($documented);
});
