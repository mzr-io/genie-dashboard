<?php

use App\Modules\Connector\Contracts\AddressClass;
use App\Modules\Connector\Contracts\BlockedAddress;
use App\Modules\Connector\Contracts\Cidr;

// Story 2.2: the address-class matrix. Every class of the spec has at least one address here, in IPv4 and IPv6.

it('classifies an address as undeniable, whatever a grant says', function (string $address) {
    expect(BlockedAddress::classifyText($address))->toBe(AddressClass::Undeniable)
        ->and(BlockedAddress::blocks($address))->toBeTrue();
})->with([
    'loopback' => ['127.0.0.1'],
    'loopback top' => ['127.255.255.255'],
    'link-local' => ['169.254.1.1'],
    'cloud metadata' => ['169.254.169.254'],
    'Alibaba Cloud metadata (inside CGNAT)' => ['100.100.100.200'],
    'Azure wireserver' => ['168.63.129.16'],
    'unspecified' => ['0.0.0.0'],
    'this network' => ['0.1.2.3'],
    'multicast' => ['224.0.0.1'],
    'multicast top' => ['239.255.255.255'],
    'reserved' => ['240.0.0.1'],
    'broadcast' => ['255.255.255.255'],
    'ietf protocol assignments' => ['192.0.0.1'],
    'documentation TEST-NET-1' => ['192.0.2.10'],
    'documentation TEST-NET-2' => ['198.51.100.10'],
    'documentation TEST-NET-3' => ['203.0.113.10'],
    '6to4 relay' => ['192.88.99.1'],
    'benchmarking' => ['198.18.0.1'],
    'benchmarking top' => ['198.19.255.255'],
    'IPv6 loopback' => ['::1'],
    'IPv6 unspecified' => ['::'],
    'IPv6 link-local' => ['fe80::1'],
    'IPv6 site-local' => ['fec0::1'],
    'IPv6 metadata' => ['fd00:ec2::254'],
    'IPv6 multicast' => ['ff02::1'],
    'IPv4-mapped' => ['::ffff:8.8.8.8'],
    'IPv4-mapped loopback' => ['::ffff:7f00:1'],
    'IPv4-compatible' => ['::808:808'],
    'NAT64' => ['64:ff9b::808:808'],
    'NAT64 local-use' => ['64:ff9b:1::1'],
    '6to4' => ['2002:808:808::1'],
    'Teredo' => ['2001:0:4136:e378:8000:63bf:3fff:fdd2'],
    'IPv6 documentation' => ['2001:db8::1'],
    'IPv6 documentation 3fff' => ['3fff::1'],
    'IPv6 benchmarking' => ['2001:2::1'],
    'discard prefix' => ['100::1'],
    'unparsable' => ['not-an-ip'],
    'empty' => [''],
    'with a zone' => ['fe80::1%eth0'],
    'dotted with leading zero' => ['010.0.0.1'],
]);

it('classifies RFC 1918, CGNAT and ULA space as grantable', function (string $address) {
    expect(BlockedAddress::classifyText($address))->toBe(AddressClass::Grantable)
        ->and(BlockedAddress::blocks($address))->toBeTrue();
})->with([
    '10/8' => ['10.1.2.3'],
    '172.16/12' => ['172.16.0.1'],
    '172.16/12 top' => ['172.31.255.255'],
    '192.168/16' => ['192.168.0.1'],
    'CGNAT' => ['100.64.0.1'],
    'CGNAT next to the Alibaba metadata address' => ['100.100.100.199'],
    'CGNAT top' => ['100.127.255.255'],
    'ULA fc' => ['fc00::1'],
    'ULA fd' => ['fd12:3456:789a::1'],
]);

it('classifies other addresses as public', function (string $address) {
    expect(BlockedAddress::classifyText($address))->toBe(AddressClass::Public)
        ->and(BlockedAddress::blocks($address))->toBeFalse();
})->with([
    ['93.184.216.34'],
    ['8.8.8.8'],
    ['172.15.255.255'],
    ['172.32.0.0'],
    ['100.63.255.255'],
    ['100.128.0.0'],
    ['198.17.255.255'],
    ['198.20.0.0'],
    ['2606:4700:4700::1111'],
    ['2001:4860:4860::8888'],
    ['fb00::1'],
]);

it('lets undeniable win over grantable and adds the deployment CIDRs to undeniable', function () {
    // The IPv6 metadata address is inside ULA space.
    expect(BlockedAddress::classifyText('fd00:ec2::254'))->toBe(AddressClass::Undeniable);

    $own = [Cidr::parse('10.99.0.0/16'), Cidr::parse('93.184.216.0/24')];

    expect(BlockedAddress::classifyText('10.99.1.1', $own))->toBe(AddressClass::Undeniable)
        ->and(BlockedAddress::classifyText('93.184.216.34', $own))->toBe(AddressClass::Undeniable)
        ->and(BlockedAddress::classifyText('10.98.1.1', $own))->toBe(AddressClass::Grantable)
        ->and(BlockedAddress::classifyText('10.99.1.1'))->toBe(AddressClass::Grantable);
});

it('treats a binary address of the wrong length as undeniable', function () {
    expect(BlockedAddress::classify('abc'))->toBe(AddressClass::Undeniable)
        ->and(BlockedAddress::classify(''))->toBe(AddressClass::Undeniable);
});
