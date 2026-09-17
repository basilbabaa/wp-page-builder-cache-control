# WP Page Builder Cache Control

Cache purge controls inside page builders that hide the WordPress admin bar.

Bricks takes over the full viewport and suppresses the admin bar, so the purge
menus contributed by caching plugins are unreachable without leaving the
builder. This plugin puts equivalent controls into the builder's own toolbar,
and optionally consolidates the scattered cache menus in the admin bar into one.

Supports Nginx Helper, Redis Object Cache and Cloudflare out of the box. Other
caches and other builders plug in through filters.

## Why not just force the admin bar back on

Because it fights the builder and loses. Bricks loads twice — an outer app shell
and an iframe rendering your actual page — and the admin bar is `position: fixed`
with an `html { margin-top: 32px }` offset. Re-enabling it lands on top of the
builder's own toolbar and shifts the canvas, misrepresenting the design you are
editing. Mounting a couple of buttons into the toolbar is far less invasive.

## Install

Download the zip from [Releases](../../releases) and upload it via
**Plugins → Add Plugin → Upload Plugin**.

Once installed, the plugin updates itself through the normal WordPress update
flow — it uses core's `update_plugins_{$hostname}` mechanism pointed at this
repository's releases. No update library, no license key, no phone-home beyond
one cached call to the GitHub releases API.

## What you get

**In the builder toolbar** — one button per cache that is actually present, plus
a button that copies an uncached-preview link.

**In the admin bar** *(optional)* — a **Cache Control** menu, which can also
adopt the menus other cache plugins add so one entry replaces several.

**Automatically** — Cloudflare purges the changed URL when you save, including
saves made inside the builder.

Controls only appear when they can do something. No Cloudflare token means no
Cloudflare button; no object cache drop-in means no flush button. Nothing is
shown that would fail if you clicked it.

## Architecture

Three pieces, each independently extensible.

**Providers** (`src/Providers/`) — a cache backend. Implements `Provider`:
`id()`, `label()`, `icon()` (inline SVG), `is_available()`, `purge()`.

**Catalog** (`src/Controls/`) — the single answer to *which controls apply on
this site right now*. `Catalog::all()` returns everything applicable;
`Catalog::some( [ ... ] )` returns a named subset in order, dropping whatever
does not apply.

**Surfaces** (`src/Surfaces/`, plus the admin bar menu) — somewhere controls are
drawn. A surface decides only *how* to render what the Catalog hands it, never
which controls exist.

Adding a cache touches no builder code. Adding a builder touches no cache code.

> Surfaces must not compute their own control list. The admin bar menu did once
> and immediately disagreed with the builder: it hid the whole menu unless
> Cloudflare was configured, taking the preview link with it — though that link
> depends on the origin page cache and works with no Cloudflare at all.

## Bundled providers

| Provider | Backend | Detected by | Purge |
| --- | --- | --- | --- |
| `cloudflare` | Cloudflare edge | API token configured | `POST /zones/{id}/purge_cache` |
| `nginx` | Nginx Helper | `Nginx_Helper` + live `enable_purge` | `do_action( 'rt_nginx_helper_purge_all' )` |
| `redis` | Redis Object Cache | `WP_REDIS_VERSION` + `wp_using_ext_object_cache()` | `wp_cache_flush()` |

### Nginx Helper on managed hosts

Availability is read from the live `$nginx_helper_admin->options` global, not
from the stored `rt_wp_nginx_helper_options` row.

On hosts that configure Nginx Helper through `wp-config.php` constants — GridPane
does this — the stored row is stale and misleading. It can read
`enable_purge = 0` and `cache_method = enable_fastcgi` while the plugin is
actually running with purging enabled over Redis, because the constants are
merged in at runtime and force `enable_purge` on. Trusting the row hides the
control on exactly the hosts where it works.

## Uncached preview links

The leading toolbar button solves a problem the purge buttons do not: checking
your work in a second browser session while the page cache still serves the
cached copy to everyone else.

