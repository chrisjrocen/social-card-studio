# Social Card Studio — Technical & Product Specification

**Working name:** Social Card Studio
**Slug / text domain:** `social-card-studio`
**PHP namespace:** `ChrxDigital\SocialCardStudio`
**Prefix:** `scstudio_` (functions, options, meta, hooks), `SCSTUDIO_` (constants)
— *this was `scs_` in spec 1.0. Three characters is rejected both by WPCS 3.x and by the WordPress.org review team as too short to be unique, so do not shorten it back.*
**Author:** Chris Rwakabubu — Chrx Digital Solutions
**Spec version:** 1.2 — 2026-09-05 (adds §13.9, the workspace spend budget; removes the per-site call cap; lengthens the prefix to `scstudio_`)
**Status:** Approved for build, Phase 0–1

> WordPress plugin that generates a custom, branded share image (OG image) for posts, custom post types, products and the homepage — with a non-AI template renderer as the free core and AI generation as the paid tier.

---

## 0. Decisions I was asked to make

Four questions were delegated during the interview. Answers are recorded here up front so they can be challenged before code exists.

| # | Question | Decision | Why |
|---|---|---|---|
| D1 | Render engine | **Server-side PHP compositor (Imagick preferred, GD fallback) driven by a JSON Card Document, with a browser DOM preview for WYSIWYG** | Three of the four chosen generation triggers (auto on publish, lazy on OG request, bulk backfill) run with no browser present. A browser-canvas-authoritative design cannot serve them. A screenshot service adds cost, an external dependency and .org review friction to a free tier. See §4. |
| D2 | Storage model | **Dedicated `uploads/social-card-studio/` folder as canonical store, non-attachment by default; opt-in "register in media library" for offload/CDN sites** | Keeps the media library clean, avoids generating WP's full thumbnail size set for every card, makes cleanup deterministic. The opt-in covers the one real downside — offload plugins only see attachments. See §5.3. |
| D3 | Fonts | **Theme-first resolution chain with mandatory fallbacks**: theme.json/Blocksy → locally cached webfont → bundled OFL families → user upload → script-coverage fallback | "Inherit theme fonts" alone breaks whenever the theme's font is remote-hosted or is WOFF2 (GD and Imagick need TTF/OTF). The chain preserves the intent and never renders tofu. See §6. |
| D4 | Release phasing | **v1.0 = non-AI core only, shipped to WordPress.org. v1.1 = AI copy mode. Pro 1.0 = AI images + builder + credits.** | The renderer is the hard, defensible part and every site needs it. AI has nothing to overlay onto until it exists, and .org listing is the distribution engine that makes Pro sellable. See §20. |

**Additional call I made that was not asked:** the social image is **always JPEG** (or PNG only where a template genuinely needs transparency, which none currently do). WebP and AVIF are excluded from social output regardless of the site's media settings, because several scrapers and messaging clients still fail on them. Flagged for your approval in §7.2.

---

## 1. Product summary

### 1.1 The problem
When a post is shared to Facebook, X, LinkedIn, WhatsApp or Slack, the preview image comes from `og:image`. Most WordPress sites fall back to the featured image (wrong aspect ratio, text illegible at thumbnail size, no branding) or to a generic site logo (identical for every post). The result is low click-through and zero brand recall.

### 1.2 The solution
A card generator that composes a purpose-built 1200×630 image per post — headline, brand, author, category, background — automatically, with an escape hatch for AI-generated art and AI-written share copy.

### 1.3 Tiers

| Capability | Free (.org) | Pro (Freemius) |
|---|---|---|
| Template renderer (Imagick/GD) | ✅ | ✅ |
| Curated presets | 6 layouts | 6 + premium packs |
| Template customisation | Settings panel (colours, logo, fonts, position, overlay) | Visual layer-based builder |
| Post types | Posts + public CPTs + homepage | + WooCommerce products |
| Output sizes | 1200×630 OG | + 1080×1080 square, 1000×1500 Pinterest |
| Triggers | On demand, on publish, lazy, bulk backfill | Same |
| SEO plugin integration | ✅ | ✅ |
| AI copy mode (headline/subhead) | ✅ with your own API key | ✅ + credits |
| AI background mode | — | ✅ |
| AI full-image mode | — | ✅ |
| Credits via hosted proxy | — | ✅ |
| Usage dashboard & cost log | Basic (copy mode only) | Full |
| Multisite network library | — | ✅ (Phase 4) |
| WP-CLI | ✅ | ✅ |

### 1.4 Scope of "what gets a card"
Posts, every public custom post type (each toggled individually in settings), the homepage/front page, and — in Pro — WooCommerce products. Pages are available as an opt-in post type but are off by default. **Taxonomy and CPT archive pages are out of scope for 1.x**; they were not selected in the interview and they need a different token set (term name, description, post count) that would expand the template schema. Noted here so the omission is deliberate rather than forgotten.

### 1.5 Non-goals for 1.x
Scheduling or publishing to social networks. Analytics on share performance. Video or animated cards. A/B testing of cards. Editing the underlying featured image.

---

## 2. Core concepts

Three nouns carry the whole architecture. Everything else is plumbing.

### 2.1 Card Document
A JSON structure describing *how to draw a card*, independent of any renderer. Stored per template, versioned, validated against a strict schema.

```jsonc
{
  "schema": 1,
  "id": "editorial-left",
  "version": 3,
  "canvas": { "w": 1200, "h": 630, "bg": "#0F172A" },
  "safe_area": { "top": 48, "right": 56, "bottom": 48, "left": 56 },
  "layers": [
    {
      "type": "image",
      "id": "bg",
      "source": "{{featured_image}}",
      "fallback": "{{site_default_bg}}",
      "fit": "cover",
      "box": { "x": 0, "y": 0, "w": 1200, "h": 630 },
      "effects": { "blur": 0, "grayscale": false, "tint": null }
    },
    {
      "type": "gradient",
      "id": "scrim",
      "box": { "x": 0, "y": 0, "w": 1200, "h": 630 },
      "stops": [ { "at": 0.0, "color": "#0F172AE6" }, { "at": 1.0, "color": "#0F172A33" } ],
      "angle": 90,
      "auto_contrast": { "target": 4.5, "against": "headline" }
    },
    {
      "type": "text",
      "id": "headline",
      "content": "{{share_headline|title}}",
      "box": { "x": 56, "y": 300, "w": 760, "h": 220 },
      "font": { "role": "heading", "weight": 700 },
      "size": { "min": 34, "max": 64, "autofit": true },
      "line_height": 1.12,
      "tracking": -0.01,
      "align": "left",
      "vertical_align": "bottom",
      "max_lines": 3,
      "overflow": "ellipsis",
      "color": "#FFFFFF",
      "shadow": { "x": 0, "y": 2, "blur": 8, "color": "#00000059" }
    },
    { "type": "text", "id": "eyebrow", "content": "{{primary_term}}", "…": "…" },
    { "type": "text", "id": "meta", "content": "{{author_name}} · {{reading_time}}", "…": "…" },
    { "type": "image", "id": "avatar", "source": "{{author_avatar}}", "shape": "circle", "…": "…" },
    { "type": "image", "id": "logo", "source": "{{site_logo}}", "…": "…" },
    { "type": "shape", "id": "accent-bar", "shape": "rect", "fill": "{{brand_accent}}", "…": "…" }
  ]
}
```

Layer types in 1.0: `image`, `text`, `gradient`, `shape` (rect / rounded-rect / circle / line). No arbitrary HTML, no scripting, no remote URLs outside the allowlist.

### 2.2 Card Record
The result of rendering a Card Document for one post. Stored in post meta.

```php
// meta key: _scstudio_card
[
  'schema'        => 1,
  'input_hash'    => 'a1b2c3d4e5f6a7b8',   // see §9.1
  'template_id'   => 'editorial-left',
  'template_ver'  => 3,
  'source'        => 'template',            // template | ai_copy | ai_background | ai_full
  'engine'        => 'imagick-7.1.1',
  'file'          => '2026/09/card-1482-a1b2c3d4.jpg',
  'url'           => 'https://…/uploads/social-card-studio/2026/09/card-1482-a1b2c3d4.jpg',
  'w' => 1200, 'h' => 630, 'bytes' => 214_338, 'mime' => 'image/jpeg',
  'alt'           => 'Why your WordPress backups are lying to you — WP Fundi',
  'attachment_id' => null,                  // set only when media-library mode is on
  'generated_at'  => 1757030400,
  'generated_by'  => 3,
  'variants'      => [ /* Pro: square, pinterest */ ],
]
```

