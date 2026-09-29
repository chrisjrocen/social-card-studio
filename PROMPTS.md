# Social Card Studio — Build Prompt Pack

Companion to `SPEC.md`. Written for Claude Code (or any agentic CLI that can create files and run WP-CLI/Composer/npm).

**How to use it**

1. Put `SPEC.md` in the repository root.
2. Run **Prompt 0 (Master)** once, in a fresh session. It establishes architecture, conventions and the working agreement.
3. Run the module prompts in order. Each is self-contained enough to survive a context reset — it names the spec sections to re-read first.
4. Do not run module prompts out of order without reading their **Depends on** line; the layout engine and the Card Document are load-bearing for everything else.

Notation: `«…»` marks something you fill in.

---

## Prompt 0 — Master (run once, first)

```
You are building a WordPress plugin called Social Card Studio. The complete
specification is in SPEC.md at the repository root. Read it fully before writing
any code — all of it, not the summary sections.

YOUR ROLE
Senior WordPress plugin engineer. You write production plugin code that would pass
WordPress.org plugin review and a security audit on the first attempt. You are
building a freemium product that will be installed on shared hosting by non-technical
users, so defensive coding and graceful degradation matter more than elegance.

WHAT WE ARE BUILDING
A plugin that generates a branded 1200x630 social share image (og:image) for posts,
custom post types, the homepage and (Pro) WooCommerce products. A server-side PHP
compositor renders a JSON "Card Document" into a JPEG. A paid tier adds AI-generated
backgrounds, AI-written headlines and full AI image generation.

NON-NEGOTIABLE ARCHITECTURAL CONSTRAINTS (from SPEC.md §2–§4)
1. The Card Document (JSON) is the single contract between templates and renderers.
   Nothing renders from hardcoded layout. Nothing renders from HTML.
2. The authoritative renderer is server-side PHP: Imagick preferred, GD fallback.
   The browser DOM preview is a preview only and never produces the shipped file.
3. GD is the design baseline. No shipped preset may require an Imagick-only effect.
4. Post saves must never get slower. On-publish generation enqueues an Action
   Scheduler job; it never renders inline.
5. No public page may fatal because of this plugin. Every render path is wrapped and
   falls back through the chain in §12.
6. No outbound HTTP request happens without explicit admin consent. This is a
   WordPress.org release requirement, not a preference.

CODING CONVENTIONS
- PHP 8.1+, strict types, PSR-4 autoload under ChrxDigital\SocialCardStudio,
  composer autoloader committed or generated at build.
- WordPress Coding Standards (PHPCS with WordPress-Extra + WordPress-Docs) and
  PHPStan level 6. Both must pass; wire them into composer scripts in Phase 0.
- Prefixes: functions/options/meta/hooks `scstudio_`, constants `SCSTUDIO_`, text domain
  `social-card-studio`. No global functions except the plugin bootstrap.
- Every user-facing string translatable. Every output escaped at the point of output.
  Every input sanitised at the point of entry. Every REST route has a real
  permission_callback.
- Dependency injection through a tiny container in Plugin.php. No service locators,
  no static state that survives a switch_to_blog().
- Runtime dependencies: Action Scheduler only. No other Composer packages at runtime.
- Every class gets a docblock stating which SPEC.md section it implements.

WORKING AGREEMENT
- Work in the module order defined in PROMPTS.md. Complete a module before starting
  the next; a module is complete when its acceptance criteria pass, not when the
  files exist.
- Write the test alongside the code, not afterwards.
- When SPEC.md is ambiguous or wrong, stop and say so with your recommended
  resolution. Do not silently invent behaviour and do not paper over a gap.
- Never leave a TODO in place of error handling.
- After each module, output: files created/changed, how to verify it manually, and
  anything in the spec you now believe is wrong.

FIRST TASK
Do not write feature code yet. Read SPEC.md and produce:
(a) the full file tree you intend to create for Phase 0 and Phase 1,
(b) the PHP interfaces for CardDocument, Renderer, SeoIntegration, AiProvider and
    CardRepository, with signatures and docblocks only — no implementations,
(c) a numbered list of every place the spec is ambiguous, contradictory or
    under-specified, with your recommended resolution for each.
Then stop and wait.
```

---

## Module prompts

### M1 — Foundations *(Phase 0)*

