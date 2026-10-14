<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Dox_Functions_Admin {

	const SLUG  = 'dox-functions';
	const CAP   = 'manage_options';

	// Per-user transient that repopulates the editor after a failed save, so a
	// syntax error never costs the user the code they just wrote.
	const STASH_KEY = 'dox_functions_stash_';

	// Cap for JSON imports: file size and snippet count.
	const IMPORT_MAX_BYTES = 2097152; // 2 MB
	const IMPORT_MAX_ITEMS = 200;

	/**
	 * May the current user create, edit, activate or run snippets?
	 *
	 * This plugin stores PHP and executes it with eval(), so access must match
	 * WordPress's own trust boundary for running arbitrary code — which is
	 * stricter than manage_options:
	 *
	 *  - Multisite: only Super Admins may manage snippets. Regular site
	 *    administrators have manage_options, but WordPress deliberately
	 *    withholds unfiltered_html and the code editors from them because
	 *    subsite admins are not trusted to execute PHP. Allowing eval() here
	 *    for a plain manage_options user is a network-wide privilege
	 *    escalation (remote code execution) launched from a single subsite.
	 *  - DISALLOW_FILE_MODS / DISALLOW_FILE_EDIT no longer close the screen
	 *    (since 1.4.1): they lock PHP only, which type_refused() enforces on
	 *    every write, while HTML snippets stay manageable.
	 *
	 * The bare capability constant (self::CAP) is kept only as the menu
	 * registration cap; this method is the authoritative gate used by every
	 * handler and the page renderer. What each user may do with each TYPE is
	 * decided by type_refused().
	 */
	public static function user_can_manage() {
		if ( is_multisite() ) {
			return is_super_admin();
		}
		return current_user_can( self::CAP );
	}

	/**
	 * ¿Puede este usuario guardar, importar, duplicar o activar un snippet de
	 * este tipo? Devuelve '' si puede, o la clave del texto que explica por qué
	 * no:
	 *  - HTML se imprime sin filtrar en la web pública, así que pide la misma
	 *    capacidad que WordPress exige para publicar scripts (unfiltered_html,
	 *    que DISALLOW_UNFILTERED_HTML quita a todos y que en multisitio solo
	 *    tienen los Super Admins).
	 *  - PHP se ejecuta con eval(): con DISALLOW_FILE_EDIT o DISALLOW_FILE_MODS
	 *    queda bloqueado.
	 */
	public static function type_refused( $type ) {
		if ( 'html' === $type ) {
			return current_user_can( 'unfiltered_html' ) ? '' : 'html_needs_unfiltered';
		}
		return Dox_Functions::php_locked() ? 'php_locked' : '';
	}

	/**
	 * One nonce per action, with the snippet id inside when there is one
	 * (dox_functions_delete_12), so the nonce the editor's JS holds to check
	 * syntax can't also delete or export, and a delete link only works for
	 * its own row.
	 */
	public static function nonce_action( $action, $id = 0 ) {
		return 'dox_functions_' . $action . ( $id ? '_' . (int) $id : '' );
	}

	public function __construct() {
		add_action( 'admin_menu',            array( $this, 'menu' ) );
		add_action( 'dox_core_register',     array( $this, 'register_in_dox_menu' ) );
		add_action( 'admin_page_access_denied', array( $this, 'redirect_old_url' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_head',            array( $this, 'admin_favicon' ) );
		add_action( 'admin_notices',         array( $this, 'global_notices' ) );
		add_action( 'admin_post_dox_functions_save',         array( $this, 'handle_save' ) );
		add_action( 'admin_post_dox_functions_delete',       array( $this, 'handle_delete' ) );
		add_action( 'admin_post_dox_functions_toggle',       array( $this, 'handle_toggle' ) );
		add_action( 'admin_post_dox_functions_duplicate',    array( $this, 'handle_duplicate' ) );
		add_action( 'admin_post_dox_functions_export',       array( $this, 'handle_export' ) );
		add_action( 'admin_post_dox_functions_import',       array( $this, 'handle_import' ) );
		add_action( 'admin_post_dox_functions_safemode_off', array( $this, 'handle_safemode_off' ) );
		add_action( 'wp_ajax_dox_functions_validate',        array( $this, 'ajax_validate' ) );
		add_action( 'wp_ajax_dox_functions_toggle',          array( $this, 'ajax_toggle' ) );
	}

	/**
	 * Apunta el plugin en el menú común "Dox Plugins", que crea dox-core. La
	 * página la registra el core, para que todos los plugins Dox cuelguen igual.
	 */
	public function register_in_dox_menu( $core ) {
		if ( ! self::user_can_manage() ) {
			return;
		}
		$core->register_plugin( array(
			'slug'    => 'dox-functions',
			'name'    => dox_t( 'plugin_name' ),
			'version' => DOX_FUNCTIONS_VERSION,
			'summary' => dox_t( 'plugin_summary' ),
			'page'    => array(
				'page_title' => dox_t( 'plugin_name' ),
				'menu_title' => dox_t( 'plugin_name' ),
				'capability' => self::CAP,
				'menu_slug'  => self::SLUG,
				'callback'   => array( $this, 'render_page' ),
			),
		) );
	}

	public function menu() {
		// Don't even register the page for users who aren't allowed to run PHP
		// here (subsite admins on multisite, or sites that locked down code
		// editing). Without the page registered, the screen is inaccessible;
		// handlers below re-check as defence in depth.
		if ( ! self::user_can_manage() ) {
			return;
		}

		// Con dox-core la pantalla vive en el menú Dox Plugins; esto es el
		// respaldo por si el core no llegara a cargarse.
		if ( function_exists( 'dox_core' ) ) {
			return;
		}

		add_management_page(
			dox_t( 'plugin_name' ),
			dox_t( 'plugin_name' ),
			self::CAP,
			self::SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * ¿Estamos en la pantalla del plugin? Dentro del menú Dox Plugins el hook es
	 * dox-plugins_page_dox-functions; tools_page_... solo cuando actúa el
	 * respaldo de menu().
	 */
	private static function is_our_screen( $hook ) {
		$screens = array( 'tools_page_' . self::SLUG );
		if ( function_exists( 'dox_core' ) ) {
			$screens[] = dox_core()->page_hook( 'dox-functions' );
		}
		return in_array( $hook, array_filter( $screens ), true );
	}

	public function assets( $hook ) {
		if ( ! self::is_our_screen( $hook ) ) return;
		if ( ! self::user_can_manage() ) return;

		wp_enqueue_style( 'dox-functions-inter', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		wp_enqueue_style( 'dox-functions-admin', DOX_FUNCTIONS_URL . 'assets/admin.css', array(), DOX_FUNCTIONS_VERSION );
		// wp_enqueue_code_editor() pulls in CodeMirror (script + styles) itself.
		wp_enqueue_code_editor( array( 'type' => 'application/x-httpd-php' ) );
		wp_enqueue_script( 'dox-functions-admin', DOX_FUNCTIONS_URL . 'assets/admin.js', array( 'code-editor' ), DOX_FUNCTIONS_VERSION, true );
		wp_localize_script( 'dox-functions-admin', 'DoxFunctions', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::nonce_action( 'validate' ) ),
			'starter' => dox_t( 'starter_code' ),
			'i18n'    => array(
				'validating'    => dox_t( 'validating' ),
				'request_error' => dox_t( 'request_error' ),
			),
		) );
	}

	public function admin_favicon() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! self::is_our_screen( $screen->id ) ) return;
		echo '<link rel="icon" type="image/svg+xml" href="' . esc_url( DOX_FUNCTIONS_URL . 'assets/icon.svg' ) . '">';
	}

	/**
	 * Site-wide admin notices (standard WP styling — our CSS only loads on the
	 * plugin page): a safe-mode reminder, and a one-shot alert when a snippet
	 * was auto-disabled by an error. Both skip the plugin page, which shows
	 * its own richer versions.
	 */
	public function global_notices() {
		if ( ! self::user_can_manage() ) return;

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && self::is_our_screen( $screen->id ) ) return;

		if ( Dox_Functions::safe_mode_forced() ) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html( dox_t( 'safe_mode_forced' ) ) . '</strong></p></div>';
		} elseif ( get_option( Dox_Functions::OPT_SAFEMODE ) ) {
			$off_url = wp_nonce_url( admin_url( 'admin-post.php?action=dox_functions_safemode_off' ), self::nonce_action( 'safemode_off' ) );
			echo '<div class="notice notice-warning"><p><strong>'
				. esc_html( dox_t( 'safe_mode_global' ) ) . '</strong> '
				. '<a href="' . esc_url( $off_url ) . '">' . esc_html( dox_t( 'safe_mode_off' ) ) . '</a>'
				. '</p></div>';
		}

		$err = get_option( Dox_Functions::OPT_ERROR_NOTICE );
		if ( $err && is_array( $err ) ) {
			$id      = (int) ( $err['id'] ?? 0 );
			$snippet = $id ? Dox_Functions::get_snippet( $id ) : null;
			$title   = ( $snippet && $snippet->title ) ? $snippet->title : dox_t( 'no_title' );
			$message = (string) ( $err['message'] ?? '' );

			echo '<div class="notice notice-error"><p>'
				. esc_html( sprintf( dox_t( 'auto_disabled_notice' ), $title, $message ) ) . ' '
				. '<a href="' . esc_url( self::page_url( array( 'action' => 'edit', 'id' => $id ) ) ) . '">' . esc_html( dox_t( 'review_snippet' ) ) . '</a>'
				. '</p></div>';
		}
	}

	/**
	 * La pantalla vive en admin.php cuando cuelga del menú Dox Plugins y en
	 * tools.php solo cuando actúa el respaldo de menu(). La condición tiene que
	 * ser la misma que allí: WordPress responde "Sorry, you are not allowed to
	 * access this page" a una URL de tools.php si la página está registrada
	 * bajo otro padre, y de aquí salen todos los enlaces y las redirecciones.
	 */
	public static function page_url( $args = array() ) {
		$base = function_exists( 'dox_core' ) ? 'admin.php' : 'tools.php';
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( $base ) );
	}

	/**
	 * Hasta la 1.1.0 la pantalla era tools.php?page=dox-functions. Quien la tenga
	 * en marcadores llega aquí en vez de a un "no tienes permiso": WordPress
	 * avisa con este hook justo antes de cortar, todavía sin haber pintado nada.
	 */
	public function redirect_old_url() {
		global $pagenow;

		if ( 'tools.php' !== $pagenow || ! function_exists( 'dox_core' ) ) return;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo se lee para redirigir a la misma pantalla.
		if ( ! isset( $_GET['page'] ) || self::SLUG !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) return;
		if ( ! self::user_can_manage() ) return;

		$args = array();
		foreach ( array( 'action', 'id' ) as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET[ $key ] ) ) $args[ $key ] = sanitize_key( wp_unslash( $_GET[ $key ] ) );
		}

		wp_safe_redirect( self::page_url( $args ) );
		exit;
	}

	public function render_page() {
		if ( ! self::user_can_manage() ) {
			wp_die( esc_html( dox_t( 'unauthorized' ) ) );
		}

		// Visiting the plugin page acknowledges the auto-disable alert: the
		// list badges and editor notice carry the detail from here on.
		if ( get_option( Dox_Functions::OPT_ERROR_NOTICE ) ) {
			delete_option( Dox_Functions::OPT_ERROR_NOTICE );
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list';
		$id     = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

		echo '<div class="wrap dxf-app">';

		// Editing a snippet that no longer exists (deleted meanwhile) must not
		// render a ghost editor that pretends to save — say so and show the list.
		$missing = 'edit' === $action && $id && ! Dox_Functions::get_snippet( $id );
		if ( $missing ) {
			$action = 'list';
			$id     = 0;
		}

		if ( 'edit' === $action || 'new' === $action ) {
			// El editor lleva su propia barra con el título; el modo seguro se
			// avisa igual arriba, porque cambia lo que hace "Guardar".
			$this->render_safe_mode_strip();
			$this->render_editor( $id );
		} else {
			$this->render_header();
			if ( $missing ) {
				echo '<div class="dxf-strip dxf-strip--bad" role="alert"><span class="dxf-strip__icon">' . self::icon( 'alert' ) . '</span><p>' . esc_html( dox_t( 'not_found' ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			$this->render_list();
		}

		echo '</div>';
	}

	/**
	 * Iconos de trazo (24 × 24) en línea: sin peticiones ni fuentes de iconos,
	 * y heredan el color del texto.
	 */
	private static function icon( $name ) {
		$paths = array(
			'plus'     => '<path d="M12 5v14M5 12h14"/>',
			'php'      => '<path d="M8 3H7a2 2 0 0 0-2 2v5a2 2 0 0 1-2 2 2 2 0 0 1 2 2v5a2 2 0 0 0 2 2h1"/><path d="M16 21h1a2 2 0 0 0 2-2v-5a2 2 0 0 1 2-2 2 2 0 0 1-2-2V5a2 2 0 0 0-2-2h-1"/>',
			'html'     => '<path d="m16 18 6-6-6-6"/><path d="m8 6-6 6 6 6"/>',
			'globe'    => '<circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
			'lock'     => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
			'monitor'  => '<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/>',
			'zap'      => '<path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/>',
			'head'     => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/>',
			'body'     => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M7 13h10"/>',
			'footer'   => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 15h18"/>',
			'edit'     => '<path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/>',
			'copy'     => '<rect x="8" y="8" width="14" height="14" rx="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
			'trash'    => '<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>',
			'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
			'upload'   => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5"/><path d="M12 3v12"/>',
			'search'   => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
			'alert'    => '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4M12 17h.01"/>',
			'shield'   => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
			'back'     => '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
			'check'    => '<path d="M20 6 9 17l-5-5"/>',
		);
		if ( ! isset( $paths[ $name ] ) ) return '';
		return '<svg class="dxf-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[ $name ] . '</svg>';
	}

	/**
	 * Dónde corre cada snippet: ámbito (PHP) o ubicación (HTML), con su
	 * icono. El color de cada uno sale del CSS por data-where, siempre el mismo.
	 */
	private static function where_options( $type ) {
		if ( 'html' === $type ) {
			return array(
				'head'      => 'head',
				'body_open' => 'body',
				'footer'    => 'footer',
			);
		}
		return array(
			'everywhere' => 'globe',
			'admin'      => 'lock',
			'frontend'   => 'monitor',
			'once'       => 'zap',
		);
	}

	/** Clave del "dónde" de un snippet guardado: su ubicación o su ámbito. */
	private static function where_key( $s ) {
		return 'html' === ( $s->type ?? 'php' )
			? Dox_Functions::sanitize_location( $s->location ?? '' )
			: (string) $s->scope;
	}

	private static function where_chip( $s ) {
		$type  = 'html' === ( $s->type ?? 'php' ) ? 'html' : 'php';
		$key   = self::where_key( $s );
		$icons = self::where_options( $type );
		$label = 'html' === $type ? dox_t( 'loc_' . $key ) : dox_t( 'scope_' . $key );
		return '<span class="dxf-where" data-where="' . esc_attr( $key ) . '">'
			. self::icon( $icons[ $key ] ?? 'globe' )
			. esc_html( $label ) . '</span>';
	}

	private static function type_tile( $type ) {
		$type = 'html' === $type ? 'html' : 'php';
		return '<span class="dxf-type" data-type="' . $type . '" title="' . esc_attr( dox_t( 'type_' . $type ) ) . '">'
			. self::icon( $type ) . '</span>';
	}

	private function render_header( $subtitle = '' ) {
		?>
		<header class="dxf-head">
			<span class="dxf-appicon"><img src="<?php echo esc_url( DOX_FUNCTIONS_URL . 'assets/icon.svg' ); ?>" alt=""></span>
			<div class="dxf-head__text">
				<h1><?php echo esc_html( dox_t( 'plugin_name' ) ); ?> <span class="dxf-ver"><?php echo esc_html( DOX_FUNCTIONS_VERSION ); ?></span></h1>
				<p><?php echo esc_html( $subtitle ? $subtitle : dox_t( 'list_intro' ) ); ?></p>
			</div>
		</header>
		<?php
		// WordPress coloca aquí los avisos de otros plugins (los mueve tras
		// .wp-header-end), en vez de dentro de la primera tarjeta.
		?>
		<hr class="wp-header-end">
		<?php
		$this->render_safe_mode_strip();
	}

	private function render_safe_mode_strip() {
		$forced = Dox_Functions::safe_mode_forced();
		$safe   = (bool) get_option( Dox_Functions::OPT_SAFEMODE );
		?>
		<?php if ( Dox_Functions::php_locked() ) : ?>
			<div class="dxf-strip dxf-strip--warn" role="status">
				<span class="dxf-strip__icon"><?php echo self::icon( 'lock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<p><strong><?php echo esc_html( dox_t( 'code_locked_title' ) ); ?></strong> <?php echo esc_html( dox_t( 'code_locked_body' ) ); ?></p>
			</div>
		<?php endif; ?>
		<?php if ( $forced ) : ?>
			<div class="dxf-strip dxf-strip--warn" role="status">
				<span class="dxf-strip__icon"><?php echo self::icon( 'shield' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<p><strong><?php echo esc_html( dox_t( 'safe_strip_title' ) ); ?></strong> <?php echo esc_html( dox_t( 'safe_strip_forced' ) ); ?></p>
			</div>
		<?php elseif ( $safe ) : ?>
			<div class="dxf-strip dxf-strip--warn" role="status">
				<span class="dxf-strip__icon"><?php echo self::icon( 'shield' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<p><strong><?php echo esc_html( dox_t( 'safe_strip_title' ) ); ?></strong> <?php echo esc_html( dox_t( 'safe_strip_body' ) ); ?></p>
				<a class="dxf-btn dxf-btn--dark" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=dox_functions_safemode_off' ), self::nonce_action( 'safemode_off' ) ) ); ?>"><?php echo esc_html( dox_t( 'safe_mode_off' ) ); ?></a>
			</div>
		<?php endif;
	}

	private function render_list() {
		$snippets    = Dox_Functions::get_snippets();
		$date_format = get_option( 'date_format' );

		// Read-only result flags from import redirects (display only).
		$imported   = isset( $_GET['imported'] ) ? absint( $_GET['imported'] ) : null;
		$import_err = ! empty( $_GET['import_err'] );
		$refused    = isset( $_GET['refused'] ) ? sanitize_key( wp_unslash( $_GET['refused'] ) ) : '';
		$skipped    = array(
			'html' => isset( $_GET['skipped_html'] ) ? absint( $_GET['skipped_html'] ) : 0,
			'php'  => isset( $_GET['skipped_php'] ) ? absint( $_GET['skipped_php'] ) : 0,
		);

		$count = array( 'all' => count( $snippets ), 'on' => 0, 'off' => 0, 'error' => 0, 'php' => 0, 'html' => 0 );
		foreach ( $snippets as $s ) {
			$count[ $s->is_active ? 'on' : 'off' ]++;
			if ( ! empty( $s->last_error ) ) $count['error']++;
			$count[ 'html' === ( $s->type ?? 'php' ) ? 'html' : 'php' ]++;
		}

		$export_url = wp_nonce_url( admin_url( 'admin-post.php?action=dox_functions_export' ), self::nonce_action( 'export' ) );
		?>
		<?php if ( null !== $imported && ! $import_err && ( $imported || ! array_sum( $skipped ) ) ) : ?>
			<div class="dxf-strip dxf-strip--ok" role="status">
				<span class="dxf-strip__icon"><?php echo self::icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<p><?php echo esc_html( sprintf( dox_t( 'imported_ok' ), $imported ) ); ?></p>
			</div>
		<?php endif; ?>
		<?php
		// Lo que se rechazó por tipo: al duplicar o activar (refused) y al
		// importar (skipped_*), con el motivo de type_refused().
		$refusals = array();
		if ( in_array( $refused, array( 'html', 'php' ), true ) ) {
			$refusals[] = dox_t( self::type_refused( $refused ) ?: ( 'html' === $refused ? 'html_needs_unfiltered' : 'php_locked' ) );
		}
		foreach ( $skipped as $type => $n ) {
			if ( $n ) {
				$refusals[] = sprintf( dox_t( 'import_skipped' ), $n ) . ' ' . dox_t( 'html' === $type ? 'html_needs_unfiltered' : 'php_locked' );
			}
		}
		foreach ( $refusals as $message ) : ?>
			<div class="dxf-strip dxf-strip--bad" role="alert">
				<span class="dxf-strip__icon"><?php echo self::icon( 'lock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<p><?php echo esc_html( $message ); ?></p>
			</div>
		<?php endforeach; ?>
		<?php if ( $import_err ) : ?>
			<div class="dxf-strip dxf-strip--bad" role="alert">
				<span class="dxf-strip__icon"><?php echo self::icon( 'alert' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<p><?php echo esc_html( dox_t( 'import_error' ) ); ?></p>
			</div>
		<?php endif; ?>
		<?php if ( $count['error'] ) : ?>
			<div class="dxf-strip dxf-strip--bad" role="status">
				<span class="dxf-strip__icon"><?php echo self::icon( 'alert' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<p><strong><?php echo esc_html( sprintf( dox_t( 1 === $count['error'] ? 'errors_one' : 'errors_many' ), $count['error'] ) ); ?></strong> <?php echo esc_html( dox_t( 1 === $count['error'] ? 'errors_hint_one' : 'errors_hint_many' ) ); ?></p>
				<button type="button" class="dxf-btn dxf-btn--light" data-dxf-filter-go="error"><?php echo esc_html( dox_t( 'see_them' ) ); ?></button>
			</div>
		<?php endif; ?>

		<?php if ( empty( $snippets ) ) : ?>
			<?php $this->render_empty(); ?>
			<?php return; ?>
		<?php endif; ?>

		<div class="dxf-bar">
			<div class="dxf-stats">
				<div class="dxf-stat" data-tone="on">
					<span class="dxf-stat__value num"><?php echo (int) $count['on']; ?></span>
					<span class="dxf-stat__label"><span class="dxf-dot"></span><?php echo esc_html( dox_t( 'stat_on' ) ); ?></span>
				</div>
				<div class="dxf-stat" data-tone="off">
					<span class="dxf-stat__value num"><?php echo (int) $count['off']; ?></span>
					<span class="dxf-stat__label"><span class="dxf-dot"></span><?php echo esc_html( dox_t( 'stat_off' ) ); ?></span>
				</div>
				<div class="dxf-stat" data-tone="<?php echo $count['error'] ? 'bad' : 'flat'; ?>">
					<span class="dxf-stat__value num"><?php echo (int) $count['error']; ?></span>
					<span class="dxf-stat__label"><span class="dxf-dot"></span><?php echo esc_html( dox_t( 'stat_error' ) ); ?></span>
				</div>
			</div>
			<div class="dxf-bar__tools">
				<a class="dxf-btn" href="<?php echo esc_url( $export_url ); ?>"><?php echo self::icon( 'download' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span class="dxf-btn__text"><?php echo esc_html( dox_t( 'export_json' ) ); ?></span></a>
				<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dxf-import">
					<?php wp_nonce_field( self::nonce_action( 'import' ) ); ?>
					<input type="hidden" name="action" value="dox_functions_import">
					<label class="dxf-btn" title="<?php echo esc_attr( dox_t( 'import_note' ) ); ?>">
						<?php echo self::icon( 'upload' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span class="dxf-btn__text"><?php echo esc_html( dox_t( 'import_json' ) ); ?></span>
						<input type="file" id="dxf-import-file" name="import_file" accept=".json,application/json">
					</label>
				</form>
				<a class="dxf-btn dxf-btn--dark" href="<?php echo esc_url( self::page_url( array( 'action' => 'new' ) ) ); ?>"><?php echo self::icon( 'plus' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php echo esc_html( dox_t( 'new_function' ) ); ?></span></a>
			</div>
		</div>

		<div class="dxf-card dxf-list">
			<?php // Filtros y buscador solo cuando hay lista que filtrar. ?>
			<?php if ( $count['all'] > 3 ) : ?>
				<div class="dxf-filters" role="toolbar" aria-label="<?php echo esc_attr( dox_t( 'filters' ) ); ?>">
					<button type="button" class="dxf-filter" data-filter="all" aria-pressed="true"><?php echo esc_html( dox_t( 'filter_all' ) ); ?> <b class="num"><?php echo (int) $count['all']; ?></b></button>
					<button type="button" class="dxf-filter" data-filter="on" aria-pressed="false"><span class="dxf-dot" data-tone="on"></span><?php echo esc_html( dox_t( 'stat_on' ) ); ?> <b class="num"><?php echo (int) $count['on']; ?></b></button>
					<button type="button" class="dxf-filter" data-filter="off" aria-pressed="false"><span class="dxf-dot" data-tone="off"></span><?php echo esc_html( dox_t( 'stat_off' ) ); ?> <b class="num"><?php echo (int) $count['off']; ?></b></button>
					<?php if ( $count['error'] ) : ?>
						<button type="button" class="dxf-filter" data-filter="error" aria-pressed="false"><span class="dxf-dot" data-tone="bad"></span><?php echo esc_html( dox_t( 'stat_error' ) ); ?> <b class="num"><?php echo (int) $count['error']; ?></b></button>
					<?php endif; ?>
					<?php if ( $count['php'] && $count['html'] ) : ?>
						<span class="dxf-filters__sep" aria-hidden="true"></span>
						<button type="button" class="dxf-filter" data-filter="php" aria-pressed="false"><span class="dxf-dot" data-type="php"></span>PHP <b class="num"><?php echo (int) $count['php']; ?></b></button>
						<button type="button" class="dxf-filter" data-filter="html" aria-pressed="false"><span class="dxf-dot" data-type="html"></span>HTML <b class="num"><?php echo (int) $count['html']; ?></b></button>
					<?php endif; ?>
					<label class="dxf-search">
						<?php echo self::icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<span class="screen-reader-text"><?php echo esc_html( dox_t( 'search' ) ); ?></span>
						<input type="search" id="dxf-search" placeholder="<?php echo esc_attr( dox_t( 'search' ) ); ?>" autocomplete="off">
						<kbd class="dxf-kbd">/</kbd>
					</label>
				</div>
			<?php endif; ?>

			<div class="dxf-scroll">
				<table class="dxf-table">
					<thead>
						<tr>
							<th class="dxf-col-switch"><span class="screen-reader-text"><?php echo esc_html( dox_t( 'status' ) ); ?></span></th>
							<th><?php echo esc_html( dox_t( 'col_function' ) ); ?></th>
							<th><?php echo esc_html( dox_t( 'col_where' ) ); ?></th>
							<th class="r dxf-col-prio"><?php echo esc_html( dox_t( 'priority' ) ); ?></th>
							<th class="dxf-col-date"><?php echo esc_html( dox_t( 'updated' ) ); ?></th>
							<th><span class="screen-reader-text"><?php echo esc_html( dox_t( 'actions' ) ); ?></span></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $snippets as $s ) :
						$type          = 'html' === ( $s->type ?? 'php' ) ? 'html' : 'php';
						$toggle_nonce  = wp_create_nonce( self::nonce_action( 'toggle', $s->id ) );
						$toggle_url    = add_query_arg( '_wpnonce', $toggle_nonce, admin_url( 'admin-post.php?action=dox_functions_toggle&id=' . $s->id ) );
						$edit_url      = self::page_url( array( 'action' => 'edit', 'id' => $s->id ) );
						$duplicate_url = wp_nonce_url( admin_url( 'admin-post.php?action=dox_functions_duplicate&id=' . $s->id ), self::nonce_action( 'duplicate', $s->id ) );
						$delete_url    = wp_nonce_url( admin_url( 'admin-post.php?action=dox_functions_delete&id=' . $s->id ), self::nonce_action( 'delete', $s->id ) );
						$row_title     = $s->title ?: dox_t( 'no_title' );
						$search_text   = strtolower( $row_title . ' ' . wp_strip_all_tags( (string) $s->description ) );
						?>
						<tr data-active="<?php echo $s->is_active ? '1' : '0'; ?>" data-type="<?php echo esc_attr( $type ); ?>" data-error="<?php echo empty( $s->last_error ) ? '0' : '1'; ?>" data-search="<?php echo esc_attr( $search_text ); ?>">
							<td class="dxf-col-switch">
								<a class="dxf-switch <?php echo $s->is_active ? 'is-on' : ''; ?>"
									href="<?php echo esc_url( $toggle_url ); ?>"
									role="switch"
									aria-checked="<?php echo $s->is_active ? 'true' : 'false'; ?>"
									aria-label="<?php echo esc_attr( $row_title ); ?>"
									data-id="<?php echo (int) $s->id; ?>"
									data-nonce="<?php echo esc_attr( $toggle_nonce ); ?>">
									<span class="dxf-switch__knob"></span>
								</a>
							</td>
							<td>
								<div class="dxf-fn">
									<?php echo self::type_tile( $type ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<div class="dxf-fn__text">
										<a class="dxf-fn__title" href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $row_title ); ?></a>
										<?php if ( $s->description ) : ?>
											<span class="dxf-fn__desc"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $s->description ), 16 ) ); ?></span>
										<?php endif; ?>
										<?php // En el teléfono la columna "Dónde corre" no cabe: va aquí. ?>
										<span class="dxf-fn__where"><?php echo self::where_chip( $s ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
										<?php if ( ! empty( $s->last_error ) ) : ?>
											<span class="dxf-fn__error" title="<?php echo esc_attr( $s->last_error ); ?>"><?php echo self::icon( 'alert' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo esc_html( dox_t( 'auto_disabled' ) . ': ' . wp_html_excerpt( $s->last_error, 80, '…' ) ); ?></span>
										<?php endif; ?>
									</div>
								</div>
							</td>
							<td><?php echo self::where_chip( $s ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
							<td class="r num dxf-col-prio"><?php echo (int) $s->priority; ?></td>
							<td class="dxf-col-date dxf-muted"><?php echo esc_html( mysql2date( $date_format, $s->updated_at ) ); ?></td>
							<td class="dxf-actions">
								<a class="dxf-btn dxf-btn--sm dxf-edit" href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( dox_t( 'edit' ) ); ?></a>
								<a class="dxf-iconbtn" href="<?php echo esc_url( $duplicate_url ); ?>" title="<?php echo esc_attr( dox_t( 'duplicate' ) ); ?>" aria-label="<?php echo esc_attr( dox_t( 'duplicate' ) . ': ' . $row_title ); ?>"><?php echo self::icon( 'copy' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
								<a class="dxf-iconbtn dxf-iconbtn--bad" href="<?php echo esc_url( $delete_url ); ?>" title="<?php echo esc_attr( dox_t( 'delete' ) ); ?>" aria-label="<?php echo esc_attr( dox_t( 'delete' ) . ': ' . $row_title ); ?>" onclick="return confirm('<?php echo esc_js( dox_t( 'confirm_delete' ) ); ?>');"><?php echo self::icon( 'trash' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<div class="dxf-nomatch" hidden>
					<p><?php echo esc_html( dox_t( 'no_match' ) ); ?></p>
					<button type="button" class="dxf-btn" data-dxf-filter-go="all"><?php echo esc_html( dox_t( 'show_all' ) ); ?></button>
				</div>
			</div>
		</div>

		<?php $this->render_help(); ?>
		<?php
	}

	/** Lista vacía: las dos maneras de empezar, cada una con para qué sirve. */
	private function render_empty() {
		?>
		<div class="dxf-card dxf-empty">
			<h2><?php echo esc_html( dox_t( 'empty_title' ) ); ?></h2>
			<p><?php echo esc_html( dox_t( 'empty_state' ) ); ?></p>
			<div class="dxf-start">
				<?php foreach ( array( 'php', 'html' ) as $type ) :
					$why = self::type_refused( $type );
					?>
					<?php if ( $why ) : ?>
						<div class="dxf-start__card is-disabled" data-type="<?php echo esc_attr( $type ); ?>" aria-disabled="true">
							<?php echo self::type_tile( $type ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<strong><?php echo esc_html( dox_t( 'type_' . $type ) ); ?></strong>
							<span class="dxf-refused"><?php echo esc_html( dox_t( $why ) ); ?></span>
						</div>
					<?php else : ?>
						<a class="dxf-start__card" data-type="<?php echo esc_attr( $type ); ?>" href="<?php echo esc_url( self::page_url( array( 'action' => 'new', 'type' => $type ) ) ); ?>">
							<?php echo self::type_tile( $type ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<strong><?php echo esc_html( dox_t( 'type_' . $type ) ); ?></strong>
							<span><?php echo esc_html( dox_t( 'type_' . $type . '_desc' ) ); ?></span>
							<em><?php echo esc_html( dox_t( 'type_' . $type . '_examples' ) ); ?></em>
						</a>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dxf-import dxf-empty__import">
				<?php wp_nonce_field( self::nonce_action( 'import' ) ); ?>
				<input type="hidden" name="action" value="dox_functions_import">
				<label class="dxf-link">
					<?php echo esc_html( dox_t( 'empty_import' ) ); ?>
					<input type="file" id="dxf-import-file" name="import_file" accept=".json,application/json">
				</label>
			</form>
		</div>
		<?php $this->render_help(); ?>
		<?php
	}

	/** Autoservicio: qué hacer si un snippet rompe el sitio, a la vista. */
	private function render_help() {
		?>
		<aside class="dxf-help">
			<span class="dxf-help__icon"><?php echo self::icon( 'shield' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			<div>
				<strong><?php echo esc_html( dox_t( 'help_title' ) ); ?></strong>
				<p><?php echo wp_kses( dox_t( 'help_body' ), array( 'code' => array() ) ); ?></p>
			</div>
		</aside>
		<?php
	}

	private function render_editor( $id ) {
		$snippet = $id ? Dox_Functions::get_snippet( $id ) : null;

		// A failed save stashes the submitted values so nothing typed is lost;
		// they take precedence over what is stored in the database.
		$stash = get_transient( self::STASH_KEY . get_current_user_id() );
		if ( $stash ) delete_transient( self::STASH_KEY . get_current_user_id() );
		$src          = is_array( $stash ) && isset( $stash['data'] ) ? $stash['data'] : null;
		$syntax_error = is_array( $stash ) ? ( $stash['error'] ?? '' ) : '';

		// Un snippet nuevo puede llegar con ?type=html desde la lista vacía.
		$new_type = isset( $_GET['type'] ) ? Dox_Functions::sanitize_type( sanitize_key( wp_unslash( $_GET['type'] ) ) ) : 'php'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Un snippet nuevo no arranca en un tipo que este usuario no puede usar.
		if ( ! $snippet && self::type_refused( $new_type ) ) {
			$other = 'php' === $new_type ? 'html' : 'php';
			if ( ! self::type_refused( $other ) ) $new_type = $other;
		}

		$type        = $src ? Dox_Functions::sanitize_type( (string) ( $src['type'] ?? 'php' ) ) : ( $snippet ? Dox_Functions::sanitize_type( $snippet->type ?? 'php' ) : $new_type );
		$title       = $src ? (string) ( $src['title'] ?? '' )       : ( $snippet->title ?? '' );
		$description = $src ? (string) ( $src['description'] ?? '' ) : ( $snippet->description ?? '' );
		$code        = $src ? (string) ( $src['code'] ?? '' )        : ( $snippet->code ?? ( 'html' === $type ? '' : dox_t( 'starter_code' ) ) );
		$scope       = $src ? (string) ( $src['scope'] ?? 'everywhere' ) : ( $snippet->scope ?? 'everywhere' );
		$location    = $src ? Dox_Functions::sanitize_location( (string) ( $src['location'] ?? 'head' ) ) : Dox_Functions::sanitize_location( $snippet->location ?? 'head' );
		$priority    = $src ? (int) ( $src['priority'] ?? 10 )       : (int) ( $snippet->priority ?? 10 );
		$is_active   = $src ? (int) ! empty( $src['is_active'] )     : ( $snippet ? (int) $snippet->is_active : 0 );

		$date_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dxf-editor" data-type="<?php echo esc_attr( $type ); ?>">
			<?php wp_nonce_field( self::nonce_action( 'save', $id ) ); ?>
			<input type="hidden" name="action" value="dox_functions_save">
			<input type="hidden" name="id" value="<?php echo (int) $id; ?>">

			<div class="dxf-topbar">
				<a class="dxf-iconbtn" href="<?php echo esc_url( self::page_url() ); ?>" title="<?php echo esc_attr( dox_t( 'back' ) ); ?>" aria-label="<?php echo esc_attr( dox_t( 'back' ) ); ?>"><?php echo self::icon( 'back' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
				<div class="dxf-topbar__title">
					<span><?php echo esc_html( dox_t( 'my_functions' ) ); ?></span>
					<h1><?php echo esc_html( $id ? ( $title ?: dox_t( 'edit_function' ) ) : dox_t( 'create_function' ) ); ?></h1>
				</div>
				<span class="dxf-dirty" hidden><?php echo esc_html( dox_t( 'unsaved' ) ); ?></span>
				<label class="dxf-switch-inline">
					<input type="checkbox" name="is_active" value="1" <?php checked( $is_active, 1 ); ?>>
					<span class="dxf-switch <?php echo $is_active ? 'is-on' : ''; ?>"><span class="dxf-switch__knob"></span></span>
					<span><?php echo esc_html( dox_t( 'active' ) ); ?></span>
				</label>
				<button type="button" id="dxf-validate" class="dxf-btn dxf-only-php"><span class="dxf-btn__label"><?php echo esc_html( dox_t( 'validate' ) ); ?></span></button>
				<button type="submit" class="dxf-btn dxf-btn--dark"><span class="dxf-btn__label"><?php echo esc_html( dox_t( 'save_changes' ) ); ?></span></button>
			</div>
			<hr class="wp-header-end">

			<?php if ( ! empty( $_GET['saved'] ) ) : ?>
				<div class="dxf-strip dxf-strip--ok" role="status">
					<span class="dxf-strip__icon"><?php echo self::icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<p><?php echo esc_html( dox_t( 'saved' ) ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $syntax_error ) : ?>
				<div class="dxf-strip dxf-strip--bad" role="alert">
					<span class="dxf-strip__icon"><?php echo self::icon( 'alert' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<p><strong><?php echo esc_html( dox_t( 'not_saved' ) ); ?></strong> <?php echo esc_html( $syntax_error ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $snippet && ! empty( $snippet->last_error ) ) : ?>
				<div class="dxf-strip dxf-strip--bad" role="status">
					<span class="dxf-strip__icon"><?php echo self::icon( 'alert' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<p>
						<strong><?php echo esc_html( dox_t( 'auto_disabled' ) ); ?>:</strong>
						<?php echo esc_html( $snippet->last_error ); ?>
						<?php if ( ! empty( $snippet->last_error_at ) ) : ?>
							<span class="dxf-muted">(<?php echo esc_html( mysql2date( $date_format, $snippet->last_error_at ) ); ?>)</span>
						<?php endif; ?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( self::type_refused( $type ) ) : ?>
				<?php
				// El tipo actual está prohibido, así que su radio sale desactivado y
				// no se envía: este hidden lo conserva. Va antes de los radios para
				// que, si se elige el otro tipo, ese radio llegue después y gane
				// (PHP se queda con el último valor de un mismo nombre).
				?>
				<input type="hidden" name="type" value="<?php echo esc_attr( $type ); ?>">
			<?php endif; ?>
			<fieldset class="dxf-types">
				<legend class="screen-reader-text"><?php echo esc_html( dox_t( 'type' ) ); ?></legend>
				<?php foreach ( array( 'php', 'html' ) as $t ) :
					$why = self::type_refused( $t );
					?>
					<label class="dxf-choice<?php echo $why ? ' is-disabled' : ''; ?>">
						<input type="radio" name="type" value="<?php echo esc_attr( $t ); ?>" <?php checked( $type, $t ); ?> <?php disabled( (bool) $why ); ?>>
						<?php echo self::type_tile( $t ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<span class="dxf-choice__text">
							<strong><?php echo esc_html( dox_t( 'type_' . $t ) ); ?></strong>
							<?php if ( $why ) : ?>
								<span class="dxf-refused"><?php echo esc_html( dox_t( $why ) ); ?></span>
							<?php else : ?>
								<span><?php echo esc_html( dox_t( 'type_' . $t . '_desc' ) ); ?></span>
							<?php endif; ?>
						</span>
						<span class="dxf-choice__check"><?php echo self::icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					</label>
				<?php endforeach; ?>
			</fieldset>

			<div class="dxf-layout">
				<div class="dxf-card dxf-main">
					<div class="dxf-field">
						<label for="dxf-title"><?php echo esc_html( dox_t( 'title' ) ); ?></label>
						<input type="text" id="dxf-title" name="title" value="<?php echo esc_attr( $title ); ?>" placeholder="<?php echo esc_attr( dox_t( 'placeholder_title' ) ); ?>" required>
					</div>
					<div class="dxf-field">
						<label for="dxf-description"><?php echo esc_html( dox_t( 'description' ) ); ?> <span class="dxf-optional"><?php echo esc_html( dox_t( 'optional' ) ); ?></span></label>
						<input type="text" id="dxf-description" name="description" value="<?php echo esc_attr( $description ); ?>" placeholder="<?php echo esc_attr( dox_t( 'placeholder_desc' ) ); ?>">
					</div>
					<div class="dxf-field dxf-field--code">
						<div class="dxf-code-head">
							<label for="dxf-code">
								<span class="dxf-only-php"><?php echo esc_html( dox_t( 'php_code' ) ); ?></span>
								<span class="dxf-only-html"><?php echo esc_html( dox_t( 'html_code' ) ); ?></span>
							</label>
							<span id="dxf-validate-result" class="dxf-validate" role="status" aria-live="polite"></span>
						</div>
						<textarea id="dxf-code" name="code" rows="18" spellcheck="false"><?php echo esc_textarea( $code ); ?></textarea>
						<p class="dxf-hint dxf-only-php"><?php echo wp_kses_post( dox_t( 'no_open_tag' ) ); ?></p>
						<p class="dxf-hint dxf-only-html"><?php echo wp_kses_post( dox_t( 'html_hint' ) ); ?></p>
					</div>
				</div>

				<aside class="dxf-side">
					<div class="dxf-card">
						<h2 class="dxf-side__title"><?php echo esc_html( dox_t( 'where_title' ) ); ?></h2>
						<?php foreach ( array( 'php', 'html' ) as $t ) :
							$field   = 'html' === $t ? 'location' : 'scope';
							$current = 'html' === $t ? $location : $scope;
							?>
							<div class="dxf-where-list dxf-only-<?php echo esc_attr( $t ); ?>" role="radiogroup" aria-label="<?php echo esc_attr( dox_t( 'where_title' ) ); ?>">
								<?php foreach ( self::where_options( $t ) as $key => $icon ) :
									$label_key = 'html' === $t ? 'loc_' . $key : 'scope_' . $key;
									?>
									<label class="dxf-option" data-where="<?php echo esc_attr( $key ); ?>">
										<input type="radio" name="<?php echo esc_attr( $field ); ?>" value="<?php echo esc_attr( $key ); ?>" <?php checked( $current, $key ); ?>>
										<span class="dxf-option__icon"><?php echo self::icon( $icon ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
										<span class="dxf-option__text">
											<strong><?php echo esc_html( dox_t( $label_key ) ); ?></strong>
											<span><?php echo esc_html( dox_t( $label_key . '_desc' ) ); ?></span>
										</span>
									</label>
								<?php endforeach; ?>
							</div>
						<?php endforeach; ?>

						<div class="dxf-field dxf-field--prio">
							<label for="dxf-priority"><?php echo esc_html( dox_t( 'priority' ) ); ?></label>
							<input type="number" id="dxf-priority" name="priority" value="<?php echo (int) $priority; ?>" min="0" max="999">
							<p class="dxf-hint dxf-only-php"><?php echo esc_html( dox_t( 'priority_hint' ) ); ?></p>
							<p class="dxf-hint dxf-only-html"><?php echo esc_html( dox_t( 'priority_hint_html' ) ); ?></p>
						</div>
					</div>

					<?php $this->render_help(); ?>
				</aside>
			</div>
		</form>
		<?php
	}

	public function handle_save() {
		if ( ! self::user_can_manage() ) wp_die( esc_html( dox_t( 'unauthorized' ) ) );
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		check_admin_referer( self::nonce_action( 'save', $id ) );

		// The snippet body is PHP source: it cannot be "sanitised" without
		// destroying it — storing and running it IS the plugin's purpose. It is
		// unslashed here, checked for parse errors by validate_syntax() below
		// before it is ever stored, and only an author who passes
		// user_can_manage() (a Super Admin on multisite) plus a valid nonce can
		// reach this line; type_refused() then blocks PHP under
		// DISALLOW_FILE_MODS / DISALLOW_FILE_EDIT and HTML without
		// unfiltered_html. The InputNotSanitized sniff can't follow that, so it is
		// suppressed here deliberately.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$code = wp_unslash( $_POST['code'] ?? '' );

		// Unslash (WordPress adds slashes to all superglobals) and sanitise at
		// the point of entry. save_snippet() re-sanitises defensively, but doing
		// it here too keeps input handling explicit and passes Plugin Check's
		// ValidatedSanitizedInput rule. Description keeps limited HTML (wp_kses_post).
		$data = array(
			'title'       => sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ),
			'description' => wp_kses_post( wp_unslash( $_POST['description'] ?? '' ) ),
			'code'        => $code,
			'type'        => Dox_Functions::sanitize_type( sanitize_key( wp_unslash( $_POST['type'] ?? 'php' ) ) ),
			'scope'       => sanitize_key( wp_unslash( $_POST['scope'] ?? 'everywhere' ) ),
			'location'    => Dox_Functions::sanitize_location( sanitize_key( wp_unslash( $_POST['location'] ?? 'head' ) ) ),
			'priority'    => absint( $_POST['priority'] ?? 10 ),
			'is_active'   => ! empty( $_POST['is_active'] ),
		);

		// Validation BEFORE saving (syntax for PHP, stray PHP tags for HTML).
		// On failure, stash everything the user submitted so the editor
		// repopulates — the typed code must survive the redirect, not just the
		// error message.
		$why   = self::type_refused( $data['type'] );
		$check = $why ? dox_t( $why ) : Dox_Functions::validate_snippet( $data['type'], $code );
		if ( true !== $check ) {
			set_transient(
				self::STASH_KEY . get_current_user_id(),
				array( 'error' => $check, 'data' => $data ),
				5 * MINUTE_IN_SECONDS
			);
			$redirect = $id
				? self::page_url( array( 'action' => 'edit', 'id' => $id ) )
				: self::page_url( array( 'action' => 'new' ) );
			wp_safe_redirect( $redirect );
			exit;
		}

		// Editing a snippet that was deleted meanwhile: fall back to inserting
		// a new row instead of a zero-row UPDATE that pretends to save.
		if ( $id > 0 && ! Dox_Functions::get_snippet( $id ) ) {
			$id = 0;
		}

		$id = Dox_Functions::save_snippet( $data, $id );

		wp_safe_redirect( self::page_url( array( 'action' => 'edit', 'id' => $id, 'saved' => 1 ) ) );
		exit;
	}

	public function handle_delete() {
		if ( ! self::user_can_manage() ) wp_die( esc_html( dox_t( 'unauthorized' ) ) );
		$id = (int) ( $_GET['id'] ?? 0 );
		check_admin_referer( self::nonce_action( 'delete', $id ) );
		Dox_Functions::delete_snippet( $id );
		wp_safe_redirect( self::page_url() );
		exit;
	}

	public function handle_toggle() {
		if ( ! self::user_can_manage() ) wp_die( esc_html( dox_t( 'unauthorized' ) ) );
		$id = (int) ( $_GET['id'] ?? 0 );
		check_admin_referer( self::nonce_action( 'toggle', $id ) );
		$s  = Dox_Functions::get_snippet( $id );
		if ( $s ) {
			// Apagar siempre se puede; encender, solo un tipo permitido.
			$type = Dox_Functions::sanitize_type( $s->type ?? 'php' );
			if ( ! $s->is_active && self::type_refused( $type ) ) {
				wp_safe_redirect( self::page_url( array( 'refused' => $type ) ) );
				exit;
			}
			Dox_Functions::set_active( $id, ! $s->is_active );
		}
		wp_safe_redirect( self::page_url() );
		exit;
	}

	public function handle_duplicate() {
		if ( ! self::user_can_manage() ) wp_die( esc_html( dox_t( 'unauthorized' ) ) );
		$id = (int) ( $_GET['id'] ?? 0 );
		check_admin_referer( self::nonce_action( 'duplicate', $id ) );

		$s = Dox_Functions::get_snippet( $id );
		if ( ! $s ) {
			wp_safe_redirect( self::page_url() );
			exit;
		}

		$type = Dox_Functions::sanitize_type( $s->type ?? 'php' );
		if ( self::type_refused( $type ) ) {
			wp_safe_redirect( self::page_url( array( 'refused' => $type ) ) );
			exit;
		}

		$new_id = Dox_Functions::save_snippet( array(
			'title'       => mb_substr( (string) $s->title, 0, 180 ) . dox_t( 'copy_suffix' ),
			'description' => $s->description,
			'code'        => $s->code,
			'type'        => $type,
			'scope'       => $s->scope,
			'location'    => $s->location ?? 'head',
			'priority'    => $s->priority,
			'is_active'   => 0, // copies always start off
		) );

		wp_safe_redirect( self::page_url( array( 'action' => 'edit', 'id' => $new_id ) ) );
		exit;
	}

	public function handle_export() {
		if ( ! self::user_can_manage() ) wp_die( esc_html( dox_t( 'unauthorized' ) ) );
		check_admin_referer( self::nonce_action( 'export' ) );

		$snippets = Dox_Functions::get_snippets();
		$items    = array();
		foreach ( $snippets as $s ) {
			$items[] = array(
				'title'       => $s->title,
				'description' => $s->description,
				'code'        => $s->code,
				'type'        => $s->type ?? 'php',
				'scope'       => $s->scope,
				'location'    => $s->location ?? 'head',
				'priority'    => (int) $s->priority,
				'is_active'   => (bool) $s->is_active,
			);
		}

		$payload = array(
			'plugin'      => 'dox-functions',
			'version'     => DOX_FUNCTIONS_VERSION,
			'exported_at' => gmdate( 'c' ),
			'snippets'    => $items,
		);

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="dox-functions-' . gmdate( 'Ymd-His' ) . '.json"' );
		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		exit;
	}

	public function handle_import() {
		if ( ! self::user_can_manage() ) wp_die( esc_html( dox_t( 'unauthorized' ) ) );
		check_admin_referer( self::nonce_action( 'import' ) );

		$file = $_FILES['import_file'] ?? null;
		if ( ! $file || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] )
			|| (int) $file['size'] > self::IMPORT_MAX_BYTES ) {
			wp_safe_redirect( self::page_url( array( 'import_err' => 1 ) ) );
			exit;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local upload tmp file.
		$raw  = file_get_contents( $file['tmp_name'] );
		$json = json_decode( (string) $raw, true );

		// Accept our own export format, or a bare array of snippet objects.
		$items = null;
		if ( is_array( $json ) && isset( $json['snippets'] ) && is_array( $json['snippets'] ) ) {
			$items = $json['snippets'];
		} elseif ( is_array( $json ) && isset( $json[0] ) ) {
			$items = $json;
		}
		if ( empty( $items ) ) {
			wp_safe_redirect( self::page_url( array( 'import_err' => 1 ) ) );
			exit;
		}

		$count   = 0;
		$skipped = array( 'html' => 0, 'php' => 0 );
		foreach ( array_slice( $items, 0, self::IMPORT_MAX_ITEMS ) as $item ) {
			if ( ! is_array( $item ) ) continue;
			$code = self::import_text( $item, 'code' );
			if ( '' === trim( $code ) ) continue;

			// Un tipo que este usuario o este sitio no permite no entra, ni
			// siquiera desactivado: se cuenta y se avisa en la lista.
			$type = Dox_Functions::sanitize_type( self::import_text( $item, 'type', 'php' ) );
			if ( self::type_refused( $type ) ) {
				$skipped[ $type ]++;
				continue;
			}

			// Imported snippets NEVER arrive active: they were written for
			// another site and must be reviewed here before running.
			// save_snippet() sanitises every field and whitelists the scope.
			$new_id = Dox_Functions::save_snippet( array(
				'title'       => self::import_text( $item, 'title' ),
				'description' => self::import_text( $item, 'description' ),
				'code'        => $code,
				'type'        => $type,
				'scope'       => self::import_text( $item, 'scope', 'everywhere' ),
				'location'    => self::import_text( $item, 'location', 'head' ),
				'priority'    => is_numeric( $item['priority'] ?? null ) ? (int) $item['priority'] : 10,
				'is_active'   => 0,
			) );

			// Flag invalid code visibly (badge in the list) without spamming
			// the site-wide notice — the importer is looking at the list.
			$check = Dox_Functions::validate_snippet( $type, $code );
			if ( true !== $check ) {
				Dox_Functions::record_error( $new_id, $check, false );
			}
			$count++;
		}

		wp_safe_redirect( self::page_url( array_filter( array(
			'imported'     => $count,
			'skipped_html' => $skipped['html'],
			'skipped_php'  => $skipped['php'],
		) ) + array( 'imported' => 0 ) ) );
		exit;
	}

	/**
	 * A field of an imported item as text. A hand-edited JSON can carry an
	 * array or object where we expect a string; (string) on that throws an
	 * "Array to string conversion" warning, so anything non-scalar counts as
	 * missing.
	 */
	private static function import_text( $item, $key, $default = '' ) {
		$value = $item[ $key ] ?? $default;
		return is_scalar( $value ) ? (string) $value : $default;
	}

	public function handle_safemode_off() {
		// Turning safe mode OFF re-authorises stored snippets to execute, so it
		// needs the same trust as authoring them (super admin on multisite;
		// under DISALLOW_FILE_* PHP stays off anyway). The emergency ON switch stays broadly
		// available in the runner because it only ever *stops* execution.
		if ( ! self::user_can_manage() ) wp_die( esc_html( dox_t( 'unauthorized' ) ) );
		check_admin_referer( self::nonce_action( 'safemode_off' ) );
		delete_option( Dox_Functions::OPT_SAFEMODE );
		wp_safe_redirect( self::page_url() );
		exit;
	}

	/**
	 * AJAX: syntax-check the code in the editor without saving.
	 */
	public function ajax_validate() {
		if ( ! self::user_can_manage() ) {
			wp_send_json_error( array( 'message' => dox_t( 'unauthorized' ) ), 403 );
		}
		check_ajax_referer( self::nonce_action( 'validate' ), 'nonce' );

		// PHP source — not sanitisable; same rationale as handle_save(). It is
		// only parsed (token_get_all), never executed or stored here.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$code  = wp_unslash( $_POST['code'] ?? '' );
		$type  = Dox_Functions::sanitize_type( sanitize_key( wp_unslash( $_POST['type'] ?? 'php' ) ) );
		$check = Dox_Functions::validate_snippet( $type, $code );

		if ( true === $check ) {
			wp_send_json_success( array( 'message' => dox_t( 'syntax_ok' ) ) );
		}
		wp_send_json_error( array( 'message' => $check ) );
	}

	/**
	 * AJAX: toggle a snippet without a full page reload. The list's switch
	 * links keep working as plain nonce'd GETs when JS is unavailable.
	 */
	public function ajax_toggle() {
		if ( ! self::user_can_manage() ) {
			wp_send_json_error( array( 'message' => dox_t( 'unauthorized' ) ), 403 );
		}
		$id = (int) ( $_POST['id'] ?? 0 );
		check_ajax_referer( self::nonce_action( 'toggle', $id ), 'nonce' );

		$s  = Dox_Functions::get_snippet( $id );
		if ( ! $s ) {
			wp_send_json_error( array( 'message' => dox_t( 'not_found' ) ), 404 );
		}

		$new = ! $s->is_active;
		$why = $new ? self::type_refused( Dox_Functions::sanitize_type( $s->type ?? 'php' ) ) : '';
		if ( $why ) {
			// El JS cae al enlace normal, que vuelve a la lista con el motivo.
			wp_send_json_error( array( 'message' => dox_t( $why ) ), 403 );
		}
		Dox_Functions::set_active( $id, $new );
		wp_send_json_success( array( 'active' => (bool) $new ) );
	}
}
