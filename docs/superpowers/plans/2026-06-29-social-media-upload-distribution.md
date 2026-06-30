# Social Media Upload (Facebook Page + Blogger) Distribution Support Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Facebook Page and Google Blogger as first-class distribution channel types in the existing Distribution framework, so GEOFlow can publish articles to social media and blog platforms using OAuth2 authentication with automatic token refresh.

**Architecture:** Extend the existing publisher strategy pattern. Two new `channel_type` values (`blogger`, `facebook_page`) register in the existing `DistributionChannel::channelType()` whitelist and `DistributionPublisherManager` match arms. Add a shared `OAuthTokenRefreshService` (new) to handle expiring OAuth2 tokens — the first capability of its kind in the distribution system. Reuse all existing infrastructure: tables, orchestrator, job, retry policy, idempotency, logging, payload builder, admin UI. Copy the `WordPressRestPublisher` pattern for `BloggerPublisher`; create a new shape for `FacebookPagePublisher` with full-text + cover photo posting.

**Tech Stack:** Laravel 12, Blade admin UI, Tailwind, Eloquent, PostgreSQL JSON columns, Laravel HTTP client, Facebook Graph API v24.0, Blogger API v3, PHPUnit feature/unit tests.

---

## Scope

### Included In First Implementation

- Add `blogger` and `facebook_page` channel type values to `DistributionChannel`.
- Add `BloggerPublisher` — full-article publishing via Blogger API v3 (copy of WordPress pattern).
  - `title`, `content` (HTML), `labels` (from keywords).
  - Post status: `live` (publish) or `draft`.
  - Label strategy: `keywords_to_labels`, `disabled`.
  - Health check via `GET /v3/users/self`.
  - Update via `PATCH /v3/blogs/{blogId}/posts/{postId}`.
  - Delete via `DELETE /v3/blogs/{blogId}/posts/{postId}?useTrash=true`.
- Add `FacebookPagePublisher` — social posting via Facebook Graph API v24.
  - Full article text as `message` (title + plain-text content).
  - Cover image uploaded as photo (via `POST /{page-id}/photos` multipart).
  - Text-only fallback if no image available.
  - Character-limit warning logged (63206 limit, still attempts).
  - Health check via `GET /{page-id}?fields=name,id`.
  - Update via `POST /{post_id}` with `message`.
  - Delete via `DELETE /{post_id}`.
- Add `OAuthTokenRefreshService` — auto-refresh expiring tokens before publish.
  - Google (Blogger): `POST https://oauth2.googleapis.com/token` with `grant_type=refresh_token`.
  - Facebook: `GET https://graph.facebook.com/{version}/oauth/access_token?grant_type=fb_exchange_token`.
  - Token envelope `{access_token, refresh_token, expires_at, token_type}` encrypted via `ApiKeyCrypto`.
- Add `BloggerRequestFactory` and `FacebookPageRequestFactory` — HTTP clients with Bearer auth + token refresh.
- Extend `DistributionController` — validation, store/update branching, secret creation, config normalization.
- Extend Blade forms: radio cards + conditional fieldsets for both types in create/edit/show views.
- Extend lang files: `en/admin.php` + `zh_CN/admin.php` — blogger/facebook sections, validation messages.
- Add config keys to `config/geoflow.php` — `facebook_app_id`, `facebook_app_secret`, `facebook_graph_version`, `facebook_char_limit`, `google_client_id`, `google_client_secret`.
- Add unit tests for publisher resolution + OAuth refresh.
- Add feature tests for channel creation, health checks, validation.

### Deferred

- Full OAuth2 authorization-code callback flow (manual token paste for v1).
- Facebook personal profile / Group posting (Page only).
- Video upload support (Facebook Page video posts).
- Other social platforms (Mastodon, Bluesky, X/Twitter, LinkedIn, Dev.to).
- Blogger image upload to Google Photos (keeps original GEOFlow URLs — site must be public).
- `syncSiteSettings` for social channels (not supported by platform APIs).

---

## File Map

### New Files

| File | Purpose | Based On |
|---|---|---|
| `app/Services/GeoFlow/OAuthTokenRefreshService.php` | Token refresh for Google + Facebook OAuth2 | New |
| `app/Services/GeoFlow/BloggerRequestFactory.php` | Bearer-auth HTTP client with refresh | Copy of `WordPressRestRequestFactory.php` |
| `app/Services/GeoFlow/BloggerPublisher.php` | Blogger posts CRUD | Copy of `WordPressRestPublisher.php` |
| `app/Services/GeoFlow/FacebookPageRequestFactory.php` | Bearer-auth HTTP client with refresh | Copy of `WordPressRestRequestFactory.php` |
| `app/Services/GeoFlow/FacebookPagePublisher.php` | Facebook Page feed/photo posts | New shape |
| `tests/Unit/SocialPublisherResolutionTest.php` | Publisher resolution test | Copy of `DistributionPublisherManagerTest.php` |
| `tests/Unit/OAuthTokenRefreshServiceTest.php` | OAuth token refresh test | New |
| `tests/Feature/AdminDistributionSocialChannelTest.php` | Social channel HTTP tests | Copy of WP section in `AdminDistributionPageTest.php` |

### Modified Files