```
Implement SPEC.md Phase 0 foundations. Re-read SPEC.md §3, §5, §12.4, §18.3.

Depends on: nothing.

Build:
1. Plugin bootstrap: header, GPLv2+ licence, requirements gate (WP 6.4+, PHP 8.1+,
   and GD-with-FreeType OR Imagick-with-FreeType). If requirements fail, show an
   admin notice and return — never fatal, never partially initialise.
2. The DI container and hook registration in Plugin.php.
3. Support layer: Env (capability detection with results cached in a transient),
   Log (writes to the scstudio_events table), Hash, Color, Str, Schema (a generic
   allowlist JSON schema validator).
4. Activation: create the {prefix}scstudio_events table per SPEC §5.4 via dbDelta, create
   the uploads directory with index.php and .htaccess, set scstudio_db_version, seed
   default settings. Must be multisite-safe (per-blog activation and network
   activation both correct).
5. Settings storage: scstudio_settings option with the full default array from §5.1, a
   single sanitise callback validating against the schema, and a
   settings_fingerprint accessor.
6. Diagnostics admin screen per §12.4, minus the checks that depend on later modules
   (font resolution, SEO detection, AI) — leave those as clearly-labelled stubs.
7. uninstall.php honouring delete_data_on_uninstall (default false).
8. Composer setup with PHPCS (WordPress-Extra, WordPress-Docs) and PHPStan level 6,
   plus composer scripts: lint, lint:fix, analyse, test.
9. GitHub Actions CI running lint, analyse and PHPUnit on PHP 8.1/8.2/8.3.

Acceptance:
- Plugin activates and deactivates cleanly on single site and multisite, with no
  notices at WP_DEBUG=true.
- On a host with neither GD nor Imagick, activation shows the notice and the plugin
  does nothing else — no fatals anywhere in wp-admin.
- Diagnostics renders and correctly reports the image library, memory limit,
  execution time, uploads writability and permalink structure.
- composer lint, composer analyse and composer test all pass on an empty test suite.
```

### M2 — Card Document, tokens, hashing *(Phase 0)*

```
Implement the Card Document layer. Re-read SPEC.md §2.1, §2.2, §8, §9.1, §18.3.

Depends on: M1.

Build:
1. CardDocument value object: immutable, constructed from validated JSON, with typed
   accessors for canvas, safe area and ordered layers. Layer types: image, text,
   gradient, shape. Nothing else is accepted.
2. A strict schema validator (built on M1's Schema): unknown keys rejected outright,
   numerics clamped to declared ranges, colours matched against
   #RGB|#RRGGBB|#RRGGBBAA|rgba(), image sources restricted to local attachment IDs,
   plugin-managed file paths, or tokens. Reject anything else. Write hostile-input
   tests: path traversal in a source, a remote URL, a 10,000-layer document, a
   negative box width, a colour containing JS, deeply nested structures.
3. TokenResolver implementing every token in §8 (core, editorial, author). Missing
   tokens resolve to empty string and their layer collapses. Author avatar fetching
   uses wp_safe_remote_get with a 30-day cache and is skipped entirely if the site
   blocks external requests or disables avatars. Expose the scstudio_resolve_token filter.
4. InputHasher per §9.1, including settings_fingerprint and
   resolved_font_fingerprint (accept the font fingerprint as an injected value for
   now; M3 will supply it).
5. CardRecord + CardRepository: read/write _scstudio_card and companion meta, compute
   staleness, register all post meta with correct show_in_rest and auth_callback
   values per §5.2.

Acceptance:
- Every hostile-input test rejects with a clear error and no PHP warning.
- Changing a post title changes the hash; changing an unrelated setting does not.
- Token resolution is covered by unit tests including the empty-collapse behaviour.
```

### M3 — Fonts and text layout *(Phase 1 — the hard one)*

