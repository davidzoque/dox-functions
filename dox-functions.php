<?php
/**
 * Plugin Name: Dox Functions
 * Plugin URI:  https://doxstudio.com
 * Description: Administra snippets de PHP personalizados con un panel visual. Activa o desactiva funciones con un clic, sin tocar functions.php.
 * Version:     1.1.0
 * Author:      Dox Studio
 * Author URI:  https://doxstudio.com
 * License:     GPL-2.0+
 * Update URI:  https://github.com/davidzoque/dox-functions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DOX_FUNCTIONS_VERSION', '1.1.0' );
define( 'DOX_FUNCTIONS_FILE', __FILE__ );
define( 'DOX_FUNCTIONS_DIR', plugin_dir_path( __FILE__ ) );
define( 'DOX_FUNCTIONS_URL', plugin_dir_url( __FILE__ ) );

require_once DOX_FUNCTIONS_DIR . 'includes/class-dox-functions-i18n.php';
require_once DOX_FUNCTIONS_DIR . 'includes/class-dox-functions.php';
require_once DOX_FUNCTIONS_DIR . 'includes/class-dox-functions-admin.php';
require_once DOX_FUNCTIONS_DIR . 'includes/class-dox-functions-runner.php';

register_activation_hook( __FILE__, array( 'Dox_Functions', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Dox_Functions', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Dox_Functions', 'instance' ) );

// ─── Auto-actualizaciones desde GitHub (Plugin Update Checker) ────────────────
// El plugin se actualiza desde las releases del repo de GitHub, no desde
// WordPress.org. Para repos privados, define el token en wp-config.php:
//     define( 'DOX_FUNCTIONS_GITHUB_TOKEN', 'github_pat_xxxxxxxx' );
// (fine-grained PAT con permiso de solo lectura de "Contents" sobre el repo)
$dox_functions_puc = DOX_FUNCTIONS_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
if ( file_exists( $dox_functions_puc ) ) {
	require_once $dox_functions_puc;

	$dox_functions_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/davidzoque/dox-functions/',
		__FILE__,
		'dox-functions'
	);
	$dox_functions_update_checker->setBranch( 'main' );
	// Usa el ZIP limpio que el workflow de GitHub Actions adjunta a cada release
	$dox_functions_update_checker->getVcsApi()->enableReleaseAssets();
	if ( defined( 'DOX_FUNCTIONS_GITHUB_TOKEN' ) && DOX_FUNCTIONS_GITHUB_TOKEN ) {
		$dox_functions_update_checker->setAuthentication( DOX_FUNCTIONS_GITHUB_TOKEN );
	}
}