Companion keys: `_scstudio_stale` (bool), `_scstudio_disabled` (bool, per-post opt-out), `_scstudio_overrides` (headline/subhead/template/AI prompt written by the author), `_scstudio_ai_meta` (provider, model, prompt, credits — Pro).

### 2.3 Renderer
An interface with three implementations, all consuming the same Card Document.

```php
interface Renderer {
    public function supports(): bool;                 // env capability check
    public function priority(): int;
    public function render( CardDocument $doc, Context $ctx ): RenderResult;
}
```

| Renderer | Where it runs | Role |
|---|---|---|
| `ImagickRenderer` | Server | **Authoritative.** Preferred when Imagick ≥ 6.9 with FreeType. |
| `GdRenderer` | Server | **Authoritative fallback.** Requires GD with FreeType (`imagettftext`). |
| `DomPreviewRenderer` | Browser (editor only) | Instant WYSIWYG while typing. Never produces the shipped file. |
| `HtmlRenderer` | External headless service | Phase 4, Pro only. Deferred. |

**Parity rule.** The DOM preview is explicitly a preview. On "Generate", the editor discards it and displays the real server render. Any layout logic (wrapping, autofit) is specified once in §6.3 and implemented twice, with a shared fixture suite (§19.2) that fails the build if the two diverge beyond tolerance.

---

## 3. Architecture

```
social-card-studio/
├── social-card-studio.php          # bootstrap, requirements gate, autoload
├── uninstall.php
├── composer.json                    # PSR-4, no runtime deps beyond Action Scheduler
├── src/
│   ├── Plugin.php                   # container + hook registration
│   ├── Support/                     # Env, Log, Hash, Color, Str, Schema
│   ├── Card/
│   │   ├── CardDocument.php         # value object + schema validator
│   │   ├── TokenResolver.php        # {{title}} → real values (§8)
│   │   ├── CardRecord.php
│   │   ├── CardRepository.php       # meta read/write, staleness
│   │   └── InputHasher.php
│   ├── Render/
│   │   ├── RendererFactory.php
│   │   ├── ImagickRenderer.php
│   │   ├── GdRenderer.php
│   │   ├── TextLayout.php           # SHARED wrap/autofit engine (§6.3)
│   │   ├── FontResolver.php         # §6.1
│   │   ├── ImageSource.php          # featured image / upload / AI result
│   │   └── Optimizer.php            # quality ladder, size budget (§7.2)
│   ├── Storage/
│   │   ├── CardStore.php            # write/delete/URL, grace retention
│   │   └── MediaLibraryBridge.php   # opt-in attachment mode
│   ├── Templates/
│   │   ├── TemplateRegistry.php
│   │   └── presets/*.json
│   ├── Delivery/
│   │   ├── MetaTags.php             # own og/twitter output
│   │   ├── Fallback.php             # §12
│   │   ├── Endpoint.php             # lazy render route (§10.3)
│   │   └── Integrations/{Yoast,RankMath,SEOPress,SlimSEO,AIOSEO,SEOFramework}.php
│   ├── Generation/
│   │   ├── Scheduler.php            # Action Scheduler wrapper
│   │   ├── OnPublish.php
│   │   ├── Backfill.php
│   │   └── Lock.php                 # stampede protection
│   ├── Admin/
│   │   ├── SettingsPage.php  DiagnosticsPage.php  BackfillPage.php
│   │   ├── PostListColumn.php  Notices.php  UsageDashboard.php
│   ├── Rest/
│   │   ├── PreviewController.php  CommitController.php
│   │   ├── AiController.php  BackfillController.php
│   ├── Ai/                          # free: copy only. Pro add-on extends.
│   │   ├── AiManager.php  Consent.php  Sanitizer.php  RateLimiter.php
│   │   ├── PromptBuilder.php  EventLog.php
│   │   ├── Budget/                  # §13.9 workspace spend budget
│   │   │   ├── BudgetLedger.php     # locking, buckets, reserve/settle/release
│   │   │   ├── Reservation.php  PriceBook.php  Grants.php
│   │   └── Providers/{OpenAI,Gemini,Anthropic,Replicate,ScsProxy}.php
│   ├── Cli/Commands.php
│   └── Compat/{Multisite,Offload,Sitemaps,Privacy}.php
├── assets/
│   ├── fonts/                       # bundled OFL families
│   └── img/                         # preset backgrounds, placeholder
├── build/                           # compiled editor JS/CSS
├── editor/                          # @wordpress/scripts source
└── languages/
```

**Runtime dependencies:** Action Scheduler (bundled via Composer, the same library WooCommerce ships — safe to bundle, initialises only once per site). Nothing else. No framework, no Composer packages that duplicate WP core APIs.

**Minimums:** WordPress 6.4+, PHP 8.1+, GD with FreeType **or** Imagick with FreeType. The bootstrap refuses to load with an admin notice if neither image library is usable, rather than fataling.

---

## 4. Render engine — the decision in full (D1)

### 4.1 What was rejected and why

**Browser canvas as the authoritative renderer.** Attractive: pixel-perfect WYSIWYG, no server image libraries, no font pain. Fatal: you chose auto-generation on publish, lazy generation on first OG request, and bulk backfill. None of those has a browser. A canvas-only plugin can only ever generate cards for posts a human opens in the editor — which is the minority of the archive, and none of the machine-triggered paths.

**HTML/CSS → screenshot service.** Best typography by a wide margin, and the honest answer for "why do the good OG image services look better". Rejected for the free core because it means every free user's post content leaves their server, an external dependency the plugin cannot guarantee, per-image cost you absorb, and a .org review conversation you don't need. Kept as a Phase 4 Pro renderer, where a premium template pack can justify it.

### 4.2 What we build

Server-side compositing, with the Card Document as the contract:

1. `RendererFactory` picks `ImagickRenderer` if Imagick with FreeType is present, else `GdRenderer`, else fails to the fallback chain (§12).
2. Both renderers consume the identical Card Document and the identical `TextLayout` results. Only the drawing primitives differ.
3. The editor renders the same Card Document as absolutely-positioned DOM at 0.5 scale for instant feedback, then swaps in the server PNG/JPEG on Generate.

### 4.3 Imagick vs GD — what differs

| Concern | Imagick | GD |
|---|---|---|
| Text quality | Better hinting, kerning via `queryFontMetrics` | Acceptable, `imagettfbbox` metrics |
| Gradients | Native `ImagickPixel` gradients | Drawn line-by-line into a temp layer, cached |
| Rounded corners / circle mask | Native clip path | Alpha mask composite |
| Drop shadow | Native `shadowImage` | Offset blurred copy (3-pass box blur) |
| Colour emoji | Possible with Noto Color Emoji (Pro, Phase 3) | Not possible — emoji stripped |
| Peak memory (1200×630 + 2000px bg) | ~70 MB | ~90 MB |
| Availability on cheap shared hosting | ~60% | ~99% |

GD is therefore the assumed baseline for template design. No preset may depend on an Imagick-only effect; Imagick-only effects are progressive enhancement, and the diagnostics screen states plainly which engine the site is using and what it costs them.

### 4.4 Performance budget
Hard targets, enforced by the test suite:

- Template render: **≤ 800 ms**, **≤ 96 MB peak**, on a 1200×630 card with a 2000 px source background, PHP 8.1, no opcode cache assumptions.
- Before compositing, source backgrounds are downscaled to at most 2× the destination box. A 6000 px camera JPEG is never loaded at full size.
- If `memory_get_usage(true)` headroom against `WP_MEMORY_LIMIT` drops below 64 MB, the renderer degrades: skip blur, skip shadow, downscale background further, and log a `degraded_render` event.
- On-publish generation is **never** inline. It enqueues an Action Scheduler job. A post save must not get slower because this plugin is active. This is a release blocker, not a nice-to-have.

---

## 5. Data model & storage

### 5.1 Options
Single option `scstudio_settings` (autoloaded, kept under 32 KB), plus `scstudio_ai_settings` (not autoloaded, contains encrypted keys), plus `scstudio_db_version`.

```php
'scstudio_settings' => [
  'enabled_post_types' => ['post','product', /* + any public CPT, toggled per type */],
  'default_template'   => 'editorial-left',
  'per_type_template'  => [ 'product' => 'product-price' ],
  'priority'           => 'gap_fill',            // gap_fill | override_auto | always   §10.2
  'regeneration_mode'  => 'auto_template_manual_ai', // | auto_all | manual_all         §9.2
  'triggers'           => [ 'on_publish' => true, 'lazy' => true ],
  'brand'              => [ 'accent' => '#2563EB', 'logo_id' => 44, 'logo_position' => 'top-left' ],
  'typography'         => [ 'source' => 'theme', 'heading' => null, 'body' => null ],
  'homepage_card'      => [ 'enabled' => true, 'headline' => '', 'template' => 'brand-statement' ],
  'output'             => [ 'format' => 'jpeg', 'quality' => 82, 'target_bytes' => 600000, 'max_bytes' => 1048576 ],
  'media_library_mode' => false,                  // §5.3
  'sizes'              => ['og'],                 // Pro adds 'square','pinterest'
  'alt_text_pattern'   => '{{headline}} — {{site_name}}',
  'emoji'              => 'strip',                // strip | keep_if_supported
  'delete_data_on_uninstall' => false,
]
```

