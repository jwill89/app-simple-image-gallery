<?php

declare(strict_types=1);

use App\Support\ClientAddress;
use Illuminate\Http\Request;

// What the rate limits count a visitor by: an IPv4 address as itself, an IPv6
// client by its /64.

it('counts an IPv4 address as itself', function (): void {
    expect(ClientAddress::keyFor('203.0.113.7'))->toBe('203.0.113.7');
});

it('counts every address of an IPv6 /64 as the same visitor, however it is written', function (string $address): void {
    expect(ClientAddress::keyFor($address))->toBe('2001:db8:1:2::/64');
})->with([
    'compressed' => '2001:db8:1:2::1',
    'the last address of the /64' => '2001:db8:1:2:ffff:ffff:ffff:ffff',
    'written out' => '2001:0DB8:0001:0002:0000:0000:0000:0001',
    'with an embedded IPv4 tail' => '2001:db8:1:2::192.0.2.1',
]);

it('tells one /64 from the next', function (): void {
    expect(ClientAddress::keyFor('2001:db8:1:3::1'))->not->toBe(ClientAddress::keyFor('2001:db8:1:2::1'))
        ->and(ClientAddress::keyFor('2001:db8:2:2::1'))->not->toBe(ClientAddress::keyFor('2001:db8:1:2::1'));
});

it('counts an IPv4 address written as IPv6 as that IPv4 address', function (): void {
    expect(ClientAddress::keyFor('::ffff:203.0.113.7'))->toBe('203.0.113.7');
});

it('leaves text that is not an address as it is, so nothing it names is shared by accident', function (): void {
    expect(ClientAddress::keyFor(''))->toBe('')
        ->and(ClientAddress::keyFor('unknown'))->toBe('unknown');
});

it('reads the address from the request', function (): void {
    $request = Request::create('/', server: ['REMOTE_ADDR' => '2001:db8:aaaa:bbbb::5']);

    expect(ClientAddress::key($request))->toBe('2001:db8:aaaa:bbbb::/64');
});
