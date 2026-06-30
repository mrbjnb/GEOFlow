<?php

namespace Tests\Unit;

use App\Models\DistributionChannel;
use App\Models\DistributionChannelSecret;
use App\Services\GeoFlow\OAuthTokenRefreshService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OAuthTokenRefreshServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'geoflow.google_client_id' => 'test-google-id',
            'geoflow.google_client_secret' => 'test-google-secret',
            'geoflow.facebook_app_id' => 'test-fb-id',
            'geoflow.facebook_app_secret' => 'test-fb-secret',
        ]);
    }

    public function test_encrypt_initial_credentials_returns_valid_encrypted_string(): void
    {
        $service = app(OAuthTokenRefreshService::class);
        $ciphertext = $service->encryptInitialCredentials('tok_access', 'tok_refresh', '2027-01-01T00:00:00Z');

        $this->assertStringStartsWith('enc:v1:', $ciphertext);
        $this->assertNotEmpty($ciphertext);
    }

    public function test_encrypt_initial_credentials_is_decryptable(): void
    {
        $service = app(OAuthTokenRefreshService::class);
        $ciphertext = $service->encryptInitialCredentials('tok_access', 'tok_refresh', '2027-01-01T00:00:00Z');

        $decrypted = app(ApiKeyCrypto::class)->decrypt($ciphertext);
        $data = json_decode($decrypted, true);

        $this->assertIsArray($data);
        $this->assertSame('tok_access', $data['access_token']);
        $this->assertSame('tok_refresh', $data['refresh_token']);
        $this->assertSame('Bearer', $data['token_type']);
    }

    public function test_ensure_valid_token_returns_credentials_without_refresh(): void
    {
        $ciphertext = app(OAuthTokenRefreshService::class)
            ->encryptInitialCredentials('tok_access', 'tok_refresh', '2027-01-01T00:00:00Z');

        $channel = DistributionChannel::query()->create([
            'name' => 'Blogger', 'domain' => 'blogger.com', 'endpoint_url' => 'https://blogger.googleapis.com',
            'channel_type' => 'blogger', 'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'blog_test',
            'secret_ciphertext' => $ciphertext,
            'status' => 'active',
            'scopes' => ['blogger.posts'],
        ]);

        $result = app(OAuthTokenRefreshService::class)->ensureValidToken($channel, 'blogger');

        $this->assertSame('tok_access', $result['access_token']);
        $this->assertSame('tok_refresh', $result['refresh_token']);
    }

    public function test_it_refreshes_google_token_when_expired(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response([
                'access_token' => 'new_access',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ]),
        ]);

        $ciphertext = app(OAuthTokenRefreshService::class)
            ->encryptInitialCredentials('old_access', 'tok_refresh', '2024-01-01T00:00:00Z');

        $channel = DistributionChannel::query()->create([
            'name' => 'Blogger2', 'domain' => 'blogger.com', 'endpoint_url' => 'https://blogger.googleapis.com',
            'channel_type' => 'blogger', 'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'blog_test2',
            'secret_ciphertext' => $ciphertext,
            'status' => 'active',
            'scopes' => ['blogger.posts'],
        ]);

        $result = app(OAuthTokenRefreshService::class)->ensureValidToken($channel, 'blogger');

        $this->assertSame('new_access', $result['access_token']);
        $this->assertSame('tok_refresh', $result['refresh_token']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://oauth2.googleapis.com/token'
                && $request['grant_type'] === 'refresh_token';
        });
    }

    public function test_it_refreshes_facebook_token_when_expired(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'access_token' => 'fb_new_access',
                'expires_in' => 5184000,
                'token_type' => 'bearer',
            ]),
        ]);

        $ciphertext = app(OAuthTokenRefreshService::class)
            ->encryptInitialCredentials('old_fb_access', '', '2024-01-01T00:00:00Z');

        $channel = DistributionChannel::query()->create([
            'name' => 'FB', 'domain' => 'facebook.com', 'endpoint_url' => 'https://graph.facebook.com',
            'channel_type' => 'facebook_page', 'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'fb_test',
            'secret_ciphertext' => $ciphertext,
            'status' => 'active',
            'scopes' => ['facebook.page_manage'],
        ]);

        $result = app(OAuthTokenRefreshService::class)->ensureValidToken($channel, 'facebook');

        $this->assertSame('fb_new_access', $result['access_token']);
    }

    public function test_it_throws_on_revoked_token(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $ciphertext = app(OAuthTokenRefreshService::class)
            ->encryptInitialCredentials('old_access', 'bad_refresh', '2024-01-01T00:00:00Z');

        $channel = DistributionChannel::query()->create([
            'name' => 'Blogger3', 'domain' => 'blogger.com', 'endpoint_url' => 'https://blogger.googleapis.com',
            'channel_type' => 'blogger', 'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'blog_test3',
            'secret_ciphertext' => $ciphertext,
            'status' => 'active',
            'scopes' => ['blogger.posts'],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Google token 刷新失败');

        app(OAuthTokenRefreshService::class)->ensureValidToken($channel, 'blogger');
    }
}