`scstudio_ai_settings` is never autoloaded — it holds the encrypted keys (§13.3) and the
spend budget (§13.9):

```php
'scstudio_ai_settings' => [
  // … consent, provider, keys, model, data scope, block list, retention …
  'budget' => [
    'enabled'         => true,
    'amount_usd'      => 10.00,
    'window_days'     => 30,          // filterable; not exposed in the 1.1 UI
    'thresholds'      => [ 50, 80, 100 ],
    'notify'          => true,
    'price_overrides' => [],          // model_id => usd, admin corrections
  ],
]
```

The live ledger (`scstudio_ai_budget`, §13.9.1) is a separate option from these settings,
so that resetting or re-saving settings never silently clears accrued spend.

### 5.2 Post meta
`_scstudio_card`, `_scstudio_stale`, `_scstudio_disabled`, `_scstudio_overrides`, `_scstudio_ai_meta`. All registered with `register_post_meta()` — `show_in_rest` true for `_scstudio_overrides` and `_scstudio_disabled` (the editor writes them), false and `auth_callback` gated for the rest (server writes them).

### 5.3 File storage (D2)

**Canonical path**

```
wp-content/uploads/social-card-studio/
├── index.php              # blank, directory-listing guard
├── .htaccess              # deny execution of anything but images
├── fonts/                 # resolved + uploaded font files
├── cache/                 # transient render artefacts, pruned daily
└── 2026/09/card-{post_id}-{hash8}.jpg
```

On multisite: `uploads/sites/{blog_id}/social-card-studio/…` — derived from `wp_upload_dir()`, never hardcoded.

**Why not the media library by default**
- Every attachment triggers the site's full registered image-size set. A site with 12 sizes would generate 13 files per card for zero benefit.
- Cards would appear in every gallery block, featured-image picker and media modal, which is a support ticket generator.
- A user deleting a card attachment "to clean up" silently breaks live shares.
- Deterministic cleanup: delete post → delete `social-card-studio/**/card-{id}-*`.

**Why the opt-in exists**
WP Offload Media, Cloudflare R2 plugins and most CDN offloaders hook `wp_generate_attachment_metadata` and only ever see attachments. On a site that offloads uploads, a non-attachment card stays on origin — usually fine, occasionally not (origin behind a strict firewall, or uploads dir not web-served). So:

`media_library_mode = true` makes each card an attachment with `_scstudio_card_marker` meta, and the plugin then:
- filters `intermediate_image_sizes_advanced` to return `[]` for these attachments (no size set),
- hides them from the library grid via `ajax_query_attachments_args` and `pre_get_posts` on `attachment`,
- excludes them from core and SEO-plugin image sitemaps,
- still tracks them in `_scstudio_card` so the fallback chain is unchanged.

**Extension points for offload without attachments:** `scstudio_card_dir`, `scstudio_card_url`, and `do_action( 'scstudio_card_written', $path, $post_id, $record )` — enough for a site-specific S3 push.

**Grace retention.** When a card is regenerated, the previous file is **not** deleted immediately. Platform caches and already-published posts may still point at it. Old files are moved to a `retire` index and deleted by a daily task after **30 days** (filterable, `scstudio_grace_days`).

### 5.4 Custom table
One table, `{prefix}scstudio_events`, for AI usage, render failures and backfill outcomes.

```sql
CREATE TABLE {prefix}scstudio_events (
  id            BIGINT UNSIGNED AUTO_INCREMENT,
  blog_id       BIGINT UNSIGNED NOT NULL DEFAULT 1,
  type          VARCHAR(32)  NOT NULL,      -- ai_call | render_error | render_ok | backfill | degraded_render
  post_id       BIGINT UNSIGNED NULL,
  user_id       BIGINT UNSIGNED NULL,
  provider      VARCHAR(32)  NULL,
  model         VARCHAR(64)  NULL,
  mode          VARCHAR(32)  NULL,          -- copy | background | full | template
  status        VARCHAR(16)  NOT NULL,      -- ok | error | blocked | rate_limited | capped
  credits       DECIMAL(10,4) NULL,
  cost_usd      DECIMAL(10,5) NULL,
  duration_ms   INT UNSIGNED NULL,
  message       TEXT NULL,                  -- error text or moderation reason, never raw post content
  prompt_hash   CHAR(40) NULL,
  created_at    DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY blog_created (blog_id, created_at),
  KEY post (post_id),
  KEY user_created (user_id, created_at)
);
```

Retention is a setting (§13.8). The table is capped defensively: if it exceeds 250k rows, the daily cleanup trims oldest-first regardless of retention setting, and logs that it did.

The spend budget (§13.9) needs no schema change — `cost_usd`, `type` and `status`
already carry it. Two `type` values are added, `budget_grant` and `budget_threshold`,
and `status = 'capped'` is written on every budget denial. The table is the audit
trail for spend; the ledger option is only a fast counter, and the two are allowed to
disagree after a manual purge without either being wrong.

---

## 6. Fonts (D3)

The single most common way server-side card generators produce garbage. Specified in detail deliberately.

### 6.1 Resolution chain
`FontResolver::resolve( string $role )` where role is `heading` | `body` | `mono`. Steps, first success wins, every step logged in diagnostics:

1. **Explicit setting.** Admin picked a font in settings (bundled or uploaded). Absolute path returned.
2. **Theme.** Parse `wp_get_global_settings()['typography']['fontFamilies']` (theme.json and Font Library). For each `fontFace.src`:
   - `file:./assets/fonts/x.ttf` → resolve against the theme dir → usable directly.
   - A local `.woff2`/`.woff` → **not usable by GD/Imagick.** Attempt conversion if `fontTools`/`woff2_decompress` is available (it usually is not); otherwise skip this face and continue the chain, recording "theme font is WOFF2, cannot rasterise".
   - A remote URL (`fonts.gstatic.com`, a CDN) → only fetched if the admin has ticked *Allow downloading webfonts* (off by default, one-time consent, .org-compliant). Cached to `uploads/social-card-studio/fonts/` with the origin recorded.
3. **Blocksy / theme-specific adapter.** Blocksy stores typography in its own options and can self-host Google fonts; if a self-hosted path exists, use it. Adapter interface `scstudio_theme_font_adapter` so other themes can be added without touching core.
4. **Bundled families.** Ship three, all SIL OFL 1.1, subsetted to Latin + Latin-Ext:
   - `Inter` (400/600/700) — heading and body default
   - `Source Serif 4` (400/700) — editorial presets
   - `JetBrains Mono` (500) — code/tech presets
