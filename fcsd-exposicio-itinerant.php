<?php
/**
 * Plugin Name: FCSD - Exposició Itinerant (Obres d’art úniques)
 * Description: Landing privada a /ca/exposicio-itinerant amb productes “Obra Única” (1/1), compra ràpida (MONEI/Bizum) i email extra d’avisos.
 * Version: 1.0.1
 * Author: FCSD / Evelia
 * Text Domain: fcsd-exposicio
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'FCSD_EXPO_FILE', __FILE__ );
define( 'FCSD_EXPO_DIR', plugin_dir_path( __FILE__ ) );
define( 'FCSD_EXPO_URL', plugin_dir_url( __FILE__ ) );

// Carga clases
require_once FCSD_EXPO_DIR . 'includes/class-fcsd-core.php';
require_once FCSD_EXPO_DIR . 'includes/class-fcsd-frontend.php';
require_once FCSD_EXPO_DIR . 'includes/class-fcsd-commerce.php';

// Activación
register_activation_hook( __FILE__, [ 'FCSD_Core', 'activate' ] );

// Bootstrap – esperamos a que WooCommerce haya cargado (si existe)
add_action( 'plugins_loaded', function () {
	$core = FCSD_Core::instance();

	// Cargamos frontend siempre (no depende de WC)
	new FCSD_Frontend( $core );

	// Sólo cargamos la capa WooCommerce si WC está activo
	if ( class_exists( 'WooCommerce' ) ) {
		new FCSD_Commerce( $core );
	}
}, 11 );
