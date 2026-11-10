<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

test('only configured proxies can supply the original IP and HTTPS scheme', function (array $proxies, string $expectedIp, bool $secure) {
    config(['funeral.trusted_proxies' => $proxies]);
    Route::get('/__proxy-test', fn (Request $request) => [
        'ip' => $request->ip(), 'secure' => $request->isSecure(),
        'host' => $request->getHost(), 'port' => $request->getPort(),
    ]);
    $this->withServerVariables(['REMOTE_ADDR' => '172.20.0.5'])->withHeaders([
        'Host' => 'pemakaman.example.test', 'X-Forwarded-For' => '203.0.113.42',
        'X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'attacker.example.test',
        'X-Forwarded-Port' => '1234',
    ])->get('http://pemakaman.example.test/__proxy-test')->assertOk()->assertExactJson([
        'ip' => $expectedIp, 'secure' => $secure,
        'host' => 'pemakaman.example.test', 'port' => $secure ? 443 : 80,
    ]);
})->with([
    'direct access ignores forwarded headers' => [[], '172.20.0.5', false],
    'an allowlisted proxy can forward IP and HTTPS' => [['172.20.0.5'], '203.0.113.42', true],
    'the private compose network trusts its HTTP proxy' => [['*'], '203.0.113.42', true],
]);
