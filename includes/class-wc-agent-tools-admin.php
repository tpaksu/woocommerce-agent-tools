<?php
/**
 * The Tools screen that explains how to connect an agent, and issues the credential it needs.
 *
 * @package woocommerce-agent-tools
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Tools screen that explains how to connect an agent, and issues the credential it needs.
 */
final class WC_Agent_Tools_Admin {

	const PAGE_SLUG = 'woocommerce-agent-tools';
	const ACTION    = 'wcat_create_password';
	const NONCE     = 'wcat_create_password_nonce';

	/**
	 * A password is shown exactly once, right after it is created, because only its hash
	 * is stored. Held here between the POST handler and the render.
	 *
	 * @var array{name:string,password:string}|null
	 */
	private static $new_credential = null;

	/**
	 * Hook up.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_create_password' ) );
	}

	/**
	 * Add the screen under Tools.
	 */
	public static function register_page(): void {
		add_management_page(
			__( 'Agent Tools', 'woocommerce-agent-tools' ),
			__( 'Agent Tools', 'woocommerce-agent-tools' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * The MCP endpoint this store serves.
	 *
	 * Read from the adapter's live server object rather than by re-applying its config
	 * filter, which would run every callback again, this plugin's own among them.
	 */
	public static function endpoint(): string {
		$namespace = 'mcp';
		$route     = 'mcp-adapter-default-server';

		if ( class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
			$server = \WP\MCP\Core\McpAdapter::instance()->get_server( $route );
			if ( $server ) {
				$namespace = $server->get_server_route_namespace();
				$route     = $server->get_server_route();
			}
		}

		return rest_url( trailingslashit( $namespace ) . $route );
	}

	/**
	 * How many abilities an agent would discover right now.
	 */
	public static function visible_tool_count(): int {
		if ( ! function_exists( 'wp_get_abilities' ) || ! class_exists( '\WP\MCP\Abilities\McpAbilityExposure' ) ) {
			return 0;
		}
		$count = 0;
		foreach ( wp_get_abilities() as $ability ) {
			if ( \WP\MCP\Abilities\McpAbilityExposure::is_public( $ability ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Create an application password for the current user and keep it for one render.
	 */
	public static function handle_create_password(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'woocommerce-agent-tools' ), 403 );
		}
		check_admin_referer( self::NONCE );

		$user_id = get_current_user_id();
		if ( ! wp_is_application_passwords_available_for_user( $user_id ) || ! current_user_can( 'create_app_password', $user_id ) ) {
			self::redirect_with( 'unavailable' );
		}

		$name    = isset( $_POST['wcat_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['wcat_name'] ) ) : '';
		$name    = '' === $name ? __( 'Agent (MCP)', 'woocommerce-agent-tools' ) : $name;
		$created = WP_Application_Passwords::create_new_application_password( $user_id, array( 'name' => $name ) );

		if ( is_wp_error( $created ) ) {
			self::redirect_with( 'failed' );
		}

		// Record the uuid so direct REST writes from this credential are held to the gate.
		if ( isset( $created[1]['uuid'] ) ) {
			WC_Agent_Tools_Rest_Guard::remember_password( (string) $created[1]['uuid'] );
		}

		// Stash the plaintext for a single render. It cannot be recovered afterwards.
		set_transient(
			'wcat_new_password_' . $user_id,
			array(
				'name'     => $name,
				'password' => $created[0],
			),
			60
		);
		self::redirect_with( 'created' );
	}

	/**
	 * Send the browser back to the screen with a notice code.
	 *
	 * @param string $notice Notice code.
	 */
	private static function redirect_with( string $notice ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => self::PAGE_SLUG,
					'wcat_notice' => $notice,
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	/**
	 * Render the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$user      = wp_get_current_user();
		$endpoint  = self::endpoint();
		$notice    = isset( $_GET['wcat_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['wcat_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice code, no state change.
		$available = wp_is_application_passwords_available_for_user( $user->ID ) && current_user_can( 'create_app_password', $user->ID );

		if ( 'created' === $notice ) {
			$stashed = get_transient( 'wcat_new_password_' . $user->ID );
			if ( is_array( $stashed ) ) {
				self::$new_credential = $stashed;
				delete_transient( 'wcat_new_password_' . $user->ID );
			}
		}

		echo '<div class="wrap">';
		printf( '<h1>%s</h1>', esc_html__( 'Agent Tools', 'woocommerce-agent-tools' ) );

		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: %d: number of abilities an agent can discover. */
					_n(
						'This store exposes %d tool to a connected agent, compiled from the REST routes of WooCommerce and the extensions installed beside it.',
						'This store exposes %d tools to a connected agent, compiled from the REST routes of WooCommerce and the extensions installed beside it.',
						self::visible_tool_count(),
						'woocommerce-agent-tools'
					),
					self::visible_tool_count()
				)
			)
		);

		self::render_notice( $notice );
		self::render_endpoint( $endpoint );
		self::render_credential_section( $user, $endpoint, $available );
		self::render_capability_warning( $user );

		echo '</div>';
	}

	/**
	 * Notices for the outcomes of the create action.
	 *
	 * @param string $notice Notice code.
	 */
	private static function render_notice( string $notice ): void {
		if ( 'failed' === $notice ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'The application password could not be created.', 'woocommerce-agent-tools' )
			);
		}
		if ( 'unavailable' === $notice ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Application passwords are not available for your account. They require a site reachable over HTTPS, unless the site is local.', 'woocommerce-agent-tools' )
			);
		}
	}

