<?php

defined('ABSPATH') || exit;

/**
 * Core logic for the WooCommerce Order Status Limiter.
 *
 * Intercepts manual order status changes in wp-admin and blocks transitions
 * that are not permitted for the current user's role.
 */
class OSL_Limiter
{
    public const OPTION_ENABLED = 'wicket_admin_settings_woo_order_status_limits_enabled';
    public const OPTION_EXEMPT = 'wicket_admin_settings_woo_order_status_exempt_roles';
    public const OPTION_MESSAGE = 'wicket_admin_settings_woo_order_status_block_message';
    public const OPTION_RULES = 'wicket_admin_settings_woo_order_status_rules_json';


    /**
     * Whether the settings section has already been added via wicket_settings_tabs.
     * Used to prevent the deprecated fallback filter from duplicating it.
     */
    private static bool $settings_section_added = false;

    /**
     * Register all operational hooks.
     * Called at init priority 1 — wicket_get_option is available here.
     */
    public static function init(): void
    {
        // Always register UI hooks regardless of the enabled toggle.
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_assets']);
        add_action('admin_notices', [__CLASS__, 'render_error_notice'], 20);

        // WPSettings::save() calls $option->implementation->sanitize($value) which bypasses
        // our 'sanitize' arg entirely. WordPress also adds magic_quotes to all $_POST data,
        // so the JSON arrives slashed ({"from":"x"} becomes {\"from\":\"x\"}). This filter
        // runs after the library's sanitize and fixes both issues.
        add_filter('wp_settings_new_options_' . self::OPTION_RULES, [__CLASS__, 'filter_save_rules_json']);

        if (!self::is_enabled()) {
            return;
        }

        // Intercept quick-mark "Mark as X" links from the order list.
        // These are page navigations (not AJAX), so we redirect back on block.
        add_action('wp_ajax_woocommerce_mark_order_status', [__CLASS__, 'check_ajax_status_change'], 1);

        // Intercept order edit page save — priority 1 runs before WC_Meta_Box_Order_Data::save
        // at priority 40, so our $_POST overwrite is read by WooCommerce. Works for both
        // legacy CPT and HPOS: in both cases WC reads $_POST['order_status'] at priority 40.
        add_action('woocommerce_process_shop_order_meta', [__CLASS__, 'check_order_edit_save'], 1);

        // Intercept bulk status changes before WooCommerce processes them.
        add_action('load-edit.php', [__CLASS__, 'intercept_bulk_status_changes']);
        add_action('load-woocommerce_page_wc-orders', [__CLASS__, 'intercept_bulk_status_changes']);
    }

    // -------------------------------------------------------------------------
    // Wicket Settings integration (mirrors wicket-wp-guest-checkout pattern)
    // -------------------------------------------------------------------------

    /**
     * Hook into wicket_settings_tabs to inject our section into the Integrations tab.
     * Wraps the existing callback so the original sections are preserved.
     *
     * @param array $tabs Tabs configuration array.
     * @return array
     */
    public static function extend_settings_tabs(array $tabs): array
    {
        foreach ($tabs as $priority => $config) {
            if (!is_array($config) || ('integrations' !== ($config['key'] ?? ''))) {
                continue;
            }

            $original_callback = $config['callback'] ?? null;
            self::$settings_section_added = true;

            $tabs[$priority]['callback'] = function ($tab) use ($original_callback): void {
                if (is_callable($original_callback)) {
                    call_user_func($original_callback, $tab);
                }
                self::add_settings_section($tab);
            };

            return $tabs;
        }

        return $tabs;
    }

    /**
     * Fallback for older base plugin versions that don't expose wicket_settings_tabs.
     * Skipped if extend_settings_tabs already ran.
     *
     * @param mixed $integrations_tab WPSettings tab instance.
     * @return mixed
     */
    public static function extend_settings_tab_fallback($integrations_tab)
    {
        if (self::$settings_section_added) {
            return $integrations_tab;
        }

        self::add_settings_section($integrations_tab);

        return $integrations_tab;
    }

    /**
     * Add the Order Status Limits section and its options to a tab object.
     *
     * @param mixed $tab WPSettings tab instance.
     */
    private static function add_settings_section($tab): void
    {
        if (!is_object($tab) || !method_exists($tab, 'add_section')) {
            return;
        }

        $section = $tab->add_section(__('WooCommerce Order Status Limits', 'wicket-osl'), [
            'as_link'     => true,
            'description' => __('Restrict which order status transitions staff can make manually in wp-admin. Automated workflows (AutomateWoo, scheduled actions) are never affected.', 'wicket-osl'),
        ]);

        self::register_settings($section);
    }

