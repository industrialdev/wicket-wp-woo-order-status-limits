# Wicket WooCommerce Order Status Limits

Restricts manual WooCommerce order status changes in wp-admin based on configurable rules and user role exemptions. Automated workflows (AutomateWoo, scheduled actions) are never affected.

## Features

- **Three interception points** — blocks status changes made via the order list quick-mark links, the order edit page, and bulk actions
- **Role-based exemptions** — configure which WP roles bypass all limits (default: `administrator`)
- **Configurable rules** — define which FROM → TO transitions are blocked via a visual table UI
- **Import / export** — export your rules as a JSON file and import them on other sites
- **Automated workflows unaffected** — only manual admin changes are intercepted; AutomateWoo, WooCommerce Subscriptions scheduled actions, and REST API calls are not blocked
- **HPOS-compatible** — works with both legacy CPT-based orders and WooCommerce High-Performance Order Storage
- **Custom error message** — configure the notice shown to blocked users
- **Developer filters** — override block decisions and modify rules programmatically

## Requirements

- WordPress 6.0+
- WooCommerce 8.0+
- PHP 8.0+
- [Wicket Base Plugin](https://github.com/industrialdev/wicket-wp-base-plugin)

## Installation

### Via Bedrock / Composer (recommended)

Add the repository to your Bedrock `composer.json`:

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "git@github.com:industrialdev/wicket-wp-woo-order-status-limits"
    }
  ]
}
```

Then require the plugin:

```bash
composer require industrialdev/wicket-wp-woo-order-status-limits
```

### Manual

1. Clone or download this repository into `wp-content/plugins/wicket-wp-woo-order-status-limits/`
2. Activate the plugin in **Plugins → Installed Plugins**

## Configuration

1. Go to **Wicket → Settings → Integrations**
2. Find the **WooCommerce Order Status Limits** section
3. Enable the feature with the **Enable Order Status Change Limits** checkbox
4. Optionally set **Exempt Roles** — comma-separated role slugs that bypass all limits (e.g. `administrator, shop_manager`)
5. Optionally customize the **Blocked Status Change Message** shown to blocked users
6. Configure **Blocked Transition Rules** using the FROM → TO table (see defaults below)

Changes take effect immediately on save. No cache flush required.

## Default Blocked Transitions

When no custom rules are saved, the following 30 transitions are blocked for non-exempt users:

| From        | To          |
|-------------|-------------|
| on-hold     | completed   |
| on-hold     | refunded    |
| cancelled   | pending     |
| cancelled   | processing  |
| cancelled   | completed   |
| cancelled   | refunded    |
| cancelled   | on-hold     |
| cancelled   | draft       |
| cancelled   | trash       |
| completed   | cancelled   |
| completed   | refunded    |
| completed   | on-hold     |
| completed   | pending     |
| completed   | processing  |
| completed   | draft       |
| completed   | trash       |
| processing  | cancelled   |
| processing  | refunded    |
| processing  | on-hold     |
| processing  | pending     |
| processing  | trash       |
| processing  | draft       |
| refunded    | on-hold     |
| refunded    | pending     |
| refunded    | processing  |
| refunded    | completed   |
| refunded    | cancelled   |
| refunded    | draft       |
| refunded    | trash       |
| pending     | completed   |
| pending     | refunded    |

Custom rules replace the defaults entirely — saving an empty table removes all restrictions.

## Hooks & Filters

### `wicket_order_status_limit_blocked`

Override the block decision for a specific order and transition. Return `true` to block, `false` to allow.

```php
add_filter( 'wicket_order_status_limit_blocked', function( $is_blocked, $order, $from, $to ) {
    // Allow VIP orders to bypass all limits.
    if ( $order->get_meta( '_is_vip_order' ) ) {
        return false;
    }
    return $is_blocked;
}, 10, 4 );
```

**Parameters:**
- `bool $is_blocked` — whether the transition is currently blocked by the rules
- `WC_Abstract_Order $order` — the order being modified
- `string $from` — current status slug (no `wc-` prefix)
- `string $to` — requested new status slug (no `wc-` prefix)

### `wicket_order_status_limit_rules`

Modify the active blocked-rules array dynamically.

```php
add_filter( 'wicket_order_status_limit_rules', function( $rules, $order ) {
    // Add an extra rule for a specific product type.
    if ( $order && $order->get_meta( '_contains_digital' ) ) {
        $rules[] = [ 'from' => 'processing', 'to' => 'on-hold' ];
    }
    return $rules;
}, 10, 2 );
```

**Parameters:**
- `array $rules` — array of `['from' => string, 'to' => string]` rule objects
- `WC_Abstract_Order|null $order` — order context, or `null` when called outside an order context

## Architecture

### Interception Points

The plugin hooks into three separate WooCommerce paths for manual status changes:

1. **Quick-mark links** (`wp_ajax_woocommerce_mark_order_status`, priority 1) — the "Mark as X" dropdown links in the order list. WooCommerce implements these as page navigations, so the plugin redirects back with a transient error on block.

2. **Order edit page** (`woocommerce_process_shop_order_meta`, priority 1) — fires before WooCommerce reads `$_POST['order_status']` at priority 40. The plugin overwrites the POST value with the original status, preventing the change from ever being applied.

3. **Bulk actions** (`load-edit.php` and `load-woocommerce_page_wc-orders`) — intercepts bulk "Change status to X" actions before WooCommerce processes the order list.

### Class Overview

**`OSL_Limiter`** (singleton-style static class in `includes/class-osl-limiter.php`)
- Registers settings in the Wicket Integrations tab
- Renders the rules table UI
- Intercepts all three status change paths
- Evaluates rules via `is_transition_blocked()` and `get_blocked_rules()`

**`osl_admin.js`** (`assets/js/osl-admin.js`)
- Powers the interactive FROM → TO rules table in settings
- Syncs table rows to a hidden `<textarea>` as JSON
- Handles import (file upload) and export (file download)

### Error Display

Blocked changes are communicated via WordPress transients. The plugin stores a user-specific transient (`osl_error_{user_id}`, 30-second TTL) on block, then displays it as an admin notice on the next page load and deletes it immediately after display.

## Development

### Setup

```bash
# Install dev dependencies (php-cs-fixer)
composer install
```

### Code Style

This project follows PSR-12 enforced by [PHP-CS-Fixer](https://cs.symfony.com/). Rules are defined in `.php-cs-fixer.dist.php`.

```bash
# Check for style violations (no changes)
composer cs:lint