Many nginx page-cache configurations skip the cache for any request carrying a
`wordpress_no_cache` cookie — **whether or not the visitor is signed in**. So
rather than disabling caching site-wide, the button mints a signed, short-lived
URL that sets that cookie on whichever browser opens it. Paste it into a private
window and that window reads past the cache for its whole session. Everyone else
keeps getting cached pages.

```
/?pbcc-nocache=<expires>.<hmac>   → sets cookie, 302s to the clean URL
/?pbcc-nocache-off=1              → clears it again
```

- Signed with `hash_hmac( 'sha256', ..., wp_salt( 'auth' ) )`, **10 minutes** to
  redeem. Forged and expired tokens are ignored — no cookie, no error.
- Cookie lifetime is bounded (default 2h, clamped 5 min–24 h) so a bookmarked
  link cannot leave a browser skipping the cache forever.
- Redemption responds `X-Robots-Tag: noindex, nofollow` and redirects to the
  clean URL, so the token never lingers in the address bar or the referrer.

An unauthenticated URL that makes a site skip its cache is a soft
denial-of-service vector if it leaks into a sitemap or a forwarded email.
Signing plus a bounded lifetime is what makes it safe to hand around.

### If your host uses a different cookie

`wordpress_no_cache` is the common default but not universal. Point the plugin
at whatever your cache actually matches:

```php
add_filter( 'pbcc/bypass_cookie', fn () => 'my_host_no_cache' );
```

Getting this wrong fails **silently** — the cookie is set, the cache ignores it,
and pages simply look stale. Confirm against your own config before trusting it.

**Not offered:** a site-wide "cache off" switch. Disabling a page cache for
anonymous traffic generally needs a server config change and a reload, which is
not reachable from PHP. The cookie covers the real use case without it.

## Cloudflare

Talks to the Cloudflare API directly, so it neither needs nor conflicts with any
Cloudflare plugin.

Create a token scoped to **Zone → Cache Purge → Purge** and nothing else. A
purge-only token is a nuisance if it leaks, not a compromise. Do not reuse a
token that also manages firewall rules, zone settings or R2.

The **zone id is discovered automatically** from the site's own domain and cached
for a week, so there is nothing per-site to configure beyond the token. It walks
up from the host (`shop.example.com` → `example.com`) to find the registrable
domain. Override with `PBCC_CF_ZONE_ID` if needed.

**Test Cloudflare connection** on the settings page resolves the zone live and
reports either the matched zone id or the actual API error.

### Auto-purge

On by default once a token is present. Purges the changed post's permalink plus
the front page — not the whole zone. URLs are collected during the request and
sent as one API call on `shutdown`.

**Page builders need their own signal.** Bricks' `bricks_save_post` AJAX handler
writes post meta directly and fires *no actions at all* — there is no
`do_action` anywhere in its `ajax.php` — so `post_updated` and
`transition_post_status` never fire on a builder save. Builder saves are matched
by AJAX action name instead:

```php
add_filter( 'pbcc/cloudflare/builder_actions', function ( array $actions ): array {
    $actions['some_builder_save'] = 'postId'; // action => post id parameter
    return $actions;
} );
```

This works because WordPress registers `shutdown` as a PHP shutdown function, so
it still runs after `wp_send_json` ends the AJAX request.

### Edge HTML caching

Cloudflare does not cache HTML by default (`cf-cache-status: DYNAMIC`), which is
what lets the uncached-preview link work — the cookie reaches the origin because
Cloudflare forwards the request there.

Enable "Cache Everything" or APO and Cloudflare answers HTML from the edge, the
cookie never reaches the origin, and **the preview link silently stops working**.
Worth knowing before turning either on.

## Admin bar menu

Optional. Adds a **Cache Control** menu with a Cloudflare purge and the preview
link. Each item appears on its own merits — the Cloudflare item needs a token,
the preview link needs a page cache — and the menu only disappears when neither
applies.

