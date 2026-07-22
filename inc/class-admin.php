<?php
/**
 * Admin settings UI for network and per-site ability configuration.
 *
 * @package HLB\MCP
 */

namespace HLB\MCP;

defined( 'ABSPATH' ) || exit;

/**
 * Renders both settings screens from one shared field renderer and handles saves.
 */
class Admin {

	const MENU_SLUG         = 'hlb-mcp-abilities';
	const NONCE_NET         = 'hlb_mcp_save_network';
	const ACTION_NET        = 'hlb_mcp_save_network';
	const SITE_OPTION_GROUP = 'hlb_mcp_site_group';

	/**
	 * Ability registry.
	 *
	 * @var Registry
	 */
	private $registry;

	/**
	 * Settings store.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Hook suffix of the per-site settings page, once registered.
	 *
	 * @var string
	 */
	private $site_page_hook = '';

	/**
	 * Hook suffix of the network settings page, once registered.
	 *
	 * @var string
	 */
	private $network_page_hook = '';

	/**
	 * Constructor.
	 *
	 * @param Registry $registry Ability registry.
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Registry $registry, Settings $settings ) {
		$this->registry = $registry;
		$this->settings = $settings;
	}

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'admin_menu', [ $this, 'register_site_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		if ( is_multisite() ) {
			add_action( 'network_admin_menu', [ $this, 'register_network_page' ] );
			add_action( 'admin_post_' . self::ACTION_NET, [ $this, 'handle_save_network' ] );
		}

		add_filter( 'plugin_action_links_' . HLB_MCP_BASENAME, [ $this, 'action_links' ] );
	}

	/* --------------------------------------------------------------- Menu registration */

	/**
	 * Register the per-site options page.
	 *
	 * @return void
	 */
	public function register_site_page() {
		$this->site_page_hook = add_options_page(
			__( 'HLB MCP Abilities', 'hlb-mcp-abilities' ),
			__( 'HLB MCP Abilities', 'hlb-mcp-abilities' ),
			'manage_options',
			self::MENU_SLUG,
			[ $this, 'render_site_page' ]
		);
	}

	/**
	 * Register the network settings page.
	 *
	 * @return void
	 */
	public function register_network_page() {
		$this->network_page_hook = add_submenu_page(
			'settings.php',
			__( 'HLB MCP Abilities', 'hlb-mcp-abilities' ),
			__( 'HLB MCP Abilities', 'hlb-mcp-abilities' ),
			'manage_network_options',
			self::MENU_SLUG,
			[ $this, 'render_network_page' ]
		);
	}