```
Implement font resolution and the shared text layout engine. Re-read SPEC.md §6 in
full, twice. This module determines whether the plugin's output looks designed or
looks generated.

Depends on: M2.

Build:
1. FontResolver implementing the five-step chain in §6.1 exactly: explicit setting →
   theme.json/Font Library → theme adapter (ship a Blocksy adapter behind
   scstudio_theme_font_adapter) → bundled OFL families → script-coverage fallback.
   - Resolve file:./ sources against the theme directory.
   - Detect WOFF/WOFF2 and skip them with a recorded reason — GD and Imagick cannot
     rasterise them. Do not attempt to use them.
   - Only fetch remote webfonts when the admin has enabled "Allow downloading
     webfonts" (default off). Cache to uploads/social-card-studio/fonts/.
   - Every step records why it won or was skipped, for the Diagnostics screen.
   - Produce a stable font fingerprint for the input hash.
2. Bundle Inter (400/600/700), Source Serif 4 (400/700) and JetBrains Mono (500),
   subsetted to Latin + Latin-Ext, with their OFL licence files committed.
3. Font upload handling: .ttf/.otf only, validated by magic bytes, 5 MB cap,
   manage_options only, stored outside the year/month tree. Reject .woff2 with a
   message explaining why.
4. TextLayout implementing §6.3 precisely: normalisation (including the emoji
   policy), binary-search autofit to 0.5px, greedy word wrap, grapheme-safe breaking
   of over-long words, max_lines + ellipsis, ascender-based baseline placement.
   Measurement is abstracted behind a MetricsProvider so Imagick and GD both plug in.
5. Script detection and the §6.2 policy: if the text needs complex shaping (Arabic,
   Persian, Urdu, Devanagari) and the environment cannot shape it, throw a typed
   UnsupportedScriptException. Do not attempt a broken render. Simple RTL is
   right-aligned with Intl bidi when available.
6. The identical layout algorithm in JavaScript (editor/src/layout.js) for the DOM
   preview, plus a shared JSON fixture file both implementations consume.

Acceptance:
- A parity test renders the fixture set through PHP and through the JS engine (node)
  and asserts identical line counts and identical chosen font sizes.
- A 40-word title, a single 30-character unbroken word, an all-caps title and a
  title with HTML entities all lay out sensibly at every preset box size.
- An Arabic title raises UnsupportedScriptException on a host without shaping,
  rather than producing reversed or disconnected glyphs.
- Diagnostics shows the resolved font per role and the winning chain step.
```

### M4 — Renderers and optimiser *(Phase 1)*

```
Implement the two server-side renderers and the output optimiser. Re-read SPEC.md
§2.3, §4.2–§4.4, §7.2.

Depends on: M2, M3.

Build:
1. Renderer interface, RendererFactory (Imagick ≥ 6.9 with FreeType preferred, GD
   fallback, neither → typed exception).
2. ImagickRenderer and GdRenderer. Both consume the same CardDocument and the same
   TextLayout output; only drawing primitives differ. Implement per §4.3: gradients,
   rounded rect and circle masks, drop shadow (GD: offset blurred copy, 3-pass box
   blur), cover/contain image fitting, tint and grayscale.
3. ImageSource: load a featured image or uploaded file, downscale to at most 2× the
   destination box before compositing, honour EXIF orientation, strip metadata,
   convert to sRGB.
4. Memory guard: before compositing, check headroom against WP_MEMORY_LIMIT; below
   64 MB, degrade (skip blur, skip shadow, downscale further) and log a
   degraded_render event.
5. Optimizer per §7.2: JPEG progressive 4:2:0, quality ladder 82→74→66→58, target
   600 KB, hard ceiling 1 MB, discard above 5 MB. WebP and AVIF are never produced
   for the social image — filter image_editor_output_format for our writes only,
   never globally.
6. The legibility helper: sample the region under a text layer, compute WCAG
   contrast against the text colour, and deepen a gradient scrim in steps until it
   reaches 4.5:1 or hits its cap. (AI backgrounds depend on this in M11; templates
   with photo backgrounds need it now.)

Acceptance:
- Render budget met: ≤800 ms and ≤96 MB peak for a 1200×630 card over a 2000 px
  background, on PHP 8.1.
- The same Card Document rendered by Imagick and by GD differs by less than 3% on a
  perceptual diff.
- A 6000 px camera JPEG as background does not exhaust memory on a 128 MB host.
- Every output file is JPEG, sRGB, under 1 MB, with no EXIF.
```

### M5 — Storage and templates *(Phase 1)*

