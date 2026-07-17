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

	const MENU_SLUG    = 'hlb-mcp-abilities';
	const NONCE_NET    = 'hlb_mcp_save_network';
	const NONCE_SITE   = 'hlb_mcp_save_site';
	const ACTION_NET   = 'hlb_mcp_save_network';
	const ACTION_SITE  = 'hlb_mcp_save_site';

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
		add_action( 'admin_post_' . self::ACTION_SITE, [ $this, 'handle_save_site' ] );

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
		add_options_page(
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
		add_submenu_page(
			'settings.php',
			__( 'HLB MCP Abilities', 'hlb-mcp-abilities' ),
			__( 'HLB MCP Abilities', 'hlb-mcp-abilities' ),
			'manage_network_options',
			self::MENU_SLUG,
			[ $this, 'render_network_page' ]
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

				<?php $this->render_fields( $enabled, false ); ?>
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
			<?php $this->maybe_updated_notice(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SITE ); ?>" />
				<?php wp_nonce_field( self::NONCE_SITE ); ?>

				<?php if ( $multisite ) : ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Network defaults', 'hlb-mcp-abilities' ); ?></th>
							<td>
								<label>
									<input type="checkbox" id="hlb-mcp-override" name="override" value="1" <?php checked( $override ); ?> />
									<?php esc_html_e( 'Override network defaults for this site', 'hlb-mcp-abilities' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'When unchecked, this site inherits the network defaults shown below (read-only).', 'hlb-mcp-abilities' ); ?>
								</p>
							</td>
						</tr>
					</table>
				<?php endif; ?>

				<div id="hlb-mcp-fields">
					<?php $this->render_fields( $checked, $disabled_fields ); ?>
				</div>

				<?php submit_button( __( 'Save changes', 'hlb-mcp-abilities' ) ); ?>
			</form>
		</div>

		<?php if ( $multisite ) : ?>
		<script>
		( function () {
			var toggle = document.getElementById( 'hlb-mcp-override' );
			var fields = document.getElementById( 'hlb-mcp-fields' );
			if ( ! toggle || ! fields ) { return; }
			function sync() {
				var boxes = fields.querySelectorAll( 'input[type=checkbox]' );
				for ( var i = 0; i < boxes.length; i++ ) { boxes[ i ].disabled = ! toggle.checked; }
				fields.style.opacity = toggle.checked ? '1' : '0.55';
			}
			toggle.addEventListener( 'change', sync );
			sync();
		} )();
		</script>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render the grouped ability checkboxes.
	 *
	 * @param string[] $checked  Ability ids that should be checked.
	 * @param bool     $disabled Whether inputs are disabled (inheriting).
	 * @return void
	 */
	private function render_fields( array $checked, $disabled ) {
		$categories = $this->registry->categories();
		$available  = $this->registry->available();
		$checked    = array_flip( $checked );

		foreach ( $categories as $cat_id => $cat_label ) {
			$in_cat = array_filter(
				$available,
				static function ( $def ) use ( $cat_id ) {
					return $def['category'] === $cat_id;
				}
			);
			if ( empty( $in_cat ) ) {
				continue;
			}
			?>
			<h2 class="title"><?php echo esc_html( $cat_label ); ?></h2>
			<table class="form-table" role="presentation">
				<tbody>
				<?php foreach ( $in_cat as $id => $def ) : ?>
					<tr>
						<th scope="row" style="width:22em;">
							<label for="hlb-<?php echo esc_attr( sanitize_html_class( $id ) ); ?>">
								<?php echo esc_html( $def['label'] ); ?>
							</label>
							<?php echo $this->badge( $def ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped. ?>
						</th>
						<td>
							<label for="hlb-<?php echo esc_attr( sanitize_html_class( $id ) ); ?>">
								<input
									type="checkbox"
									id="hlb-<?php echo esc_attr( sanitize_html_class( $id ) ); ?>"
									name="hlb_abilities[]"
									value="<?php echo esc_attr( $id ); ?>"
									<?php checked( isset( $checked[ $id ] ) ); ?>
									<?php disabled( $disabled ); ?>
								/>
								<span class="description"><?php echo esc_html( $def['description'] ); ?></span>
							</label>
							<code style="margin-left:.5em;opacity:.6;"><?php echo esc_html( $id ); ?></code>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php
		}
	}

	/**
	 * A small readonly/destructive badge for an ability.
	 *
	 * @param array $def Ability definition.
	 * @return string Escaped HTML.
	 */
	private function badge( array $def ) {
		$ann = isset( $def['annotations'] ) ? $def['annotations'] : [];
		if ( ! empty( $ann['destructive'] ) ) {
			return ' <span style="color:#b32d2e;font-size:11px;">' . esc_html__( 'destructive', 'hlb-mcp-abilities' ) . '</span>';
		}
		if ( ! empty( $ann['readonly'] ) ) {
			return ' <span style="color:#3a7d44;font-size:11px;">' . esc_html__( 'read-only', 'hlb-mcp-abilities' ) . '</span>';
		}
		return ' <span style="color:#996800;font-size:11px;">' . esc_html__( 'writes', 'hlb-mcp-abilities' ) . '</span>';
	}

	/**
	 * Render the connection info box (server name + endpoint).
	 *
	 * @return void
	 */
	private function render_connection_box() {
		$adapter_ok = class_exists( '\\WP\\MCP\\Core\\McpAdapter' );
		?>
		<div class="card" style="max-width:none;">
			<h2 style="margin-top:0;"><?php esc_html_e( 'MCP connection', 'hlb-mcp-abilities' ); ?></h2>
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
							<p class="description" style="color:#b32d2e;">
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
	 * Persist per-site settings.
	 *
	 * @return void
	 */
	public function handle_save_site() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'hlb-mcp-abilities' ) );
		}
		check_admin_referer( self::NONCE_SITE );

		$override = ! empty( $_POST['override'] );
		// On single site, override is implicit (there is no network layer).
		if ( ! is_multisite() ) {
			$override = true;
		}
		$ids = $this->posted_ability_ids();

		$this->settings->save_site( $override, $ids );

		$this->redirect_back( admin_url( 'options-general.php?page=' . self::MENU_SLUG ) );
	}

	/**
	 * Read and sanitize the posted ability id list.
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
