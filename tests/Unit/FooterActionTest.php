<?php

use App\Modules\Blocks\Application\FooterAction;

// Story 5.4: the footer action's address is a literal scheme and host, with user tokens only after them.
it('accepts an https or http address with user tokens after the host', function () {
    foreach ([
        'https://erp.example.com/approvals?user={user.email}',
        'http://intranet.example/path/{user.name}/open#top',
        'https://example.com',
        'https://example.com:8443/a?b={user.email}&c={user.name}',
    ] as $url) {
        expect(FooterAction::validUrl($url))->toBeTrue($url);
    }
});

it('refuses another scheme, a token in the host, credentials, scripts and stray braces', function () {
    foreach ([
        'javascript:alert(1)',
        'ftp://example.com/x',
        '//example.com/x',
        '/relative/path',
        'https://{user.name}.example.com/x',
        'https://example.com:{user.name}/x',
        'https://{user.name}@example.com/x',
        'https://user:pass@example.com/x',
        'https://example.com/{revenue}',
        'https://example.com/ a',
        'https://example.com/\\evil',
        '',
        'https://'.str_repeat('a', 500).'.com',
    ] as $url) {
        expect(FooterAction::validUrl($url))->toBeFalse($url);
    }
});

it('judges the whole action: target, label and address', function () {
    $errors = [];
    $ok = FooterAction::check(['target' => 'external', 'label' => 'View all approvals', 'url' => 'https://erp.example.com/a?u={user.email}'], $errors);

    expect($errors)->toBe([])->and($ok)->toBe(['target' => 'external', 'label' => 'View all approvals', 'url' => 'https://erp.example.com/a?u={user.email}']);

    $errors = [];
    FooterAction::check(['target' => 'external', 'label' => '{revenue} items', 'url' => 'javascript:alert(1)'], $errors);

    expect(array_keys($errors))->toBe(['config.display.footer.label', 'config.display.footer.url'])
        ->and($errors['config.display.footer.url'][0])->toBe('Use a link that starts with https:// or http://.');

    $errors = [];
    $expanded = FooterAction::check(['target' => 'expanded_view', 'label' => 'View all approvals', 'url' => ''], $errors);

    expect($errors)->toBe([])->and($expanded)->toBe(['target' => 'expanded_view', 'label' => 'View all approvals', 'url' => null]);

    $errors = [];
    expect(FooterAction::check(['target' => 'external', 'label' => '', 'url' => ''], $errors))->toBeNull()->and($errors)->toBe([]);
});