```
Implement the storage layer and the preset templates. Re-read SPEC.md §5.3, §7.1,
§7.3, §18.1.

Depends on: M4.

Build:
1. CardStore: write to uploads/social-card-studio/{year}/{month}/card-{id}-{hash8}.jpg,
   multisite-aware via wp_upload_dir(), URL derived at read time (never stored
   absolute in a way that breaks on migration). Grace retention: retire the previous
   file rather than deleting it, purge after scstudio_grace_days (default 30) on a daily
   task. Expose scstudio_card_dir, scstudio_card_url and the scstudio_card_written action.
2. MediaLibraryBridge for the opt-in media_library_mode: create the attachment,
   return [] from intermediate_image_sizes_advanced for our attachments, hide them
   from the library grid (ajax_query_attachments_args and pre_get_posts), exclude
   them from core and SEO image sitemaps, set _wp_attachment_image_alt.
3. Alt text generation per §7.3, from alt_text_pattern, stored on the record and
   editable.
4. Lifecycle hooks per §18.1: trash, permanent delete, delete_attachment,
   switch_theme, settings update, daily cleanup.
5. TemplateRegistry plus SIX presets as JSON Card Documents, all GD-renderable:
   - editorial-left      photo background, dark scrim, large left headline, eyebrow term, byline
   - bold-statement      solid brand colour, oversized centred headline, logo bottom-left
   - split-frame         50/50 image and colour panel, headline in the panel
   - minimal-serif       near-white ground, serif headline, thin accent rule, small logo
   - author-feature      circular avatar, author name and byline, headline right
   - brand-statement     the homepage/site default card: site name, tagline, logo
   Each must survive a 1-word title and a 40-word title without redesign.

Acceptance:
- Regenerating a card produces a new filename and the old file survives the grace
  window.
- Deleting a post removes every file, variant, attachment and event row for it.
- All six presets render correctly on both engines across the M3 fixture titles.
- Switching media_library_mode on and off does not break existing cards.
```

### M6 — Delivery *(Phase 1)*

```
Implement og:image delivery, SEO plugin integration, the fallback chain and the lazy
endpoint. Re-read SPEC.md §10, §12.1–§12.2.

Depends on: M5.

Build:
1. MetaTags: our own og:* and twitter:* output per §10.1, on singular views and the
   front page, guarded by scstudio_output_own_tags and suppressed when an SEO integration
   is active. Everything escaped.
2. SeoIntegration interface plus six adapters: Yoast, Rank Math, SEOPress, All in One
   SEO, The SEO Framework, Slim SEO. Each must implement has_manual_image() by
   reading that plugin's own meta key — this is what makes override_auto correct.
   Detect at plugins_loaded; exactly one active, in the priority order in §10.2.
3. The three priority modes (gap_fill default, override_auto, always) plus the
   per-post _scstudio_disabled opt-out honoured in all three. The settings UI must state
   plainly that gap_fill rarely fires because SEO plugins fall back to the featured
   image, and recommend override_auto for most sites.
4. Fallback per §12.1: all seven steps, each verified to exist before use. Never emit
   an unverified URL. Expose scstudio_fallback_chain.
5. The lazy endpoint per §10.3: REST route, pretty rewrite, query fallback,
   publish/visibility validation, transient lock against scraper stampedes, 5 s render
   budget, redirect-to-fallback on failure, per-IP rate limit with 429 + Retry-After,
   immutable cache headers.
6. The subtlety in §10.3: once a card file exists, og:image emits the STATIC file
   URL. The endpoint URL is emitted only for posts with no card yet.

Acceptance:
- Integration matrix passes: {no image, manual image, featured image, nothing} ×
  {gap_fill, override_auto, always} × six SEO plugins, plus the none-installed case.
- Three concurrent requests to the endpoint for an ungenerated post produce exactly
  one render.
- A post with a card serves a static immutable URL; the endpoint sees no traffic.
- Every failure mode redirects to a real image; nothing ever 500s.
```

### M7 — Triggers, scheduling and backfill *(Phase 1)*