5. **Script coverage fallback.** Before layout, detect the dominant Unicode script of the resolved text. If the chosen face lacks coverage, substitute a bundled Noto subset for that script (Phase 1 ships Latin-Ext only; Arabic, Ge'ez/Amharic and Devanagari subsets are downloaded on demand with consent, or uploaded).

Uploaded fonts: `.ttf` and `.otf` only, validated by magic bytes (`\x00\x01\x00\x00`, `OTTO`, `true`, `ttcf`), max 5 MB, `manage_options` capability, stored outside the year/month tree. `.woff2` uploads are rejected with a message explaining why and pointing at a converter.

### 6.2 Honest constraints (must be documented in the plugin UI, not just the readme)

- **Colour emoji cannot be drawn by GD.** Default `emoji = strip`: emoji are removed from headline text before layout. With Imagick + an installed Noto Color Emoji, `keep_if_supported` renders them (Phase 3). The setting explains this in one sentence.
- **Complex-script shaping** (Arabic, Persian, Urdu contextual forms; Devanagari conjuncts) requires HarfBuzz, which neither GD nor typical Imagick builds expose to PHP. Behaviour: `TextLayout` detects an RTL or complex script, and if the environment cannot shape it, the post falls back to the site default card (§12) and logs `unsupported_script` with a clear admin notice naming the post. **We do not render broken Arabic.** Correct shaping for these scripts is a Phase 4 item, most likely via the HTML renderer.
- Simple RTL without shaping requirements (e.g. Hebrew) is rendered right-aligned with reversed logical order when `Intl` bidi is available; otherwise the same fallback applies.

### 6.3 Text layout algorithm (shared spec — implement identically in PHP and JS)

Given a text layer with `box`, `size.{min,max}`, `line_height`, `max_lines`, `overflow`:

1. Normalise: strip shortcodes, strip HTML, decode entities, collapse whitespace, apply emoji policy, trim.
2. Binary search font size over `[min, max]` with 0.5 px precision. For each candidate size:
   a. Greedy word wrap: accumulate words while measured width ≤ `box.w`. Measure with `imagettfbbox` (GD) / `queryFontMetrics` (Imagick) / canvas `measureText` (JS), including tracking.
   b. A single word wider than `box.w` is broken by grapheme cluster, never mid-cluster.
   c. Fit = `lines ≤ max_lines` **and** `lines × size × line_height ≤ box.h`.
3. Largest fitting size wins. If none fits, use `min` and apply `overflow`:
   - `ellipsis`: truncate the last line at the last grapheme boundary that leaves room for `…`.
   - `shrink_box`: allowed only for layers marked `flexible`.
4. Vertical placement per `vertical_align` within `box`; baseline computed from ascender, not from the bounding box, so cards with and without descenders align.
5. Never render a text layer whose resolved content is empty — collapse it and let `flexible` siblings reflow.

Tracking, line height and autofit are the three things that make a card look designed rather than generated. They are not optional.

---

## 7. Output rules

### 7.1 Sizes

| Slug | Dimensions | Tier | Emitted as |
|---|---|---|---|
| `og` | 1200×630 | Free | `og:image`, `twitter:image` |
| `square` | 1080×1080 | Pro | `_scstudio_card.variants`, exposed via REST + shortcode for manual use |
| `pinterest` | 1000×1500 | Pro | same |

Only `og` is ever emitted into head tags. The others exist because social teams need them; the plugin gives them a copy button and a REST endpoint, not a meta tag. Minimum acceptable inbound background: 600×315.

### 7.2 Format and file size (my call — please confirm)

- **Format: JPEG.** Progressive, chroma subsampling 4:2:0, sRGB, EXIF stripped. PNG only if a template declares `requires_alpha` (none in 1.0).
- **WebP/AVIF are excluded for the social image** even if the site's media settings prefer them. Facebook's scraper, several Android WhatsApp builds and some enterprise Slack/Teams unfurlers still fail or silently drop WebP. The plugin locally filters `image_editor_output_format` for its own writes only, never globally.
- **Size budget:** target **≤ 600 KB**, hard ceiling **1 MB**. Quality ladder 82 → 74 → 66 → 58; if still over, downscale the background source and recomposite once; if still over, log `oversize` and fall back. Anything above 5 MB is discarded outright — Facebook drops it and WhatsApp will not preview it.
- **Filename carries an 8-char input hash** — `card-1482-a1b2c3d4.jpg`. This is what actually busts platform caches: a regenerated card gets a new URL, so Facebook and LinkedIn fetch it rather than serving the copy they cached weeks ago. Query-string busting is unreliable; several scrapers normalise it away.
- Cards are served with `Cache-Control: public, max-age=31536000, immutable`, safe precisely because the URL is content-addressed.

### 7.3 Alt text
Generated for every card and stored in `_scstudio_card.alt`:
- Template mode: `alt_text_pattern`, default `{{headline}} — {{site_name}}`.
- AI copy mode: same pattern using the AI headline.
- AI background / full mode: the provider is asked for a one-sentence description; if unavailable, fall back to the pattern.
- Always editable in the editor panel.
- Emitted as `og:image:alt` and `twitter:image:alt`. Also passed to `MediaLibraryBridge` as `_wp_attachment_image_alt` in media-library mode.

---

## 8. Card data tokens

Available in every text layer and in `alt_text_pattern`. Missing tokens resolve to empty string and collapse their layer.

**Core**

| Token | Notes |
|---|---|
| `{{title}}` | `get_the_title()`, entities decoded |
| `{{share_headline}}` | `_scstudio_overrides.headline`, else AI copy, else falls through to `{{title}}` |
| `{{headline}}` | Alias of `{{share_headline}}`. It exists because the default `alt_text_pattern` in §5.1 and §7.3 is written with this spelling; without the alias every card's alt text would render as " — Site Name". |
| `{{subhead}}` | override, else AI subhead, else empty |
| `{{site_name}}` `{{site_tagline}}` | |
| `{{site_logo}}` | custom-logo, else site icon, else empty |
| `{{featured_image}}` | attachment, resized before compositing |
| `{{permalink}}` `{{domain}}` | `{{domain}}` is host without `www.` |
| `{{brand_accent}}` | from settings, or sampled from the logo (Pro) |

**Editorial**

| Token | Notes |
|---|---|
| `{{excerpt}}` | `get_the_excerpt()`, trimmed to 160 chars at a word boundary |
| `{{primary_term}}` | Yoast/Rank Math primary term if set, else first term of the type's primary taxonomy |
| `{{date}}` | site date format, filterable |
| `{{reading_time}}` | `ceil( word_count / 220 )` min, string localised with `_n()` |
| `{{post_type_label}}` | singular label, useful for CPT cards |

**Author**

| Token | Notes |
|---|---|
| `{{author_name}}` | display name |
| `{{author_avatar}}` | `get_avatar_url( 96 )`. **Gravatar is a remote request** — fetched server-side with `wp_safe_remote_get`, cached 30 days; skipped entirely if the site has disabled avatars or blocked external requests. A local avatar plugin's URL is used when present. |
| `{{author_role}}` | a user meta field the plugin registers (`scstudio_byline`), falling back to the user's description first line |

**WooCommerce (Pro)** — `{{price}}`, `{{regular_price}}`, `{{sale_badge}}`, `{{sku}}`, `{{rating}}`, `{{product_image}}`.

**Deferred to Phase 4:** arbitrary ACF/meta key mapping (`{{meta:field_name}}`, `{{acf:hero_subtitle}}`). Not in 1.0 — but `TokenResolver` exposes `scstudio_resolve_token` so it can be added by a snippet today.

**Per-post overrides in 1.0** are limited and deliberate: headline, subhead, template choice, AI prompt, disable checkbox. That is the set an author actually needs; anything broader belongs in the builder.

---

## 9. Regeneration & staleness

### 9.1 Input hash
```
input_hash = substr( sha1( json_encode([
  schema_version, template_id, template_version, settings_fingerprint,
  normalized_headline, normalized_subhead, primary_term_id, author_id,
  featured_image_id, featured_image_modified,
  resolved_font_fingerprint, engine_id, locale, size_slug
]) ), 0, 16 )
```
`settings_fingerprint` is a hash of only the render-affecting settings (brand, typography, output), so toggling an unrelated setting does not invalidate 3,000 cards. `resolved_font_fingerprint` means a theme switch that changes fonts invalidates cards automatically — no special-case hook needed.

### 9.2 Policy
Setting `regeneration_mode`, default **`auto_template_manual_ai`**:

| Mode | Template-rendered cards | AI-generated cards |
|---|---|---|
| `auto_template_manual_ai` *(default)* | Regenerated automatically when the hash changes | Marked stale, never regenerated silently |
| `auto_all` | Auto | Auto (spends credits — the setting says so explicitly) |
| `manual_all` | Marked stale only | Marked stale only |

AI cards are protected by default because they cost money and because a human approved that specific image. Silently replacing an approved image with a different AI output is the worst behaviour this plugin could have.

### 9.3 Surfacing staleness
- Post list column: thumbnail + badge (`Current` / `Stale` / `None` / `Failed` / `Disabled`), sortable, with a filter dropdown.
- Bulk action: *Regenerate social card*.
- Admin screen "Needs attention": stale count, failed count, uncovered count, one-click bulk regenerate through the backfill queue.
- Editor panel shows a stale banner with a Regenerate button and, for AI cards, the credit cost of doing so.

---

## 10. Delivery

### 10.1 Own meta tag output
When no supported SEO plugin is active, the plugin outputs on singular views and the front page:

```
og:type, og:title, og:description, og:url, og:site_name, og:locale
og:image, og:image:secure_url, og:image:width, og:image:height, og:image:type, og:image:alt
twitter:card = summary_large_image, twitter:title, twitter:description, twitter:image, twitter:image:alt
```

Guarded by a `did_action` check and a `scstudio_output_own_tags` filter so a theme already emitting OG tags can suppress ours. Every value escaped with `esc_attr` / `esc_url`.

### 10.2 SEO plugin integration
Detection at `plugins_loaded`, one integration active at a time (first match by this order): Yoast SEO, Rank Math, SEOPress, All in One SEO, The SEO Framework, Slim SEO.

Each integration implements:
```php
interface SeoIntegration {
    public function is_active(): bool;
    public function has_manual_image( int $post_id ): bool;   // read that plugin's own meta
    public function hook(): void;                              // filter its og/twitter image + dimensions + alt
}
```

**Priority setting `priority`** (site-wide, default `gap_fill`):

| Value | Behaviour |
|---|---|
| `gap_fill` *(default)* | Supply our card only when the SEO plugin would otherwise have no image at all. Least surprising. In practice this rarely fires, because most SEO plugins fall back to the featured image — the settings UI says so plainly and recommends `override_auto` for most sites. |
| `override_auto` | Replace images the SEO plugin *derived* automatically (featured image, first content image, site default), but never one a human explicitly set in the social tab. **This is the setting most sites actually want.** |
| `always` | Our card wins unless the post has `_scstudio_disabled`. |

Per-post opt-out (`_scstudio_disabled`) is honoured in all three modes.

Known filters used: `wpseo_opengraph_image` / `wpseo_twitter_image` / `wpseo_opengraph_image_size`; `rank_math/opengraph/facebook/image` and `.../twitter/image`; `seopress_social_og_thumb`; `aioseo_facebook_tags`; `the_seo_framework_og_image_args`; `slim_seo_open_graph_tags`. Each integration has its own fixture test so a host plugin's update failing us is caught, not discovered by a client.

### 10.3 Lazy generation endpoint
Route: `GET /wp-json/scstudio/v1/card/{post_id}` with `?v={hash}`; pretty rewrite `/social-card/{post_id}-{hash}.jpg` when permalinks allow, query fallback `?scstudio_card={id}&v={hash}` when they do not.

Behaviour:
1. Validate the post is published and publicly viewable. Private, draft, trashed, or password-protected → 404. This is the only public endpoint; it is read-only, and its output is derived entirely from public post data.
2. If the file exists → 302 to the static file with long cache headers.
3. If not → acquire a short-lived lock (`Lock.php`, transient-based, 30 s) to prevent a stampede when three scrapers hit simultaneously. Render inline with a **5 s hard budget** (filterable). Stream the bytes, write the file, store the record.
4. On failure or lock contention → 302 to the current fallback image (§12). Never a 500, never a broken image.
5. Per-IP rate limit (default 60/hour) with a 429 and `Retry-After`.

**Important subtlety:** once a card exists, `og:image` emits the **static file URL**, not the endpoint. The endpoint URL appears in head tags only for posts with no card yet. Scrapers follow the redirect fine, but a direct image URL is more reliable across the long tail of unfurlers, and it also means the endpoint carries almost no production traffic.

### 10.4 Homepage / front page card
Rendered once from a `brand-statement` template using site name, tagline, logo and an admin-set headline. Regenerated when those change. Emitted on the front page and as the ultimate fallback (§12 step 5) for every other view.

---

## 11. Generation triggers

All four selected modes ship, and they compose rather than conflict — `CardRepository::ensure()` is the single entry point and is idempotent on the input hash.

| Trigger | Mechanism | Notes |
|---|---|---|
| **On demand (editor)** | `POST /scstudio/v1/preview` → `POST /scstudio/v1/commit` | Full control, mandatory for AI. |
| **On publish/update** | `transition_post_status` + `save_post` → enqueue Action Scheduler job | Async only. Skips autosaves, revisions, bulk-edit, REST meta-only updates, and posts with `_scstudio_disabled`. Setting can disable. |
| **Lazy on OG request** | §10.3 | The safety net that covers the entire back catalogue with zero effort. |
| **Bulk backfill** | Admin screen + `wp scstudio regenerate` | Action Scheduler batches, default 10 per batch, 1 batch/minute, resumable, progress bar, cancel button, per-batch memory guard. Filters: post type, date range, only-missing / only-stale / all. Dry-run mode reporting how many cards would be generated and (for AI) what it would cost. |

Concurrency: `Lock.php` keys on `post_id + size_slug`. A lazy request while a scheduled job is running returns the fallback rather than double-rendering.

---

## 12. Failure handling & fallbacks

### 12.1 The chain
Resolution order for `og:image`, evaluated top-down, each step verified to exist before use:

1. Fresh generated card (hash matches).
2. Stale generated card — *only if* `regeneration_mode` allows stale serving (it does by default; a slightly outdated card beats no card).
3. Per-post manual social image set in the SEO plugin — respected in `gap_fill` and `override_auto`.
4. Featured image, if ≥ 600×315.
5. Site default card (the homepage/brand card, pre-rendered at settings save).
6. Custom logo / site icon, if ≥ 600×315.
7. Emit nothing and let the SEO plugin do whatever it would have done. **Never emit a URL we have not verified.**

### 12.2 Never break the front end
Every render path is wrapped. A thrown exception, a `wp_die` from a bad image, an out-of-memory in a child process — none of them may produce a fatal on a public page. Rendering during page output happens only in the lazy endpoint, which is a separate request from the post itself.

### 12.3 Notices and logging
- Failures write a `render_error` event with post ID, engine, step, and the exception message (never post content).
- A dismissible admin notice appears for users with `manage_options` when there are failures in the last 24 h, linking to Diagnostics. Dismissal is per-user and per-error-signature, so the same notice does not nag daily.
- The post list column shows `Failed` with a tooltip.

### 12.4 Diagnostics screen
A single page that answers "why isn't this working" without a support ticket:
- Imagick present? version? FreeType? formats?  GD present? FreeType? version?
- `memory_limit`, `max_execution_time`, `upload_max_filesize`
- Uploads directory writable? plugin directory created? `.htaccess` present?
- Permalink structure and whether the pretty card route resolves
- Resolved fonts per role, with the chain step that won and any skipped steps with reasons
- Detected SEO plugin and active priority mode
- Action Scheduler present and processing (last run time)
- AI: enabled, provider, key present (masked), credits, spend budget (amount, spent, remaining, active grants) and whether `GET_LOCK` is available to the ledger
- Last 20 events from `scstudio_events`
- **"Run test render"** button → renders a sample card with the current settings and displays it inline, with timing and peak memory

Registering the same checks with WP Site Health (`site_status_tests`) is a small addition on top and is scheduled for 1.2.

---

## 13. AI module

Off by default. Ships in the free plugin for **copy mode only, with the user's own API key**; image modes are Pro.

### 13.1 The three modes

**`copy` — AI writes the share text, the template renders it.**
Input: title, and (per data-scope setting) excerpt or first N words. Output: a headline ≤ 70 characters, an optional subhead ≤ 90, optional 1–3 hashtags, and an alt-text sentence. Text model only, so it costs a fraction of a cent and works with an Anthropic, OpenAI or Gemini key. Cheapest way to make cards feel written rather than truncated, and it proves the entire AI plumbing before an image model is involved.

**`background` — AI generates artwork, the template overlays the text.**
The recommended image mode. Text stays crisp and correct because it is still drawn by our renderer. Style presets: *editorial photo*, *flat vector*, *abstract gradient mesh*, *duotone brand*, *hand-drawn*, *isometric tech*. Brand accent colour injected into the prompt. Negative prompt always includes text/letters/watermark/logo suppression.

**Legibility guard (required, not optional):** after the background is generated, sample the region under each text layer, compute contrast against the text colour, and if below 4.5:1 deepen the scrim gradient in steps until it passes or the scrim reaches its cap. An AI background that makes the headline unreadable is a worse card than no card.

**`full` — AI generates the entire image including text.**
Highest wow factor, least reliable: image models still mangle typography, and brand consistency is gone. Shipped because you asked for it, but gated: a one-time acknowledgement per site ("text in AI-generated images is frequently misspelled — always review before publishing"), mandatory preview, and no participation in auto-regeneration under any setting.

The editor panel presents these as three buttons with an honest one-line description of each, not as an opaque "AI" toggle.

### 13.2 Providers
```php
interface AiProvider {
    public function id(): string;
    public function capabilities(): array;              // ['text'] | ['image'] | both
    public function generate_text( TextRequest $r ): TextResult;
    public function generate_image( ImageRequest $r ): ImageResult;
    public function estimate_cost( Request $r ): float; // USD, for the dashboard
}
```
Shipped adapters: **OpenAI** (text + image), **Google Gemini** (text + image), **Anthropic** (text only), **Replicate / Stability** (image), and **ScsProxy** (Pro credits). Model IDs are configuration, not hardcoded constants, with a sane default per provider and a dropdown populated from a small bundled manifest that can be updated without a plugin release.

### 13.3 Keys and credits
- **BYO key (free and Pro):** stored in `scstudio_ai_settings` (not autoloaded), encrypted with `sodium_crypto_secretbox` using a key derived from `AUTH_KEY`/`SECURE_AUTH_SALT`. **This is obfuscation, not real security** — anyone with database *and* filesystem access can decrypt it. The settings UI says exactly that in one sentence rather than implying safety it cannot provide. Keys are `manage_options`-only, never returned by REST, always masked (`sk-…4f2a`).
- **Credits (Pro):** requests go to the Chrx proxy signed with the Freemius licence key + site URL + timestamp HMAC. The proxy returns image bytes and the remaining balance. The site's own API key is never sent to the proxy, and the proxy never sees more than the sanitised payload the site would have sent the provider directly.
- Precedence setting: *prefer credits* / *prefer my key* / *credits only when key fails*.

### 13.4 Consent and transparency
The AI module cannot be enabled without passing a consent screen that states, on one page:
- which provider will be contacted and at which endpoint,
- exactly what data leaves the site (rendered from the current data-scope setting),
- that generation costs money, and whose money,
- links to the provider's privacy policy and terms,
- how long the plugin retains logs.

**Payload preview** is a real feature, not a paragraph: pick any post, see the literal JSON that would be POSTed, before enabling.

**Data scope setting:** `title_only` | `title_excerpt` *(default)* | `title_excerpt_body` with `N` words, default 300. Never the full post body by default.

### 13.5 Sanitisation and moderation
Before any outbound call, `Sanitizer` runs:
1. `strip_shortcodes`, `wp_strip_all_tags`, entity decode, whitespace collapse.
2. Remove base64 blobs, URLs beyond the permalink, and any content inside blocks marked `scstudio-exclude`.
3. Redact patterns: email addresses, phone numbers (E.164 and local forms), sequences of 12+ digits, and anything matching an admin-defined regex list. Redactions are counted and shown in the payload preview.
4. Site block list: if any listed term appears in the payload, generation is aborted with a clear message and a `blocked` event. Useful for church, NGO and health-adjacent clients.
5. Provider safety response: a result flagged by the provider is **discarded, never attached**, logged with the reason, and surfaced to the author as "the provider declined this request" with the option to edit the prompt.
6. Returned images are downloaded via `wp_safe_remote_get`, validated by `getimagesize` and magic bytes, size-capped at 10 MB, and re-encoded before use — never trusted as-is.

### 13.6 Guardrails
| Guardrail | Default | Notes |
|---|---|---|
| Preview before save | **Always on, not configurable** | AI output is never written to a post without a human clicking *Use this*. |
| Capability | `scstudio_generate_ai`, mapped to `edit_others_posts` | Contributors and authors can use templates; only editors/admins spend money. Filterable per role. |
| Workspace spend budget | $10 / rolling 30 days, **on** | The money guardrail. Denominated in USD, scoped to one WordPress install, enforced locally. See §13.9. |
| Per-user loop guard | 20/hour | Transient counter, per user per blog. **Not a quota** — it exists so a stuck editor or a runaway script cannot drain the budget in ninety seconds, which is the one failure mode a spend budget cannot catch. Presented in the UI as runaway protection, never as an allowance. |
| Kill switch | `define('SCSTUDIO_DISABLE_AI', true)` | Wins over everything, for agencies locking down client sites. |
| Cost visibility | Always | Every call logged with provider, model, mode, duration, credits and estimated USD. |

A per-site *call* cap was specified in earlier drafts and has been removed. Counting
calls does not bound money: a single `full`-mode image can cost a hundred times a
`copy`-mode call, so a 200-call cap is worth anywhere between a few cents and tens of
dollars depending on which buttons people press. For an agency running client sites on
the client's own provider key, that is precisely the wrong guarantee — the site owner
gets a surprise invoice from a plugin that reported itself well within limits.

### 13.7 Usage dashboard
Generations by mode and by user, estimated spend, credits remaining, a 30-day sparkline, error and block rate, top spenders, and a CSV export. The spend budget (§13.9) is shown at the top as budget / spent / remaining with a 30-day burn-down, since it is the number that answers the question this screen exists for. Enough for a site owner to answer "why did we spend $40 on images last month" without asking you.

### 13.8 Log retention & privacy
Retention setting: 30 / 90 / 180 / 365 days / forever, default **90**. Daily cleanup task. Manual purge button. `message` and `prompt_hash` are stored; **raw prompts and post content are never stored** in the event log. GDPR: registered exporter and eraser (`wp_privacy_personal_data_exporters` / `_erasers`) covering user-linked event rows, and a privacy-policy suggestion via `wp_add_privacy_policy_content()`.

### 13.9 Workspace spend budget

A **workspace** is one WordPress install — one blog on a multisite network. Not a
licence, not a team, not a user. The budget is denominated in **USD**, enforced
**locally against the site's own database**, and is **on by default** at $10 per
rolling 30 days.

Local enforcement is a deliberate limit on what this can promise. The Pro credits
proxy is not the authority, because BYO-key calls never reach it — and BYO-key calls
are exactly the ones worth guarding, since that is where an unexpected provider
invoice lands on a client who did not choose the model.

**Honesty requirement.** The figures are estimates derived from a bundled price list.
They will drift from the provider's actual invoice. The settings UI must say so in one
sentence, in the same register as the API-key obfuscation disclosure in §13.3. This is
a guard rail, not a billing control, and the plugin must not imply otherwise.

#### 13.9.1 Ledger

One non-autoloaded option, `scstudio_ai_budget`, mutated only under a named DB lock:

```php
[
  'schema'  => 1,
  'buckets' => [ '2026-09-05' => 0.4312, '2026-09-04' => 1.980, /* … */ ],  // ≤ 30 days
  'reservations' => [
    'r_9f2c…' => [ 'amount' => 0.0200, 'user' => 3, 'created' => 1757030400, 'expires' => 1757031300 ],
  ],
  'grants'  => [ [ 'amount' => 5.00, 'by' => 1, 'at' => 1757030400, 'expires' => 1757635200, 'used' => 0.0 ] ],
  'notified'=> [ '50' => 1757030400, '80' => null, '100' => null ],
]
```

- `spent     = sum( buckets ) + sum( active reservations )`
- `available = budget + sum( active grant remainder ) − spent`

The rolling window is thirty day-buckets rather than a query over `scstudio_events`, so a
read is O(1) and the row stays lockable. The cost is 24-hour granularity on when
budget frees up, which is the right trade for a monthly-scale limit. Buckets older
than `window_days` are dropped on every lock acquisition. **Day keys are computed in
the site timezone via `wp_timezone()`, never `date()`** — a site in `Africa/Kampala`
must not roll its window on UTC midnight.

**Locking.** `GET_LOCK( 'scstudio_budget_' . get_current_blog_id(), 3 )` around every
read-modify-write, released in a `finally`. Where `GET_LOCK` is unavailable, fall back
to a compare-and-swap `UPDATE … WHERE option_value = <previous>` with bounded retries.
Two concurrent generations must never both pass a check that only one of them fits
under. This is the only place in the plugin where a race costs real money, and it gets
a dedicated concurrency test (§19.1).

#### 13.9.2 Reserve, then reconcile

```php
$handle = $ledger->reserve( $estimate_usd, $ctx );   // Reservation | Denied
// … provider call …
$ledger->settle( $handle, $actual_usd );             // reservation → today's bucket
$ledger->release( $handle );                         // reservation dropped, costs nothing
```

`AiManager` wraps every outbound call. The estimate comes from
`AiProvider::estimate_cost()`; `settle()` replaces it with the real figure computed
from returned token usage where the provider reports it, and keeps the estimate where
it does not. Reserving before the call is what makes the budget a ceiling rather than
a tripwire — charging only after the fact allows an unbounded number of concurrent
calls to overshoot together.

Reservations carry a TTL (default 15 minutes, comfortably above the longest provider
timeout, filterable via `scstudio_budget_reservation_ttl`). Orphans left by a fatal
mid-call are pruned under the same lock, so a crash can never permanently sequester
budget.

**Billable policy** — budget is consumed when the provider did real work. Filterable
via `scstudio_budget_is_billable`.

| Outcome | Result |
|---|---|
| Success, author clicked *Use this* | settle — counts |
| Success, author clicked *Discard* or *Regenerate* | **settle — counts.** The provider charged for it. Not counting this would make *Regenerate* a free infinite loop. |
| Provider-flagged / moderation refusal | settle at estimate — counts. Providers generally bill a generation that completed and was then filtered. |
| Timeout, connection error, 5xx | release — free |
| Identical-input cache hit (no outbound call) | never reserved — free |

#### 13.9.3 Price book

`assets/ai-models.json` — the manifest §13.2 already introduces for model IDs — gains
a `price` per model: per-1M input and output tokens for text models, per-image for
image models. Because the manifest is updatable without a plugin release, a provider's
price change does not require shipping a version. Admins can override any model's
price in AI settings (`budget.price_overrides`) to match an enterprise or discounted
rate. Filter: `scstudio_budget_price_book`.

#### 13.9.4 Exhaustion — three behaviours by caller context

| Context | Behaviour |
|---|---|
| **Interactive** — editor AI buttons, preview REST routes | Hard stop. `403` with a structured body naming remaining budget and how to raise it. Buttons render disabled with the reason inline — never a silent failure, never a spinner that does not resolve. |
| **Automated** — on-publish Action Scheduler job | Degrade to a template render, log a `capped` event, surface nothing to the author. The post still gets a card. |
| **Bulk backfill** | The existing dry run compares projected cost against available budget and warns with the shortfall figure. The admin may proceed knowingly; posts past exhaustion degrade to template cards, and the results log states how many did. No pre-flight refusal — a job that would partly succeed should be allowed to partly succeed. |

#### 13.9.5 Grants

When the budget is hit, the escape hatch is a **grant**: a bounded top-up with an
amount and an expiry (default 7 days), applying to the current window and then
evaporating. `manage_options` only, nonce'd, and every grant writes a `budget_grant`
event naming the user, the amount and the expiry.

Grants are additive to the budget and are deliberately **not** a bypass flag. There is
no state in which the limiter is switched off — a "suspend for 24 hours" switch is the
easiest way for a site to spend a whole month with the guard down and nobody noticing.

#### 13.9.6 Surfacing

1. **Editor panel** (§14 item 5) — remaining budget beside the three AI buttons,
   alongside the per-action cost estimate already specified there. This is the one
   place the number changes a decision.
2. **Usage dashboard** (§13.7) — budget, spent, remaining and a 30-day burn-down,
   next to the existing sparkline and top-spenders table.
3. **Admin notices** at 50 / 80 / 100%, fired from `settle()`, reusing §12.3's
   per-user, per-signature dismissal so they do not nag daily. The `notified`
   timestamps in the ledger make each threshold fire once per window crossing rather
   than on every call above the line.

Email alerts are deliberately excluded from 1.1: they need an opt-out, working site
mail and a bounce story, and the admin notice already reaches the person who can act.

#### 13.9.7 Multisite

Each blog holds its own ledger. The network admin may set an optional **spend
ceiling** (§16); a site's effective budget is `min( site budget, network remaining )`.
`reserve()` takes the blog lock, then a network-scoped lock, and releases the blog
reservation if the network denies. Both ledgers settle together. Network spend lives
in a site-meta ledger of the same shape, so one implementation serves both scopes.

---

## 14. Editor UX

A sidebar panel (`PluginDocumentSettingPanel`) in the block editor, plus a classic-editor metabox with a reduced feature set.

**Panel, top to bottom**
1. **Card preview** — the current card, or a live DOM preview if unsaved changes exist, with a `Current` / `Stale` / `None` / `Disabled` status pill.
2. **Template** — dropdown of presets, with per-post override; changing it updates the DOM preview instantly.
3. **Headline** — text field pre-filled with the post title, character counter with a soft warning past ~70. Subhead field below it, collapsed by default.
4. **Generate** — renders server-side and shows the real image. Primary action.
5. **AI** (only if enabled and the user has the capability) — three buttons: *Write the headline*, *Generate a background*, *Generate the whole card*. Each shows an estimated cost or credit count, with the workspace budget remaining (§13.9) displayed alongside them. Results appear in a review strip; nothing is applied until *Use this*. A *Regenerate* re-rolls — and **is charged**, because the provider charged us; the UI says so rather than letting an author assume re-rolling is free. A *Discard* makes no further call. When the budget is exhausted the three buttons render disabled with the reason and the remaining figure inline.
6. **Alt text** — editable, pre-filled.
7. **Advanced** — per-post disable checkbox, "download card", "copy image URL", and (Pro) the square/Pinterest variants.

**Rules**
- The panel never blocks publishing. A missing card is a status, not an error.
- Generating writes to the post's meta immediately on *Use this*; it does not wait for the post save, so a card is never lost to a browser crash.
- All strings translatable via `@wordpress/i18n`; the panel is RTL-aware.
- Keyboard accessible, focus-managed, and the preview image carries its own alt text.

---

## 15. Admin screens

| Screen | Purpose |
|---|---|
| **Settings → Social Cards** | Post types, default and per-type templates, priority mode, triggers, regeneration mode, brand (logo, accent), typography source, output (quality, budget), alt text pattern, emoji policy, media-library mode, uninstall behaviour. |
| **Templates** | Preset gallery with live thumbnails rendered by the server. Free: pick + configure. Pro: *Edit* opens the builder. |
| **AI** | Consent status, provider, keys, model, data scope, block list, spend budget and grants (§13.9), price overrides, loop guard, retention, payload preview, usage dashboard. |
| **Bulk generate** | Filters, dry run, cost estimate, queue progress, cancel, results log. |
| **Diagnostics** | §12.4. |
| **Post list column** | Thumbnail, status badge, sortable, filterable, bulk regenerate action. |

Settings use the Settings API with a single sanitise callback validating against the same schema the REST layer uses — one validator, two entry points.

---

## 16. Multisite

- Network admin screen: network defaults for brand, typography, output, priority, AI enablement and the spend ceiling, each with a *lock* toggle. Locked values are read-only per site.
- Templates are per-site in 1.x; a network template library is Phase 4.
- Uploads resolve per site via `wp_upload_dir()`; `scstudio_events` is a single network table with a `blog_id` column and per-site views.
- **AI spend ceiling**: the network admin can set a rolling-30-day USD ceiling for the whole network, with a lock toggle like the other network defaults. Each site keeps its own budget and ledger; a site's effective budget is `min( site budget, network remaining )`, so one busy site cannot quietly consume the network's headroom without the ceiling stopping it. Mechanics in §13.9.7.
- Freemius licence activated at network level covers all sites; per-site activation also supported.
- Bulk backfill runs per site only — no network-wide job, deliberately, to keep queue behaviour predictable.
- `switch_to_blog()` safety: every path that touches uploads or options re-resolves after a switch; no static caching of paths across blog switches.

---

## 17. Internationalisation & text handling

- Text domain `social-card-studio`, `load_plugin_textdomain`, all PHP strings wrapped, all JS strings via `@wordpress/i18n` with `wp_set_script_translations`.
- Admin CSS ships an RTL build (`wp_style_add_data( $handle, 'rtl', 'replace' )`).
- Numbers, dates and reading time localised (`number_format_i18n`, `date_i18n`, `_n`).
- Rendering: §6.2 governs what the renderer can and cannot draw. The rule is *correct or fallback, never mangled*.
- Multilingual plugins: WPML and Polylang translations are separate posts, so each gets its own card automatically. A filter `scstudio_card_language` lets a template pick a per-language font or template.

---

## 18. Cleanup, compatibility, security

### 18.1 Lifecycle hooks
| Event | Action |
|---|---|
| `wp_trash_post` | Keep the card (post may be restored); mark disabled for delivery. |
| `before_delete_post` | Delete all card files and variants for that post, delete the attachment in media-library mode, delete event rows referencing it. |
| `delete_attachment` | If it was a featured image or logo, mark affected cards stale. |
| `switch_theme` | Font fingerprint changes → hash changes → affected cards go stale automatically. |
| `update_option( 'scstudio_settings' )` | Recompute `settings_fingerprint`, re-render the site default card. |
| Daily cron | Purge retired files past the grace window, trim the event table, refresh credit balance (Pro). |
| Uninstall | Options, meta, table and files removed **only** if `delete_data_on_uninstall` is on. Default off. |

### 18.2 Compatibility
- **Offload/CDN:** `media_library_mode` + `scstudio_card_url` filter + `scstudio_card_written` action (§5.3).
- **Page caches:** cards are static files with immutable URLs; no cache interaction needed. The lazy endpoint sends `Cache-Control` and is excluded from page-cache HTML caching by virtue of being a REST/image route.
- **Sitemaps:** card attachments excluded from `wp_sitemaps_posts_query_args`, Yoast and Rank Math image sitemaps.
- **Migration:** URLs are absolute in the record but always re-derived from `wp_upload_dir()` at read time, so a domain change or a Local→production migration needs no search-replace. `wp scstudio doctor` reports and repairs orphaned records.
- **Action Scheduler:** if WooCommerce or another plugin already loaded it, ours defers to the loaded copy.

### 18.3 Security
- Every REST route has a real `permission_callback`; the only public one is the card endpoint, which serves derived public data and is rate-limited.
- Nonces on every admin action and REST write; capability checks server-side, never only in the UI.
- Card Documents validated against a strict allowlist schema — unknown keys rejected, numerics clamped, colours matched against `#RGB|#RRGGBB|#RRGGBBAA|rgba()`, font families matched against the resolved list, image sources restricted to local attachments, plugin-managed files, or an explicit host allowlist.
- **SSRF:** all outbound fetches use `wp_safe_remote_get`; AI image results and webfonts are validated by content type, magic bytes and size before being written.
- Uploaded fonts validated by magic bytes; the plugin uploads directory carries `index.php` and an `.htaccess` denying execution.
- Output escaping on every meta tag; no user-supplied HTML rendered anywhere.
- GPLv2+; no minified-only JS without sources; no telemetry without opt-in (Freemius opt-in must be skippable, and the free plugin must function fully if skipped).

---

## 19. Testing

### 19.1 Unit (PHPUnit + WP test suite)
Input hashing determinism and change detection; the fallback chain across all seven steps; token resolution including missing-token collapse; the size/quality ladder; sanitiser redaction patterns; schema validator rejecting hostile Card Documents.

Spend budget (§13.9), which is the money-critical arithmetic and is tested harder than the rest:

- **Concurrency.** 20 parallel generations against a budget with room for 3 settle exactly 3 and deny 17. Run against real MySQL, not a mock — a mocked lock proves nothing about the thing the lock exists for.
- **Rolling window.** With the site timezone set to `Africa/Kampala`, day 1's spend stops counting at the start of day 31 *in that timezone*; the same holds across a DST transition in a DST-observing zone.
- **Reservation lifecycle.** A process killed mid-call leaves a reservation that is pruned at TTL and does not permanently reduce available budget; a timeout releases immediately; a successful-but-discarded generation does not release.
- **Billable policy.** Each row of the §13.9.2 table, including a provider-flagged result counting and a cache hit never reserving.
- **Grants.** A grant expires without leaving residue; a grant cannot be spent twice; grant issuance writes an audit event.

### 19.2 Visual regression
A golden-image fixture set: **10 titles** (1 word, 12 words, 40 words, all-caps, emoji, Arabic, Amharic, Luganda with diacritics, a 30-character unbroken word, an HTML-entity-laden title) × **6 presets** × **both engines**. Rendered on CI, compared against goldens with a perceptual diff (tolerance 1.5%). Any pixel drift is a deliberate, reviewed change. The same fixtures drive the PHP-vs-JS layout parity check (§2.3): line counts and chosen font sizes must match exactly.

### 19.3 Integration
Each SEO plugin integration against a matrix of {no image set, manual image set, featured image present, nothing at all} × three priority modes. Lazy endpoint under concurrent requests (lock behaviour). Backfill of 500 posts within memory and time budgets. Multisite: settings inheritance and lock behaviour.

### 19.4 Manual scraper checklist (release gate)
Facebook Sharing Debugger, X Card Validator, LinkedIn Post Inspector, WhatsApp preview on Android and iOS, Slack unfurl, Telegram, Discord, iMessage. Verified for: a fresh card, a regenerated card (does the new URL actually replace the cached one), and a post with the card disabled.

### 19.5 Environment matrix
PHP 8.1 / 8.2 / 8.3; GD-only host; Imagick host; a host with neither (must degrade gracefully, not fatal); shared hosting with 128 MB memory; multisite subdirectory and subdomain.

---

## 20. Release phasing (D4)

**Phase 0 — Foundations (≈1 week).** Scaffolding, requirements gate, container, settings framework, Card Document schema + validator, storage layer, event table, diagnostics screen, CI with the fixture harness. Nothing user-visible except Diagnostics — and Diagnostics is what makes every later phase debuggable.

**Phase 1 — v1.0, free, WordPress.org (≈3–4 weeks).** The full non-AI product: both renderers, text layout engine, font resolution chain, 6 presets, settings, editor panel with DOM preview, SEO integrations and own tag output, priority modes, lazy endpoint, on-publish and on-demand generation, bulk backfill, fallback chain, post list column, WP-CLI, i18n, uninstall. **Ship this to .org before writing a line of AI code.**

*Rationale:* the renderer is the hard part and the defensible part. It is also what every subsequent feature stands on — AI backgrounds are worthless without a compositor to put text on them. And the .org listing is the distribution channel that makes Pro sellable at all; getting into review early means the review queue runs in parallel with Phase 2.

**Phase 2 — v1.1, free (≈2 weeks).** AI copy mode with BYO key. Consent flow, payload preview, sanitiser, the workspace spend budget (§13.9), event log, basic usage dashboard, three text providers. This exercises the entire AI pipeline at near-zero cost per call, so the expensive image path in Phase 3 is built on plumbing that already works in production.

**Phase 3 — Pro 1.0 (≈3–4 weeks).** Freemius SDK and licensing, the visual template builder, AI background and full-image modes, the legibility guard, the credits proxy, multi-size variants, premium template packs, WooCommerce product cards, full usage dashboard.

**Phase 4 — 1.3+ / Pro 1.1.** Multisite network template library, the HTML/headless renderer for premium typography and complex-script support, ACF/meta token mapping, Site Health tests, colour emoji, per-card share analytics.

**Recommended MVP line: Phase 1.** Everything in Phases 2–4 is optional revenue; Phase 1 alone is a plugin people would install.

---

## 21. Extensibility

| Hook | Type | Purpose |
|---|---|---|
| `scstudio_card_document` | filter | Mutate the Card Document before render |
| `scstudio_resolve_token` | filter | Add or override tokens (the ACF escape hatch) |
| `scstudio_template_registry` | filter | Register custom templates from a theme or plugin |
| `scstudio_renderers` | filter | Register an additional renderer |
| `scstudio_font_paths` / `scstudio_theme_font_adapter` | filter | Font resolution overrides |
| `scstudio_should_generate` | filter | Veto generation per post |
| `scstudio_priority_for_post` | filter | Per-post priority override |
| `scstudio_card_dir` / `scstudio_card_url` | filter | Relocate storage (offload) |
| `scstudio_card_written` | action | Post-write hook (CDN push) |
| `scstudio_output_own_tags` | filter | Suppress our meta tags |
| `scstudio_fallback_chain` | filter | Reorder or extend the fallback steps |
| `scstudio_ai_request` / `scstudio_ai_result` | filter | Inspect or modify AI payloads |
| `scstudio_ai_providers` | filter | Register a provider adapter |
| `scstudio_budget_amount` / `scstudio_budget_window_days` | filter | Override the workspace spend budget and its window (§13.9) |
| `scstudio_budget_price_book` | filter | Supply or correct per-model USD prices |
| `scstudio_budget_is_billable` | filter | Decide whether an outcome settles or releases |
| `scstudio_budget_reservation_ttl` | filter | Orphan-reservation timeout |
| `scstudio_grace_days`, `scstudio_lazy_timeout`, `scstudio_rate_limits` | filter | Tuning |

WP-CLI: `wp scstudio generate <ids>`, `wp scstudio regenerate --all|--stale|--post_type=`, `wp scstudio doctor`, `wp scstudio purge --retired`, `wp scstudio test-render`, `wp scstudio budget status`, `wp scstudio budget grant <amount> [--days=7]`, `wp scstudio budget reset`.

---

## 22. Open items

1. **Plugin name and .org slug** — "Social Card Studio" is a working title; check slug availability and trademark collisions before Phase 0 ends.
2. **Is AI copy mode free or Pro?** Spec assumes free with BYO key, on the argument that it drives installs and costs you nothing. Revisit if it cannibalises Pro.
3. **Credits pricing** — cost per image varies 10× by model. Needs a margin model before the proxy is built.
4. **Blocksy typography API stability** — the adapter reads options that are not a public API. Needs a defensive read and a graceful skip.
5. **WebP exclusion** (§7.2) — confirm you agree; it is my call, not yours.
6. **Complex-script rendering** (§6.2) — confirm that falling back to the site default card for unshapeable scripts is acceptable rather than attempting an imperfect render.
7. **Price-list maintenance** (§13.9.3) — the budget is only as honest as
   `assets/ai-models.json`. Provider prices change without notice, and a stale manifest
   makes every site's budget quietly wrong in the same direction. Needs an owner, a
   review cadence, and a decision on whether the manifest is fetched (which means a
   phone-home the free tier should not have) or shipped with releases.
8. **Freemius vs. self-hosted licensing** — chosen Freemius; the SDK footprint and revenue share should be sanity-checked against your own EDD/SureCart setup on wp-fundi.com before Phase 3.

---

*Companion document: `PROMPTS.md` — the master and per-module build prompts for implementing this spec.*
