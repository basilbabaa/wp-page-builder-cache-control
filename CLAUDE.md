# WP Page Builder Cache Control

Read `README.md` first (289 lines), then `CHANGELOG.md`.

A WordPress plugin that puts cache-purge controls (Nginx Helper, Redis, and Cloudflare) inside page builders that hide the WordPress admin bar. Public repo: github.com/basilbabaa/wp-page-builder-cache-control. Current release v0.9.0, which updates itself from the GitHub release. Build a zip with the script in `bin/` (output goes to `dist/`).

**State (2026-10-03):** tested on smiletallahassee.com.
- **Open:** estuaryfarms.com needs a manual 0.9.0 install.
- **Open:** Cloudflare edge purge is not implemented yet.

**Git:** the history had Claude trailers stripped and was force-pushed on 2026-10-03. Don't pull or merge from an old clone. A local-only tag `pre-public-history` keeps 15 early commits. Never add Co-Authored-By trailers.