	/**
	 * The endpoint, which is the same on every store and needs no configuration.
	 *
	 * @param string $endpoint Endpoint URL.
	 */
	private static function render_endpoint( string $endpoint ): void {
		printf( '<h2>%s</h2>', esc_html__( 'Endpoint', 'woocommerce-agent-tools' ) );
		printf(
			'<p>%s</p>',
			esc_html__( 'Point your MCP client at this address. The MCP Adapter serves it; there is nothing to configure.', 'woocommerce-agent-tools' )
		);
		printf(
			'<p><input type="text" class="large-text code" readonly onclick="this.select()" value="%s" /></p>',
			esc_attr( $endpoint )
		);
	}

	/**
	 * The authentication step: issue an application password, then show the config once.
	 *
	 * @param WP_User $user      Current user.
	 * @param string  $endpoint  Endpoint URL.
	 * @param bool    $available Whether this user may create an application password.
	 */
	private static function render_credential_section( WP_User $user, string $endpoint, bool $available ): void {
		printf( '<h2>%s</h2>', esc_html__( 'Authentication', 'woocommerce-agent-tools' ) );

		if ( null !== self::$new_credential ) {
			$auth = base64_encode( $user->user_login . ':' . self::$new_credential['password'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Builds an HTTP Basic credential, not obfuscation.

			printf(
				'<div class="notice notice-success"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'Application password created.', 'woocommerce-agent-tools' ),
				esc_html__( 'It is shown only once, because only a hash of it is stored.', 'woocommerce-agent-tools' )
			);
			printf(
				'<p><input type="text" class="large-text code" readonly onclick="this.select()" value="%s" /></p>',
				esc_attr( self::$new_credential['password'] )
			);

			printf( '<h3>%s</h3>', esc_html__( 'Claude Code', 'woocommerce-agent-tools' ) );
			printf(
				'<p><textarea class="large-text code" rows="4" readonly onclick="this.select()">%s</textarea></p>',
				esc_textarea(
					sprintf(
						"claude mcp add --transport http woo-store %s \\\n  --header \"Authorization: Basic %s\"",
						$endpoint,
						$auth
					)
				)
			);

			printf( '<h3>%s</h3>', esc_html__( 'Any other MCP client', 'woocommerce-agent-tools' ) );
			printf(
				'<p>%s</p>',
				esc_html__( 'Use HTTP transport against the endpoint above, with these headers:', 'woocommerce-agent-tools' )
			);
			printf(
				'<p><textarea class="large-text code" rows="3" readonly onclick="this.select()">%s</textarea></p>',
				esc_textarea(
					sprintf(
						"Authorization: Basic %s\nMCP-Protocol-Version: 2025-06-18",
						$auth
					)
				)
			);
			printf(
				'<p><em>%s</em></p>',
				esc_html__( 'Most clients read their configuration at start-up, so restart yours after adding this.', 'woocommerce-agent-tools' )
			);
			return;
		}

		if ( ! $available ) {
			printf(
				'<p>%s</p>',
				esc_html__( 'Application passwords are unavailable for your account, so a credential has to come from elsewhere. They need the site to be reachable over HTTPS, unless it is a local environment.', 'woocommerce-agent-tools' )
			);
			return;
		}

		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: current user's login name. */
					__( 'An MCP client authenticates as a WordPress user. Create an application password for %s and this page will hand you the client configuration, filled in.', 'woocommerce-agent-tools' ),
					$user->user_login
				)
			)
		);

		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( self::NONCE );
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( self::ACTION ) );
		printf(
			'<p><label for="wcat_name">%s</label><br /><input type="text" id="wcat_name" name="wcat_name" class="regular-text" value="%s" /></p>',
			esc_html__( 'Name, so you can recognise and revoke it later', 'woocommerce-agent-tools' ),
			esc_attr__( 'Agent (MCP)', 'woocommerce-agent-tools' )
		);
		submit_button( __( 'Create application password', 'woocommerce-agent-tools' ) );
		echo '</form>';

		$existing = WP_Application_Passwords::get_user_application_passwords( $user->ID );
		if ( ! empty( $existing ) ) {
			printf(
				'<p><a href="%s">%s</a></p>',
				esc_url( admin_url( 'profile.php#application-passwords-section' ) ),
				esc_html(
					sprintf(
						/* translators: %d: number of existing application passwords. */
						_n( 'Review or revoke your %d existing application password', 'Review or revoke your %d existing application passwords', count( $existing ), 'woocommerce-agent-tools' ),
						count( $existing )
					)
				)
			);
		}
	}

	/**
	 * What the agent will be able to do, which is the single most important thing on this
	 * screen and the one nobody thinks to ask.
	 *
	 * @param WP_User $user Current user.
	 */
	private static function render_capability_warning( WP_User $user ): void {
		printf( '<h2>%s</h2>', esc_html__( 'What the agent will be able to do', 'woocommerce-agent-tools' ) );

		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: current user's login name. */
					__( 'Every call runs as %s, and each route\'s own permission check decides. The agent reaches exactly what that user reaches in wp-admin, and nothing more.', 'woocommerce-agent-tools' ),
					$user->user_login
				)
			)
		);

		$risky = array_values(
			array_filter(
				array(
					'install_plugins'  => user_can( $user, 'install_plugins' ) ? __( 'install plugins', 'woocommerce-agent-tools' ) : '',
					'activate_plugins' => user_can( $user, 'activate_plugins' ) ? __( 'activate and deactivate plugins', 'woocommerce-agent-tools' ) : '',
					'manage_options'   => user_can( $user, 'manage_options' ) ? __( 'change store settings', 'woocommerce-agent-tools' ) : '',
				)
			)
		);

		if ( ! empty( $risky ) ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s</p><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: comma-separated list of capabilities. */
						__( 'This account can %s, and can place a real order on WooCommerce.com. An agent holding its application password can do the same, and writes have no undo.', 'woocommerce-agent-tools' ),
						implode( ', ', $risky )
					)
				),
				esc_html__( 'Consider creating a separate user with a narrower role, such as Shop manager, and generating the application password there instead.', 'woocommerce-agent-tools' )
			);
		}

		printf(
			'<p>%s</p>',
			esc_html__( 'To tell the agent how to behave, edit agents.md in this plugin, or place your own at wp-content/agents.md. Its text is sent in the MCP handshake.', 'woocommerce-agent-tools' )
		);
	}
}