```
Implement generation triggers. Re-read SPEC.md §11, §9.2, §9.3.

Depends on: M6.

Build:
1. Scheduler wrapping Action Scheduler, deferring to an already-loaded copy
   (WooCommerce) when present.
2. OnPublish: transition_post_status and save_post → enqueue, never render inline.
   Skip autosaves, revisions, bulk edit, meta-only REST updates, and _scstudio_disabled
   posts. This is a release blocker: prove with a timing test that activating the
   plugin does not measurably slow a post save.
3. CardRepository::ensure() as the single idempotent entry point for all four
   triggers, keyed on the input hash, with per-post+size locking so a lazy request
   during a scheduled job does not double-render.
4. Regeneration policy per §9.2: the auto_template_manual_ai default, plus auto_all
   and manual_all. AI-sourced cards never regenerate silently under the default.
5. Backfill: admin screen with filters (post type, date range, only-missing /
   only-stale / all), dry run reporting counts and (later) cost, Action Scheduler
   batches of 10 at 1/minute, resumable, cancellable, live progress, per-batch memory
   guard, results log.
6. Staleness surfacing per §9.3: post list column with thumbnail and status badge,
   sortable and filterable, bulk "Regenerate social card" action, and a "Needs
   attention" summary.

Acceptance:
- Publishing a post with the plugin active is not measurably slower than without it.
- A 500-post backfill completes within memory and time budgets on a 128 MB host and
  survives being cancelled and resumed.
- Changing a post title marks the card stale and regenerates it under the default
  mode; an AI card only goes stale.
```

### M8 — Editor panel *(Phase 1)*

```
Implement the block editor sidebar panel. Re-read SPEC.md §14, §2.3.

Depends on: M7, and the JS layout engine from M3.

Build:
1. A @wordpress/scripts build (editor/ → build/), registered with
   wp_set_script_translations.
2. PluginDocumentSettingPanel per §14: preview with status pill, template dropdown,
   headline field with a character counter warning past ~70, collapsible subhead,
   Generate button, alt text field, and an Advanced section (per-post disable,
   download, copy URL).
3. DomPreviewRenderer: render the Card Document as absolutely-positioned DOM at 0.5
   scale using the M3 JS layout engine, updating live as the author types (debounced
   250 ms).
4. On Generate: call POST /scstudio/v1/preview, discard the DOM preview, display the real
   server-rendered image. On "Use this": POST /scstudio/v1/commit writes the meta
   immediately — do not wait for the post save.
5. REST PreviewController and CommitController with real permission callbacks and
   nonces.
6. A classic-editor metabox with the same fields minus the live preview.
7. Leave a clearly-marked slot for the AI section; M11 fills it.

Acceptance:
- The panel never blocks publishing; a missing card is a status, not an error.
- The DOM preview's line breaks match the server render for every M3 fixture title.
- Panel is keyboard navigable, RTL-correct, and all strings are translatable.
- Closing the browser after "Use this" but before saving the post does not lose the
  card.
```

### M9 — Admin, CLI, i18n, release *(Phase 1 — closes v1.0)*

```
Complete v1.0. Re-read SPEC.md §12.3–§12.4, §15, §16, §17, §18, §19, §21.

Depends on: M8.

Build:
1. All admin screens per §15: Settings, Templates gallery (server-rendered
   thumbnails), Bulk generate, Diagnostics (now complete, including font chain and
   SEO detection), and the post list column.
2. Notices per §12.3: dismissible per user and per error signature, capability-gated,
   never nagging daily for the same problem.
3. Multisite per §16: network settings with lock toggles, per-blog uploads,
   switch_to_blog safety, the shared events table with blog_id.
4. i18n per §17: full string coverage, RTL admin CSS, localised numbers and dates,
   WPML/Polylang per-language cards.
5. Compatibility per §18.2: sitemap exclusion, offload filters, migration-safe URLs.
6. WP-CLI per §21: generate, regenerate, doctor, purge, test-render.
7. All extensibility hooks in the §21 table, each with a docblock and a usage example
   in a docs/hooks.md file.
8. The test suites in §19.1–§19.3, including the golden-image visual regression
   harness (10 titles × 6 presets × 2 engines, 1.5% perceptual tolerance) wired into
   CI.
9. readme.txt for WordPress.org: description, FAQ, screenshots, changelog, and an
   honest "Requirements and limitations" section covering GD vs Imagick, emoji and
   complex scripts.

Acceptance:
- The §19.4 manual scraper checklist passes: Facebook, X, LinkedIn, WhatsApp
  (Android + iOS), Slack, Telegram, Discord, iMessage — for a fresh card, a
  regenerated card and a disabled card.
- The §19.5 environment matrix passes, including the neither-GD-nor-Imagick host.
- PHPCS, PHPStan level 6 and the full test suite are green.
- Plugin Check (the official .org tool) reports no errors.
```

