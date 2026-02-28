# Social Content Aggregator

A production-ready WordPress plugin that aggregates social content from:

- **Meta Graph API** (Instagram Business + Facebook Page)
- **Pinterest API v5**
- **Optional RSS/Atom feeds**
- **Optional scraping fallback** (used when API credentials are not configured)

It stores imported content in WordPress, applies caption/hashtag processing, and publishes according to configurable workflow rules (draft, immediate publish, or scheduled).

---

## Features

## 1) Admin Settings (Settings → Social Aggregator)

Configure all ingestion and publishing behavior from one settings page:

- Facebook Page ID
- Instagram Business Account ID
- Pinterest Board ID
- Meta Access Token (preserved if left blank on update)
- Pinterest Access Token (preserved if left blank on update)
- Cache TTL (seconds)
- Sync limit per platform
- Minimum engagement threshold
- Publishing mode:
  - Save as Draft
  - Publish Immediately
  - Schedule Automatically
- Schedule time (HH:MM)
- Schedule frequency (Once / Daily / Weekly)
- Target post type (loaded dynamically from all public post types)
- RSS/Atom feed ingestion toggle + fallback feed URLs
- Scraping toggle + scrape URLs
- Hashtag blacklist
- Manual **Sync Now** button with nonce and capability checks

---

## 2) Source Priority Logic (API first when configured)

The plugin auto-selects ingestion source per platform:

- If platform API credentials are present → uses official API.
- If credentials are missing → uses scraping fallback for that platform (if enabled and URLs are provided).

This means first-time setups can start with scraping URLs, then transparently switch to API-backed ingestion once credentials are configured.

---

## 3) API Integrations

### Instagram (Meta Graph)
Fetches media fields including:

- caption
- media_url
- media_type
- permalink
- timestamp
- like_count
- comments_count

### Facebook (Meta Graph)
Fetches page post data including:

- message (caption)
- permalink_url
- created_time
- full_picture
- likes.summary(true)
- comments.summary(true)

### Pinterest API v5
Fetches board pin data including:

- title + description
- media image URL
- link
- created_at
- metrics (save/comment counts where available)

All API requests use `wp_remote_get()`.

---

## 4) Feed + Scrape Fallbacks

### RSS/Atom feed ingestion
- Uses WordPress feed parser (`fetch_feed`)
- Maps feed items to normalized post payloads
- Supports feed URL list in settings

### Scraping fallback
- Optional fallback parser for configured URLs
- Extracts basic title/og:image data from HTML
- Uses transients for caching
- Intended for scenarios where credentials are not yet configured

---

## 5) Processing Pipeline

Imported items are normalized and passed through:

1. Deduplication (permalink/external ID)
2. Engagement filtering (`like_count + comments_count` >= threshold)
3. Caption cleaning
4. Hashtag extraction + blacklist filtering
5. Trending hashtag update and optional top-hashtag append
6. Link removal enforcement
7. Scheduled/publish/draft post argument generation
8. CPT/post persistence and media handling

---

## 6) Caption Cleaning + Link Removal

Before save/publish, content processor removes:

- URLs
- `<a>` anchor tags
- emails
- @mentions
- UTM/tracking fragments
- extra whitespace

Output is sanitized/plain readable content.

---

## 7) Hashtag Engine + Trending Table

The plugin includes a hashtag engine that:

- Extracts hashtags with regex
- Normalizes to lowercase
- Removes duplicates
- Filters blacklist terms and common noise tags
- Stores usage/engagement stats in custom table:
  - `{$wpdb->prefix}social_hashtags`

Trending tags are ranked by:

- `avg_engagement DESC`
- `usage_count DESC`

---

## 8) Publishing Modes + Scheduler

Publishing behavior is controlled via settings:

- **Draft** → creates draft posts
- **Publish** → publishes immediately
- **Schedule** → creates `future` posts with computed `post_date`

Scheduler supports:

- once
- daily
- weekly

Timezone compatibility uses `current_time('timestamp')`.

---

## 9) Custom Post Type + Metadata

Registers `social_posts` CPT and stores:

- external ID
- original URL
- platform
- engagement score
- likes/comments
- source (`api` / `feed` / `scrape`)
- timestamp
- hashtags

### Media handling
- Uses `media_sideload_image()`
- File type validation
- Prevent duplicate media downloads by source URL meta
- Sets featured image automatically

---

## 10) Shortcode

Use:

```text
[social_posts platform="instagram" sort="engagement" limit="6" hashtag="marketing" source="api"]
```

Supported attributes:

- `platform` (instagram|facebook|pinterest|external)
- `sort` (engagement|recent)
- `limit`
- `hashtag`
- `source` (api|feed|scrape)

Output:

- responsive card grid
- featured image (if available)
- caption excerpt
- engagement count
- original post link

---

## 11) Security and Reliability

- Settings sanitization for all fields
- Tokens preserved safely when left blank
- Admin capability checks (`manage_options`)
- Nonce verification for manual sync
- Escaped front-end/admin output
- Transients for API/feed/scrape caching
- Basic sync rate limiting via transient counter
- Logging support using `error_log()`

---

## 12) Cron Events

- `sca_refresh_posts_event` (every 2 hours)
- `sca_scheduled_publish_event` (daily/weekly when schedule mode requires)

On activation:

- Registers CPT
- Creates hashtag table via `dbDelta`
- Schedules periodic sync event

On deactivation:

- Unschedules plugin cron events
- Flushes rewrite rules

---

## File Structure

```text
social-content-aggregator.php
includes/
  class-sca-plugin.php
  class-sca-admin.php
  class-sca-api-service.php
  class-sca-scheduler.php
  class-sca-content-processor.php
  class-sca-hashtag-engine.php
  class-sca-cpt.php
  class-sca-shortcode.php
```

---

## Installation

1. Copy plugin directory to `wp-content/plugins/social-content-aggregator`.
2. Activate **Social Content Aggregator** from WordPress Plugins page.
3. Open **Settings → Social Aggregator**.
4. Add API credentials and/or fallback sources.
5. Run **Sync Now** to import immediately.

---

## Notes

- For production use, prefer official APIs over scraping.
- Ensure all external integrations comply with provider terms and legal requirements.
- Keep access tokens secure and rotate periodically.
