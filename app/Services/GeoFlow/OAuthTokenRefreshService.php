<?php

namespace App\Services\GeoFlow;

use App\Models\DistributionChannel;
use App\Models\DistributionChannelSecret;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OAuthTokenRefreshService
{
    public function __construct(private readonly ApiKeyCrypto $apiKeyCrypto) {}

    /**
     * Ensure the channel has a valid access token; refresh if expired/near-expiry.
     *
     * @return array{access_token:string,refresh_token:string,expires_at:?string,token_type:string}
     */
    public function ensureValidToken(DistributionChannel $channel, string $platform): array
    {
        $channel->loadMissing('activeSecret');
        $secret = $channel->activeSecret;
        if (! $secret instanceof DistributionChannelSecret) {
            throw new RuntimeException('社交渠道缺少 OAuth 凭据。');
        }

        $credentials = $this->decryptCredentials($secret);
        $expiresAt = $credentials['expires_at'] ?? null;

        // Refresh if no expiry recorded, or token expires within 5 minutes.
        $needsRefresh = $expiresAt === null
            || now()->addMinutes(5)->gte(now()->parse($expiresAt));

        if (! $needsRefresh) {
            return $credentials;
        }

        $refreshed = $platform === 'blogger'
            ? $this->refreshGoogleToken($credentials['refresh_token'])
            : $this->refreshFacebookToken($credentials['access_token']);

        $credentials['access_token'] = $refreshed['access_token'];
        $credentials['expires_at'] = $refreshed['expires_at'];
        $credentials['token_type'] = $refreshed['token_type'] ?? 'Bearer';
        // Google keeps the same refresh_token; Facebook long-lived tokens don't have one.
        if ($platform === 'blogger' && ! empty($refreshed['refresh_token'])) {
            $credentials['refresh_token'] = $refreshed['refresh_token'];
        }

        $this->reEncryptSecret($secret, $credentials);

        return $credentials;
    }

    /**
     * Build an encrypted secret_ciphertext envelope from initial OAuth tokens.
     *
     * @return string enc:v1:... ciphertext suitable for distribution_channel_secrets.secret_ciphertext
     */
    public function encryptInitialCredentials(string $accessToken, string $refreshToken, ?string $expiresAt): string
    {
        $envelope = json_encode([
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_at' => $expiresAt,
            'token_type' => 'Bearer',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->apiKeyCrypto->encrypt((string) $envelope);
    }

    /**
     * @return array{access_token:string,refresh_token:string,expires_at:?string,token_type:string}
     */
    private function decryptCredentials(DistributionChannelSecret $secret): array
    {
        $json = $this->apiKeyCrypto->decrypt((string) $secret->secret_ciphertext);
        if ($json === '') {
            throw new RuntimeException('OAuth 凭据解密失败。');
        }
        $data = json_decode($json, true);
        if (! is_array($data) || empty($data['access_token'])) {
            throw new RuntimeException('OAuth 凭据格式无效。');
        }

        return [
            'access_token' => (string) $data['access_token'],
            'refresh_token' => (string) ($data['refresh_token'] ?? ''),
            'expires_at' => isset($data['expires_at']) ? (string) $data['expires_at'] : null,
            'token_type' => (string) ($data['token_type'] ?? 'Bearer'),
        ];
    }

    /**
     * @param  array{access_token:string,refresh_token:string,expires_at:?string,token_type:string}  $credentials
     */
    private function reEncryptSecret(DistributionChannelSecret $secret, array $credentials): void
    {
        $envelope = json_encode($credentials, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $secret->forceFill([
            'secret_ciphertext' => $this->apiKeyCrypto->encrypt((string) $envelope),
            'last_used_at' => now(),
        ])->save();
    }

    /**
     * @return array{access_token:string,expires_at:string,token_type:string,refresh_token?:string}
     */
    private function refreshGoogleToken(string $refreshToken): array
    {
        if ($refreshToken === '') {
            throw new RuntimeException('Google refresh_token 缺失，无法刷新。请重新授权。');
        }
        $clientId = (string) config('geoflow.google_client_id', '');
        $clientSecret = (string) config('geoflow.google_client_secret', '');
        if ($clientId === '' || $clientSecret === '') {
            throw new RuntimeException('未配置 GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET。');
        }

        $response = Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Google token 刷新失败：HTTP '.$response->status().' '.$response->body());
        }
        $data = $response->json();
        $expiresIn = (int) ($data['expires_in'] ?? 3600);

        return [
            'access_token' => (string) ($data['access_token'] ?? ''),
            'expires_at' => now()->addSeconds($expiresIn)->toIso8601String(),
            'token_type' => (string) ($data['token_type'] ?? 'Bearer'),
            'refresh_token' => $refreshToken,
        ];
    }

    /**
     * @return array{access_token:string,expires_at:string,token_type:string}
     */
    private function refreshFacebookToken(string $exchangeToken): array
    {
        $appId = (string) config('geoflow.facebook_app_id', '');
        $appSecret = (string) config('geoflow.facebook_app_secret', '');
        if ($appId === '' || $appSecret === '') {
            throw new RuntimeException('未配置 FACEBOOK_APP_ID / FACEBOOK_APP_SECRET。');
        }
        $version = (string) config('geoflow.facebook_graph_version', 'v24.0');

        $response = Http::timeout(15)->get("https://graph.facebook.com/{$version}/oauth/access_token", [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $appId,
            'client_secret' => $appSecret,
            'fb_exchange_token' => $exchangeToken,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Facebook token 刷新失败：HTTP '.$response->status().' '.$response->body());
        }
        $data = $response->json();
        $expiresIn = (int) ($data['expires_in'] ?? 5184000);

        return [
            'access_token' => (string) ($data['access_token'] ?? ''),
            'expires_at' => now()->addSeconds($expiresIn)->toIso8601String(),
            'token_type' => (string) ($data['token_type'] ?? 'bearer'),
        ];
    }
}