### M10 — AI core and copy mode *(Phase 2 — v1.1)*

```
Implement the AI foundation and copy mode only. No image generation in this module.
Re-read SPEC.md §13.1 (copy), §13.2–§13.6, §13.8.

Depends on: M9.

Build:
1. AiManager, disabled by default, behind the SCSTUDIO_DISABLE_AI kill switch.
2. Consent per §13.4: a screen that cannot be bypassed, naming the provider and
   endpoint, showing what data leaves the site, stating that generation costs money,
   linking to the provider's policies, and stating log retention. The payload preview
   is a real feature — pick any post, see the literal JSON that would be POSTed.
3. Data scope setting: title_only | title_excerpt (default) | title_excerpt_body with
   N words (default 300). Never the full body by default.
4. Sanitizer per §13.5 steps 1–4: strip shortcodes and HTML, remove base64 and stray
   URLs, redact emails, phone numbers and 12+ digit runs plus an admin regex list
   (count the redactions and show them in the preview), and abort on the site block
   list with a `blocked` event.
5. AiProvider interface plus OpenAI, Gemini and Anthropic text adapters. Model IDs
   are configuration with a bundled manifest, not hardcoded constants.
6. Key storage per §13.3: sodium_crypto_secretbox keyed from AUTH_KEY/SECURE_AUTH_SALT,
   manage_options only, never returned by REST, always masked. The settings UI must
   state in one sentence that this is obfuscation and not real security.
7. Guardrails per §13.6: the scstudio_generate_ai capability mapped to edit_others_posts,
   preview-before-save which is not configurable, and a 20/hour per-user counter that
   is a runaway-loop guard only — it must not be labelled or presented as a quota.
8. The workspace spend budget, per §13.9 in full. This is the money guardrail and the
   most correctness-sensitive code in the module:
   - BudgetLedger over the non-autoloaded `scstudio_ai_budget` option: 30 day-buckets keyed
     in the site timezone via wp_timezone(), reservations, grants and threshold
     timestamps. Every read-modify-write happens under GET_LOCK with a compare-and-swap
     fallback where GET_LOCK is unavailable. Two concurrent calls must never both pass
     a check that only one fits under.
   - reserve() before the provider call using AiProvider::estimate_cost(), then
     settle() with the real figure from reported token usage, or release(). Reservations
     expire on a TTL so a fatal mid-call cannot sequester budget permanently.
   - The billable table in §13.9.2 exactly: a discarded-but-successful generation and a
     provider-flagged result both count; transport failures release; cache hits never
     reserve. Behind the scstudio_budget_is_billable filter.
   - PriceBook reading per-model prices from the assets/ai-models.json manifest, with
     admin overrides for enterprise rates.
   - Three exhaustion behaviours by caller context per §13.9.4: hard stop with a
     structured 403 for interactive routes, silent degrade-to-template plus a `capped`
     event for the on-publish job, and warn-then-run-until-dry for backfill.
   - Grants per §13.9.5: bounded top-up with an expiry, manage_options only, audited.
     There must be no code path that disables the limiter outright.
   - Surfacing per §13.9.6: remaining budget in the editor panel beside the AI buttons,
     budget/spent/remaining plus a burn-down on the usage dashboard, and 50/80/100%
     notices reusing the §12.3 dismissal machinery.
   - The settings UI must state in one sentence that these are estimates from a bundled
     price list and will drift from the provider's invoice. Do not imply it is a
     billing control.
   - WP-CLI: wp scstudio budget status | grant <amount> [--days=] | reset.
9. Copy mode: prompt builder producing a ≤70-char headline, optional ≤90-char
   subhead, optional 1–3 hashtags and an alt-text sentence. Results land in the
   editor's review strip; nothing is applied until the author clicks "Use this".
10. EventLog + the usage dashboard (§13.7) and retention/purge/GDPR handling (§13.8).
   Raw prompts and post content are never stored — only a prompt hash. The budget adds
   `budget_grant` and `budget_threshold` types and writes status `capped` on denial;
   no schema change is needed.

Acceptance:
- With AI disabled, the plugin makes zero outbound requests. Verify with a network
  monitor, not by reading the code.
- The payload preview matches byte-for-byte what is actually sent.
- A provider outage degrades to the template card with a logged event and a clear
  editor message — never a broken card and never a fatal.
- Concurrency: 20 parallel generations against a budget with room for 3 settle exactly
  3 and deny 17. Test against real MySQL — a mocked lock proves nothing about the
  thing the lock exists for.
- Rolling window: with the site timezone set to Africa/Kampala, day 1's spend stops
  counting at the start of day 31 in that timezone, and the same holds across a DST
  transition in a DST-observing zone.
- Reservation lifecycle: a process killed mid-call leaves a reservation that is pruned
  at TTL and does not permanently reduce available budget; a timeout releases
  immediately; a successful-but-discarded generation does not release.
- Degradation with the budget exhausted: publishing still produces a template card and
  a `capped` event, the editor's AI buttons are disabled with a readable reason, and a
  backfill of 100 posts completes with every post carrying a card.
- Estimate honesty: for a scripted run of known calls, the dashboard's spend figure
  matches hand-computed manifest prices exactly, so any drift from a real invoice is
  attributable to the price list and not to the arithmetic.
- Manual: set the budget to $0.02, run one copy-mode generation, and confirm the
  second is refused with a message a non-technical site owner can act on.
```

