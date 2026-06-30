<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\DistributionChannel;
use App\Models\DistributionChannelSecret;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminDistributionSocialChannelTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::query()->create([
            'username' => 'social_admin',
            'password' => 'secret-123',
            'email' => 'social-admin@example.com',
            'display_name' => 'Social Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        config([
            'geoflow.google_client_id' => 'test-google-id',
            'geoflow.google_client_secret' => 'test-google-secret',
            'geoflow.facebook_app_id' => 'test-fb-id',
            'geoflow.facebook_app_secret' => 'test-fb-secret',
        ]);
    }

    public function test_admin_can_create_blogger_channel(): void
    {
        $response = $this->actingAs($this->admin, 'admin')->post(route('admin.distribution.store'), [
            'name' => 'My Blogger Blog',
            'domain' => 'blogger.com',
            'endpoint_url' => 'https://blogger.googleapis.com',
            'channel_type' => 'blogger',
            'status' => 'active',
            'blogger_blog_id' => '123456789',
            'blogger_post_status' => 'live',
            'blogger_label_strategy' => 'keywords_to_labels',
            'blogger_access_token' => 'ya29.test_access_token',
            'blogger_refresh_token' => '1/test_refresh_token',
        ]);

        $response->assertRedirect(route('admin.distribution.show', ['channelId' => 1]));

        $this->assertDatabaseHas('distribution_channels', [
            'name' => 'My Blogger Blog',
            'channel_type' => 'blogger',
        ]);

        $channel = DistributionChannel::query()->where('name', 'My Blogger Blog')->firstOrFail();
        $this->assertTrue($channel->isBlogger());
        $this->assertSame('123456789', $channel->resolvedBloggerConfig()['blogger_blog_id']);
        $this->assertSame('live', $channel->resolvedBloggerConfig()['blogger_post_status']);

        $secret = DistributionChannelSecret::query()
            ->where('distribution_channel_id', (int) $channel->id)
            ->where('status', 'active')
            ->firstOrFail();
        $this->assertStringStartsWith('blog_', $secret->key_id);
        $this->assertSame(['blogger.posts'], $secret->scopes);
    }

    public function test_admin_can_create_facebook_page_channel(): void
    {
        $response = $this->actingAs($this->admin, 'admin')->post(route('admin.distribution.store'), [
            'name' => 'My Facebook Page',
            'domain' => 'facebook.com',
            'endpoint_url' => 'https://graph.facebook.com',
            'channel_type' => 'facebook_page',
            'status' => 'active',
            'facebook_page_id' => '987654321',
            'facebook_char_limit' => 60000,
            'facebook_access_token' => 'EAA_test_access_token',
            'facebook_refresh_token' => '',
        ]);

        $response->assertRedirect(route('admin.distribution.show', ['channelId' => 1]));

        $this->assertDatabaseHas('distribution_channels', [
            'name' => 'My Facebook Page',
            'channel_type' => 'facebook_page',
        ]);

        $channel = DistributionChannel::query()->where('name', 'My Facebook Page')->firstOrFail();
        $this->assertTrue($channel->isFacebookPage());
        $this->assertSame('987654321', $channel->resolvedFacebookConfig()['facebook_page_id']);
        $this->assertSame(60000, $channel->resolvedFacebookConfig()['facebook_char_limit']);

        $secret = DistributionChannelSecret::query()
            ->where('distribution_channel_id', (int) $channel->id)
            ->where('status', 'active')
            ->firstOrFail();
        $this->assertStringStartsWith('fb_', $secret->key_id);
        $this->assertSame(['facebook.page_manage'], $secret->scopes);
    }

    public function test_blogger_channel_requires_blog_id_on_create(): void
    {
        $response = $this->actingAs($this->admin, 'admin')->post(route('admin.distribution.store'), [
            'name' => 'Bad Blogger',
            'domain' => 'blogger.com',
            'endpoint_url' => 'https://blogger.googleapis.com',
            'channel_type' => 'blogger',
            'status' => 'active',
            'blogger_access_token' => 'ya29.test',
        ]);

        $response->assertSessionHasErrors('blogger_blog_id');
    }

    public function test_facebook_channel_requires_page_id_on_create(): void
    {
        $response = $this->actingAs($this->admin, 'admin')->post(route('admin.distribution.store'), [
            'name' => 'Bad Facebook',
            'domain' => 'facebook.com',
            'endpoint_url' => 'https://graph.facebook.com',
            'channel_type' => 'facebook_page',
            'status' => 'active',
            'facebook_access_token' => 'EAA.test',
        ]);

        $response->assertSessionHasErrors('facebook_page_id');
    }

    public function test_blogger_health_check(): void
    {
        // Fake both the token refresh and the health endpoint
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response([
                'access_token' => 'fresh_token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ]),
            'blogger.googleapis.com/v3/users/self' => Http::response([
                'id' => '42',
                'displayName' => 'Test User',
            ]),
        ]);

        $channel = DistributionChannel::query()->create([
            'name' => 'Blogger Health',
            'domain' => 'blogger.com',
            'endpoint_url' => 'https://blogger.googleapis.com',
            'channel_type' => 'blogger',
            'channel_config' => ['blogger_blog_id' => '123456789'],
            'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'blog_hc',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt(
                json_encode(['access_token' => 'test', 'refresh_token' => 'google_test_refresh', 'expires_at' => null, 'token_type' => 'Bearer'])
            ),
            'status' => 'active',
            'scopes' => ['blogger.posts'],
        ]);

        $response = $this->actingAs($this->admin, 'admin')
            ->post(route('admin.distribution.health', ['channelId' => (int) $channel->id]));
        $response->assertRedirect();

        $channel->refresh();
        $this->assertSame('ok', $channel->last_health_status);
    }

    public function test_facebook_health_check(): void
    {
        // Fake both the token refresh and the health endpoint
        Http::fake([
            'graph.facebook.com/v24.0/oauth/access_token*' => Http::response([
                'access_token' => 'fresh_fb_token',
                'expires_in' => 5184000,
                'token_type' => 'bearer',
            ]),
            'graph.facebook.com/v24.0/987654321*' => Http::response([
                'id' => '987654321',
                'name' => 'My Page',
            ]),
        ]);

        $channel = DistributionChannel::query()->create([
            'name' => 'FB Health',
            'domain' => 'facebook.com',
            'endpoint_url' => 'https://graph.facebook.com',
            'channel_type' => 'facebook_page',
            'channel_config' => ['facebook_page_id' => '987654321'],
            'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'fb_hc',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt(
                json_encode(['access_token' => 'fb_test', 'refresh_token' => '', 'expires_at' => '2027-01-01T00:00:00Z', 'token_type' => 'Bearer'])
            ),
            'status' => 'active',
            'scopes' => ['facebook.page_manage'],
        ]);

        $response = $this->actingAs($this->admin, 'admin')
            ->post(route('admin.distribution.health', ['channelId' => (int) $channel->id]));
        $response->assertRedirect();

        $channel->refresh();
        $this->assertSame('ok', $channel->last_health_status);
    }

    public function test_blogger_channel_show_page_renders_health_status_and_guide(): void
    {
        $channel = DistributionChannel::query()->create([
            'name' => 'Blogger Show Test',
            'domain' => 'blogger.com',
            'endpoint_url' => 'https://blogger.googleapis.com',
            'channel_type' => 'blogger',
            'channel_config' => [
                'blogger_blog_id' => '123456',
                'blogger_post_status' => 'live',
                'blogger_label_strategy' => 'keywords_to_labels',
            ],
            'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'blog_show',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt(
                json_encode(['access_token' => 'test', 'refresh_token' => 'refresh', 'expires_at' => null, 'token_type' => 'Bearer'])
            ),
            'status' => 'active',
            'scopes' => ['blogger.posts'],
        ]);

        $this->actingAs($this->admin, 'admin')
            ->get(route('admin.distribution.show', ['channelId' => (int) $channel->id]))
            ->assertOk()
            ->assertSee(__('admin.distribution.channel_type.blogger'))
            ->assertSee(__('admin.distribution.blogger.guide_title'))
            ->assertSee(__('admin.distribution.field.health_status'))
            ->assertSee(__('admin.distribution.blogger.blog_id'))
            ->assertDontSee(route('admin.distribution.sync-settings', ['channelId' => (int) $channel->id], false))
            ->assertDontSee(__('admin.distribution.detail.target_package_files'));
    }

    public function test_facebook_channel_show_page_renders_health_status_and_guide(): void
    {
        $channel = DistributionChannel::query()->create([
            'name' => 'Facebook Show Test',
            'domain' => 'facebook.com',
            'endpoint_url' => 'https://graph.facebook.com',
            'channel_type' => 'facebook_page',
            'channel_config' => [
                'facebook_page_id' => '987654321',
                'facebook_char_limit' => 63206,
            ],
            'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'fb_show',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt(
                json_encode(['access_token' => 'test', 'refresh_token' => '', 'expires_at' => null, 'token_type' => 'Bearer'])
            ),
            'status' => 'active',
            'scopes' => ['facebook.page_manage'],
        ]);

        $this->actingAs($this->admin, 'admin')
            ->get(route('admin.distribution.show', ['channelId' => (int) $channel->id]))
            ->assertOk()
            ->assertSee(__('admin.distribution.channel_type.facebook_page'))
            ->assertSee(__('admin.distribution.facebook.guide_title'))
            ->assertSee(__('admin.distribution.field.health_status'))
            ->assertSee(__('admin.distribution.facebook.page_id'))
            ->assertSee(__('admin.distribution.facebook.char_limit_warning'))
            ->assertDontSee(route('admin.distribution.sync-settings', ['channelId' => (int) $channel->id], false))
            ->assertDontSee(__('admin.distribution.detail.target_package_files'));
    }
}
