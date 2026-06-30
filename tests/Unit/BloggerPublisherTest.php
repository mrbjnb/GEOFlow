<?php

namespace Tests\Unit;

use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\DistributionChannelSecret;
use App\Services\GeoFlow\BloggerPublisher;
use App\Services\GeoFlow\OAuthTokenRefreshService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BloggerPublisherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'geoflow.google_client_id' => 'test-google-id',
            'geoflow.google_client_secret' => 'test-google-secret',
        ]);
    }

    public function test_it_publishes_article_to_blogger_posts_endpoint(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response([
                'access_token' => 'fresh_token', 'expires_in' => 3600, 'token_type' => 'Bearer',
            ]),
            'blogger.googleapis.com/v3/blogs/123456/posts' => Http::response([
                'id' => '789', 'url' => 'https://blog.example.com/2026/hello-world', 'status' => 'LIVE',
            ]),
        ]);

        [, $distribution] = $this->makeDistribution();

        $result = app(BloggerPublisher::class)->publish($distribution, [
            'article' => ['title' => 'Hello World', 'content_html' => '<p>Hello</p>', 'keywords' => 'geo, ai'],
            'assets' => ['images' => []],
        ]);

        $this->assertSame('789', $result['remote_id']);
        $this->assertSame('https://blog.example.com/2026/hello-world', $result['remote_url']);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && str_contains($request->url(), 'blogger.googleapis.com/v3/blogs/123456/posts')
                && ! str_contains($request->url(), 'isDraft=true')
                && $request['title'] === 'Hello World'
                && $request['content'] === '<p>Hello</p>'
                && $request['labels'] === ['geo', 'ai'];
        });
    }

    public function test_it_publishes_as_draft_when_configured(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response([
                'access_token' => 'fresh_token', 'expires_in' => 3600, 'token_type' => 'Bearer',
            ]),
            'blogger.googleapis.com/v3/blogs/123456/posts*' => Http::response([
                'id' => '789', 'url' => 'https://blog.example.com/2026/hello-world', 'status' => 'DRAFT',
            ]),
        ]);

        [, $distribution] = $this->makeDistribution(['channel_config' => [
            'blogger_blog_id' => '123456', 'blogger_post_status' => 'draft', 'blogger_label_strategy' => 'keywords_to_labels',
        ]]);

        $result = app(BloggerPublisher::class)->publish($distribution, [
            'article' => ['title' => 'Draft Post', 'content_html' => '<p>Draft</p>', 'keywords' => ''],
            'assets' => ['images' => []],
        ]);

        $this->assertSame('789', $result['remote_id']);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && str_contains($request->url(), 'isDraft=true');
        });
    }

    public function test_it_updates_existing_blogger_post(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response([
                'access_token' => 'fresh_token', 'expires_in' => 3600, 'token_type' => 'Bearer',
            ]),
            'blogger.googleapis.com/v3/blogs/123456/posts/789' => Http::response([
                'id' => '789', 'url' => 'https://blog.example.com/2026/hello-updated', 'status' => 'LIVE',
            ]),
        ]);

        [, $distribution] = $this->makeDistribution(['remote_id' => '789']);

        $result = app(BloggerPublisher::class)->update($distribution, [
            'article' => ['title' => 'Updated', 'content_html' => '<p>Updated</p>', 'keywords' => ''],
            'assets' => ['images' => []],
        ]);

        $this->assertSame('789', $result['remote_id']);
        $this->assertSame('https://blog.example.com/2026/hello-updated', $result['remote_url']);
    }

    public function test_it_deletes_existing_blogger_post_with_use_trash(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response([
                'access_token' => 'fresh_token', 'expires_in' => 3600, 'token_type' => 'Bearer',
            ]),
            'blogger.googleapis.com/v3/blogs/123456/posts/789*' => Http::response([], 200),
        ]);

        [, $distribution] = $this->makeDistribution(['remote_id' => '789']);

        $result = app(BloggerPublisher::class)->delete($distribution);

        $this->assertTrue($result['deleted']);
        $this->assertSame('789', $result['remote_id']);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'DELETE'
                && str_contains($request->url(), 'useTrash=true');
        });
    }

    public function test_health_checks_blogger_user_endpoint(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response([
                'access_token' => 'fresh_token', 'expires_in' => 3600, 'token_type' => 'Bearer',
            ]),
            'blogger.googleapis.com/v3/users/self' => Http::response([
                'id' => '42', 'displayName' => 'Test User',
            ]),
        ]);

        [$channel] = $this->makeDistribution();

        $result = app(BloggerPublisher::class)->health($channel);

        $this->assertTrue($result['ok']);
        $this->assertSame('blogger', $result['channel_type']);
        $this->assertSame('42', $result['user_id']);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array{0:DistributionChannel,1:ArticleDistribution}
     */
    private function makeDistribution(array $overrides = []): array
    {
        $channelConfig = $overrides['channel_config'] ?? [
            'blogger_blog_id' => '123456', 'blogger_post_status' => 'live', 'blogger_label_strategy' => 'keywords_to_labels',
        ];
        unset($overrides['channel_config']);

        $channel = DistributionChannel::query()->create([
            'name' => 'Blogger Test', 'domain' => 'blogger.com', 'endpoint_url' => 'https://blogger.googleapis.com',
            'channel_type' => 'blogger', 'channel_config' => $channelConfig, 'status' => 'active',
        ]);

        $service = app(OAuthTokenRefreshService::class);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'blog_test',
            'secret_ciphertext' => $service->encryptInitialCredentials('test_access', 'test_refresh', '2024-01-01T00:00:00Z'),
            'status' => 'active', 'scopes' => ['blogger.posts'],
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
            'action' => 'publish', 'status' => 'queued', 'idempotency_key' => 'blog-test-key',
        ], $overrides));

        return [$channel, $distribution];
    }
}