### M11 — AI image modes *(Phase 3 — Pro)*

```
Implement AI background and full-image generation. Re-read SPEC.md §13.1
(background, full), §13.2, §13.5 steps 5–6, §9.2.

Depends on: M10, and the legibility helper from M4.

Build:
1. Background mode: image providers (OpenAI, Gemini, Replicate/Stability), six style
   presets (editorial photo, flat vector, abstract gradient mesh, duotone brand,
   hand-drawn, isometric tech), brand accent injection, and a negative prompt that
   always suppresses text, letters, watermarks and logos.
2. The legibility guard — mandatory, not optional. After generation, sample the
   region under each text layer, compute contrast, and deepen the scrim in steps
   until it reaches 4.5:1 or hits its cap. An AI background that makes the headline
   unreadable must never ship.
3. Full-image mode with the §13.1 gate: a one-time per-site acknowledgement that AI
   image text is frequently misspelled, mandatory preview, and exclusion from
   auto-regeneration under every regeneration_mode.
4. Result handling per §13.5 step 6: download via wp_safe_remote_get, validate by
   getimagesize and magic bytes, cap at 10 MB, re-encode before use, never trust
   as-is. A provider-flagged result is discarded and never attached.
5. AI alt text: ask the provider for a one-sentence description of the generated
   image; fall back to the pattern if unavailable.
6. Editor: the three AI buttons with honest one-line descriptions and a visible cost
   or credit count, a review strip with regenerate and discard, and per-post prompt
   overrides stored in _scstudio_overrides.

Acceptance:
- A deliberately low-contrast generated background still produces a readable card.
- A flagged or failed generation costs the author nothing further and leaves the
  existing card untouched.
- No AI card is ever regenerated without a human click, under any setting.
```

### M12 — Pro packaging *(Phase 3 — Pro)*

```
Build the commercial tier. Re-read SPEC.md §1.3, §7.1, §13.3, and the Pro rows
throughout.

Depends on: M11.

Build:
1. Freemius SDK integration: licensing, activation, updates, the free/Pro boundary as
   a single capability gate rather than scattered conditionals. The opt-in prompt
   must be skippable and the free plugin must be fully functional if skipped.
2. The visual template builder: a layer-based editor over the Card Document schema —
   add/remove/reorder layers, drag and resize boxes with snapping to the safe area,
   typography and colour controls, token picker, live DOM preview, save as a custom
   template. It writes the same validated JSON the presets use; no new schema.
3. Multi-size variants: 1080×1080 square and 1000×1500 Pinterest, rendered from
   size-specific layout overrides in the Card Document. Only the og size is ever
   emitted in head tags; the others get a copy button and a REST endpoint.
4. WooCommerce product cards: the price/badge/rating tokens from §8 and a
   product-price preset.
5. The credits proxy client: HMAC-signed with the Freemius licence key, site URL and
   timestamp; returns image bytes and the remaining balance. The site's own API key
   is never sent to the proxy. Precedence setting: prefer credits / prefer my key /
   credits only when key fails.
6. Premium template packs as a registerable bundle.

Acceptance:
- Deactivating the licence degrades to free behaviour cleanly — existing Pro cards
  keep working, Pro features become unavailable, nothing breaks.
- A builder-created template passes the same strict schema validation as a preset,
  and a hostile template JSON is rejected identically.
- Credit balance, spend and errors reconcile between the proxy and the local event
  log.
```

