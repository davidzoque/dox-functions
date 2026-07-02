<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Dox_Functions_Admin {

	const SLUG  = 'dox-functions';
	const CAP   = 'manage_options';
	const NONCE = 'dox_functions_nonce';

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
	 *  - DISALLOW_FILE_MODS / DISALLOW_FILE_EDIT: when a site has locked down
	 *    code editing via these constants, honour that intent — no snippet
	 *    management for anyone, regardless of role.
	 *
	 * The bare capability constant (self::CAP) is kept only as the menu
	 * registration cap; this method is the authoritative gate used by every
	 * handler and the page renderer.
	 */
	public static function user_can_manage() {
		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			return false;
		}
		if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
			return false;
		}
		if ( is_multisite() ) {
			return is_super_admin();
		}
		return current_user_can( self::CAP );
	}

	public function __construct() {
		add_action( 'admin_menu',            array( $this, 'menu' ) );
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

	public function menu() {
		// Don't even register the Tools page for users who aren't allowed to
		// run PHP here (subsite admins on multisite, or sites that locked down
		// code editing). Without the page registered, tools.php?page=dox-functions
		// is inaccessible; handlers below re-check as defence in depth.
		if ( ! self::user_can_manage() ) {
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

	public function assets( $hook ) {
		if ( 'tools_page_' . self::SLUG !== $hook ) return;
		if ( ! self::user_can_manage() ) return;

		wp_enqueue_style( 'dox-functions-admin', DOX_FUNCTIONS_URL . 'assets/admin.css', array(), DOX_FUNCTIONS_VERSION );
		// wp_enqueue_code_editor() pulls in CodeMirror (script + styles) itself.
		wp_enqueue_code_editor( array( 'type' => 'application/x-httpd-php' ) );
		wp_enqueue_script( 'dox-functions-admin', DOX_FUNCTIONS_URL . 'assets/admin.js', array( 'code-editor' ), DOX_FUNCTIONS_VERSION, true );
		wp_localize_script( 'dox-functions-admin', 'DoxFunctions', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE ),
			'i18n'    => array(
				'validating'    => dox_t( 'validating' ),
				'request_error' => dox_t( 'request_error' ),
			),
		) );
	}

	public function admin_favicon() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'tools_page_' . self::SLUG !== $screen->id ) return;
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
		if ( $screen && 'tools_page_' . self::SLUG === $screen->id ) return;

		if ( get_option( Dox_Functions::OPT_SAFEMODE ) ) {
			$off_url = wp_nonce_url( admin_url( 'admin-post.php?action=dox_functions_safemode_off' ), self::NONCE );
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

	public static function page_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'tools.php' ) );
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

		echo '<div class="dox-wrap">';
		$this->render_header();

		// Editing a snippet that no longer exists (deleted meanwhile) must not
		// render a ghost editor that pretends to save — say so and show the list.
		if ( 'edit' === $action && $id && ! Dox_Functions::get_snippet( $id ) ) {
			echo '<div class="dox-notice dox-notice-error">' . esc_html( dox_t( 'not_found' ) ) . '</div>';
			$action = 'list';
			$id     = 0;
		}

		if ( 'edit' === $action || 'new' === $action ) {
			$this->render_editor( $id );
		} else {
			$this->render_list();
		}

		echo '</div>';
	}

	private function render_header() {
		$safe = (bool) get_option( Dox_Functions::OPT_SAFEMODE );
		?>
		<div class="dox-card dox-header">
			<div class="dox-brand">
				<img src="<?php echo esc_url( DOX_FUNCTIONS_URL . 'assets/logo.svg' ); ?>" alt="Dox Studio" class="dox-logo-img">
				<span class="dox-pill"><?php echo esc_html( dox_t( 'pill' ) ); ?></span>
			</div>
			<div class="dox-header-actions">
				<?php if ( $safe ) : ?>
					<a class="dox-btn dox-btn-ghost" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=dox_functions_safemode_off' ), self::NONCE ) ); ?>"><?php echo esc_html( dox_t( 'safe_mode_off' ) ); ?></a>
				<?php endif; ?>
				<a class="dox-btn dox-btn-primary" href="<?php echo esc_url( self::page_url( array( 'action' => 'new' ) ) ); ?>"><?php echo esc_html( dox_t( 'new_function' ) ); ?></a>
			</div>
		</div>
		<?php if ( $safe ) : ?>
			<div class="dox-notice"><?php echo esc_html( dox_t( 'safe_mode_active' ) ); ?></div>
		<?php endif;
	}

	private function render_list() {
		$snippets    = Dox_Functions::get_snippets();
		$date_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		// Read-only result flags from import redirects (display only).
		$imported   = isset( $_GET['imported'] ) ? absint( $_GET['imported'] ) : null;
		$import_err = ! empty( $_GET['import_err'] );
		?>
		<?php if ( null !== $imported && ! $import_err ) : ?>
			<div class="dox-notice dox-notice-success"><?php echo esc_html( sprintf( dox_t( 'imported_ok' ), $imported ) ); ?></div>
		<?php endif; ?>
		<?php if ( $import_err ) : ?>
			<div class="dox-notice dox-notice-error"><?php echo esc_html( dox_t( 'import_error' ) ); ?></div>
		<?php endif; ?>

		<div class="dox-card">
			<div class="dox-card-head">
				<div>
					<h2><?php echo esc_html( dox_t( 'my_functions' ) ); ?></h2>
					<p><?php echo esc_html( dox_t( 'list_intro' ) ); ?></p>
				</div>
				<div class="dox-list-tools">
					<a class="dox-btn dox-btn-ghost" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=dox_functions_export' ), self::NONCE ) ); ?>"><?php echo esc_html( dox_t( 'export_json' ) ); ?></a>
					<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dox-import-form">
						<?php wp_nonce_field( self::NONCE ); ?>
						<input type="hidden" name="action" value="dox_functions_import">
						<label class="dox-btn dox-btn-ghost dox-file-label" title="<?php echo esc_attr( dox_t( 'import_note' ) ); ?>">
							<?php echo esc_html( dox_t( 'import_json' ) ); ?>
							<input type="file" id="dox-import-file" name="import_file" accept=".json,application/json">
						</label>
					</form>
				</div>
			</div>

			<?php if ( empty( $snippets ) ) : ?>
				<div class="dox-empty">
					<p><?php echo esc_html( dox_t( 'empty_state' ) ); ?></p>
					<a class="dox-btn dox-btn-primary" href="<?php echo esc_url( self::page_url( array( 'action' => 'new' ) ) ); ?>"><?php echo esc_html( dox_t( 'new_function' ) ); ?></a>
				</div>
			<?php else : ?>
				<div class="dox-table-wrap">
					<table class="dox-table">
						<thead>
							<tr>
								<th><?php echo esc_html( dox_t( 'status' ) ); ?></th>
								<th><?php echo esc_html( dox_t( 'title' ) ); ?></th>
								<th><?php echo esc_html( dox_t( 'scope' ) ); ?></th>
								<th><?php echo esc_html( dox_t( 'priority' ) ); ?></th>
								<th><?php echo esc_html( dox_t( 'updated' ) ); ?></th>
								<th></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $snippets as $s ) :
							$toggle_url    = wp_nonce_url( admin_url( 'admin-post.php?action=dox_functions_toggle&id=' . $s->id ), self::NONCE );
							$edit_url      = self::page_url( array( 'action' => 'edit', 'id' => $s->id ) );
							$duplicate_url = wp_nonce_url( admin_url( 'admin-post.php?action=dox_functions_duplicate&id=' . $s->id ), self::NONCE );
							$delete_url    = wp_nonce_url( admin_url( 'admin-post.php?action=dox_functions_delete&id=' . $s->id ), self::NONCE );
							$row_title     = $s->title ?: dox_t( 'no_title' );
							?>
							<tr>
								<td>
									<a class="dox-switch <?php echo $s->is_active ? 'is-on' : ''; ?>"
										href="<?php echo esc_url( $toggle_url ); ?>"
										role="switch"
										aria-checked="<?php echo $s->is_active ? 'true' : 'false'; ?>"
										aria-label="<?php echo esc_attr( $row_title ); ?>"
										data-id="<?php echo (int) $s->id; ?>">
										<span class="dox-switch-knob"></span>
									</a>
								</td>
								<td>
									<a href="<?php echo esc_url( $edit_url ); ?>"><strong><?php echo esc_html( $row_title ); ?></strong></a>
									<?php if ( $s->description ) : ?>
										<div class="dox-muted"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $s->description ), 18 ) ); ?></div>
									<?php endif; ?>
									<?php if ( ! empty( $s->last_error ) ) : ?>
										<div class="dox-error-line" title="<?php echo esc_attr( $s->last_error ); ?>">⚠ <?php echo esc_html( dox_t( 'auto_disabled' ) . ': ' . wp_html_excerpt( $s->last_error, 90, '…' ) ); ?></div>
									<?php endif; ?>
								</td>
								<td><span class="dox-tag"><?php echo esc_html( dox_t( 'scope_' . $s->scope ) ); ?></span></td>
								<td><?php echo (int) $s->priority; ?></td>
								<td class="dox-muted"><?php echo esc_html( mysql2date( $date_format, $s->updated_at ) ); ?></td>
								<td class="dox-row-actions">
									<a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( dox_t( 'edit' ) ); ?></a>
									<a href="<?php echo esc_url( $duplicate_url ); ?>"><?php echo esc_html( dox_t( 'duplicate' ) ); ?></a>
									<a href="<?php echo esc_url( $delete_url ); ?>" class="dox-danger" onclick="return confirm('<?php echo esc_js( dox_t( 'confirm_delete' ) ); ?>');"><?php echo esc_html( dox_t( 'delete' ) ); ?></a>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
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

		$title       = $src ? (string) ( $src['title'] ?? '' )       : ( $snippet->title ?? '' );
		$description = $src ? (string) ( $src['description'] ?? '' ) : ( $snippet->description ?? '' );
		$code        = $src ? (string) ( $src['code'] ?? '' )        : ( $snippet->code ?? dox_t( 'starter_code' ) );
		$scope       = $src ? (string) ( $src['scope'] ?? 'everywhere' ) : ( $snippet->scope ?? 'everywhere' );
		$priority    = $src ? (int) ( $src['priority'] ?? 10 )       : (int) ( $snippet->priority ?? 10 );
		$is_active   = $src ? (int) ! empty( $src['is_active'] )     : ( $snippet ? (int) $snippet->is_active : 0 );

		$date_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dox-card dox-editor">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="action" value="dox_functions_save">
			<input type="hidden" name="id" value="<?php echo (int) $id; ?>">

			<?php if ( ! empty( $_GET['saved'] ) ) : ?>
				<div class="dox-notice dox-notice-success"><?php echo esc_html( dox_t( 'saved' ) ); ?></div>
			<?php endif; ?>

			<?php if ( $syntax_error ) : ?>
				<div class="dox-notice dox-notice-error"><?php echo esc_html( sprintf( dox_t( 'syntax_error' ), $syntax_error ) ); ?></div>
			<?php endif; ?>

			<?php if ( $snippet && ! empty( $snippet->last_error ) ) : ?>
				<div class="dox-notice dox-notice-error">
					<strong><?php echo esc_html( dox_t( 'auto_disabled' ) ); ?>:</strong>
					<?php echo esc_html( $snippet->last_error ); ?>
					<?php if ( ! empty( $snippet->last_error_at ) ) : ?>
						<span class="dox-muted">(<?php echo esc_html( mysql2date( $date_format, $snippet->last_error_at ) ); ?>)</span>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="dox-card-head">
				<div>
					<a class="dox-back" href="<?php echo esc_url( self::page_url() ); ?>"><?php echo esc_html( dox_t( 'back' ) ); ?></a>
					<h2><?php echo esc_html( $id ? dox_t( 'edit_function' ) : dox_t( 'create_function' ) ); ?></h2>
					<p><?php echo esc_html( dox_t( 'editor_intro' ) ); ?></p>
				</div>
				<label class="dox-switch-inline">
					<input type="checkbox" name="is_active" value="1" <?php checked( $is_active, 1 ); ?>>
					<span class="dox-switch <?php echo $is_active ? 'is-on' : ''; ?>"><span class="dox-switch-knob"></span></span>
					<span><?php echo esc_html( dox_t( 'active' ) ); ?></span>
				</label>
			</div>

			<div class="dox-grid">
				<div class="dox-field">
					<label for="dox-title"><?php echo esc_html( dox_t( 'title' ) ); ?></label>
					<input type="text" id="dox-title" name="title" value="<?php echo esc_attr( $title ); ?>" placeholder="<?php echo esc_attr( dox_t( 'placeholder_title' ) ); ?>" required>
				</div>
				<div class="dox-field">
					<label for="dox-scope"><?php echo esc_html( dox_t( 'scope' ) ); ?></label>
					<select id="dox-scope" name="scope">
						<option value="everywhere" <?php selected( $scope, 'everywhere' ); ?>><?php echo esc_html( dox_t( 'scope_everywhere' ) ); ?></option>
						<option value="admin"      <?php selected( $scope, 'admin' ); ?>><?php echo esc_html( dox_t( 'scope_admin' ) ); ?></option>
						<option value="frontend"   <?php selected( $scope, 'frontend' ); ?>><?php echo esc_html( dox_t( 'scope_frontend' ) ); ?></option>
						<option value="once"       <?php selected( $scope, 'once' ); ?>><?php echo esc_html( dox_t( 'scope_once' ) ); ?></option>
					</select>
				</div>
				<div class="dox-field">
					<label for="dox-priority"><?php echo esc_html( dox_t( 'priority' ) ); ?></label>
					<input type="number" id="dox-priority" name="priority" value="<?php echo (int) $priority; ?>" min="0" max="999">
					<p class="dox-muted"><?php echo esc_html( dox_t( 'priority_hint' ) ); ?></p>
				</div>
			</div>

			<div class="dox-field">
				<label for="dox-description"><?php echo esc_html( dox_t( 'description' ) ); ?></label>
				<input type="text" id="dox-description" name="description" value="<?php echo esc_attr( $description ); ?>" placeholder="<?php echo esc_attr( dox_t( 'placeholder_desc' ) ); ?>">
			</div>

			<div class="dox-field">
				<label for="dox-code"><?php echo esc_html( dox_t( 'php_code' ) ); ?></label>
				<textarea id="dox-code" name="code" rows="18"><?php echo esc_textarea( $code ); ?></textarea>
				<p class="dox-muted"><?php echo wp_kses_post( dox_t( 'no_open_tag' ) ); ?></p>
			</div>

			<div class="dox-actions">
				<span id="dox-validate-result" class="dox-validate-result" role="status" aria-live="polite"></span>
				<a class="dox-btn dox-btn-ghost" href="<?php echo esc_url( self::page_url() ); ?>"><?php echo esc_html( dox_t( 'cancel' ) ); ?></a>
				<button type="button" id="dox-validate" class="dox-btn dox-btn-ghost"><?php echo esc_html( dox_t( 'validate' ) ); ?></button>
				<button type="submit" class="dox-btn dox-btn-primary"><?php echo esc_html( dox_t( 'save_changes' ) ); ?></button>
			</div>
		</form>
		<?php
	}

	public function handle_save() {
		if ( ! self::user_can_manage() ) wp_die( esc_html( dox_t( 'unauthorized' ) ) );
		check_admin_referer( self::NONCE );

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		// The snippet body is PHP source: it cannot be "sanitised" without
		// destroying it — storing and running it IS the plugin's purpose. It is
		// unslashed here, checked for parse errors by validate_syntax() below
		// before it is ever stored, and only an author who passes
		// user_can_manage() (a Super Admin on multisite; blocked entirely under
		// DISALLOW_FILE_MODS / DISALLOW_FILE_EDIT) plus a valid nonce can reach
		// this line. The InputNotSanitized sniff can't follow that, so it is
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
			'scope'       => sanitize_key( wp_unslash( $_POST['scope'] ?? 'everywhere' ) ),
			'priority'    => absint( $_POST['priority'] ?? 10 ),
			'is_active'   => ! empty( $_POST['is_active'] ),
		);

		// Syntax validation BEFORE saving. On failure, stash everything the
		// user submitted so the editor repopulates — the typed code must
		// survive the redirect, not just the error message.
		$check = Dox_Functions::validate_syntax( $code );
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
		check_admin_referer( self::NONCE );
		Dox_Functions::delete_snippet( (int) ( $_GET['id'] ?? 0 ) );
		wp_safe_redirect( self::page_url() );
		exit;
	}

	public function handle_toggle() {
		if ( ! self::user_can_manage() ) wp_die( esc_html( dox_t( 'unauthorized' ) ) );
		check_admin_referer( self::NONCE );
		$id = (int) ( $_GET['id'] ?? 0 );
		$s  = Dox_Functions::get_snippet( $id );
		if ( $s ) Dox_Functions::set_active( $id, ! $s->is_active );
		wp_safe_redirect( self::page_url() );
		exit;
	}

	public function handle_duplicate() {
		if ( ! self::user_can_manage() ) wp_die( esc_html( dox_t( 'unauthorized' ) ) );
		check_admin_referer( self::NONCE );

		$s = Dox_Functions::get_snippet( (int) ( $_GET['id'] ?? 0 ) );
		if ( ! $s ) {
			wp_safe_redirect( self::page_url() );
			exit;
		}

		$new_id = Dox_Functions::save_snippet( array(
			'title'       => mb_substr( (string) $s->title, 0, 180 ) . dox_t( 'copy_suffix' ),
			'description' => $s->description,
			'code'        => $s->code,
			'scope'       => $s->scope,
			'priority'    => $s->priority,
			'is_active'   => 0, // copies always start off
		) );

		wp_safe_redirect( self::page_url( array( 'action' => 'edit', 'id' => $new_id ) ) );
		exit;
	}

	public function handle_export() {
		if ( ! self::user_can_manage() ) wp_die( esc_html( dox_t( 'unauthorized' ) ) );
		check_admin_referer( self::NONCE );

		$snippets = Dox_Functions::get_snippets();
		$items    = array();
		foreach ( $snippets as $s ) {
			$items[] = array(
				'title'       => $s->title,
				'description' => $s->description,
				'code'        => $s->code,
				'scope'       => $s->scope,
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
		check_admin_referer( self::NONCE );

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

		$count = 0;
		foreach ( array_slice( $items, 0, self::IMPORT_MAX_ITEMS ) as $item ) {
			if ( ! is_array( $item ) ) continue;
			$code = (string) ( $item['code'] ?? '' );
			if ( '' === trim( $code ) ) continue;

			// Imported snippets NEVER arrive active: they were written for
			// another site and must be reviewed here before running.
			// save_snippet() sanitises every field and whitelists the scope.
			$new_id = Dox_Functions::save_snippet( array(
				'title'       => (string) ( $item['title'] ?? '' ),
				'description' => (string) ( $item['description'] ?? '' ),
				'code'        => $code,
				'scope'       => (string) ( $item['scope'] ?? 'everywhere' ),
				'priority'    => (int) ( $item['priority'] ?? 10 ),
				'is_active'   => 0,
			) );

			// Flag invalid code visibly (badge in the list) without spamming
			// the site-wide notice — the importer is looking at the list.
			$check = Dox_Functions::validate_syntax( $code );
			if ( true !== $check ) {
				Dox_Functions::record_error( $new_id, $check, false );
			}
			$count++;
		}

		wp_safe_redirect( self::page_url( array( 'imported' => $count ) ) );
		exit;
	}

	public function handle_safemode_off() {
		// Turning safe mode OFF re-authorises stored snippets to execute, so it
		// needs the same trust as authoring them (super admin on multisite,
		// blocked under DISALLOW_FILE_*). The emergency ON switch stays broadly
		// available in the runner because it only ever *stops* execution.
		if ( ! self::user_can_manage() ) wp_die( esc_html( dox_t( 'unauthorized' ) ) );
		check_admin_referer( self::NONCE );
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
		check_ajax_referer( self::NONCE, 'nonce' );

		// PHP source — not sanitisable; same rationale as handle_save(). It is
		// only parsed (token_get_all), never executed or stored here.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$code  = wp_unslash( $_POST['code'] ?? '' );
		$check = Dox_Functions::validate_syntax( $code );

		if ( true === $check ) {
			wp_send_json_success( array( 'message' => dox_t( 'syntax_ok' ) ) );
		}
		wp_send_json_error( array( 'message' => sprintf( dox_t( 'syntax_error' ), $check ) ) );
	}

	/**
	 * AJAX: toggle a snippet without a full page reload. The list's switch
	 * links keep working as plain nonce'd GETs when JS is unavailable.
	 */
	public function ajax_toggle() {
		if ( ! self::user_can_manage() ) {
			wp_send_json_error( array( 'message' => dox_t( 'unauthorized' ) ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );

		$id = (int) ( $_POST['id'] ?? 0 );
		$s  = Dox_Functions::get_snippet( $id );
		if ( ! $s ) {
			wp_send_json_error( array( 'message' => dox_t( 'not_found' ) ), 404 );
		}

		$new = ! $s->is_active;
		Dox_Functions::set_active( $id, $new );
		wp_send_json_success( array( 'active' => (bool) $new ) );
	}
}
