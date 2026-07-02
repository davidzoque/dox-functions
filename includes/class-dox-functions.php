<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Dox_Functions {

	const TABLE            = 'dox_functions';
	const OPT_SAFEMODE     = 'dox_functions_safe_mode';
	const OPT_DB_VERSION   = 'dox_functions_db_version';
	const OPT_ERROR_NOTICE = 'dox_functions_error_notice';
	const CACHE_KEY        = 'dox_functions_active_cache';
	const CACHE_TTL        = HOUR_IN_SECONDS * 12;

	// Bump when the CREATE TABLE below changes. Plugin updates do NOT fire the
	// activation hook, so maybe_upgrade() compares this against a stored option
	// on every load and runs dbDelta only when they differ. On multisite this
	// also creates the per-site table the first time a subsite loads the
	// plugin (network activation only creates the main site's table).
	const DB_VERSION = '2';

	const SCOPES = array( 'everywhere', 'admin', 'frontend', 'once' );

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		self::maybe_upgrade();
		new Dox_Functions_Admin();
		new Dox_Functions_Runner();
	}

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	public static function maybe_upgrade() {
		if ( self::DB_VERSION === get_option( self::OPT_DB_VERSION ) ) {
			return;
		}
		self::create_table();
		update_option( self::OPT_DB_VERSION, self::DB_VERSION );
	}

	public static function create_table() {
		global $wpdb;
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title VARCHAR(191) NOT NULL DEFAULT '',
			description TEXT NULL,
			code LONGTEXT NOT NULL,
			scope VARCHAR(20) NOT NULL DEFAULT 'everywhere',
			is_active TINYINT(1) NOT NULL DEFAULT 0,
			priority INT NOT NULL DEFAULT 10,
			last_error TEXT NULL,
			last_error_at DATETIME NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY is_active (is_active),
			KEY scope (scope)
		) $charset;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public static function activate() {
		self::create_table();
		update_option( self::OPT_DB_VERSION, self::DB_VERSION );
	}

	public static function deactivate() {
		// Keep snippets in DB. Just clear safe mode flag.
		delete_option( self::OPT_SAFEMODE );
	}

	public static function get_snippets( $only_active = false ) {
		// Cache only the hot path (active snippets used on every front-end request).
		if ( $only_active ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( false !== $cached ) return $cached;
		}

		global $wpdb;
		$table = self::table_name();
		$where = $only_active ? 'WHERE is_active = 1' : '';
		$rows  = $wpdb->get_results( "SELECT * FROM $table $where ORDER BY priority ASC, id ASC" );

		if ( $only_active ) set_transient( self::CACHE_KEY, $rows, self::CACHE_TTL );
		return $rows;
	}

	public static function flush_cache() {
		delete_transient( self::CACHE_KEY );
	}

	public static function get_snippet( $id ) {
		global $wpdb;
		$table = self::table_name();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
	}

	public static function save_snippet( $data, $id = 0 ) {
		global $wpdb;
		$table = self::table_name();

		$row = array(
			'title'         => sanitize_text_field( $data['title'] ?? '' ),
			'description'   => wp_kses_post( $data['description'] ?? '' ),
			'code'          => (string) ( $data['code'] ?? '' ),
			'scope'         => in_array( $data['scope'] ?? '', self::SCOPES, true ) ? $data['scope'] : 'everywhere',
			'is_active'     => ! empty( $data['is_active'] ) ? 1 : 0,
			'priority'      => isset( $data['priority'] ) ? (int) $data['priority'] : 10,
			// A human edit supersedes any recorded auto-disable error.
			'last_error'    => null,
			'last_error_at' => null,
		);

		if ( $id > 0 ) {
			$wpdb->update( $table, $row, array( 'id' => $id ) );
			self::flush_cache();
			return $id;
		}
		$wpdb->insert( $table, $row );
		self::flush_cache();
		return (int) $wpdb->insert_id;
	}

	public static function delete_snippet( $id ) {
		global $wpdb;
		$wpdb->delete( self::table_name(), array( 'id' => (int) $id ) );
		self::flush_cache();
	}

	public static function set_active( $id, $active ) {
		global $wpdb;
		$table = self::table_name();
		// updated_at has ON UPDATE CURRENT_TIMESTAMP and should reflect the last
		// content edit, not a mere on/off toggle — so it is pinned explicitly.
		$wpdb->query( $wpdb->prepare(
			"UPDATE $table SET is_active = %d, updated_at = updated_at WHERE id = %d",
			$active ? 1 : 0,
			(int) $id
		) );
		self::flush_cache();
	}

	/**
	 * Record why a snippet was auto-disabled so the admin can see it in the UI
	 * (list badge + editor notice) instead of only in the PHP error log.
	 * Also stores a one-shot admin notice unless $notify is false (imports).
	 */
	public static function record_error( $id, $message, $notify = true ) {
		global $wpdb;
		$table   = self::table_name();
		$message = (string) $message;

		$wpdb->query( $wpdb->prepare(
			"UPDATE $table SET is_active = 0, last_error = %s, last_error_at = %s, updated_at = updated_at WHERE id = %d",
			$message,
			current_time( 'mysql' ),
			(int) $id
		) );
		self::flush_cache();

		if ( $notify ) {
			update_option( self::OPT_ERROR_NOTICE, array(
				'id'      => (int) $id,
				'message' => $message,
				'time'    => time(),
			) );
		}
	}

	/**
	 * Atomically claim a "run once" snippet: deactivate it and report whether
	 * THIS request won the claim. Two concurrent requests cannot both get true,
	 * so the snippet executes exactly once even under load.
	 */
	public static function claim_run_once( $id ) {
		global $wpdb;
		$table   = self::table_name();
		$claimed = $wpdb->query( $wpdb->prepare(
			"UPDATE $table SET is_active = 0, updated_at = updated_at WHERE id = %d AND is_active = 1",
			(int) $id
		) );
		self::flush_cache();
		return (bool) $claimed;
	}

	/**
	 * Remove a leading <?php tag while PRESERVING line numbering: whitespace
	 * (including newlines) around the tag is kept, only the tag itself goes.
	 * Used both before eval() and before syntax validation, so reported error
	 * lines match what the user sees in the editor.
	 */
	public static function strip_php_tag( $code ) {
		return preg_replace( '/^(\s*)<\?php(\s|$)/i', '$1$2', (string) $code, 1 );
	}

	/**
	 * Validate PHP syntax without executing the snippet.
	 * Returns true on success, or an error message string.
	 */
	public static function validate_syntax( $code ) {
		$code = self::strip_php_tag( $code );
		if ( '' === trim( $code ) ) return true;

		// token_get_all with TOKEN_PARSE throws ParseError on bad syntax — no
		// execution. The '<?php ' prefix adds no newline and strip_php_tag()
		// preserves line numbers, so getLine() matches the editor line.
		try {
			@token_get_all( '<?php ' . $code, TOKEN_PARSE );
			return true;
		} catch ( \ParseError $e ) {
			return $e->getMessage() . ' (line ' . max( 1, $e->getLine() ) . ')';
		} catch ( \Throwable $e ) {
			return $e->getMessage();
		}
	}
}
