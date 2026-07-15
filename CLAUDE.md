# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A WordPress plugin (**HLB MCP Abilities**) that exposes a curated, admin-controlled set of
WordPress **Abilities** to the [MCP Adapter](https://github.com/WordPress/mcp-adapter) so
third-party tools/agents can drive the site over MCP. Multisite-ready and network-activatable.
Requires WordPress 6.9+ (Abilities API in core), PHP 7.4+, and the MCP Adapter plugin.

## Commands

There is **no PHPUnit suite**; quality is gated by coding-standards lint plus runtime
verification against a disposable WordPress.

```bash
composer install          # install dev tooling (coding-standards ruleset / PHPCS)
composer lint             # phpcs — MUST pass clean (0 errors/0 warnings) before committing
composer lint:fix         # phpcbf — auto-fix formatting (short arrays, spacing, docblocks)
vendor/bin/phpcs inc/class-registry.php   # lint a single file
```

- The plugin ships as `hlb-mcp-abilities.php` + `uninstall.php` + `inc/`. `vendor/` and
  `composer.lock` are git-ignored — Composer is dev-only tooling, never a runtime dependency.
- PHP syntax check: use `/opt/homebrew/bin/php -l <file>` (the shell's `php` alias may point at
  a stale MAMP path).

### Runtime verification (the only way to truly test this plugin)

The plugin's core wiring (hook timing, ability registration, MCP server creation) only exists
inside a real WordPress with the Abilities API **and** the MCP Adapter active. The
`@wp-playground/cli` boot fails in this environment, so use a **throwaway Docker WordPress**:

1. Spin up a disposable MariaDB + a named volume, download WP core on the host (wp-cli's
   extractor OOMs), copy it into the volume **mounted at `/var/www/html`** (a fresh volume
   mounted anywhere else is root-owned and copies fail), `wp config create` + `wp core install`.
2. Copy this plugin + the MCP Adapter into `wp-content/plugins`, `wp plugin activate` both.
3. Drive assertions with `wp eval-file` against a probe script that fires `init` +
   `rest_api_init`, then checks: abilities register (`wp_get_ability(...)` non-null), a
   default-off write ability stays unregistered, an ability `execute()`s, and
   `rest_get_server()->get_routes()` contains `/hlb_<host>/mcp`.

This exact flow (and a working probe) is what caught the WP 7.0 category bug below.

## Architecture

Three separate systems, wired together by this plugin:

- **Abilities API** (WP core) — `wp_register_ability()` on `wp_abilities_api_init` defines
  *what the site can do*.
- **MCP Adapter** — `create_server()` on `mcp_adapter_init` *projects* selected abilities onto
  an MCP REST endpoint.
- **This plugin** — owns the ability registry, the settings UI, and the enabled-set resolver.

### The single-resolver invariant (most important)

`Settings::enabled_ids()` is the **one** function that decides which abilities are live. It is
called by **both** `Abilities` (which `wp_register_ability()`s each enabled id) **and** `Server`
(which passes the same ids to `create_server()`). Never compute the enabled set anywhere else —
if registration and the server tool list diverge, the adapter logs `ability '…' does not exist`
and exposes broken tools.

### Registry as source of truth

`inc/class-registry.php` is a declarative catalogue: one entry per ability (id, label,
description, category, capability, `default`, annotations, `input_schema`, `handler`, optional
`condition`). It drives the settings UI, the registration loop, and the server tool list.
Handlers (`inc/handlers/*`) are **thin adapters over core WP APIs** — no business logic. To add
an ability: add a registry entry + a handler method; everything else follows automatically.

Defaults policy: read-only abilities default **on**, write/destructive default **off**.
`condition` (e.g. WooCommerce active) gates whether an ability is even available on a site.

### Multisite settings model

- Network default: `hlb_mcp_network` site-option (`update_site_option`), also holds the
  `network_mode` flag.
- Per-site: `hlb_mcp_site` option with an `override` flag.
- Resolution: on multisite, a subsite inherits the network default unless `override` is set;
  single-site uses its own option. Always intersected with the currently-*available* registry
  ids, so a stale/renamed/inactive id can never be registered.
- `uninstall.php` must clear **both** the network option and every per-blog option row.

### Network mode (multisite)

Default model = **one MCP server per subsite**, each acting only on its own site. Optionally,
`network_mode` (network-admin toggle) makes the **main site's** server able to target any subsite:
`Abilities::build_args()` adds an optional `site` property to every ability's input schema (except
`hlb/list-sites`) and wraps both the permission and execute callbacks in
`switch_to_blog( resolve_blog_id( $input['site'] ) )` / `restore_current_blog()`. The
`hlb/list-sites` ability (available only in network mode on the main site) lets agents discover
subsites. Capability checks run **inside** the switched context, so a non-member/non-superadmin is
correctly denied on the target subsite. `Abilities::network_context()` gates all of this
(`is_multisite() && is_main_site() && Settings::is_network_mode()`); when off, the `site` arg and
`hlb/list-sites` do not exist.

### Server naming

`Settings::server_slug()` produces the MCP server id / REST namespace:
`hlb_{domain}` for single/main site, `hlb_{maindomain}_{subsiteslug}` for a subsite (main
network domain + subdomain-label-or-path). Endpoint: `/wp-json/{slug}/mcp`. Overridable via the
`hlb_mcp_server_slug` filter.

### Dependency handling

`inc/class-dependency.php` detects three states (active / installed-inactive / not-installed) and
offers a one-click admin action. The MCP Adapter is **not on wordpress.org**, so "install"
sideloads its GitHub release ZIP (`releases/latest/download/mcp-adapter.zip`, unpacks to
`mcp-adapter/mcp-adapter.php`) via `Plugin_Upgrader`; network-activates when this plugin is
network-active. The plugin never fatals when the adapter is absent — abilities still register,
only the MCP server is skipped.

### Class loading

Custom autoloader in the bootstrap file maps `HLB\MCP\Foo_Bar` → `inc/class-foo-bar.php` and
`HLB\MCP\Handlers\Content` → `inc/handlers/class-content.php`. By convention, the
bootstrap (`hlb-mcp-abilities.php`) is **not** namespaced; all namespaced classes live in `inc/`.

## Gotchas (learned from real runtime failures — don't reintroduce)

- **WP 7.0 requires a `description` on ability categories.** `wp_register_ability_category()`
  silently fails without it, which cascades: every ability in that category bails with "category
  not registered" and the MCP server exposes zero working tools. Always pass `label` + `description`.
- **Read handlers must do per-object capability checks.** A coarse `permission_callback` like
  `current_user_can('read')` does *not* gate unpublished content — `get_post()` returns drafts.
  Read handlers re-check (`read_post`, or force public statuses for non-editors) so a
  low-privilege caller can't read drafts/private posts by ID.
- **Hook timing:** abilities register on `wp_abilities_api_init` (fired lazily on first registry
  access after `init`); the server is created on `mcp_adapter_init`. The resolver is read at both
  points — abilities must be registered before the server resolves its tool ids.

## Coding standard

[`humanmade/coding-standards`](https://github.com/humanmade/coding-standards), enforced by `phpcs.xml.dist`: short array syntax
(`[]`), **no Yoda conditions**, tabs, full docblocks, `inc/` for namespaced code. Run
`composer lint` before every commit and keep it at zero.
