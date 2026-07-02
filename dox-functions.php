<?php
/**
 * Plugin Name: Dox Functions
 * Plugin URI:  https://doxstudio.com
 * Description: Administra snippets de PHP personalizados con un panel visual. Activa o desactiva funciones con un clic, sin tocar functions.php.
 * Version:     1.1.0
 * Author:      Dox Studio
 * Author URI:  https://doxstudio.com
 * License:     GPL-2.0+
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