    // -------------------------------------------------------------------------
    // Settings
    // -------------------------------------------------------------------------

    /**
     * Register settings fields into the Wicket WooCommerce section.
     *
     * Called via the 'wicket_woocommerce_settings_section' action hook which is
     * fired from the Wicket base plugin's register_integrations_tab() method.
     *
     * @param mixed $section WPSettings Section instance.
     */
    public static function register_settings($section): void
    {
        $section->add_option('checkbox', [
            'name'        => self::OPTION_ENABLED,
            'label'       => __('Enable Order Status Change Limits', 'wicket-osl'),
            'description' => __('Restrict manual order status changes in wp-admin. Automated workflows (AutomateWoo, scheduled actions) are not affected.', 'wicket-osl'),
            'default'     => '0',
        ]);

        $section->add_option('text', [
            'name'        => self::OPTION_EXEMPT,
            'label'       => __('Exempt Roles', 'wicket-osl'),
            'description' => __('Comma-separated WP role slugs that bypass all status change limits (e.g. <code>administrator, shop_manager</code>). Roles are read live — new roles added via MDP sync are recognised immediately.', 'wicket-osl'),
            'default'     => 'administrator',
        ]);

        $section->add_option('textarea', [
            'name'        => self::OPTION_MESSAGE,
            'label'       => __('Blocked Status Change Message', 'wicket-osl'),
            'description' => __('Admin notice displayed when a user attempts a blocked status transition.', 'wicket-osl'),
            'default'     => __('You do not have permission to change the order status in this way.', 'wicket-osl'),
        ]);

        // Custom-rendered field: FROM/TO blocked-transitions table.
        // Label and description are handled inside render_rules_field (the <tr> wrapper).
        $section->add_option('textarea', [
            'name'     => self::OPTION_RULES,
            'label'    => '',
            'sanitize' => [__CLASS__, 'sanitize_rules_json'],
            'render'   => [__CLASS__, 'render_rules_field'],
        ]);
    }

    /**
     * Called via wp_settings_new_options_{OPTION_RULES} filter after the library sanitizes.
     * Unslashes the magic-quoted POST value then runs our JSON sanitizer.
     *
     * @param string $value Value after sanitize_textarea_field (still has WP magic-quote slashes).
     * @return string Clean JSON string.
     */
    public static function filter_save_rules_json(string $value): string
    {
        return self::sanitize_rules_json(wp_unslash($value));
    }

    /**
     * Sanitize the rules JSON value before saving.
     *
     * @param string $value Raw POST value.
     * @return string Clean JSON string, or empty string if invalid.
     */
    public static function sanitize_rules_json(string $value): string
    {
        if (empty($value)) {
            return '';
        }

        $decoded = json_decode(wp_unslash($value), true);

        if (!is_array($decoded)) {
            return '';
        }

        $clean = [];
        foreach ($decoded as $rule) {
            if (empty($rule['from']) || empty($rule['to'])) {
                continue;
            }
            $clean[] = [
                'from' => sanitize_key($rule['from']),
                'to'   => sanitize_key($rule['to']),
            ];
        }

        return wp_json_encode($clean);
    }

    /**
     * Render the custom FROM/TO rules table field.
     *
     * Must return a <tr> so it fits inside the <table><tbody> that WPSettings
     * renders around all section options (see section.php view).
     *
     * @param mixed $impl WPSettings option implementation instance.
     */
    public static function render_rules_field($impl): string
    {
        $name = $impl->get_name_attribute();
        $value = wp_unslash($impl->get_value_attribute() ?: '');
        $label = esc_html__('Blocked Transition Rules', 'wicket-osl');
        $description = esc_html__('Each row defines a blocked FROM → TO status transition for non-exempt users.', 'wicket-osl');

        ob_start();
        ?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label><?php echo $label; ?></label>
			</th>
			<td class="forminp">
				<style>
					#osl-rules-table th,
					#osl-rules-table td { padding: 8px 10px; }
					#osl-rules-table select { min-width: 180px; }
					#osl-rules-table th:last-child,
					#osl-rules-table td:last-child { width: 1%; white-space: nowrap; }
				</style>
				<div id="osl-rules-table-wrap">
					<table id="osl-rules-table" class="widefat striped" style="max-width:600px;">
						<thead>
							<tr>
								<th><?php esc_html_e('From Status', 'wicket-osl'); ?></th>
								<th><?php esc_html_e('To Status', 'wicket-osl'); ?></th>
								<th></th>
							</tr>
						</thead>
						<tbody id="osl-rules-tbody">
							<tr class="osl-loading-row">
								<td colspan="3"><?php esc_html_e('Loading&hellip;', 'wicket-osl'); ?></td>
							</tr>
						</tbody>
					</table>
					<p style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
						<button type="button" id="osl-add-rule" class="button">
							<?php esc_html_e('+ Add Rule', 'wicket-osl'); ?>
						</button>
						<button type="button" id="osl-export-rules" class="button">
							<?php esc_html_e('Export JSON', 'wicket-osl'); ?>
						</button>
						<button type="button" id="osl-import-rules" class="button">
							<?php esc_html_e('Import JSON', 'wicket-osl'); ?>
						</button>
						<input type="file" id="osl-import-file" accept=".json" style="display:none;">
					</p>
				</div>
				<textarea
					id="osl-rules-json"
					name="<?php echo esc_attr($name); ?>"
					rows="1"
					style="display:none;"
				><?php echo esc_textarea($value); ?></textarea>
				<p class="description"><?php echo $description; ?></p>
			</td>
		</tr>
		<?php
        return ob_get_clean();
    }