# Auto-fix style violations
composer cs:fix
```

Run `composer cs:lint` before committing.

### Version Bumping

```bash
# Interactive — prompts for new version
composer version-bump

# Or pass the version directly
composer version-bump 1.1.0
```

This updates the version in both `composer.json` and the plugin file header, then runs `cs:fix`.

After bumping:

```bash
git add composer.json wicket-wp-woo-order-status-limits.php
git commit -m "Bump version to 1.1.0"
git tag 1.1.0
git push && git push --tags
```

### Production Build

```bash
# Remove dev dependencies and optimize autoloader
composer production
```

## Troubleshooting

**"Wicket WooCommerce Order Status Limits requires WooCommerce" notice**
WooCommerce must be installed and activated before this plugin runs.

**"Wicket WooCommerce Order Status Limits requires the Wicket Base Plugin" notice**
The [Wicket Base Plugin](https://github.com/industrialdev/wicket-wp-base-plugin) must be installed and activated.

**Status changes are being blocked for administrators**
Check the **Exempt Roles** setting — `administrator` should be listed. Role slugs are case-sensitive.

**AutomateWoo / scheduled actions are being blocked**
They should not be. The plugin only intercepts requests that originate from a logged-in admin user via the three wp-admin paths. Programmatic status changes via `$order->update_status()` are never intercepted.

**Settings section not appearing in Wicket → Integrations**
Confirm both WooCommerce and the Wicket Base Plugin are active. The section requires the Wicket Settings tab infrastructure.

## License

GPL-2.0-or-later — see [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html)