| File | Change |
|---|---|
| `app/Models/DistributionChannel.php` | Add `blogger`/`facebook_page` to `channelType()` whitelist. Add `isBlogger()`, `isFacebookPage()`, `resolvedBloggerConfig()`, `resolvedFacebookConfig()`. |
| `app/Services/GeoFlow/DistributionPublisherManager.php` | Inject 2 new publishers + 2 match arms |
| `app/Http/Controllers/Admin/DistributionController.php` | Constructor DI (add `OAuthTokenRefreshService`). Validation rules (add blogger/facebook fields). Store/update branching. `normalizeChannelConfig()` branches. `createBloggerSecret()`, `createFacebookSecret()`. |
| `resources/views/admin/distribution/create.blade.php` | Add 2 radio cards (change grid to `lg:grid-cols-5`). Add Blogger + Facebook conditional fieldsets. |
| `resources/views/admin/distribution/edit.blade.php` | Add Blogger + Facebook edit sections (channel_type locked). Token blank = keep current pattern. |
| `resources/views/admin/distribution/show.blade.php` | Add Blogger + Facebook guide sections. Hide target-package/rewrite for social. |
| `lang/en/admin.php` | Add `blogger`, `facebook` sub-arrays in `distribution` key. Add validation messages. |
| `lang/zh_CN/admin.php` | Same translations in Chinese. |
| `config/geoflow.php` | Add 5 new config keys for OAuth app credentials + Facebook char limit. |

---

## Platform API Contracts

### Blogger API v3

**Authentication:** Bearer token (Google OAuth2 access token), auto-refreshed via refresh_token.

**Base URL:** `https://blogger.googleapis.com/v3/`

| Operation | Endpoint | Method | Body |
|---|---|---|---|
| Health | `/users/self` | GET | — |
| Publish | `/blogs/{blogId}/posts` | POST | `{title, content, labels[]}` |
| Update | `/blogs/{blogId}/posts/{postId}` | PATCH | `{title, content, labels[]}` |
| Delete | `/blogs/{blogId}/posts/{postId}?useTrash=true` | DELETE | — |
| Draft | Same as publish + `?isDraft=true` query param | POST | Same body |

**Post schema:**
```json
{
  "title": "Article Title",
  "content": "<p>HTML content</p>",
  "labels": ["keyword1", "keyword2"]
}
```

**Health response:**
```json
{
  "id": "12345",
  "displayName": "Admin Name"
}
```

### Facebook Graph API v24

**Authentication:** Page access token (Bearer), auto-refreshed via `fb_exchange_token`.

**Base URL:** `https://graph.facebook.com/v24.0/`

| Operation | Endpoint | Method | Body/Query |
|---|---|---|---|
| Health | `/{page-id}?fields=name,id` | GET | — |
| Publish (text) | `/{page-id}/feed` | POST | `{message}` |
| Publish (photo) | `/{page-id}/photos` | POST | Multipart: `caption`, `source` (binary), `published=true` |
| Update | `/{post-id}` | POST | `{message}` |
| Delete | `/{post-id}` | DELETE | — |

**Page post limits:** 63206 characters for `message`. If exceeded, a warning event is logged but the post is still attempted (Facebook may reject it serverside).

**Post by photo (retrieved via GET `/{post-id}?fields=permalink_url`):**
```json
{
  "permalink_url": "https://www.facebook.com/{page_id}/posts/{post_id}"
}
```

But we construct permalink from `page_id` + `post_id`.

---

## Tasks

### Task 1: Add Social Channel Type Support To Model

**Files:**
- Modify: `app/Models/DistributionChannel.php`
- Add: `tests/Unit/SocialPublisherResolutionTest.php`

- [ ] **Step 1: Extend `channelType()` whitelist**

In `app/Models/DistributionChannel.php`, line 102, change:
```php
return in_array($type, ['geoflow_agent', 'wordpress_rest', 'generic_http_api'], true) ? $type : 'geoflow_agent';
```
to:
```php
return in_array($type, ['geoflow_agent', 'wordpress_rest', 'generic_http_api', 'blogger', 'facebook_page'], true) ? $type : 'geoflow_agent';
```

- [ ] **Step 2: Add type predicates after `isGenericHttpApi()` (after line 118)**

```php
public function isBlogger(): bool
{
    return $this->channelType() === 'blogger';
}

public function isFacebookPage(): bool
{
    return $this->channelType() === 'facebook_page';
}
```

- [ ] **Step 3: Add `resolvedBloggerConfig()` after `resolvedChannelConfig()` (after line 148)**

```php
/**
 * @return array{blogger_blog_id:string,blogger_post_status:string,blogger_label_strategy:string}
 */
public function resolvedBloggerConfig(): array
{
    $stored = is_array($this->channel_config) ? $this->channel_config : [];
    $postStatus = (string) ($stored['blogger_post_status'] ?? 'live');
    $labelStrategy = (string) ($stored['blogger_label_strategy'] ?? 'keywords_to_labels');

    return [
        'blogger_blog_id' => trim((string) ($stored['blogger_blog_id'] ?? '')),
        'blogger_post_status' => in_array($postStatus, ['live', 'draft'], true) ? $postStatus : 'live',
        'blogger_label_strategy' => in_array($labelStrategy, ['keywords_to_labels', 'disabled'], true) ? $labelStrategy : 'keywords_to_labels',
    ];
}
```

- [ ] **Step 4: Add `resolvedFacebookConfig()` after `resolvedBloggerConfig()`**

```php
/**
 * @return array{facebook_page_id:string,facebook_char_limit:int}
 */
public function resolvedFacebookConfig(): array
{
    $stored = is_array($this->channel_config) ? $this->channel_config : [];

    return [
        'facebook_page_id' => trim((string) ($stored['facebook_page_id'] ?? '')),
        'facebook_char_limit' => min(63206, max(0, (int) ($stored['facebook_char_limit'] ?? config('geoflow.facebook_char_limit', 63206)))),
    ];
}
```

- [ ] **Step 5: Add publisher resolution test**

