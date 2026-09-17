# Changelog

All notable changes to this project are documented here.

This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.9.0] — 2026-09-17

First public release.

### Added
- Self-updating through WordPress's built-in `update_plugins_{$hostname}`
  mechanism, pointed at this repository's releases. No update library.
- `pbcc/bypass_cookie` filter, so the uncached-preview cookie can be pointed at
  whatever name a given host's page cache actually matches.

### Changed
- Documentation rewritten for general use rather than one specific fleet.

## [0.8.1] — 2026-09-17

### Fixed
- **Bricks 2.4 compatibility.** Bricks moved the toolbar into a new
  `#bricks-workspace` and replaced the `#bricks-toolbar` id with a
  `.bricks-toolbar` class, so the mount selector matched nothing and no controls
  appeared. The stylesheet was prefixed with the same id, so even a successful
  mount would have rendered unstyled. Both now accept either form, and the CSS
  uses `:is(#bricks-toolbar, .bricks-toolbar)` to keep id-level specificity
  against the builder's own rules. One build covers 2.3 and 2.4.

## [0.8.0] — 2026-09-14

### Added
- Settings screen field dependency: consolidation requires the admin bar menu,
  enforced in the getter, the save handler and the UI. A disabled checkbox
  submits nothing, so the save handler cannot simply read the field.

## [0.7.0]

### Added
- Optional consolidation of other cache plugins' admin bar menus into one
  **Cache Control** entry, by re-parenting rather than rewriting. Reversible.

### Changed
- Admin bar menu and settings screen both renamed to **Cache Control**.

## [0.6.0]

### Changed
- Introduced `Controls\Catalog` as the single answer to which controls apply,
  and reduced surfaces to rendering what they are handed.

### Fixed
- The admin bar menu computed its own control list and disagreed with the
  builder: it hid the entire menu unless Cloudflare was configured, taking the
  uncached-preview link with it — though that link depends on the origin page
  cache and needs no Cloudflare at all.

## [0.5.0]

### Added
- Optional **Cache Control** menu in the WordPress admin bar.
- Shared clipboard helper that races the async Clipboard API against a timeout
  and falls back to a selection copy. In an unfocused document `writeText()` can
  neither resolve nor reject, which previously left a menu item reading
  "Working" permanently.

### Changed
- The uncached-preview control now leads the builder toolbar.

## [0.4.0]

### Added
- Settings screen. Constants defined in `wp-config.php` take precedence over
  stored values and render their field as locked rather than being silently
  ignored.

## [0.3.0]

### Added
- Cloudflare provider talking to the API directly, so it neither needs nor
  conflicts with any Cloudflare plugin. Zone id is discovered from the site's own
  domain and cached.
- Automatic Cloudflare purge on content change.

### Notes
- Auto-purge needs two separate signals. Core post hooks cover the block and
  classic editors but never fire for Bricks: its `bricks_save_post` AJAX handler
  writes post meta directly and contains no `do_action` calls at all. Builder
  saves are matched by AJAX action name instead, flushing on `shutdown` — which
  still runs after `wp_send_json` because WordPress registers it as a PHP
  shutdown function.

## [0.2.0]

### Added
- Uncached-preview links: a signed, short-lived URL that sets a cache-bypass
  cookie on whichever browser opens it, so a private window can read past the
  page cache while real visitors keep getting cached pages.

## [0.1.0]

Initial build: purge controls for Nginx Helper and Redis Object Cache mounted
into the Bricks builder toolbar.

### Notes
- Nginx Helper availability is read from the live `$nginx_helper_admin->options`
  global rather than the stored option row. On hosts that configure the plugin
  through `wp-config.php` constants the stored row is stale, reporting purging as
  disabled while it is actually enabled — which would hide the control on exactly
  the hosts where it works.
