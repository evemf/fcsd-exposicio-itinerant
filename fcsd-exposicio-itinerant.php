<?php
/**
 * Plugin Name: FCSD - Exposició Itinerant (Obres d’art úniques)
 * Description: Landing privada a /ca/exposicio-itinerant amb productes “Obra d’art única” (1/1), compra ràpida (MONEI/Bizum) i email extra d’avisos.
 * Version: 1.0.0
 * Author: FCSD / Evelia
 * Text Domain: fcsd-exposicio
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'FCSD_EXPO_FILE', __FILE__ );
define( 'FCSD_EXPO_DIR', plugin_dir_path( __FILE__ ) );
define( 'FCSD_EXPO_URL', plugin_dir_url( __FILE__ ) );

// Carga núcleo y módulos (3 archivos)
require_once FCSD_EXPO_DIR . 'includes/class-fcsd-core.php';
require_once FCSD_EXPO_DIR . 'includes/class-fcsd-frontend.php';
require_once FCSD_EXPO_DIR . 'includes/class-fcsd-commerce.php';

// Activación SIEMPRE en el archivo principal
register_activation_hook( __FILE__, [ 'FCSD_Core', 'activate' ] );

// Bootstrap
add_action( 'plugins_loaded', function () {
	$core = FCSD_Core::instance();   // constantes, helpers, textdomain, término product_type
	new FCSD_Frontend( $core );      // landing + privacidad + render
	new FCSD_Commerce( $core );      // WooCommerce (tipo producto, carrito, pasarelas, checkout, emails, admin)
}, 11 );
