<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

class TrustDeploymentProxies extends TrustProxies
{
    protected $headers = Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO;

    /** @return list<string>|string */
    protected function proxies(): array|string
    {
        $proxies = config('funeral.trusted_proxies', []);

        return $proxies === ['*'] ? '*' : $proxies;
    }
}
