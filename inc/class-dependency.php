<?php
/**
 * MCP Adapter dependency management: detect, offer to install, and offer to activate.
 *
 * The MCP Adapter is distributed via GitHub (not the wordpress.org directory), so the
 * one-click install sideloads its published release ZIP rather than using the core
 * install-by-slug flow.
 *
 * @package HLB\MCP
 */

namespace HLB\MCP;

defined( 'ABSPATH' ) || exit;

/**
 * Guards the MCP Adapter dependency and drives install/activate from an admin notice.
 */
class Dependency {

	const ADAPTER_CLASS    = '\\WP\\MCP\\Core\\McpAdapter';
	const ADAPTER_BASENAME = 'mcp-adapter/mcp-adapter.php';
	const DOWNLOAD_URL     = 'https://github.com/WordPress/mcp-adapter/releases/latest/download/mcp-adapter.zip';

	const ACTION_INSTALL  = 'hlb_mcp_install_adapter';
	const ACTION_ACTIVATE = 'hlb_mcp_activate_adapter';

	/**
	 * Register admin hooks (notices + action handlers).
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'admin_notices', [ $this, 'render_notice' ] );
		add_action( 'network_admin_notices', [ $this, 'render_notice' ] );
		add_action( 'admin_post_' . self::ACTION_INSTALL, [ $this, 'handle_install' ] );
		add_action( 'admin_post_' . self::ACTION_ACTIVATE, [ $this, 'handle_activate' ] );
	}

	/* --------------------------------------------------------------------- Detection */

	/**
	 * Whether the adapter is loaded (installed and active).
	 *
	 * @return bool
	 */
	public function is_active() {
		return class_exists( self::ADAPTER_CLASS );
	}

	/**
	 * The adapter's plugin basename if it is installed, else empty string.
	 *
	 * @return string
	 */
	public function installed_basename() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_plugins();

		if ( isset( $plugins[ self::ADAPTER_BASENAME ] ) ) {
			return self::ADAPTER_BASENAME;
		}

