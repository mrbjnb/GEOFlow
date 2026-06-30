<?php

namespace App\Services\GeoFlow;

use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use Illuminate\Http\Client\Response;
use RuntimeException;

class BloggerPublisher implements DistributionPublisherInterface
{
    public function __construct(
        private readonly BloggerRequestFactory $requestFactory,
    ) {}

    public function health(DistributionChannel $channel): array
    {
        $response = $this->requestFactory->request($channel, 10)
            ->get('https://blogger.googleapis.com/v3/users/self');
        $this->throwIfFailed($response, 'Blogger 健康检查');
        $user = $response->json();
        if (! is_array($user)) {
            $user = [];
        }

        return [
            'ok' => true,
            'channel_type' => 'blogger',
            'user_id' => (string) ($user['id'] ?? ''),
            'user_name' => (string) ($user['displayName'] ?? ''),
        ];
    }

    public function publish(ArticleDistribution $distribution, array $payload): array
    {
        $distribution->loadMissing('channel');
        $channel = $this->channel($distribution);
        $config = $channel->resolvedBloggerConfig();
        $url = $this->postsUrl($channel);
        if ($config['blogger_post_status'] === 'draft') {
            $url .= '?isDraft=true';
        }
        $response = $this->requestFactory->request($channel)
            ->post($url, $this->postPayload($payload, $config));
        $this->throwIfFailed($response, 'Blogger 文章发布');

        return $this->postResult($response);
    }

    public function update(ArticleDistribution $distribution, array $payload): array
    {
        $distribution->loadMissing('channel');
        $channel = $this->channel($distribution);
        $postId = (string) ($distribution->remote_id ?? '');
        if ($postId === '') {
            return $this->publish($distribution, $payload);
        }
        $config = $channel->resolvedBloggerConfig();
        $response = $this->requestFactory->request($channel)
            ->patch($this->postsUrl($channel).'/'.$postId, $this->postPayload($payload, $config));
        $this->throwIfFailed($response, 'Blogger 文章更新');

        return $this->postResult($response);
    }

    public function delete(ArticleDistribution $distribution): array
    {
        $distribution->loadMissing('channel');
        $channel = $this->channel($distribution);
        $postId = (string) ($distribution->remote_id ?? '');
        if ($postId === '') {
            return ['deleted' => true, 'remote_id' => null, 'remote_url' => null, 'message' => 'missing_remote_post_id'];
        }
        $response = $this->requestFactory->request($channel)
            ->delete($this->postsUrl($channel).'/'.$postId.'?useTrash=true');
        $this->throwIfFailed($response, 'Blogger 文章删除');

        return ['deleted' => true, 'remote_id' => $postId, 'remote_url' => null];
    }

    public function syncSiteSettings(DistributionChannel $channel): array
    {
        return ['ok' => true, 'message' => 'Blogger 不支持站点设置同步。'];
    }

    private function postsUrl(DistributionChannel $channel): string
    {
        $blogId = $channel->resolvedBloggerConfig()['blogger_blog_id'];

        return 'https://blogger.googleapis.com/v3/blogs/'.$blogId.'/posts';
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $config
     * @return array<string,mixed>
     */
    private function postPayload(array $payload, array $config): array
    {
        $article = is_array($payload['article'] ?? null) ? $payload['article'] : [];
        $labels = [];
        if ($config['blogger_label_strategy'] === 'keywords_to_labels') {
            $keywords = trim((string) ($article['keywords'] ?? ''));
            if ($keywords !== '') {
                $labels = array_values(array_filter(array_map('trim', explode(',', $keywords)), fn ($v) => $v !== ''));
            }
        }
        $body = [
            'title' => (string) ($article['title'] ?? ''),
            'content' => (string) ($article['content_html'] ?? ''),
        ];
        if ($labels !== []) {
            $body['labels'] = $labels;
        }

        return $body;
    }

    private function channel(ArticleDistribution $distribution): DistributionChannel
    {
        if (! $distribution->channel instanceof DistributionChannel) {
            throw new RuntimeException('分发记录缺少 Blogger 渠道。');
        }

        return $distribution->channel;
    }

    private function throwIfFailed(Response $response, string $operation): void
    {
        if (! $response->failed()) {
            return;
        }
        $body = strip_tags((string) $response->body());
        $body = preg_replace('/\s+/', ' ', trim($body));
        $summary = is_string($body) && mb_strlen($body) > 300 ? mb_substr($body, 0, 300).'...' : (string) $body;
        throw new RuntimeException($operation.'失败：HTTP '.$response->status().($summary !== '' ? ' '.$summary : ''));
    }

    /**
     * @return array<string,mixed>
     */
    private function postResult(Response $response): array
    {
        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('Blogger 返回内容不是有效 JSON。');
        }

        return [
            'remote_id' => (string) ($json['id'] ?? ''),
            'remote_url' => (string) ($json['url'] ?? ''),
            'remote_meta' => [
                'blogger_post_id' => (string) ($json['id'] ?? ''),
                'blogger_status' => (string) ($json['status'] ?? ''),
            ],
        ];
    }
}
