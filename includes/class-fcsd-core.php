<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Núcleo: constantes, utilidades, textdomain y alta del término product_type.
 */
if ( ! class_exists( 'FCSD_Core' ) ) :

class FCSD_Core {

	/** Opciones */
	const OPT_EXTRA_EMAIL = 'fcsd_expo_extra_email';
	const OPT_GW_DEFAULT  = 'fcsd_expo_gw_default';
	const OPT_GW_BIZUM    = 'fcsd_expo_gw_bizum';

	/** Landing */
	const LANDING_SLUG    = 'exposicio-itinerant';
	const LANDING_LOCALE  = 'ca';

	/** Producto + metadatos */
	const PRODUCT_TYPE    = 'obra_unica';
	const META_AUTOR      = '_fcsd_obra_autor';
	const META_ANY        = '_fcsd_obra_any';
	const META_MESURES    = '_fcsd_obra_mesures';

	/** Nonces / sesión */
	const NONCE_BUY       = 'fcsd_expo_buy';
	const SESSION_GW      = 'fcsd_expo_chosen_gateway';

	const VERSION         = '1.0.1';

	private static $instance = null;

	public $file;
	public $dir;
	public $url;

	private function __construct() {
		$this->file = defined('FCSD_EXPO_FILE') ? FCSD_EXPO_FILE : __FILE__;
		$this->dir  = defined('FCSD_EXPO_DIR')  ? FCSD_EXPO_DIR  : plugin_dir_path( $this->file );
		$this->url  = defined('FCSD_EXPO_URL')  ? FCSD_EXPO_URL  : plugin_dir_url( $this->file );

		add_action( 'plugins_loaded', [ $this, 'load_textdomain' ] );

		// Crea el término de product_type cuando WooCommerce ya ha registrado la taxonomía.
		add_action( 'init', [ $this, 'ensure_product_type_term' ], 20 );

		// Si en la activación la taxonomía no existía, lo dejamos diferido para el primer init disponible.
		add_action( 'init', [ $this, 'maybe_complete_deferred_setup' ], 20 );
	}