---

## Appendix A — Design prompts for the six presets

Use these with a design tool or an image model to produce reference comps before
writing the preset JSON. They describe layout intent, not final pixels.

```
Design a 1200x630 social share card for a WordPress blog. «PRESET DESCRIPTION».
Constraints: 56px side margins and 48px top/bottom safe area; the headline must stay
readable at 300px wide (thumbnail size in a feed); maximum three lines of headline;
one accent colour plus one neutral; no decorative element may sit under the headline;
assume the headline may be anywhere from 3 to 40 words. Deliver light and dark
variants. Show the layout with a 1-word headline and with a 40-word headline so the
composition is proven at both extremes.
```

Substitute for `«PRESET DESCRIPTION»`:

1. **editorial-left** — full-bleed photo background, dark gradient scrim from the bottom-left, large left-aligned headline sitting on the lower third, a small uppercase category eyebrow above it, author name and reading time on one line below.
2. **bold-statement** — no photograph. Solid brand-colour field, oversized centred headline as the only content, logo bottom-left, thin accent rule bottom-right.
3. **split-frame** — vertical 50/50 split: image on the right, solid colour panel on the left holding the eyebrow, headline and byline.
4. **minimal-serif** — near-white ground, large serif headline, a 2px accent rule above it, small logo top-left, date bottom-right. Generous whitespace.
5. **author-feature** — circular author avatar at 160px on the left, name and byline beneath it, headline right-aligned in the remaining two-thirds, subtle brand-tinted background.
6. **brand-statement** — the homepage card: site name in the display weight, tagline beneath, logo, brand gradient ground.

---

## Appendix B — Built-in AI prompt templates

These ship inside the plugin as the prompts it sends. They are product content, not
build instructions. Every placeholder is filled by the sanitised payload (§13.5).

**Copy mode (text model)**

```
You write social share headlines for a website called {{site_name}}.

Article title: {{title}}
Article summary: {{excerpt}}

Write a share headline that would make someone stop scrolling and click, and an
optional supporting line.

Rules:
- Headline: maximum 70 characters. No clickbait, no false promises, no "you won't
  believe". State the actual value of the article.
- Subhead: maximum 90 characters, or omit it if the headline stands alone.
- Match the article's language and register. Do not add facts the article does not
  contain.
- Also write a one-sentence alt text describing the card for a screen reader.

Return JSON only: {"headline": "...", "subhead": "...", "hashtags": ["..."], "alt": "..."}
```

**Background mode (image model)**

```
{{style_preset_description}}. Subject matter evoking: {{title}}.
Colour direction: built around {{brand_accent}}, with deep shadow areas in the
lower-left third of the frame.
Composition: 1200x630 landscape. Keep the lower-left third visually quiet and
low-detail — text will be placed there. No focal subject in that region.
Absolutely no text, letters, numbers, words, watermarks, signatures, logos or
UI elements anywhere in the image.
```

Style preset descriptions:

- **editorial photo** — "A photographic image with shallow depth of field and natural directional light, muted and desaturated, in the style of a broadsheet feature photograph"
- **flat vector** — "A flat vector illustration with bold geometric shapes, no gradients, no outlines, a limited four-colour palette"
- **abstract gradient mesh** — "A soft abstract gradient mesh with organic flowing colour transitions and gentle grain, no recognisable objects"
- **duotone brand** — "A high-contrast duotone photographic image using only two colours, heavy shadow, strong graphic silhouette"
- **hand-drawn** — "A loose hand-drawn ink illustration with visible line texture and a single spot colour, on warm off-white paper"
- **isometric tech** — "A clean isometric technical illustration with subtle ambient occlusion, a restrained palette, and a soft neutral ground"

**Full-image mode** appends:

```
This image is a complete social share card and should read as a designed graphic.
```

…with the §13.1 acknowledgement shown to the author first: *text inside AI-generated
images is frequently misspelled — review before publishing.*
