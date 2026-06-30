<?php

namespace App\Services\GeoFlow;

use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use Illuminate\Http\Client\Response;
use RuntimeException;

class FacebookPagePublisher implements DistributionPublisherInterface
{
    public function __construct(
        private readonly FacebookPageRequestFactory $requestFactory,
    ) {}

    public function health(DistributionChannel $channel): array
    {
        $pageId = $channel->resolvedFacebookConfig()['facebook_page_id'];
        $response = $this->requestFactory->request($channel, 10)
            ->get($this->graphBaseUrl().'/'.$pageId, ['fields' => 'name,id']);
        $this->throwIfFailed($response, 'Facebook 健康检查');
        $data = $response->json();
        if (! is_array($data)) {
            $data = [];
        }

        return [
            'ok' => true,
            'channel_type' => 'facebook_page',
            'page_id' => (string) ($data['id'] ?? ''),
            'page_name' => (string) ($data['name'] ?? ''),
        ];
    }

    public function publish(ArticleDistribution $distribution, array $payload): array
    {
        $distribution->loadMissing('channel');
        $channel = $this->channel($distribution);
        $config = $channel->resolvedFacebookConfig();
        $message = $this->buildMessage($payload);
        $this->warnIfOverLimit($distribution, $channel, $message);

        $images = $payload['assets']['images'] ?? [];
        $firstImage = is_array($images) && ! empty($images[0]) ? $images[0] : null;

        if ($firstImage && $this->hasImageData($firstImage)) {
            return $this->publishWithPhoto($channel, $config, $message, $firstImage);
        }

        if ($firstImage && $this->hasImageSourceUrl($firstImage)) {
            return $this->publishWithPhotoUrl($channel, $config, $message, $firstImage);
        }

        return $this->publishTextOnly($channel, $config, $message);
    }

    public function update(ArticleDistribution $distribution, array $payload): array
    {
        $distribution->loadMissing('channel');
        $channel = $this->channel($distribution);
        $postId = (string) ($distribution->remote_id ?? '');
        if ($postId === '') {
            return $this->publish($distribution, $payload);
        }
        $message = $this->buildMessage($payload);
        $response = $this->requestFactory->request($channel)
            ->post($this->graphBaseUrl().'/'.$postId, ['message' => $message]);
        $this->throwIfFailed($response, 'Facebook 文章更新');

        return [
            'remote_id' => $postId,
            'remote_url' => $this->permalink($channel, $postId),
            'remote_meta' => ['facebook_post_id' => $postId],
        ];
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
            ->delete($this->graphBaseUrl().'/'.$postId);
        $this->throwIfFailed($response, 'Facebook 文章删除');

        return ['deleted' => true, 'remote_id' => $postId, 'remote_url' => null];
    }