    /**
     * Enqueue the admin JS on the Wicket settings page only.
     *
     * @param string $hook Current admin page hook suffix.
     */
    public static function enqueue_admin_assets(string $hook): void
    {
        if (($_GET['page'] ?? '') !== 'wicket-settings') {
            return;
        }

        wp_enqueue_script(
            'wicket-osl-admin',
            OSL_PLUGIN_URL . 'assets/js/osl-admin.js',
            [],
            OSL_VERSION,
            true
        );

        $statuses = [];
        if (function_exists('wc_get_order_statuses')) {
            foreach (wc_get_order_statuses() as $key => $label) {
                $statuses[preg_replace('/^wc-/', '', $key)] = $label;
            }
        }

        wp_localize_script('wicket-osl-admin', 'oslData', [
            'statuses'          => $statuses,
            'textRemove'        => __('Remove', 'wicket-osl'),
            'textImportError'   => __('Import failed: the file must be a valid JSON array of {from, to} rule objects.', 'wicket-osl'),
            'exportFilename'    => 'osl-rules.json',
        ]);
    }

    // -------------------------------------------------------------------------
    // Status change intercepts
    // -------------------------------------------------------------------------

    /**
     * Intercept the quick-mark "Mark as X" action from the order list.
     *
     * WooCommerce implements quick-marks as direct page navigations (not AJAX fetch),
     * so we redirect back with a transient error rather than sending JSON.
     * Fires at priority 1, before WooCommerce's own handler.
     */
    public static function check_ajax_status_change(): void
    {
        // Pass invalid-nonce requests through to WooCommerce's own nonce check.
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'woocommerce-mark-order-status')) {
            return;
        }

        if (self::current_user_is_exempt()) {
            return;
        }

        $order_id = absint($_GET['order_id'] ?? 0);
        $new_status = sanitize_key($_GET['status'] ?? '');

        if (!$order_id || !$new_status) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $old_status = $order->get_status();

        if (self::is_transition_blocked($order, $old_status, $new_status)) {
            self::set_error_transient(self::get_message());
            $redirect = wp_get_referer() ?: admin_url('edit.php?post_type=shop_order');
            wp_safe_redirect($redirect);
            exit;
        }
    }

    /**
     * Intercept the order edit page save for both legacy CPT and HPOS.
     *
     * Runs at priority 1, before WC_Meta_Box_Order_Data::save at priority 40.
     * WooCommerce reads $_POST['order_status'] inside that priority-40 handler, so
     * overwriting it here prevents the blocked status from ever being applied.
     *
     * @param int $order_id Order ID being saved.
     */
    public static function check_order_edit_save(int $order_id): void
    {
        if (self::current_user_is_exempt()) {
            return;
        }

        $new_status = sanitize_key($_POST['order_status'] ?? '');
        if (empty($new_status)) {
            return;
        }

        $new_status = preg_replace('/^wc-/', '', $new_status);

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $old_status = $order->get_status();

        if ($old_status === $new_status) {
            return;
        }

        if (self::is_transition_blocked($order, $old_status, $new_status)) {
            $_POST['order_status'] = 'wc-' . $old_status;
            self::set_error_transient(self::get_message());
        }
    }

    /**
     * Intercept bulk "Change status to X" actions before WooCommerce processes them.
     * Hooked to load-edit.php and load-woocommerce_page_wc-orders.
     */
    public static function intercept_bulk_status_changes(): void
    {
        if (self::current_user_is_exempt()) {
            return;
        }

        $action = sanitize_key($_REQUEST['action'] ?? '');
        if (strpos($action, 'mark_') !== 0) {
            return;
        }

        $new_status = substr($action, 5); // strip 'mark_' prefix

        // Collect order IDs from either the legacy or HPOS request param.
        $order_ids = array_map('absint', (array) ($_REQUEST['post'] ?? $_REQUEST['id'] ?? []));
        if (empty($order_ids)) {
            return;
        }

        foreach ($order_ids as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order) {
                continue;
            }

            if (self::is_transition_blocked($order, $order->get_status(), $new_status)) {
                self::set_error_transient(self::get_message());
                $redirect = wp_get_referer() ?: admin_url('edit.php?post_type=shop_order');
                wp_safe_redirect($redirect);
                exit;
            }
        }
    }

    /**
     * Display and clear any pending error transient.
     */
    public static function render_error_notice(): void
    {
        $user_id = get_current_user_id();
        $transient = 'osl_error_' . $user_id;
        $message = get_transient($transient);

        if (!$message) {
            return;
        }

        delete_transient($transient);

        echo '<div class="notice notice-error is-dismissible" style="border-left-width:6px;padding:16px 16px 16px 20px;">';
        echo '<p style="font-size:14px;font-weight:600;margin:0 0 4px;">';
        echo '<span style="margin-right:6px;">&#9888;</span>';
        esc_html_e('Order Status Change Blocked', 'wicket-osl');
        echo '</p>';
        echo '<p style="margin:0;">' . esc_html($message) . '</p>';
        echo '</div>';
    }

    // -------------------------------------------------------------------------
    // Rule evaluation
    // -------------------------------------------------------------------------

    /**
     * Determine whether a given status transition is blocked for the current order.
     *
     * @param WC_Abstract_Order $order      The order being modified.
     * @param string            $from       Current order status (no 'wc-' prefix).
     * @param string            $to         Requested new status (no 'wc-' prefix).
     * @return bool True if the transition should be blocked.
     */
    public static function is_transition_blocked($order, string $from, string $to): bool
    {
        $rules = self::get_blocked_rules($order);

        $is_blocked = false;
        foreach ($rules as $rule) {
            if (($rule['from'] ?? '') === $from && ($rule['to'] ?? '') === $to) {
                $is_blocked = true;
                break;
            }
        }

        /*
         * Override the block decision based on order meta or other conditions.
         *
         * @param bool              $is_blocked Whether the transition is currently blocked.
         * @param WC_Abstract_Order $order      The order being modified.
         * @param string            $from       Current status slug.
         * @param string            $to         Requested status slug.
         */
        return (bool) apply_filters('wicket_order_status_limit_blocked', $is_blocked, $order, $from, $to);
    }

    /**
     * Return the active blocked-rules array.
     *
     * @param WC_Abstract_Order|null $order Order context passed to the filter.
     * @return array<int, array{from: string, to: string}>
     */
    public static function get_blocked_rules($order = null): array
    {
        $saved = wicket_get_option(self::OPTION_RULES, '');
        $rules = [];

        if (!empty($saved)) {
            $decoded = json_decode(wp_unslash($saved), true);
            if (is_array($decoded)) {
                $rules = $decoded;
            }
        }

        /*
         * Modify the blocked-rules array.
         *
         * @param array                  $rules Blocked transition rules [{from, to}, ...].
         * @param WC_Abstract_Order|null $order Order context, or null when called outside order context.
         */
        return (array) apply_filters('wicket_order_status_limit_rules', $rules, $order);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Whether the feature is enabled.
     */
    private static function is_enabled(): bool
    {
        return wicket_get_option(self::OPTION_ENABLED) === '1';
    }

    /**
     * Whether the current user has a role that bypasses all limits.
     */
    private static function current_user_is_exempt(): bool
    {
        $raw = wicket_get_option(self::OPTION_EXEMPT, 'administrator');
        $exempt = array_map('trim', explode(',', $raw));
        $user = wp_get_current_user();

        foreach ($user->roles as $role) {
            if (in_array($role, $exempt, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return the configured error message.
     */
    private static function get_message(): string
    {
        $msg = wicket_get_option(self::OPTION_MESSAGE, '');

        return $msg ?: __('You do not have permission to change the order status in this way.', 'wicket-osl');
    }

    /**
     * Store an error message in a short-lived transient for the current user.
     *
     * @param string $message Error message text.
     */
    private static function set_error_transient(string $message): void
    {
        set_transient('osl_error_' . get_current_user_id(), $message, 30);
    }
}