It deliberately does not mirror the builder toolbar. Nginx Helper and Redis
Object Cache already contribute their own admin bar menus, so this one carries
only what has no other presence there.

### Consolidating other cache menus

A second option re-parents the menus other cache plugins add, so one **Cache
Control** entry replaces several scattered across the bar.

```php
add_filter( 'pbcc/adopt_nodes', function ( array $ids ): array {
    $ids[] = 'some-other-cache-plugin-node';
    return $ids;
} );
```

Nothing is rewritten or removed. `WP_Admin_Bar::add_node()` merges into a node
that already exists — *"keep any data that isn't provided"* — so passing only an
id and a new parent preserves the other plugin's title, href and meta, and its
children reference it by id so the whole submenu travels with it. Switch the
option off and everything returns to the top level.

This runs at `admin_bar_menu` priority **999**, after other plugins have
registered. Children render in the order nodes were *first inserted*, not the
order they are re-parented, so adopted menus lead regardless.

## Settings

**Settings → Cache Control.** Shows which caches are detected, and configures
Cloudflare, the admin bar menu and the preview window.

**Constants beat the database.** Define one in `wp-config.php` and the matching
field renders as locked rather than being silently ignored — so a fleet
provisioned from a template stays authoritative while a one-off site can be
configured entirely through the UI.

| Setting | Constant |
| --- | --- |
| Cloudflare API token | `PBCC_CF_TOKEN` |
| Zone ID | `PBCC_CF_ZONE_ID` |
| Auto-purge | `PBCC_CF_AUTO_PURGE` |
| Admin bar menu | `PBCC_ADMIN_BAR` |
| Consolidate other cache menus | `PBCC_ADOPT_MENUS` |

The token is **write-only in the UI**: the stored value is never rendered back
into the form, only its presence is reported, and submitting an empty field
keeps the existing token rather than clearing it. It is stored with
`autoload = no`, and `uninstall.php` removes it with the plugin.

## Extending

```php
// A cache backend.
add_filter( 'pbcc/providers', fn ( array $p ) => [ ...$p, new My_Cache_Provider() ] );

// A builder UI.
add_filter( 'pbcc/surfaces', fn ( array $s ) => [ ...$s, new My_Builder_Surface() ] );

// Loosen the capability (defaults to manage_options).
add_filter( 'pbcc/capability', fn () => 'edit_posts' );

// React to any purge.
add_action( 'pbcc/purged', function ( string $id, array $result ): void {
    error_log( sprintf( '%s: %s', $id, $result['message'] ) );
}, 10, 2 );
```

## Bricks integration notes

- Mount point is `.group-wrapper.end` inside the toolbar — semantic and stable,
  rather than reaching into Bricks' Vue internals as some plugins do.
- Bricks **2.4** moved the toolbar into `#bricks-workspace` and replaced the
  `#bricks-toolbar` id with a `.bricks-toolbar` class. Both forms are matched, so
  one build covers 2.3 and 2.4.
- Gated on `bricks_is_builder() && ! bricks_is_builder_iframe()`. Bricks loads
  twice and the toolbar exists only in the outer shell.
- Tooltips use Bricks' native `data-balloon` attributes.
- Feedback (busy / success / error) is self-contained CSS on the icon, so it
  ports to another builder unchanged.

> When diagnosing a broken mount, read the rendered DOM, not the minified
> bundle. Bricks 2.4 composes toolbar classes dynamically, so grepping the
> source for a literal class string reports it as missing when it is present.

## Development

```bash
./bin/build.sh     # version-numbered zip in dist/, read from the plugin header
```

The build refuses to run when the header `Version:` and `PBCC_VERSION` disagree —
they are edited by hand, and the constant is what busts cached assets. Bump both.

## Requirements

WordPress 6.4+, PHP 7.4+.

## License

GPL-2.0-or-later.
