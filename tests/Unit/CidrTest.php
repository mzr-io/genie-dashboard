<?php

use App\Modules\Connector\Contracts\Cidr;

it('parses a strict network and prints it canonically', function (string $input, string $text) {
    expect(Cidr::parse($input)?->text())->toBe($text);
})->with([
    ['10.0.0.0/8', '10.0.0.0/8'],
    ['192.168.1.0/24', '192.168.1.0/24'],
    ['0.0.0.0/0', '0.0.0.0/0'],
    ['10.1.2.3/32', '10.1.2.3/32'],
    ['fd00::/8', 'fd00::/8'],
    ['FD00:0:0:0:0:0:0:0/8', 'fd00::/8'],
    ['::/0', '::/0'],
]);

it('refuses anything that is not an address, a slash and a prefix with the host bits zero', function (string $input) {
    expect(Cidr::parse($input))->toBeNull();
})->with([
    'no prefix' => ['10.0.0.0'],
    'empty prefix' => ['10.0.0.0/'],
    'prefix over 32' => ['10.0.0.0/33'],
    'prefix over 128' => ['fd00::/129'],
    'leading zero prefix' => ['10.0.0.0/08'],
    'host bits set' => ['10.0.0.1/8'],
    'host bits set v6' => ['fd00::1/8'],
    'not an address' => ['example.com/8'],
    'octal spelling' => ['010.0.0.0/8'],
    'short form' => ['10.1/16'],
    'negative' => ['10.0.0.0/-1'],
    'spaces' => [' 10.0.0.0/8'],
    'trailing newline' => ["10.0.0.0/8\n"],
    'two slashes' => ['10.0.0.0/8/8'],
    'empty' => [''],
]);

it('knows what a network contains, is within and overlaps, per family', function () {
    $ten = Cidr::parse('10.0.0.0/8');
    $narrow = Cidr::parse('10.20.0.0/16');
    $other = Cidr::parse('11.0.0.0/8');

    expect($ten->contains(inet_pton('10.255.255.255')))->toBeTrue()
        ->and($ten->contains(inet_pton('11.0.0.0')))->toBeFalse()
        ->and($ten->contains(inet_pton('::a00:1')))->toBeFalse('a 16-byte address is never in an IPv4 network')
        ->and($narrow->within($ten))->toBeTrue()
        ->and($ten->within($narrow))->toBeFalse()
        ->and($ten->overlaps($narrow))->toBeTrue()
        ->and($narrow->overlaps($ten))->toBeTrue()
        ->and($ten->overlaps($other))->toBeFalse()
        ->and(Cidr::parse('0.0.0.0/0')->contains(inet_pton('203.0.113.9')))->toBeTrue()
        ->and(Cidr::parse('fd00::/8')->overlaps($ten))->toBeFalse()
        ->and(Cidr::address(inet_pton('10.1.2.3'))->text())->toBe('10.1.2.3/32');
});
