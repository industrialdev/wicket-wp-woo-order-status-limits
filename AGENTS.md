## Project Overview

WordPress/WooCommerce plugin that restricts manual order status changes in wp-admin based on configurable rules and user role exemptions. Staff members without exempt roles cannot move orders through blocked transitions. Automated workflows (AutomateWoo, WooCommerce Subscriptions, REST API) are never affected — only manual changes made by logged-in admin users are intercepted.

This plugin integrates with the Wicket ecosystem by registering its settings inside the Wicket Base Plugin's **Settings → Integrations** tab.

## Development Commands

### Composer Scripts

```bash
# Install dev dependencies (php-cs-fixer)
composer install

# Code Quality
composer cs:lint     # Check code style (dry-run, no changes)
composer cs:fix      # Auto-fix code style issues
composer check       # Alias for cs:lint

# Version Bumping
composer version-bump          # Interactive — prompts for new version
# composer version-bump 1.1.0  # Pass version as argument (also supported)

# Production
composer production  # Install without dev dependencies, optimized autoloader
```

### Releasing a Version

```bash
composer version-bump          # Updates composer.json + plugin file header
git add composer.json wicket-wp-woo-order-status-limits.php
git commit -m "Bump version to X.Y.Z"
git tag X.Y.Z
git push && git push --tags
```

## Architecture

### Plugin Structure

```
wicket-wp-woo-order-status-limits/
├── wicket-wp-woo-order-status-limits.php   # Entry point: constants, settings hooks, osl_init()
├── includes/
│   └── class-osl-limiter.php               # All business logic (static class)
├── assets/
│   └── js/
│       └── osl-admin.js                    # Rules table UI (vanilla JS)
├── .ci/
│   └── version-bump.php                    # Version bump CLI script
├── composer.json
├── .php-cs-fixer.dist.php
└── .editorconfig
```

### Boot Sequence

1. **File load** — constants defined, `OSL_Limiter` class included, settings filters registered (`wicket_settings_tabs`, `wicket_settings_tab_int`)
2. **`init` priority 1** — `osl_init()` validates WooCommerce and Wicket Base Plugin are active, then calls `OSL_Limiter::init()`
3. **`OSL_Limiter::init()`** — registers UI hooks always (assets, notices, JSON save filter); registers interception hooks only if the feature is enabled

### Three Interception Points

| Hook | Priority | Mechanism |
|------|----------|-----------|
| `wp_ajax_woocommerce_mark_order_status` | 1 | Quick-mark "Mark as X" links in order list; redirects back on block |
| `woocommerce_process_shop_order_meta` | 1 | Order edit page save; overwrites `$_POST['order_status']` before WC reads it at priority 40 |
| `load-edit.php` / `load-woocommerce_page_wc-orders` | default | Bulk status change actions; redirects back on block |

### Settings Integration

The plugin uses the Wicket Base Plugin's WPSettings library. Two filters handle both old and new base plugin versions:

- **`wicket_settings_tabs`** (modern) — wraps the existing Integrations tab callback to append the OSL section
- **`wicket_settings_tab_int`** (fallback) — directly adds the section if the first filter didn't fire

The `$settings_section_added` flag prevents duplication if both filters fire.

### Rule Evaluation

`OSL_Limiter::is_transition_blocked($order, $from, $to)`:
1. Calls `get_blocked_rules($order)` — returns saved JSON rules if any, otherwise an empty array (no transitions blocked)
2. Iterates rules looking for a matching `from`/`to` pair
3. Applies `wicket_order_status_limit_blocked` filter — allows external override per order

### Error Display

Block events store a short-lived transient (`osl_error_{user_id}`, 30-second TTL). The `render_error_notice()` method reads and immediately deletes it, displaying a styled admin notice.

### Admin JS (`osl-admin.js`)

Vanilla JS with no build step. Receives WooCommerce order statuses via `wp_localize_script` as `window.oslData`. Renders an editable FROM/TO table, syncs changes to a hidden `<textarea>`, and handles import (FileReader) and export (Blob download).

## Constants