	public static function instance() : self {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	/**
	 * Activación del plugin: crea landing, intenta crear el término si la taxonomía ya existe,
	 * y si no existe aún (p. ej. por orden de carga), lo difiere para el siguiente init.
	 */
	public static function activate() : void {
		$self = self::instance();

		// Crea la landing si no existe.
		$self->ensure_landing_page_exists();

		// Intenta crear el término ahora si la taxonomía está disponible.
		if ( taxonomy_exists( 'product_type' ) ) {
			$self->ensure_product_type_term();
		} else {
			// Diferir para el primer init en el que esté WooCommerce.
			add_option( 'fcsd_expo_do_ptype_term', 1, '', false );
		}

		// Por si la landing afecta a reglas.
		flush_rewrite_rules();
	}

	public function load_textdomain() : void {
		load_plugin_textdomain( 'fcsd-exposicio', false, dirname( plugin_basename( $this->file ) ) . '/languages' );
	}

	/**
	 * Crea el término del tipo de producto si no existe (solo si la taxonomía está registrada).
	 */
	public function ensure_product_type_term() : void {
		if ( ! taxonomy_exists( 'product_type' ) ) return;

		$slug = self::PRODUCT_TYPE;

		// Si ya existe, nada que hacer.
		if ( term_exists( $slug, 'product_type' ) ) return;

		wp_insert_term(
			__( "Obra d’art única", 'fcsd-exposicio' ),
			'product_type',
			[ 'slug' => $slug ]
		);
	}

	/**
	 * Completa tareas que pudieron diferirse en activate() por orden de carga.
	 */
	public function maybe_complete_deferred_setup() : void {
		if ( get_option( 'fcsd_expo_do_ptype_term' ) ) {
			$this->ensure_product_type_term();
			delete_option( 'fcsd_expo_do_ptype_term' );
		}
	}

	/**
	 * Asegura que la página de landing exista y la etiqueta en el idioma (si Polylang está disponible).
	 */
	private function ensure_landing_page_exists() : void {
		$page = get_page_by_path( self::LANDING_LOCALE . '/' . self::LANDING_SLUG ) ?: get_page_by_path( self::LANDING_SLUG );
		if ( $page ) return;

		$postarr = [
			'post_title'   => __( 'Exposició itinerant', 'fcsd-exposicio' ),
			'post_name'    => self::LANDING_SLUG,
			'post_content' => '',
			'post_status'  => 'publish',
			'post_type'    => 'page',
		];

		$page_id = wp_insert_post( $postarr, true );

		if ( ! is_wp_error( $page_id ) && function_exists( 'pll_set_post_language' ) ) {
			pll_set_post_language( $page_id, self::LANDING_LOCALE );
		}
	}

	public function is_landing_page() : bool {
		if ( ! is_page() ) return false;
		$page = get_page_by_path( self::LANDING_LOCALE . '/' . self::LANDING_SLUG );
		if ( $page ) return is_page( $page->ID );
		return is_page( self::LANDING_SLUG );
	}

	public function get_landing_page_id() : int {
		$page = get_page_by_path( self::LANDING_LOCALE . '/' . self::LANDING_SLUG ) ?: get_page_by_path( self::LANDING_SLUG );
		return $page ? (int) $page->ID : 0;
	}

	/**
	 * Genera un enlace de compra directa para una obra única.
	 */
	public function buy_link( WC_Product $product, string $which, string $label, string $extra_class = '' ) : string {
		$url = add_query_arg( [
			'add-to-cart' => $product->get_id(),
			'fcsd_gw'     => $which,
			'_wpnonce'    => wp_create_nonce( 'add_to_cart' ),
			'fcsd_nonce'  => wp_create_nonce( self::NONCE_BUY ),
		], wc_get_checkout_url() );

		return sprintf(
			'<a class="fcsd-btn %s" href="%s">%s</a>',
			esc_attr( $extra_class ),
			esc_url( $url ),
			esc_html( $label )
		);
	}

	/**
	 * Normaliza restricciones de stock para obras únicas (sin “revivir” agotados).
	 */
	public function normalize_unique_stock( array $ids ) : void {
		foreach ( $ids as $pid ) {
			$p = wc_get_product( $pid );
			if ( ! $p || $p->get_type() !== self::PRODUCT_TYPE ) continue;

			$changed = false;

			// Atributos fijos de obra única
			if ( $p->get_manage_stock() !== 'yes' && $p->get_manage_stock() !== true ) { $p->set_manage_stock( true ); $changed = true; }
			if ( $p->get_backorders() !== 'no' ) { $p->set_backorders( 'no' ); $changed = true; }
			if ( ! $p->is_sold_individually() ) { $p->set_sold_individually( true ); $changed = true; }
			if ( ! $p->get_virtual() ) { $p->set_virtual( true ); $changed = true; }

			$qty_raw = $p->get_stock_quantity();
			$qty     = ( $qty_raw === '' || $qty_raw === null ) ? 0 : (int) $qty_raw;
			$status  = $p->get_stock_status();

			// Si cantidad vacía, establece 1
			if ( $qty_raw === '' || $qty_raw === null ) {
				$p->set_stock_quantity( 1 );
				$qty = 1;
				$changed = true;
			}

			// Si dice "instock" pero qty <= 0, corrige cantidad a 1
			if ( $qty <= 0 && $status === 'instock' ) {
				$p->set_stock_quantity( 1 );
				$qty = 1;
				$changed = true;
			}

			// Sincroniza estado con cantidad
			if ( $qty <= 0 && $status !== 'outofstock' ) {
				$p->set_stock_status( 'outofstock' );
				$changed = true;
			}
			if ( $qty > 0 && $status !== 'instock' ) {
				$p->set_stock_status( 'instock' );
				$changed = true;
			}

			if ( $changed ) { $p->save(); }
		}
	}
}

endif; // class_exists
