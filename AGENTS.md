# Repository Guidelines

## Project Overview
WordPress/WooCommerce plugin that restricts manual order status changes in wp-admin based on configurable rules and user role exemptions. Automated workflows (AutomateWoo, WooCommerce Subscriptions, REST API) are never affected — only manual changes made by logged-in admin users are intercepted. Registers its settings inside the Wicket Base Plugin's **Settings → Integrations** tab. See `docs/engineering/architecture.md` for the full boot sequence and interception details.

## Project Structure & Module Organization
This is a WordPress plugin rooted at `wicket-wp-woo-order-status-limits.php`.
- `wicket-wp-woo-order-status-limits.php`: entry point — constants, settings hooks, `osl_init()`.
- `includes/class-osl-limiter.php`: all business logic (static class `OSL_Limiter`).
- `assets/js/osl-admin.js`: rules table UI (vanilla JS, no build step).
- `.ci/version-bump.php`: version bump CLI script, run via `composer version-bump`.
- `docs/`: see `docs/index.md` for the full doc set (product settings, architecture, hooks/filters, security).

## Build, Test, and Development Commands
- `composer install`: install PHP dependencies.
- `composer cs:lint`: style check (`php-cs-fixer --dry-run --diff`).
- `composer cs:fix` / `composer cs:format`: apply formatting.
- `composer check`: runs `cs:lint` (the pre-push gate).
- `composer production`: production install (`--no-dev`, optimized autoloader) before release tags.

## Coding Style & Naming Conventions
- PHP 8.0+ minimum, PSR-12 enforced by PHP-CS-Fixer.
- No namespace — procedural entry point plus a single static class (`OSL_Limiter`), the WordPress convention for simple plugins.
- No Composer autoloader — the class is loaded with a direct `require_once`.
- WordPress coding conventions for HTML output: `esc_html()`, `esc_attr()`, `esc_textarea()`, `wp_safe_redirect()`.
- Text domain: `wicket-osl`.

## Security & WordPress-Specific Requirements
- Sanitize, validate, and escape all input/output (`sanitize_key()`, `absint()`, `sanitize_text_field()`, `esc_html()`).
- Nonce verification on the quick-mark intercept; transients are user-scoped to prevent cross-user leakage.
- See `docs/engineering/security.md` for full details.

## Testing Guidelines
Testing is done inside wicket-warden repository. Create unit tests and/or integration tests as needed, for any new features or bug fixes.

## Commit & Pull Request Guidelines
- Keep commits focused; avoid mixed refactor/feature changes.
- PRs should include: purpose, risk notes, `composer check` output, and screenshots for settings UI changes.
- Link related issue/ticket and call out breaking or release-impacting changes.

## Release & Branch Workflow
All work happens on branches. `main` is locked; changes land via peer-reviewed
Pull Request (devs cross-review each other). Never commit to `main` directly, and never push or open a
PR without explicit human approval.

Merging a PR to `main` **auto-releases** via the `wicket-release-bot` GitHub
App: version bump, `CHANGELOG.md` update, git tag. Never bump versions or
create tags by hand. The bump level comes from a marker in the PR title
(squash-merge makes it the commit message): _(none)_ / `#patch` = patch, `#minor`,
`#major`, or `#norelease` (no release; use for docs/tooling-only merges).
Conventional commit prefixes (`feat:`, `fix:`, `docs:`, ...) drive changelog
grouping; a `!` (e.g. `feat!:`) flags a BREAKING change.

