<?php

namespace Tests\Unit;

use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\DistributionChannelSecret;
use App\Services\GeoFlow\FacebookPagePublisher;
use App\Services\GeoFlow\OAuthTokenRefreshService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FacebookPagePublisherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'geoflow.facebook_app_id' => 'test-fb-id',
            'geoflow.facebook_app_secret' => 'test-fb-secret',
        ]);
    }

    public function test_it_publishes_text_only_to_page_feed(): void
    {
        Http::fake([
            'graph.facebook.com/v24.0/oauth/access_token*' => Http::response([
                'access_token' => 'fresh_fb', 'expires_in' => 5184000, 'token_type' => 'bearer',
            ]),
            'graph.facebook.com/v24.0/987654321/feed' => Http::response([
                'id' => '987654321_123456',
            ]),
        ]);

        [, $distribution] = $this->makeDistribution();

        $result = app(FacebookPagePublisher::class)->publish($distribution, [
            'article' => ['title' => 'Hello World', 'content_html' => '<p>Hello</p>', 'keywords' => ''],
            'assets' => ['images' => []],
        ]);

        $this->assertSame('987654321_123456', $result['remote_id']);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/987654321/feed')
                && $request['message'] === "Hello World\n\nHello";
        });
    }

    public function test_it_publishes_with_photo_when_image_has_content_base64(): void
    {
        Http::fake([
            'graph.facebook.com/v24.0/oauth/access_token*' => Http::response([
                'access_token' => 'fresh_fb', 'expires_in' => 5184000, 'token_type' => 'bearer',
            ]),
            'graph.facebook.com/v24.0/987654321/photos' => Http::response([
                'id' => 'photo_789', 'post_id' => '987654321_456789',
            ]),
        ]);

        [, $distribution] = $this->makeDistribution();

        $result = app(FacebookPagePublisher::class)->publish($distribution, [
            'article' => ['title' => 'Photo Post', 'content_html' => '<p>With image</p>', 'keywords' => ''],
            'assets' => ['images' => [[
                'source_url' => 'https://geoflow.test/images/hero.jpg',
                'mime_type' => 'image/jpeg',
                'content_base64' => base64_encode('fake-binary-image-data'),
                'filename' => 'hero.jpg',
            ]]],
        ]);

        $this->assertSame('987654321_456789', $result['remote_id']);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/987654321/photos');
        });
    }

    public function test_it_publishes_with_photo_url_when_no_base64(): void
    {
        Http::fake([
            'graph.facebook.com/v24.0/oauth/access_token*' => Http::response([
                'access_token' => 'fresh_fb', 'expires_in' => 5184000, 'token_type' => 'bearer',
            ]),
            'graph.facebook.com/v24.0/987654321/photos' => Http::response([
                'id' => 'photo_999', 'post_id' => '987654321_999888',
            ]),
        ]);

        [, $distribution] = $this->makeDistribution();

        $result = app(FacebookPagePublisher::class)->publish($distribution, [
            'article' => ['title' => 'URL Photo', 'content_html' => '<p>Remote image</p>', 'keywords' => ''],
            'assets' => ['images' => [[
                'source_url' => 'https://cdn.example.com/remote-image.jpg',
                'filename' => 'remote.jpg',
            ]]],
        ]);

        $this->assertSame('987654321_999888', $result['remote_id']);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/987654321/photos')
                && $request['url'] === 'https://cdn.example.com/remote-image.jpg';
        });
    }

    public function test_it_updates_existing_facebook_post(): void
    {
        Http::fake([
            'graph.facebook.com/v24.0/oauth/access_token*' => Http::response([
                'access_token' => 'fresh_fb', 'expires_in' => 5184000, 'token_type' => 'bearer',
            ]),
            'graph.facebook.com/v24.0/987654321_123456' => Http::response([
                'success' => true,
            ]),
        ]);

        [, $distribution] = $this->makeDistribution(['remote_id' => '987654321_123456']);

        $result = app(FacebookPagePublisher::class)->update($distribution, [
            'article' => ['title' => 'Updated', 'content_html' => '<p>Updated</p>', 'keywords' => ''],
            'assets' => ['images' => []],
        ]);

        $this->assertSame('987654321_123456', $result['remote_id']);
    }

    public function test_it_deletes_existing_facebook_post(): void
    {
        Http::fake([
            'graph.facebook.com/v24.0/oauth/access_token*' => Http::response([
                'access_token' => 'fresh_fb', 'expires_in' => 5184000, 'token_type' => 'bearer',
            ]),
            'graph.facebook.com/v24.0/987654321_123456*' => Http::response([
                'success' => true,
            ]),
        ]);

        [, $distribution] = $this->makeDistribution(['remote_id' => '987654321_123456']);

        $result = app(FacebookPagePublisher::class)->delete($distribution);

        $this->assertTrue($result['deleted']);
    }

    public function test_health_checks_facebook_page_endpoint(): void
    {
        Http::fake([
            'graph.facebook.com/v24.0/oauth/access_token*' => Http::response([
                'access_token' => 'fresh_fb', 'expires_in' => 5184000, 'token_type' => 'bearer',
            ]),
            'graph.facebook.com/v24.0/987654321*' => Http::response([
                'id' => '987654321', 'name' => 'My Page',
            ]),
        ]);

        [$channel] = $this->makeDistribution();

        $result = app(FacebookPagePublisher::class)->health($channel);

        $this->assertTrue($result['ok']);
        $this->assertSame('facebook_page', $result['channel_type']);
        $this->assertSame('987654321', $result['page_id']);
        $this->assertSame('My Page', $result['page_name']);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array{0:DistributionChannel,1:ArticleDistribution}
     */
    private function makeDistribution(array $overrides = []): array
    {
        $channel = DistributionChannel::query()->create([
            'name' => 'FB Page Test', 'domain' => 'facebook.com', 'endpoint_url' => 'https://graph.facebook.com',
            'channel_type' => 'facebook_page',
            'channel_config' => ['facebook_page_id' => '987654321', 'facebook_char_limit' => 63206],
            'status' => 'active',
        ]);

        $service = app(OAuthTokenRefreshService::class);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'fb_test',
            'secret_ciphertext' => $service->encryptInitialCredentials('fb_test_access', '', '2024-01-01T00:00:00Z'),
            'status' => 'active', 'scopes' => ['facebook.page_manage'],
        ]);

        $category = Category::query()->create(['name' => 'Tech', 'slug' => 'tech']);
        $author = Author::query()->create(['name' => 'GEOFlow']);
        $article = Article::query()->create([
            'title' => 'Hello World', 'slug' => 'hello-world', 'content' => 'Hello',
            'category_id' => (int) $category->id, 'author_id' => (int) $author->id,
            'status' => 'published', 'review_status' => 'approved', 'published_at' => now(),
        ]);

        $distribution = ArticleDistribution::query()->create(array_merge([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish', 'status' => 'queued', 'idempotency_key' => 'fb-test-key',
        ], $overrides));

        return [$channel, $distribution];
    }
}
