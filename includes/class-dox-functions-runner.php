<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Dox_Functions_Runner {

	public function __construct() {
		add_action( 'plugins_loaded', array( $this, 'run_snippets' ), 20 );
	}

	public function run_snippets() {

		// Safe mode RECOVERY switch: ?dox_safe_mode=1 (admin only) stops snippet
		// execution for this request. This is left usable by typing the URL by hand
		// on purpose, so an administrator can recover a site that a snippet broke.
		// The effect is benign (it only DISABLES execution), so no nonce is used
		// here (and wp_verify_nonce() is not yet available this early on
		// plugins_loaded). On multisite we still require a Super Admin, matching
		// who is allowed to manage snippets at all — a subsite admin has no
		// business touching this plugin's state.
		//
		// Re-enabling execution (turning safe mode OFF) is the sensitive operation
		// and is handled by Dox_Functions_Admin::handle_safemode_off() behind a
		// nonce + capability check — never via a bare GET request here.
		$can_toggle_safe_mode = is_multisite() ? is_super_admin() : current_user_can( 'manage_options' );
		if ( isset( $_GET['dox_safe_mode'] ) && $can_toggle_safe_mode ) {
			update_option( Dox_Functions::OPT_SAFEMODE, 1 );
		}
		if ( get_option( Dox_Functions::OPT_SAFEMODE ) ) {
			return;
		}

		// Honour the site's code-editing lockdown at EXECUTION time, not just in
		// the admin UI. When DISALLOW_FILE_MODS / DISALLOW_FILE_EDIT are defined
		// the owner has declared "no admin-authored code runs here". A snippet
		// that reached the table anyway — a restored/imported backup, or a DB
		// write through another plugin's flaw — must NOT be eval()'d, or it
		// becomes persistent remote code execution on a site that explicitly
		// locked code editing down.
		if ( ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS )
			|| ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) ) {
			return;
		}

		// A snippet scoped to "admin" must run only on an AUTHENTICATED dashboard
		// request — never for the public. is_admin() alone is NOT a safe signal:
		// it is also true on admin-ajax.php and admin-post.php (whose *_nopriv
		// actions are reachable by anonymous visitors), and it is true for
		// anonymous hits to wp-admin pages during the brief window before
		// auth_redirect() bounces them to the login screen. So instead of a
		// blacklist we require a real dashboard context: an admin request that
		// is not AJAX, not cron, not the admin-post form endpoint, and made by a
		// logged-in user. (Snippet code is authored by trusted super-admins; this
		// stops "admin" snippets executing for anonymous / non-dashboard
		// requests, which was the reported exposure.)
		$pagenow      = isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '';
		$is_admin     = is_admin();
		$is_dashboard = $is_admin
			&& ! wp_doing_ajax()
			&& ! wp_doing_cron()
			&& 'admin-post.php' !== $pagenow
			&& is_user_logged_in();
		$snippets     = Dox_Functions::get_snippets( true );

		foreach ( $snippets as $s ) {
			if ( 'once' === $s->scope ) {
				// Run-once snippets execute on the first request after being
				// activated, then stay deactivated. The claim is an atomic
				// UPDATE (deactivate BEFORE eval), so concurrent requests can't
				// run it twice — and a snippet that dies mid-eval in a way
				// try/catch can't see (exit, OOM) is already off.
				if ( Dox_Functions::claim_run_once( $s->id ) ) {
					$this->execute( $s );
				}
				continue;
			}
			if ( 'admin' === $s->scope && ! $is_dashboard ) continue;
			if ( 'frontend' === $s->scope && $is_admin )    continue;

			$this->execute( $s );
		}
	}

	private function execute( $snippet ) {
		$code = Dox_Functions::strip_php_tag( $snippet->code );

		try {
			// Evaluate inside a static closure so the snippet cannot reach
			// $this or the runner's local variables by accident.
			( static function ( $dox_functions_snippet_code ) {
				// phpcs:ignore Squiz.PHP.Eval.Discouraged
				eval( $dox_functions_snippet_code );
			} )( $code );
		} catch ( \Throwable $e ) {
			error_log( sprintf(
				'[Dox Functions] Error en snippet #%d (%s): %s',
				$snippet->id,
				$snippet->title,
				$e->getMessage()
			) );

			// Auto-disable the broken snippet to avoid a white screen on the
			// next load, and record WHY so the admin sees it in the UI.
			Dox_Functions::record_error( $snippet->id, $e->getMessage() );
		}
	}
}
