<?php

namespace App\Services\GeoFlow;

use App\Models\DistributionChannel;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class FacebookPageRequestFactory
{
    public function __construct(
        private readonly OAuthTokenRefreshService $oauthRefresh,
    ) {}

    public function request(DistributionChannel $channel, int $timeout = 30): PendingRequest
    {
        $credentials = $this->oauthRefresh->ensureValidToken($channel, 'facebook');

        return Http::timeout($timeout)
            ->acceptJson()
            ->withToken($credentials['access_token']);
    }
}
