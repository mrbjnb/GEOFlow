# Active Context

## Current Focus
- ✅ Social Media Distribution implemented (Facebook Page + Blogger)
- ✅ Review fixes applied — 2 showstopper bugs fixed, all tests pass
- ✅ Memory bank updated with task documentation

## Recent Changes

### June 29, 2026 — Social Media Distribution (TASK003)
- ✅ Added `blogger` and `facebook_page` as new `channel_type` values in Distribution framework
- ✅ Created `OAuthTokenRefreshService` — auto-refresh expiring Google/Facebook OAuth2 tokens
- ✅ Created `BloggerPublisher` — full article publishing via Blogger API v3
- ✅ Created `FacebookPagePublisher` — text/photo posting via Facebook Graph API v24
- ✅ Extended model, manager, controller, views, lang, config
- ✅ **8 new files, 9 modified files**
- ✅ 84 distribution tests pass (613 assertions) — zero regression
- 📁 Key files: `OAuthTokenRefreshService.php`, `BloggerPublisher.php`, `FacebookPagePublisher.php`

### June 29, 2026 — Review Fixes (TASK004)
- ✅ **Fixed Facebook image key mismatch** — `$image['data']` → `$image['content_base64']`, `$image['mime']` → `$image['mime_type']` (3 locations)
- ✅ **Fixed Blogger draft mode** — added `?isDraft=true` query parameter
- ✅ **Added Facebook URL photo fallback** — `publishWithPhotoUrl()` for images without base64
- ✅ **Removed dead parameter** — `$channel` from `BloggerPublisher::postPayload()`
- ✅ **Added `supportsSiteSettings()` model method** — cleaner Blade conditionals
- ✅ **Wrote 11 new tests** — `BloggerPublisherTest` (5) + `FacebookPagePublisherTest` (6)
- ✅ **Added edit/show Blade sections** — Blogger + Facebook forms and guides
- ✅ **Fixed test schema drift** — both test files converted to `RefreshDatabase`
- ✅ **365 tests pass** (0 regression, 8 pre-existing AdminSystemUpdatesPageTest failures)

## Next Steps
- **TASK006 completed — upstream merge, tests pass, production build verified**
- N/A — Upstream merge complete. Social media features preserved. All 85 distribution tests pass. Production builds successfully.

## Current Decisions & Considerations
- **OAuth tokens**: Stored as encrypted JSON envelope in existing `secret_ciphertext` column — no migration needed
- **Facebook photo strategy**: Three-tier fallback `base64` → `url` (source_url) → text-only
- **Blade conditionals**: `supportsSiteSettings()` method centralized in model — new channel types update one method
- **API verification**: All endpoints verified against Facebook Graph API v24.0 and Blogger API v3 discovery doc (revision 20260521)