		// Resilient fallback: match by folder or plugin name in case the ZIP unpacked
		// to a differently-named directory.
		foreach ( $plugins as $file => $data ) {
			if ( 0 === strpos( $file, 'mcp-adapter/' ) ) {
				return $file;
			}
			if ( isset( $data['Name'] ) && false !== stripos( $data['Name'], 'MCP Adapter' ) ) {
				return $file;
			}
		}
		return '';
	}

	/**
	 * Whether the adapter is installed (regardless of active state).
	 *
	 * @return bool
	 */
	public function is_installed() {
		return '' !== $this->installed_basename();
	}

	/**
	 * Whether this plugin is network-activated (so the adapter should be too).
	 *
	 * @return bool
	 */
	private function is_network_context() {
		if ( ! is_multisite() ) {
			return false;
		}
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active_for_network( HLB_MCP_BASENAME );
	}

	/* ----------------------------------------------------------------------- Notices */

	/**
	 * Render the dependency notice appropriate to the current state.
	 *
	 * @return void
	 */
	public function render_notice() {
		$this->maybe_result_notice();

		if ( $this->is_active() ) {
			return; // Dependency satisfied — nothing to show.
		}

		$installed = $this->is_installed();

		if ( ! $installed && current_user_can( 'install_plugins' ) ) {
			$this->notice_box(
				__( 'requires the WordPress MCP Adapter plugin, which is not installed.', 'hlb-mcp-abilities' ),
				self::ACTION_INSTALL,
				__( 'Install &amp; activate MCP Adapter', 'hlb-mcp-abilities' )
			);
			return;
		}

		if ( $installed && current_user_can( 'activate_plugins' ) ) {
			$this->notice_box(
				__( 'requires the WordPress MCP Adapter plugin, which is installed but not active.', 'hlb-mcp-abilities' ),
				self::ACTION_ACTIVATE,
				__( 'Activate MCP Adapter', 'hlb-mcp-abilities' )
			);
			return;
		}

		// User lacks the capability to fix it — inform only.
		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'HLB MCP Abilities', 'hlb-mcp-abilities' ),
			esc_html__( 'requires the WordPress MCP Adapter plugin. Ask a site administrator to install and activate it.', 'hlb-mcp-abilities' )
		);
	}

	/**
	 * Render a notice with a single POST action button.
	 *
	 * @param string $message Sentence following the plugin name.
	 * @param string $action  admin-post action.
	 * @param string $label   Button label (may contain entities).
	 * @return void
	 */
	private function notice_box( $message, $action, $label ) {
		// admin-post.php only exists at /wp-admin/admin-post.php (there is no network
		// variant); the redirect_to field returns the user to the originating screen.
		$post_url = admin_url( 'admin-post.php' );
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'HLB MCP Abilities', 'hlb-mcp-abilities' ); ?></strong>
				<?php echo esc_html( $message ); ?>
			</p>
			<p>
				<form method="post" action="<?php echo esc_url( $post_url ); ?>" style="display:inline;">
					<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>" />
					<input type="hidden" name="redirect_to" value="<?php echo esc_attr( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ); ?>" />
					<?php wp_nonce_field( $action ); ?>
					<button type="submit" class="button button-primary"><?php echo esc_html( $label ); ?></button>
					<a href="https://github.com/WordPress/mcp-adapter" target="_blank" rel="noopener" class="button-link" style="margin-left:.5em;"><?php esc_html_e( 'View plugin', 'hlb-mcp-abilities' ); ?></a>
				</form>
			</p>
		</div>
		<?php
	}

	/**
	 * Show a one-time result notice after an install/activate attempt.
	 *
	 * @return void
	 */
	private function maybe_result_notice() {
		if ( empty( $_GET['hlb_dep'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$state = sanitize_key( wp_unslash( $_GET['hlb_dep'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'installed' === $state ) {
			$this->result( 'success', __( 'MCP Adapter installed and activated. Your MCP endpoint is now live.', 'hlb-mcp-abilities' ) );
		} elseif ( 'activated' === $state ) {
			$this->result( 'success', __( 'MCP Adapter activated. Your MCP endpoint is now live.', 'hlb-mcp-abilities' ) );
		} elseif ( 'failed' === $state ) {
			$detail = isset( $_GET['hlb_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['hlb_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->result( 'error', __( 'Could not install or activate the MCP Adapter.', 'hlb-mcp-abilities' ) . ( $detail ? ' ' . $detail : '' ) );
		}
	}

	/**
	 * Print a simple result notice.
	 *
	 * @param string $type    success|error.
	 * @param string $message Message.
	 * @return void
	 */
	private function result( $type, $message ) {
		printf(
			'<div class="notice notice-%s is-dismissible"><p><strong>%s</strong> %s</p></div>',
			esc_attr( $type ),
			esc_html__( 'HLB MCP Abilities:', 'hlb-mcp-abilities' ),
			esc_html( $message )
		);
	}

	/* --------------------------------------------------------------------- Handlers */

	/**
	 * Download and install the MCP Adapter from its GitHub release, then activate it.
	 *
	 * @return void
	 */
	public function handle_install() {
		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to install plugins.', 'hlb-mcp-abilities' ) );
		}
		check_admin_referer( self::ACTION_INSTALL );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader-skins.php';

		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$result   = $upgrader->install( self::DOWNLOAD_URL );

		if ( is_wp_error( $result ) ) {
			$this->redirect_result( 'failed', $result->get_error_message() );
		}
		if ( is_wp_error( $skin->result ) ) {
			$this->redirect_result( 'failed', $skin->result->get_error_message() );
		}
		if ( true !== $result ) {
			$errors = $skin->get_errors();
			$msg    = is_wp_error( $errors ) && $errors->has_errors() ? $errors->get_error_message() : __( 'Installation did not complete.', 'hlb-mcp-abilities' );
			$this->redirect_result( 'failed', $msg );
		}

		// Activate the freshly-installed plugin.
		$basename = $upgrader->plugin_info();
		if ( ! $basename ) {
			$basename = $this->installed_basename();
		}
		$activated = activate_plugin( $basename, '', $this->is_network_context() );

		if ( is_wp_error( $activated ) ) {
			$this->redirect_result( 'failed', $activated->get_error_message() );
		}

		$this->redirect_result( 'installed' );
	}

	/**
	 * Activate an already-installed MCP Adapter.
	 *
	 * @return void
	 */
	public function handle_activate() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to activate plugins.', 'hlb-mcp-abilities' ) );
		}
		check_admin_referer( self::ACTION_ACTIVATE );

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$basename = $this->installed_basename();
		if ( ! $basename ) {
			$this->redirect_result( 'failed', __( 'The MCP Adapter is no longer installed.', 'hlb-mcp-abilities' ) );
		}

		$activated = activate_plugin( $basename, '', $this->is_network_context() );
		if ( is_wp_error( $activated ) ) {
			$this->redirect_result( 'failed', $activated->get_error_message() );
		}

		$this->redirect_result( 'activated' );
	}

	/**
	 * Redirect back to the originating admin page with a result flag.
	 *
	 * @param string $state installed|activated|failed.
	 * @param string $detail Optional detail message.
	 * @return void
	 */
	private function redirect_result( $state, $detail = '' ) {
		$fallback = is_network_admin() ? network_admin_url() : admin_url();
		$target   = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by caller.
		if ( ! $target ) {
			$target = wp_get_referer() ? wp_get_referer() : $fallback;
		}

		$args = [ 'hlb_dep' => $state ];
		if ( 'failed' === $state && $detail ) {
			$args['hlb_msg'] = rawurlencode( wp_strip_all_tags( $detail ) );
		}

		wp_safe_redirect( add_query_arg( $args, $target ) );
		exit;
	}
}