```php
OSL_VERSION                                              // Plugin version string
OSL_PLUGIN_DIR                                           // Absolute path to plugin directory (trailing slash)
OSL_PLUGIN_URL                                           // URL to plugin directory (trailing slash)

OSL_Limiter::OPTION_ENABLED   // 'wicket_admin_settings_woo_order_status_limits_enabled'
OSL_Limiter::OPTION_EXEMPT    // 'wicket_admin_settings_woo_order_status_exempt_roles'
OSL_Limiter::OPTION_MESSAGE   // 'wicket_admin_settings_woo_order_status_block_message'
OSL_Limiter::OPTION_RULES     // 'wicket_admin_settings_woo_order_status_rules_json'
```

All options are retrieved via `wicket_get_option()` (provided by the Wicket Base Plugin), not `get_option()`.

## Coding Standards

- **PHP 8.0+** minimum
- **PSR-12** enforced by PHP-CS-Fixer (run `composer cs:lint` before committing)
- **No namespace** — this plugin uses a procedural entry point and a single static class (`OSL_Limiter`), following the WordPress convention for simple plugins
- **No Composer autoloader** — the class is loaded with a direct `require_once`
- **WordPress coding conventions** for HTML output: `esc_html()`, `esc_attr()`, `esc_textarea()`, `wp_safe_redirect()`
- **Text domain**: `wicket-osl`

## Security

- **Nonce verification** — quick-mark intercept validates the `woocommerce-mark-order-status` nonce before acting; invalid nonces are passed through to WooCommerce's own handler
- **Capability check** — relies on WordPress's standard admin access controls (only users who can access wp-admin can reach these pages)
- **Sanitization** — all `$_GET`/`$_POST`/`$_REQUEST` values are sanitized (`sanitize_key()`, `absint()`, `sanitize_text_field()`)
- **JSON sanitization** — rules JSON is decoded, each rule is sanitized with `sanitize_key()`, then re-encoded before storage
- **Magic-quote handling** — the `wp_settings_new_options_*` filter unslashes WordPress's magic-quoted POST data before sanitizing JSON
- **Transients** are user-scoped (`osl_error_{user_id}`) to prevent cross-user leakage

## Filters Reference

### `wicket_order_status_limit_blocked`

Override whether a specific transition is blocked for a specific order.

```php
add_filter( 'wicket_order_status_limit_blocked', function( bool $is_blocked, $order, string $from, string $to ): bool {
    // Allow completed → refunded for subscription orders.
    if ( $order->get_meta( '_subscription_id' ) && $from === 'completed' && $to === 'refunded' ) {
        return false;
    }
    return $is_blocked;
}, 10, 4 );
```

### `wicket_order_status_limit_rules`

Modify the active rules array. Runs after saved rules are loaded.

```php
add_filter( 'wicket_order_status_limit_rules', function( array $rules, $order ): array {
    // Programmatically add a rule.
    $rules[] = [ 'from' => 'on-hold', 'to' => 'pending' ];
    return $rules;
}, 10, 2 );
```

## Common Modification Patterns

### Exempting a Role Programmatically

Instead of editing the settings UI, you can filter the exempt check:

```php
add_filter( 'wicket_order_status_limit_blocked', function( $is_blocked, $order, $from, $to ) {
    if ( current_user_can( 'manage_woocommerce' ) ) {
        return false; // shop managers bypass all limits
    }
    return $is_blocked;
}, 10, 4 );
```

### Allowing a Transition Only for Specific Order Meta

```php
add_filter( 'wicket_order_status_limit_blocked', function( $is_blocked, $order, $from, $to ) {
    if ( $from === 'completed' && $to === 'refunded' && $order->get_meta( '_approved_for_refund' ) ) {
        return false;
    }
    return $is_blocked;
}, 10, 4 );
```

### Replacing All Rules Dynamically

```php
add_filter( 'wicket_order_status_limit_rules', function( $rules, $order ) {
    // Replace all saved rules with a custom set.
    return [
        [ 'from' => 'completed', 'to' => 'cancelled' ],
    ];
}, 10, 2 );
```

## WordPress / WooCommerce Integration Notes

- **HPOS** — uses `wc_get_order()` and `$order->get_status()` throughout; no direct `get_post_meta()` calls
- **WPSettings library** — settings are registered via the WPSettings API bundled with the Wicket Base Plugin; option names follow the `wicket_admin_settings_*` convention
- **`wicket_get_option()`** — used instead of `get_option()` for all option reads; this wrapper is provided by the Wicket Base Plugin