Create `tests/Unit/SocialPublisherResolutionTest.php`:
```php
<?php

namespace Tests\Unit;

use App\Models\DistributionChannel;
use App\Services\GeoFlow\BloggerPublisher;
use App\Services\GeoFlow\DistributionPublisherManager;
use App\Services\GeoFlow\FacebookPagePublisher;
use Tests\TestCase;

class SocialPublisherResolutionTest extends TestCase
{
    public function test_it_resolves_blogger_publisher(): void
    {
        $channel = new DistributionChannel(['channel_type' => 'blogger']);
        $manager = app(DistributionPublisherManager::class);

        $this->assertInstanceOf(BloggerPublisher::class, $manager->forChannel($channel));
    }

    public function test_it_resolves_facebook_page_publisher(): void
    {
        $channel = new DistributionChannel(['channel_type' => 'facebook_page']);
        $manager = app(DistributionPublisherManager::class);

        $this->assertInstanceOf(FacebookPagePublisher::class, $manager->forChannel($channel));
    }
}
```

- [ ] **Step 6: Run test (may fail until publishers exist — that's OK)**

```bash
php artisan test tests/Unit/SocialPublisherResolutionTest.php
```

- [ ] **Step 7: Run existing distribution tests to confirm no regression**

```bash
php artisan test tests/Unit/DistributionPublisherManagerTest.php
```

- [ ] **Step 8: Run validation**

```bash
pnpm typecheck && pnpm lint && pnpm build
```

---

### Task 2: Create OAuth Token Refresh Service

**Files:**
- Create: `app/Services/GeoFlow/OAuthTokenRefreshService.php`
- Create: `tests/Unit/OAuthTokenRefreshServiceTest.php`

- [ ] **Step 1: Create OAuthTokenRefreshService**

Full file at `app/Services/GeoFlow/OAuthTokenRefreshService.php`:

```php
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

        $needsRefresh = $expiresAt === null || now()->addMinutes(5)->gte(now()->parse($expiresAt));

        if (! $needsRefresh) {
            return $credentials;
        }

        $refreshed = $platform === 'blogger'
            ? $this->refreshGoogleToken($credentials['refresh_token'])
            : $this->refreshFacebookToken($credentials['access_token']);

        $credentials['access_token'] = $refreshed['access_token'];
        $credentials['expires_at'] = $refreshed['expires_at'];
        $credentials['token_type'] = $refreshed['token_type'] ?? 'Bearer';
        if ($platform === 'blogger' && ! empty($refreshed['refresh_token'])) {
            $credentials['refresh_token'] = $refreshed['refresh_token'];
        }

        $this->reEncryptSecret($secret, $credentials);

        return $credentials;
    }

    /**
     * @return array{access_token:string,refresh_token:string,expires_at:?string,token_type:string}
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
```

- [ ] **Step 2: Create OAuth token refresh test**

Create `tests/Unit/OAuthTokenRefreshServiceTest.php`:
```php
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

    public function test_it_encrypts_and_decrypts_credentials(): void
    {
        $service = app(OAuthTokenRefreshService::class);
        $ciphertext = $service->encryptInitialCredentials('tok_access', 'tok_refresh', '2027-01-01T00:00:00Z');

        $channel = DistributionChannel::factory()->create(['channel_type' => 'blogger']);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'blog_test',
            'secret_ciphertext' => $ciphertext,
            'status' => 'active',
            'scopes' => ['blogger.posts'],
        ]);

        $result = $service->ensureValidToken($channel, 'blogger');

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

        $service = app(OAuthTokenRefreshService::class);
        $ciphertext = $service->encryptInitialCredentials('old_access', 'tok_refresh', '2024-01-01T00:00:00Z');

        $channel = DistributionChannel::factory()->create(['channel_type' => 'blogger']);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'blog_test2',
            'secret_ciphertext' => $ciphertext,
            'status' => 'active',
            'scopes' => ['blogger.posts'],
        ]);

        $result = $service->ensureValidToken($channel, 'blogger');

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

        $service = app(OAuthTokenRefreshService::class);
        $ciphertext = $service->encryptInitialCredentials('old_fb_access', '', '2024-01-01T00:00:00Z');

        $channel = DistributionChannel::factory()->create(['channel_type' => 'facebook_page']);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'fb_test',
            'secret_ciphertext' => $ciphertext,
            'status' => 'active',
            'scopes' => ['facebook.page_manage'],
        ]);

        $result = $service->ensureValidToken($channel, 'facebook');

        $this->assertSame('fb_new_access', $result['access_token']);
    }

    public function test_it_throws_on_revoked_token(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $service = app(OAuthTokenRefreshService::class);
        $ciphertext = $service->encryptInitialCredentials('old_access', 'bad_refresh', '2024-01-01T00:00:00Z');

        $channel = DistributionChannel::factory()->create(['channel_type' => 'blogger']);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'blog_test3',
            'secret_ciphertext' => $ciphertext,
            'status' => 'active',
            'scopes' => ['blogger.posts'],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Google token 刷新失败');

        $service->ensureValidToken($channel, 'blogger');
    }
}
```

- [ ] **Step 3: Run OAuth tests**

```bash
php artisan test tests/Unit/OAuthTokenRefreshServiceTest.php
```

Expected: all pass.

- [ ] **Step 4: Run validation**

```bash
pnpm typecheck && pnpm lint && pnpm build
```

---

### Task 3: Create Blogger Publisher

**Files:**
- Create: `app/Services/GeoFlow/BloggerRequestFactory.php`
- Create: `app/Services/GeoFlow/BloggerPublisher.php`

- [ ] **Step 1: Create BloggerRequestFactory**

Create `app/Services/GeoFlow/BloggerRequestFactory.php`:
```php
<?php

namespace App\Services\GeoFlow;

use App\Models\DistributionChannel;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class BloggerRequestFactory
{
    public function __construct(
        private readonly OAuthTokenRefreshService $oauthRefresh,
    ) {}

    public function request(DistributionChannel $channel, int $timeout = 30): PendingRequest
    {
        $credentials = $this->oauthRefresh->ensureValidToken($channel, 'blogger');

        return Http::timeout($timeout)
            ->acceptJson()
            ->asJson()
            ->withToken($credentials['access_token']);
    }
}
```

- [ ] **Step 2: Create BloggerPublisher**

Create `app/Services/GeoFlow/BloggerPublisher.php`:
```php
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
        $response = $this->requestFactory->request($channel)
            ->post($this->postsUrl($channel), $this->postPayload($channel, $payload, $config));
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
            ->patch($this->postsUrl($channel).'/'.$postId, $this->postPayload($channel, $payload, $config));
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
            ->delete($this->postsUrl($channel).'/'.$postId, ['useTrash' => 'true']);
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
    private function postPayload(DistributionChannel $channel, array $payload, array $config): array
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
```

- [ ] **Step 3: Run validation**

```bash
pnpm typecheck && pnpm lint && pnpm build
```

---

### Task 4: Create Facebook Page Publisher

**Files:**
- Create: `app/Services/GeoFlow/FacebookPageRequestFactory.php`
- Create: `app/Services/GeoFlow/FacebookPagePublisher.php`

- [ ] **Step 1: Create FacebookPageRequestFactory**

Create `app/Services/GeoFlow/FacebookPageRequestFactory.php`:
```php
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
```

- [ ] **Step 2: Create FacebookPagePublisher**

Create `app/Services/GeoFlow/FacebookPagePublisher.php`:
```php
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
        $mimeType = (string) ($image['mime'] ?? 'image/jpeg');
        $base64 = (string) ($image['data'] ?? '');
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
        return ! empty($image['data']);
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
```

- [ ] **Step 3: Run validation**

```bash
pnpm typecheck && pnpm lint && pnpm build
```

---

### Task 5: Extend Publisher Manager With Social Publishers

**Files:**
- Modify: `app/Services/GeoFlow/DistributionPublisherManager.php`

- [ ] **Step 1: Add 2 new constructor params + 2 match arms**

Current lines 10-23:
```php
    public function __construct(
        private readonly GeoFlowAgentPublisher $geoFlowAgentPublisher,
        private readonly WordPressRestPublisher $wordPressRestPublisher,
        private readonly GenericHttpApiPublisher $genericHttpApiPublisher,
    ) {}

    public function forChannel(DistributionChannel $channel): DistributionPublisherInterface
    {
        return match ($channel->channelType()) {
            'geoflow_agent' => $this->geoFlowAgentPublisher,
            'wordpress_rest' => $this->wordPressRestPublisher,
            'generic_http_api' => $this->genericHttpApiPublisher,
            default => throw new RuntimeException('不支持的分发渠道类型：'.(string) $channel->channel_type),
        };
    }
```

Change to:
```php
    public function __construct(
        private readonly GeoFlowAgentPublisher $geoFlowAgentPublisher,
        private readonly WordPressRestPublisher $wordPressRestPublisher,
        private readonly GenericHttpApiPublisher $genericHttpApiPublisher,
        private readonly BloggerPublisher $bloggerPublisher,
        private readonly FacebookPagePublisher $facebookPagePublisher,
    ) {}

    public function forChannel(DistributionChannel $channel): DistributionPublisherInterface
    {
        return match ($channel->channelType()) {
            'geoflow_agent' => $this->geoFlowAgentPublisher,
            'wordpress_rest' => $this->wordPressRestPublisher,
            'generic_http_api' => $this->genericHttpApiPublisher,
            'blogger' => $this->bloggerPublisher,
            'facebook_page' => $this->facebookPagePublisher,
            default => throw new RuntimeException('不支持的分发渠道类型：'.(string) $channel->channel_type),
        };
    }
```

- [ ] **Step 2: Run all publisher resolution tests**

```bash
php artisan test tests/Unit/DistributionPublisherManagerTest.php tests/Unit/SocialPublisherResolutionTest.php
```

Expected: both pass.

- [ ] **Step 3: Run validation**

```bash
pnpm typecheck && pnpm lint && pnpm build
```

---

### Task 6: Add Config Keys To `config/geoflow.php`

**Files:**
- Modify: `config/geoflow.php`

- [ ] **Step 1: Add social OAuth config keys**

After line 114 (`'api_key_crypto_roots'`), add:
```php
    // 社交媒体分发 OAuth 应用凭据（全局，所有同类型渠道共用）
    'facebook_app_id' => env('FACEBOOK_APP_ID', ''),
    'facebook_app_secret' => env('FACEBOOK_APP_SECRET', ''),
    'facebook_graph_version' => env('FACEBOOK_GRAPH_VERSION', 'v24.0'),
    'facebook_char_limit' => (int) env('FACEBOOK_CHAR_LIMIT', 63206),
    'google_client_id' => env('GOOGLE_CLIENT_ID', ''),
    'google_client_secret' => env('GOOGLE_CLIENT_SECRET', ''),
```

- [ ] **Step 2: Run validation**

```bash
pnpm typecheck && pnpm lint && pnpm build
```

---

### Task 7: Extend Distribution Controller

**Files:**
- Modify: `app/Http/Controllers/Admin/DistributionController.php`

- [ ] **Step 1: Add OAuthTokenRefreshService constructor injection**

Add to the constructor parameter list (after `$apiKeyCrypto`, around line 33):
```php
        private readonly OAuthTokenRefreshService $oauthTokenRefreshService,
```
And add import at top: `use App\Services\GeoFlow\OAuthTokenRefreshService;`.

- [ ] **Step 2: Extend `validateChannel()` — add `blogger` and `facebook_page` to `channel_type` rule**

Line 732, change:
```php
'channel_type' => ['nullable', 'string', 'in:geoflow_agent,wordpress_rest,generic_http_api'],
```
to:
```php
'channel_type' => ['nullable', 'string', 'in:geoflow_agent,wordpress_rest,generic_http_api,blogger,facebook_page'],
```

- [ ] **Step 3: Add new field validation rules**

After line 767 (`'generic_payload_wrapper'` line), insert:
```php
            'blogger_blog_id' => ['nullable', 'string', 'max:120'],
            'blogger_post_status' => ['nullable', 'string', 'in:live,draft'],
            'blogger_label_strategy' => ['nullable', 'string', 'in:keywords_to_labels,disabled'],
            'blogger_access_token' => ['nullable', 'string', 'max:5000'],
            'blogger_refresh_token' => ['nullable', 'string', 'max:5000'],
            'facebook_page_id' => ['nullable', 'string', 'max:120'],
            'facebook_char_limit' => ['nullable', 'integer', 'min:0', 'max:63206'],
            'facebook_access_token' => ['nullable', 'string', 'max:5000'],
            'facebook_refresh_token' => ['nullable', 'string', 'max:5000'],
```

- [ ] **Step 4: Add platform-specific validation**

Before `return $payload` at line 854, insert after the existing `generic_http_api` block (which ends around line 852):
```php
        if ($payload['channel_type'] === 'blogger') {
            if (! filled($payload['blogger_blog_id'] ?? null)) {
                throw ValidationException::withMessages([
                    'blogger_blog_id' => __('admin.distribution.validation.blogger_blog_id'),
                ]);
            }
            if ($request->isMethod('post') && ! filled($payload['blogger_access_token'] ?? null)) {
                throw ValidationException::withMessages([
                    'blogger_access_token' => __('admin.distribution.validation.blogger_access_token'),
                ]);
            }
        }
        if ($payload['channel_type'] === 'facebook_page') {
            if (! filled($payload['facebook_page_id'] ?? null)) {
                throw ValidationException::withMessages([
                    'facebook_page_id' => __('admin.distribution.validation.facebook_page_id'),
                ]);
            }
            if ($request->isMethod('post') && ! filled($payload['facebook_access_token'] ?? null)) {
                throw ValidationException::withMessages([
                    'facebook_access_token' => __('admin.distribution.validation.facebook_access_token'),
                ]);
            }
        }
```

- [ ] **Step 5: Add store() branching**

After line 116 (the `generic_http_api` store block), before the GeoFlow Agent fallback at line 118, insert:
```php
        if ($channel->isBlogger()) {
            $this->createBloggerSecret($channel, (string) $payload['blogger_access_token'], (string) ($payload['blogger_refresh_token'] ?? ''));

            return redirect()
                ->route('admin.distribution.show', ['channelId' => (int) $channel->id])
                ->with('message', __('admin.distribution.message.created'));
        }

        if ($channel->isFacebookPage()) {
            $this->createFacebookSecret($channel, (string) $payload['facebook_access_token'], (string) ($payload['facebook_refresh_token'] ?? ''));

            return redirect()
                ->route('admin.distribution.show', ['channelId' => (int) $channel->id])
                ->with('message', __('admin.distribution.message.created'));
        }
```

- [ ] **Step 6: Add update() branching and settings sync guard**

After line 206 (the `generic_http_api` update block), insert:
```php
        if ($channel->isBlogger() && filled($payload['blogger_access_token'] ?? null)) {
            DistributionChannelSecret::query()
                ->where('distribution_channel_id', (int) $channel->id)
                ->where('status', 'active')
                ->update(['status' => 'revoked']);
            $this->createBloggerSecret($channel, (string) $payload['blogger_access_token'], (string) ($payload['blogger_refresh_token'] ?? ''));
        }
        if ($channel->isFacebookPage() && filled($payload['facebook_access_token'] ?? null)) {
            DistributionChannelSecret::query()
                ->where('distribution_channel_id', (int) $channel->id)
                ->where('status', 'active')
                ->update(['status' => 'revoked']);
            $this->createFacebookSecret($channel, (string) $payload['facebook_access_token'], (string) ($payload['facebook_refresh_token'] ?? ''));
        }
```

Update the settings sync guard at line 210 — change:
```php
        if ($channel->activeSecret || ($channel->isGenericHttpApi() && $channel->resolvedGenericHttpConfig()['generic_auth_type'] === 'none')) {
```
to:
```php
        if (($channel->activeSecret && ! $channel->isBlogger() && ! $channel->isFacebookPage()) || ($channel->isGenericHttpApi() && $channel->resolvedGenericHttpConfig()['generic_auth_type'] === 'none')) {
```

- [ ] **Step 7: Extend `normalizeChannelConfig()`**

After line 929 (the generic config block), insert:
```php
        if ($channelType === 'blogger') {
            $defaults = $channel?->resolvedBloggerConfig() ?? (new DistributionChannel)->resolvedBloggerConfig();

            return [
                'blogger_blog_id' => trim((string) ($payload['blogger_blog_id'] ?? $defaults['blogger_blog_id'])),
                'blogger_post_status' => (string) ($payload['blogger_post_status'] ?? $defaults['blogger_post_status']),
                'blogger_label_strategy' => (string) ($payload['blogger_label_strategy'] ?? $defaults['blogger_label_strategy']),
            ];
        }

        if ($channelType === 'facebook_page') {
            $defaults = $channel?->resolvedFacebookConfig() ?? (new DistributionChannel)->resolvedFacebookConfig();

            return [
                'facebook_page_id' => trim((string) ($payload['facebook_page_id'] ?? $defaults['facebook_page_id'])),
                'facebook_char_limit' => (int) ($payload['facebook_char_limit'] ?? $defaults['facebook_char_limit']),
            ];
        }
```

- [ ] **Step 8: Add secret-creation methods**

After `createGenericHttpSecret()` (around line 998), insert:
```php
    private function createBloggerSecret(DistributionChannel $channel, string $accessToken, string $refreshToken): void
    {
        $ciphertext = $this->oauthTokenRefreshService->encryptInitialCredentials($accessToken, $refreshToken, now()->addHour()->toIso8601String());
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'blog_'.Str::lower(Str::random(18)),
            'secret_ciphertext' => $ciphertext,
            'status' => 'active',
            'scopes' => ['blogger.posts'],
        ]);
    }

    private function createFacebookSecret(DistributionChannel $channel, string $accessToken, string $refreshToken): void
    {
        $ciphertext = $this->oauthTokenRefreshService->encryptInitialCredentials($accessToken, $refreshToken, now()->addDays(60)->toIso8601String());
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'fb_'.Str::lower(Str::random(18)),
            'secret_ciphertext' => $ciphertext,
            'status' => 'active',
            'scopes' => ['facebook.page_manage'],
        ]);
    }
```

- [ ] **Step 9: Run validation**

```bash
pnpm typecheck && pnpm lint && pnpm build
```

---

### Task 8: Add Lang Keys (en + zh_CN)

**Files:**
- Modify: `lang/en/admin.php`
- Modify: `lang/zh_CN/admin.php`

- [ ] **Step 1: Add channel_type labels (after `generic_http_api_desc` line in en)**

In `lang/en/admin.php`, inside the `channel_type` sub-array (around line 2642-2649), add:
```php
            'blogger' => 'Blogger',
            'blogger_desc' => 'Publish full articles to a Google Blogger blog via the Blogger API v3 with OAuth2.',
            'facebook_page' => 'Facebook Page',
            'facebook_page_desc' => 'Post full article text and cover image to a Facebook Page feed via the Graph API. Shows a character-limit warning.',
```

- [ ] **Step 2: Add `blogger` settings sub-array**

After the `generic` sub-array (which ends around line 2709), add:
```php
        'blogger' => [
            'section_title' => 'Blogger Connection',
            'section_desc' => 'Enter the Blogger Blog ID and Google OAuth2 tokens. Obtain tokens from the Google OAuth Playground with the blogger scope.',
            'edit_section_desc' => 'Maintain Blogger publishing settings. Leave tokens blank to keep the saved credentials.',
            'blog_id' => 'Blogger Blog ID',
            'blog_id_help' => 'Found in the Blogger blog URL or via the API.',
            'post_status' => 'Default Post Status',
            'post_status_live' => 'Publish (LIVE)',
            'post_status_draft' => 'Draft',
            'label_strategy' => 'Label Strategy',
            'label_keywords' => 'Sync keywords as labels',
            'label_disabled' => 'Do not sync labels',
            'access_token' => 'Google OAuth Access Token',
            'refresh_token' => 'Google OAuth Refresh Token',
            'token_help' => 'Obtain via Google OAuth Playground (scope: https://www.googleapis.com/auth/blogger). GEOFlow encrypts and auto-refreshes.',
            'token_placeholder' => 'Leave blank to keep current tokens',
            'guide_title' => 'Blogger Connection Guide',
            'guide_desc' => 'Blogger channels publish full HTML articles. Images use their original GEOFlow URLs (the site must be public).',
        ],
        'facebook' => [
            'section_title' => 'Facebook Page Connection',
            'section_desc' => 'Enter the Facebook Page ID and OAuth2 access token. Obtain a long-lived Page access token from the Facebook Graph Explorer.',
            'edit_section_desc' => 'Maintain Facebook Page publishing settings. Leave the access token blank to keep the saved credential.',
            'page_id' => 'Facebook Page ID',
            'page_id_help' => 'Numeric ID of the Facebook Page you manage.',
            'char_limit' => 'Character Limit',
            'char_limit_help' => 'Facebook Page posts are limited to 63206 characters. A warning is shown if an article exceeds this.',
            'char_limit_warning' => 'Articles exceeding this limit will show a warning before posting.',
            'access_token' => 'Facebook Page Access Token',
            'refresh_token' => 'Facebook Refresh Token (optional)',
            'token_help' => 'Use a long-lived Page access token. GEOFlow encrypts and auto-refreshes via fb_exchange_token.',
            'token_placeholder' => 'Leave blank to keep current token',
            'guide_title' => 'Facebook Page Connection Guide',
            'guide_desc' => 'Facebook channels post the full article text as a Page feed post, with the cover image uploaded as a photo when available.',
        ],
```

- [ ] **Step 3: Add validation messages**

In the `validation` sub-array, add:
```php
            'blogger_blog_id' => 'The Blogger Blog ID is required.',
            'blogger_access_token' => 'A Google OAuth access token is required.',
            'facebook_page_id' => 'The Facebook Page ID is required.',
            'facebook_access_token' => 'A Facebook Page access token is required.',
```

- [ ] **Step 4: Repeat for `lang/zh_CN/admin.php`**

Mirror the same structure with Chinese translations (using the existing WordPress section as reference pattern).

- [ ] **Step 5: Run validation**

```bash
pnpm typecheck && pnpm lint && pnpm build
```

---

### Task 9: Add Blade Form Sections

**Files:**
- Modify: `resources/views/admin/distribution/create.blade.php`
- Modify: `resources/views/admin/distribution/edit.blade.php`
- Modify: `resources/views/admin/distribution/show.blade.php`

- [ ] **Step 1: Add radio cards in create form**

In `create.blade.php`, change the grid class from `lg:grid-cols-3` to `lg:grid-cols-5` (line 41). Then add two new radio cards after the generic_http_api card (after line ~60).

Follow the existing pattern from lines 42-55 (the GeoFlow Agent and WordPress cards). The new cards:
- `blogger`: label "Blogger", desc "Publish full articles to a Google Blogger blog via the Blogger API v3 with OAuth2."
- `facebook_page`: label "Facebook Page", desc "Post full article text and cover image to a Facebook Page feed via the Graph API."

- [ ] **Step 2: Add conditional Bloggers fieldsets**

After the existing Generic HTTP section (before the submit button), add:
```blade
<div data-channel-type-panel="blogger" class="@if ($channelType !== 'blogger') hidden @endif space-y-4">
    <fieldset class="rounded-lg border border-gray-200 bg-gray-50 p-4">
        <legend class="text-sm font-medium text-gray-900">{{ __('admin.distribution.blogger.section_title') }}</legend>
        <p class="mt-1 text-sm text-gray-600">{{ __('admin.distribution.blogger.section_desc') }}</p>

        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label for="blogger_blog_id" class="block text-sm font-medium text-gray-700">{{ __('admin.distribution.blogger.blog_id') }} *</label>
                <input id="blogger_blog_id" name="blogger_blog_id" type="text" value="{{ old('blogger_blog_id') }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                <p class="mt-1 text-xs text-gray-500">{{ __('admin.distribution.blogger.blog_id_help') }}</p>
            </div>
            <div>
                <label for="blogger_post_status" class="block text-sm font-medium text-gray-700">{{ __('admin.distribution.blogger.post_status') }}</label>
                <select id="blogger_post_status" name="blogger_post_status" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    <option value="live" @selected(old('blogger_post_status', 'live') === 'live')>{{ __('admin.distribution.blogger.post_status_live') }}</option>
                    <option value="draft" @selected(old('blogger_post_status') === 'draft')>{{ __('admin.distribution.blogger.post_status_draft') }}</option>
                </select>
            </div>
            <div>
                <label for="blogger_label_strategy" class="block text-sm font-medium text-gray-700">{{ __('admin.distribution.blogger.label_strategy') }}</label>
                <select id="blogger_label_strategy" name="blogger_label_strategy" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    <option value="keywords_to_labels" @selected(old('blogger_label_strategy', 'keywords_to_labels') === 'keywords_to_labels')>{{ __('admin.distribution.blogger.label_keywords') }}</option>
                    <option value="disabled" @selected(old('blogger_label_strategy') === 'disabled')>{{ __('admin.distribution.blogger.label_disabled') }}</option>
                </select>
            </div>
        </div>

        <div class="mt-4 border-t border-gray-200 pt-4">
            <h4 class="text-sm font-medium text-gray-900">{{ __('admin.distribution.blogger.access_token') }} *</h4>
            <p class="mt-1 text-sm text-gray-600">{{ __('admin.distribution.blogger.token_help') }}</p>
            <div class="mt-2 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <input id="blogger_access_token" name="blogger_access_token" type="password" value="{{ old('blogger_access_token') }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.distribution.blogger.access_token') }}">
                </div>
                <div>
                    <input id="blogger_refresh_token" name="blogger_refresh_token" type="password" value="{{ old('blogger_refresh_token') }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.distribution.blogger.refresh_token') }}">
                </div>
            </div>
        </div>
    </fieldset>
</div>
```

- [ ] **Step 3: Add conditional Facebook fieldsets**

```blade
<div data-channel-type-panel="facebook_page" class="@if ($channelType !== 'facebook_page') hidden @endif space-y-4">
    <fieldset class="rounded-lg border border-gray-200 bg-gray-50 p-4">
        <legend class="text-sm font-medium text-gray-900">{{ __('admin.distribution.facebook.section_title') }}</legend>
        <p class="mt-1 text-sm text-gray-600">{{ __('admin.distribution.facebook.section_desc') }}</p>

        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label for="facebook_page_id" class="block text-sm font-medium text-gray-700">{{ __('admin.distribution.facebook.page_id') }} *</label>
                <input id="facebook_page_id" name="facebook_page_id" type="text" value="{{ old('facebook_page_id') }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                <p class="mt-1 text-xs text-gray-500">{{ __('admin.distribution.facebook.page_id_help') }}</p>
            </div>
            <div>
                <label for="facebook_char_limit" class="block text-sm font-medium text-gray-700">{{ __('admin.distribution.facebook.char_limit') }}</label>
                <input id="facebook_char_limit" name="facebook_char_limit" type="number" min="0" max="63206" value="{{ old('facebook_char_limit', 63206) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                <p class="mt-1 text-xs text-gray-500">{{ __('admin.distribution.facebook.char_limit_help') }}</p>
            </div>
        </div>

        <div class="mt-2 rounded-md bg-yellow-50 p-3 text-sm text-yellow-800">
            {{ __('admin.distribution.facebook.char_limit_warning') }}
        </div>

        <div class="mt-4 border-t border-gray-200 pt-4">
            <h4 class="text-sm font-medium text-gray-900">{{ __('admin.distribution.facebook.access_token') }} *</h4>
            <p class="mt-1 text-sm text-gray-600">{{ __('admin.distribution.facebook.token_help') }}</p>
            <div class="mt-2 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <input id="facebook_access_token" name="facebook_access_token" type="password" value="{{ old('facebook_access_token') }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.distribution.facebook.access_token') }}">
                </div>
                <div>
                    <input id="facebook_refresh_token" name="facebook_refresh_token" type="password" value="{{ old('facebook_refresh_token') }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.distribution.facebook.refresh_token') }}">
                </div>
            </div>
        </div>
    </fieldset>
</div>
```

- [ ] **Step 4: Add edit form sections in `edit.blade.php`**

Follow the existing WordPress edit pattern (channel_type locked, token fields use `placeholder="Leave blank to keep current"`). Add Blogger and Facebook sections that are visible when `$channel->isBlogger()` or `$channel->isFacebookPage()`. For the access_token fields, use placeholder `__('admin.distribution.blogger.token_placeholder')` or `__('admin.distribution.facebook.token_placeholder')`.

- [ ] **Step 5: Add guide sections in `show.blade.php`**

Add two new guide sections, visible when `$channel->isBlogger()` or `$channel->isFacebookPage()`, following the WordPress guide pattern (lines ~2676-2682 in lang).

- [ ] **Step 6: Run validation**

```bash
pnpm typecheck && pnpm lint && pnpm build
```

---

### Task 10: Add Feature Tests

**Files:**
- Create: `tests/Feature/AdminDistributionSocialChannelTest.php`

- [ ] **Step 1: Create feature test**

Create `tests/Feature/AdminDistributionSocialChannelTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Models\DistributionChannel;
use App\Models\DistributionChannelSecret;
use App\Models\Admin;
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
        $this->admin = Admin::factory()->create();
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

        $response->assertRedirect(route('admin.distribution.show', ['channelId' => 2]));

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
        Http::fake([
            'blogger.googleapis.com/v3/users/self' => Http::response([
                'id' => '42',
                'displayName' => 'Test User',
            ]),
        ]);

        $channel = DistributionChannel::factory()->create([
            'channel_type' => 'blogger',
            'channel_config' => ['blogger_blog_id' => '123456789'],
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'blog_test',
            'secret_ciphertext' => app(\App\Support\GeoFlow\ApiKeyCrypto::class)->encrypt(
                json_encode(['access_token' => 'test', 'refresh_token' => '', 'expires_at' => null, 'token_type' => 'Bearer'])
            ),
            'status' => 'active',
            'scopes' => ['blogger.posts'],
        ]);

        $response = $this->actingAs($this->admin, 'admin')->post(route('admin.distribution.health', ['channelId' => (int) $channel->id]));
        $response->assertRedirect();

        $channel->refresh();
        $this->assertSame('ok', $channel->last_health_status);
    }

    public function test_facebook_health_check(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'id' => '987654321',
                'name' => 'My Page',
            ]),
        ]);

        $channel = DistributionChannel::factory()->create([
            'channel_type' => 'facebook_page',
            'channel_config' => ['facebook_page_id' => '987654321'],
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'fb_test',
            'secret_ciphertext' => app(\App\Support\GeoFlow\ApiKeyCrypto::class)->encrypt(
                json_encode(['access_token' => 'fb_test', 'refresh_token' => '', 'expires_at' => null, 'token_type' => 'Bearer'])
            ),
            'status' => 'active',
            'scopes' => ['facebook.page_manage'],
        ]);

        $response = $this->actingAs($this->admin, 'admin')->post(route('admin.distribution.health', ['channelId' => (int) $channel->id]));
        $response->assertRedirect();

        $channel->refresh();
        $this->assertSame('ok', $channel->last_health_status);
    }
}
```

- [ ] **Step 2: Run feature tests**

```bash
php artisan test tests/Feature/AdminDistributionSocialChannelTest.php
```

- [ ] **Step 3: Run full distribution test suite**

```bash
php artisan test --filter=Distribution
```

Expected: all pass (existing + new).

---

### Task 11: Final Regression And Validation

- [ ] **Step 1: Run all distribution tests (existing + new)**

```bash
php artisan test --filter=Distribution
```

Expected:
```
PASS  Tests\Unit\DistributionPublisherManagerTest
PASS  Tests\Unit\DistributionRetryPolicyTest
PASS  Tests\Unit\DistributionSchemaMigrationTest
PASS  Tests\Unit\DistributionQueueConfigurationTest
PASS  Tests\Unit\OAuthTokenRefreshServiceTest
PASS  Tests\Unit\SocialPublisherResolutionTest
PASS  Tests\Feature\AdminDistributionPageTest
PASS  Tests\Feature\AdminDistributionSocialChannelTest
```

- [ ] **Step 2: Run full test suite**

```bash
php artisan test
```

Expected: zero failures.

- [ ] **Step 3: Run code style**

```bash
pnpm typecheck && pnpm lint && pnpm build
```

Expected: clean.

---

## Rollout Notes

- Existing `geoflow_agent`, `wordpress_rest`, and `generic_http_api` channels must continue to publish exactly as before — no regression.
- Blogger channels use OAuth2 access tokens that expire hourly; the auto-refresh service handles this. Admin must configure `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` in `.env`.
- Facebook channels use long-lived Page access tokens (~60 days). The auto-refresh service extends them via `fb_exchange_token`. Admin must configure `FACEBOOK_APP_ID` and `FACEBOOK_APP_SECRET` in `.env`.
- On first real test, use Blogger `draft` post status and Facebook test page to avoid accidental public content.
- If Facebook returns 401/403, ensure the Page access token has the `pages_manage_posts` permission and the admin has sufficient Page role.
- Blogger has no media-upload endpoint; images in article HTML must use publicly accessible URLs (GEOFlow site URLs). Local image paths will not render.

## Self-Review

- **Spec coverage:** Channel type registration, publisher implementation, OAuth refresh, controller validation/branching, UI forms, lang keys, config, tests — all covered.
- **Placeholder scan:** No step relies on an unspecified file or unknown route. All file paths and line numbers verified against current code.
- **Type consistency:** `channel_type=blogger/facebook_page`, `channel_config` keys `blogger_*`/`facebook_*`, `resolvedBloggerConfig()`/`resolvedFacebookConfig()`, key_id prefixes `blog_`/`fb_`, scopes `['blogger.posts']`/`['facebook.page_manage']`.
- **Non-interference:** Existing distribution tests must pass unchanged. Publishers, manager match arms, controller branching are additive only. Existing channel types follow existing code paths untouched.