	/**
	 * Register the per-site option with the Settings API.
	 *
	 * Runs on every admin request (not just our own screens) because `options.php`
	 * itself fires `admin_init` when processing the save — the option must already be
	 * registered by then or it is silently rejected.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::SITE_OPTION_GROUP,
			Settings::SITE_OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_site_option' ],
				'default'           => [
					'override' => false,
					'enabled'  => [],
				],
				'show_in_rest'      => false,
			]
		);

		if ( ! is_multisite() ) {
			return;
		}

		add_settings_section( 'hlb_mcp_site_override', '', '__return_false', self::MENU_SLUG );
		add_settings_field(
			'hlb_mcp_site_override_field',
			__( 'Network defaults', 'hlb-mcp-abilities' ),
			[ $this, 'render_override_field' ],
			self::MENU_SLUG,
			'hlb_mcp_site_override'
		);
	}

	/**
	 * Enqueue the settings screen's stylesheet and script, only on our own pages.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		$our_hooks = array_filter( [ $this->site_page_hook, $this->network_page_hook ] );
		if ( ! in_array( $hook_suffix, $our_hooks, true ) ) {
			return;
		}

		wp_enqueue_style( 'hlb-mcp-admin', HLB_MCP_URL . 'assets/admin.css', [ 'dashicons' ], HLB_MCP_VERSION );
		wp_enqueue_script( 'hlb-mcp-admin', HLB_MCP_URL . 'assets/admin.js', [], HLB_MCP_VERSION, true );
		wp_localize_script(
			'hlb-mcp-admin',
			'hlbMcpAdminL10n',
			[
				/* translators: 1: number of matching abilities, 2: total number of abilities, 3: search term. */
				'searchStatus'  => __( '%1$d of %2$d abilities match "%3$s".', 'hlb-mcp-abilities' ),
				'searchCleared' => __( 'Showing all abilities.', 'hlb-mcp-abilities' ),
			]
		);
	}

	/**
	 * Add a Settings link on the plugins list.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		$url = admin_url( 'options-general.php?page=' . self::MENU_SLUG );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'hlb-mcp-abilities' ) . '</a>' );
		return $links;
	}

	/* --------------------------------------------------------------------- Page render */

	/**
	 * Render the network settings page.
	 *
	 * @return void
	 */
	public function render_network_page() {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage this network.', 'hlb-mcp-abilities' ) );
		}

		$enabled      = $this->settings->network_enabled_ids();
		$network_mode = $this->settings->is_network_mode();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'HLB MCP Abilities — Network Defaults', 'hlb-mcp-abilities' ); ?></h1>
			<p><?php esc_html_e( 'These defaults apply to every subsite that does not override them.', 'hlb-mcp-abilities' ); ?></p>
			<?php $this->maybe_updated_notice(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_NET ); ?>" />
				<?php wp_nonce_field( self::NONCE_NET ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Network mode', 'hlb-mcp-abilities' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="network_mode" value="1" <?php checked( $network_mode ); ?> />
								<?php esc_html_e( 'Let the main site\'s MCP server target any subsite via a "site" argument', 'hlb-mcp-abilities' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'When enabled, connect only the main site\'s endpoint to your MCP client. Every enabled ability gains an optional "site" argument (blog ID, path slug, or domain), and a "List network sites" ability lets agents discover subsites. Capabilities are still checked on the target subsite, so use a Super Admin credential.', 'hlb-mcp-abilities' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php $this->render_ability_tabs( $enabled, false ); ?>
				<?php submit_button( __( 'Save network defaults', 'hlb-mcp-abilities' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the per-site settings page.
	 *
	 * @return void
	 */
	public function render_site_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage this site.', 'hlb-mcp-abilities' ) );
		}

		$multisite = is_multisite();
		$override  = $this->settings->is_override();
		$site_cfg  = $this->settings->site_config();

		// What the checkboxes should reflect: site selection if overriding, else the inherited network set.
		$checked = ( $multisite && ! $override )
			? $this->settings->network_enabled_ids()
			: ( isset( $site_cfg['enabled'] ) ? (array) $site_cfg['enabled'] : $this->registry->default_enabled_ids() );

		// When inheriting on multisite, the fields are shown read-only.
		$disabled_fields = $multisite && ! $override;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'HLB MCP Abilities', 'hlb-mcp-abilities' ); ?></h1>
			<?php $this->render_connection_box(); ?>
			<?php settings_errors(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
				<?php settings_fields( self::SITE_OPTION_GROUP ); ?>

				<?php if ( $multisite ) : ?>
					<?php do_settings_sections( self::MENU_SLUG ); ?>
				<?php endif; ?>

				<div id="hlb-mcp-fields">
					<?php $this->render_ability_tabs( $checked, $disabled_fields, Settings::SITE_OPTION . '[enabled][]' ); ?>
				</div>

				<?php submit_button( __( 'Save changes', 'hlb-mcp-abilities' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Field callback for the "override network defaults" checkbox.
	 *
	 * The surrounding table row is rendered by do_settings_sections().
	 *
	 * @return void
	 */
	public function render_override_field() {
		$override = $this->settings->is_override();
		?>
		<label>
			<input type="checkbox" id="hlb-mcp-override" name="<?php echo esc_attr( Settings::SITE_OPTION ); ?>[override]" value="1" <?php checked( $override ); ?> />
			<?php esc_html_e( 'Override network defaults for this site', 'hlb-mcp-abilities' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'When unchecked, this site inherits the network defaults shown below (read-only).', 'hlb-mcp-abilities' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the searchable, tabbed ability checkboxes.
	 *
	 * @param string[] $checked    Ability ids that should be checked.
	 * @param bool     $disabled   Whether inputs are disabled (inheriting).
	 * @param string   $field_name The checkbox `name` attribute (array notation).
	 * @return void
	 */
	private function render_ability_tabs( array $checked, $disabled, $field_name = 'hlb_abilities[]' ) {
		$categories = $this->registry->categories();
		$available  = $this->registry->available();
		$checked    = array_flip( $checked );

		$groups = [];
		foreach ( $categories as $cat_id => $cat_label ) {
			$in_cat = array_filter(
				$available,
				static function ( $def ) use ( $cat_id ) {
					return $def['category'] === $cat_id;
				}
			);
			if ( ! empty( $in_cat ) ) {
				$groups[ $cat_id ] = [
					'label'     => $cat_label,
					'abilities' => $in_cat,
				];
			}
		}

		if ( empty( $groups ) ) {
			return;
		}

		$active = true;
		?>
		<div class="hlb-mcp-tabs">
			<p class="hlb-mcp-search">
				<label for="hlb-mcp-search-input" class="screen-reader-text"><?php esc_html_e( 'Search abilities', 'hlb-mcp-abilities' ); ?></label>
				<input
					type="search"
					id="hlb-mcp-search-input"
					class="regular-text"
					autocomplete="off"
					placeholder="<?php esc_attr_e( 'Search by name, id, or description…', 'hlb-mcp-abilities' ); ?>"
				/>
				<span class="hlb-mcp-search-status screen-reader-text" role="status" aria-live="polite"></span>
			</p>

			<div class="nav-tab-wrapper hlb-mcp-tablist" role="tablist" aria-label="<?php esc_attr_e( 'Ability categories', 'hlb-mcp-abilities' ); ?>">
				<?php foreach ( $groups as $cat_id => $group ) : ?>
					<?php $slug = sanitize_html_class( $cat_id ); ?>
					<button
						type="button"
						id="hlb-tab-<?php echo esc_attr( $slug ); ?>"
						class="nav-tab hlb-mcp-tab<?php echo $active ? ' nav-tab-active' : ''; ?>"
						role="tab"
						aria-selected="<?php echo $active ? 'true' : 'false'; ?>"
						aria-controls="hlb-panel-<?php echo esc_attr( $slug ); ?>"
						tabindex="<?php echo $active ? '0' : '-1'; ?>"
						data-category="<?php echo esc_attr( $cat_id ); ?>"
					>
						<?php echo esc_html( $group['label'] ); ?>
						<span class="hlb-mcp-tab-count"><?php echo count( $group['abilities'] ); ?></span>
					</button>
					<?php $active = false; ?>
				<?php endforeach; ?>
			</div>

			<?php foreach ( $groups as $cat_id => $group ) : ?>
				<?php $slug = sanitize_html_class( $cat_id ); ?>
				<div id="hlb-panel-<?php echo esc_attr( $slug ); ?>" role="tabpanel" aria-labelledby="hlb-tab-<?php echo esc_attr( $slug ); ?>" tabindex="0" class="hlb-mcp-panel">
					<table class="form-table hlb-mcp-table" role="presentation">
						<tbody>
						<?php foreach ( $group['abilities'] as $id => $def ) : ?>
							<?php
							$field_id = 'hlb-' . sanitize_html_class( $id );
							$search   = strtolower( $def['label'] . ' ' . $id . ' ' . $def['description'] );
							?>
							<tr class="hlb-mcp-row" data-hlb-search="<?php echo esc_attr( $search ); ?>">
								<th scope="row">
									<label for="<?php echo esc_attr( $field_id ); ?>">
										<?php echo esc_html( $def['label'] ); ?>
									</label>
									<?php echo $this->badge( $def ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped. ?>
								</th>
								<td>
									<label for="<?php echo esc_attr( $field_id ); ?>">
										<input
											type="checkbox"
											id="<?php echo esc_attr( $field_id ); ?>"
											name="<?php echo esc_attr( $field_name ); ?>"
											value="<?php echo esc_attr( $id ); ?>"
											<?php checked( isset( $checked[ $id ] ) ); ?>
											<?php disabled( $disabled ); ?>
										/>
										<span class="description"><?php echo esc_html( $def['description'] ); ?></span>
									</label>
									<code class="hlb-mcp-id"><?php echo esc_html( $id ); ?></code>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * A small readonly/destructive/writes badge for an ability.
	 *
	 * @param array $def Ability definition.
	 * @return string Escaped HTML.
	 */
	private function badge( array $def ) {
		$ann = isset( $def['annotations'] ) ? $def['annotations'] : [];
		if ( ! empty( $ann['destructive'] ) ) {
			return $this->badge_markup( 'destructive', 'dashicons-warning', __( 'destructive', 'hlb-mcp-abilities' ) );
		}
		if ( ! empty( $ann['readonly'] ) ) {
			return $this->badge_markup( 'readonly', 'dashicons-visibility', __( 'read-only', 'hlb-mcp-abilities' ) );
		}
		return $this->badge_markup( 'writes', 'dashicons-edit', __( 'writes', 'hlb-mcp-abilities' ) );
	}

	/**
	 * Build a single badge's markup.
	 *
	 * @param string $modifier BEM modifier (readonly|writes|destructive).
	 * @param string $dashicon Dashicon class.
	 * @param string $label    Visible label.
	 * @return string Escaped HTML.
	 */
	private function badge_markup( $modifier, $dashicon, $label ) {
		return sprintf(
			' <span class="hlb-mcp-badge hlb-mcp-badge--%1$s"><span class="dashicons %2$s" aria-hidden="true"></span>%3$s</span>',
			esc_attr( $modifier ),
			esc_attr( $dashicon ),
			esc_html( $label )
		);
	}

	/**
	 * Render the connection info box (server name + endpoint).
	 *
	 * @return void
	 */
	private function render_connection_box() {
		$adapter_ok = class_exists( '\\WP\\MCP\\Core\\McpAdapter' );
		?>
		<div class="card hlb-mcp-card">
			<h2 class="hlb-mcp-card__title"><?php esc_html_e( 'MCP connection', 'hlb-mcp-abilities' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Server name', 'hlb-mcp-abilities' ); ?></th>
					<td><code><?php echo esc_html( $this->settings->server_slug() ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Endpoint', 'hlb-mcp-abilities' ); ?></th>
					<td>
						<code><?php echo esc_html( $this->settings->endpoint_url() ); ?></code>
						<?php if ( ! $adapter_ok ) : ?>
							<p class="description hlb-mcp-error-text">
								<?php esc_html_e( 'The MCP Adapter plugin is not active, so this endpoint is not live yet.', 'hlb-mcp-abilities' ); ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Authentication', 'hlb-mcp-abilities' ); ?></th>
					<td>
						<p class="description">
							<?php
							printf(
								/* translators: %s: link to the Application Passwords profile section. */
								esc_html__( 'Third-party agents authenticate as a WordPress user. Create an %s and use it with HTTP Basic auth. Each ability is additionally gated by that user\'s capabilities.', 'hlb-mcp-abilities' ),
								'<a href="' . esc_url( admin_url( 'profile.php#application-passwords-section' ) ) . '">' . esc_html__( 'Application Password', 'hlb-mcp-abilities' ) . '</a>'
							);
							?>
						</p>
					</td>
				</tr>
				<?php if ( \HLB\MCP\Gatekeeper::active() ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Frontend Gatekeeper', 'hlb-mcp-abilities' ); ?></th>
						<td>
							<p class="description">
								<?php esc_html_e( 'Detected. If its gate is enabled, permalinks and site URLs returned by abilities are automatically tagged with its access parameter so agents can follow them without hitting its 404 gate.', 'hlb-mcp-abilities' ); ?>
							</p>
						</td>
					</tr>
				<?php endif; ?>
			</table>
		</div>
		<?php
	}

	/**
	 * Show a "settings saved" notice when redirected back with the flag.
	 *
	 * Used by the network page only — the per-site page uses settings_errors()
	 * via the Settings API save path.
	 *
	 * @return void
	 */
	private function maybe_updated_notice() {
		if ( isset( $_GET['hlb_updated'] ) && '1' === $_GET['hlb_updated'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'Settings saved.', 'hlb-mcp-abilities' )
			);
		}
	}

	/* ---------------------------------------------------------------------- Save handlers */

	/**
	 * Persist network defaults.
	 *
	 * @return void
	 */
	public function handle_save_network() {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'hlb-mcp-abilities' ) );
		}
		check_admin_referer( self::NONCE_NET );

		$ids          = $this->posted_ability_ids();
		$network_mode = ! empty( $_POST['network_mode'] );
		$this->settings->save_network( $ids, $network_mode );

		$this->redirect_back( network_admin_url( 'settings.php?page=' . self::MENU_SLUG ) );
	}

	/**
	 * Sanitize callback for the `hlb_mcp_site` Settings API option.
	 *
	 * Must tolerate a non-array/absent value: on multisite, saving while "inherit"
	 * is checked leaves every field disabled, so nothing posts and options.php calls
	 * this with null. That correctly resolves to override=false, enabled=[].
	 *
	 * @param mixed $value Raw value from $_POST (already unslashed by options.php).
	 * @return array{override:bool,enabled:string[]}
	 */
	public function sanitize_site_option( $value ) {
		$value    = is_array( $value ) ? $value : [];
		$override = ! empty( $value['override'] );
		$raw_ids  = isset( $value['enabled'] ) ? array_map( 'sanitize_text_field', (array) $value['enabled'] ) : [];

		return $this->settings->sanitize_site_config( $override, $raw_ids );
	}

	/**
	 * Read and sanitize the posted ability id list (network save path).
	 *
	 * @return string[]
	 */
	private function posted_ability_ids() {
		// Nonce is verified by the calling save handler (check_admin_referer); values are
		// sanitized on the next line and then validated against the ability registry.
		// phpcs:ignore HM.Security.NonceVerification.Missing, HM.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw = isset( $_POST['hlb_abilities'] ) ? (array) wp_unslash( $_POST['hlb_abilities'] ) : [];
		$raw = array_map( 'sanitize_text_field', $raw );
		return $this->settings->sanitize_ids( $raw );
	}

	/**
	 * Redirect back to a settings page with a saved flag.
	 *
	 * @param string $base Base URL.
	 * @return void
	 */
	private function redirect_back( $base ) {
		wp_safe_redirect( add_query_arg( 'hlb_updated', '1', $base ) );
		exit;
	}
}
