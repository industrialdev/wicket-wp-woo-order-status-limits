# Repository Guidelines

## What This Plugin Is

WordPress/WooCommerce plugin that restricts manual order status changes in wp-admin based on configurable rules and user role exemptions. Staff members without exempt roles cannot move orders through blocked transitions. Automated workflows (AutomateWoo, WooCommerce Subscriptions, REST API) are never affected — only manual changes made by logged-in admin users are intercepted. Settings register inside the Wicket Base Plugin's Settings -> Integrations tab.

For architecture (plugin structure, boot sequence, interception points, settings integration, rule evaluation, admin JS, constants, filter reference and examples): `@see atlas://packages/wicket-wp-woo-order-status-limits.md`.

## Development Commands

```bash
composer install
composer cs:lint       # check code style (dry-run)
composer cs:fix        # auto-fix code style
composer check         # alias for cs:lint
composer version-bump  # preview only — releases are automated
composer production    # install without dev dependencies, optimized autoloader
```

## Coding Standards

- PHP 8.0+ minimum; PSR-12 enforced by PHP-CS-Fixer (run `composer cs:lint` before committing).
- No namespace — procedural entry point plus a single static class (`OSL_Limiter`); the class loads via direct `require_once`, no Composer autoloader.
- WordPress escaping and redirect conventions for HTML output (`esc_html()`, `esc_attr()`, `esc_textarea()`, `wp_safe_redirect()`).
- Text domain: `wicket-osl`.

## Security

- Nonce verification on the quick-mark intercept; invalid nonces pass through to WooCommerce's own handler.
- All `$_GET`/`$_POST`/`$_REQUEST` values sanitized (`sanitize_key()`, `absint()`, `sanitize_text_field()`); rules JSON is decoded, each rule sanitized, then re-encoded before storage.
- Transients are user-scoped (`osl_error_{user_id}`) to prevent cross-user leakage.

## Release & Branch Workflow

All work happens on branches. `main` is locked; changes land via peer-reviewed Pull Request. Never commit to `main` directly, and never push or open a PR without explicit human approval.

Merging a PR to `main` **auto-releases** via the `wicket-release-bot` GitHub App: version bump, `CHANGELOG.md` update, git tag. Never bump versions or create tags by hand. The bump level comes from a marker in the PR title (squash-merge makes it the commit message): _(none)_ / `#patch` = patch, `#minor`, `#major`, or `#norelease` (no release; use for docs/tooling-only merges). Conventional commit prefixes (`feat:`, `fix:`, `docs:`, ...) drive changelog grouping; a `!` (e.g. `feat!:`) flags a BREAKING change.