    public function syncSiteSettings(DistributionChannel $channel): array
    {
        return ['ok' => true, 'message' => 'Facebook 不支持站点设置同步。'];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function buildMessage(array $payload): string
    {
        $article = is_array($payload['article'] ?? null) ? $payload['article'] : [];
        $title = trim((string) ($article['title'] ?? ''));
        $contentHtml = (string) ($article['content_html'] ?? '');
        $plainText = trim(strip_tags($contentHtml));
        $plainText = preg_replace('/\s+/', "\n", $plainText) ?? $plainText;
        if ($title !== '' && $plainText !== '') {
            return $title."\n\n".$plainText;
        }

        return $title !== '' ? $title : $plainText;
    }

    /**
     * @param  array<string,mixed>  $config
     * @param  array<string,mixed>  $image
     */
    private function publishWithPhoto(DistributionChannel $channel, array $config, string $message, array $image): array
    {
        $pageId = $config['facebook_page_id'];
        $mimeType = (string) ($image['mime_type'] ?? 'image/jpeg');
        $base64 = (string) ($image['content_base64'] ?? '');
        $binary = base64_decode($base64, true);
        if ($binary === false) {
            return $this->publishTextOnly($channel, $config, $message);
        }
        $ext = $this->extensionForMime($mimeType);
        $response = $this->requestFactory->request($channel)
            ->asMultipart()
            ->post($this->graphBaseUrl().'/'.$pageId.'/photos', [
                ['name' => 'caption', 'contents' => $message],
                ['name' => 'published', 'contents' => 'true'],
                ['name' => 'source', 'contents' => $binary, 'filename' => 'cover.'.$ext],
            ]);
        $this->throwIfFailed($response, 'Facebook 图片发布');
        $json = $response->json();
        $postId = (string) ($json['post_id'] ?? $json['id'] ?? '');

        return [
            'remote_id' => $postId,
            'remote_url' => $this->permalink($channel, $postId),
            'remote_meta' => ['facebook_post_id' => $postId, 'facebook_photo_id' => (string) ($json['id'] ?? '')],
        ];
    }

    /**
     * @param  array<string,mixed>  $config
     * @param  array<string,mixed>  $image
     */
    private function publishWithPhotoUrl(DistributionChannel $channel, array $config, string $message, array $image): array
    {
        $pageId = $config['facebook_page_id'];
        $response = $this->requestFactory->request($channel)
            ->asJson()
            ->post($this->graphBaseUrl().'/'.$pageId.'/photos', [
                'url' => (string) ($image['source_url'] ?? ''),
                'caption' => $message,
                'published' => true,
            ]);
        $this->throwIfFailed($response, 'Facebook 图片发布(URL)');
        $json = $response->json();
        $postId = (string) ($json['post_id'] ?? $json['id'] ?? '');

        return [
            'remote_id' => $postId,
            'remote_url' => $this->permalink($channel, $postId),
            'remote_meta' => ['facebook_post_id' => $postId, 'facebook_photo_id' => (string) ($json['id'] ?? '')],
        ];
    }

    /**
     * @param  array<string,mixed>  $image
     */
    private function hasImageSourceUrl(array $image): bool
    {
        return ! empty($image['source_url']);
    }

    /**
     * @param  array<string,mixed>  $config
     */
    private function publishTextOnly(DistributionChannel $channel, array $config, string $message): array
    {
        $pageId = $config['facebook_page_id'];
        $response = $this->requestFactory->request($channel)
            ->asJson()
            ->post($this->graphBaseUrl().'/'.$pageId.'/feed', ['message' => $message]);
        $this->throwIfFailed($response, 'Facebook 文本发布');
        $json = $response->json();
        $postId = (string) ($json['id'] ?? '');

        return [
            'remote_id' => $postId,
            'remote_url' => $this->permalink($channel, $postId),
            'remote_meta' => ['facebook_post_id' => $postId],
        ];
    }

    private function warnIfOverLimit(ArticleDistribution $distribution, DistributionChannel $channel, string $message): void
    {
        $limit = $channel->resolvedFacebookConfig()['facebook_char_limit'];
        if ($limit > 0 && mb_strlen($message) > $limit) {
            app(DistributionOrchestrator::class)->log(
                'warning',
                'Facebook 文章字符数('.mb_strlen($message).')超过限制('.$limit.')，仍将尝试发布。',
                (int) $channel->id,
                (int) $distribution->id,
                (int) $distribution->article_id,
                ['event' => 'facebook.char_limit_exceeded', 'length' => mb_strlen($message), 'limit' => $limit]
            );
        }
    }

    private function graphBaseUrl(): string
    {
        $version = (string) config('geoflow.facebook_graph_version', 'v24.0');

        return 'https://graph.facebook.com/'.$version;
    }

    private function permalink(DistributionChannel $channel, string $postId): string
    {
        $pageId = $channel->resolvedFacebookConfig()['facebook_page_id'];
        $cleanId = str_contains($postId, '_') ? explode('_', $postId, 2)[1] : $postId;

        return 'https://www.facebook.com/'.$pageId.'/posts/'.$cleanId;
    }

    /**
     * @param  array<string,mixed>  $image
     */
    private function hasImageData(array $image): bool
    {
        return ! empty($image['content_base64']);
    }

    private function extensionForMime(string $mime): string
    {
        return match ($mime) {
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'jpg',
        };
    }

    private function channel(ArticleDistribution $distribution): DistributionChannel
    {
        if (! $distribution->channel instanceof DistributionChannel) {
            throw new RuntimeException('分发记录缺少 Facebook 渠道。');
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
}
