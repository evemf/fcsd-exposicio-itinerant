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

	const VERSION         = '1.0.0';

	private static $instance = null;

	public $file;
	public $dir;
	public $url;

	private function __construct() {
		$this->file = defined('FCSD_EXPO_FILE') ? FCSD_EXPO_FILE : __FILE__;
		$this->dir  = defined('FCSD_EXPO_DIR')  ? FCSD_EXPO_DIR  : plugin_dir_path( $this->file );
		$this->url  = defined('FCSD_EXPO_URL')  ? FCSD_EXPO_URL  : plugin_dir_url( $this->file );

		add_action( 'plugins_loaded', [ $this, 'load_textdomain' ] );
		add_action( 'init', [ $this, 'ensure_product_type_term' ] );
	}

	public static function instance() : self {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	public static function activate() : void {
		self::instance()->ensure_product_type_term();
	}

	public function load_textdomain() : void {
		load_plugin_textdomain( 'fcsd-exposicio', false, dirname( plugin_basename( $this->file ) ) . '/languages' );
	}

	public function ensure_product_type_term() : void {
		if ( ! term_exists( self::PRODUCT_TYPE, 'product_type' ) ) {
			wp_insert_term( __( "Obra d’art única", 'fcsd-exposicio' ), 'product_type', [ 'slug' => self::PRODUCT_TYPE ] );
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

	public function buy_link( WC_Product $product, string $which, string $label, string $extra_class = '' ) : string {
		$url = add_query_arg( [
			'add-to-cart' => $product->get_id(),
			'fcsd_gw'     => $which,
			'_wpnonce'    => wp_create_nonce( self::NONCE_BUY ),
		], wc_get_checkout_url() );
		return sprintf(
			'<a class="fcsd-btn %s" href="%s">%s</a>',
			esc_attr( $extra_class ),
			esc_url( $url ),
			esc_html( $label )
		);
	}

	/** Normaliza restricciones de stock para obras únicas (sin “revivir” agotados) */
	public function normalize_unique_stock( array $ids ) : void {
		foreach ( $ids as $pid ) {
			$p = wc_get_product( $pid );
			if ( ! $p || $p->get_type() !== self::PRODUCT_TYPE ) continue;

			$changed = false;

			// Atributos fijos de obra única
			if ( $p->get_manage_stock() !== 'yes' && $p->get_manage_stock() !== true ) { $p->set_manage_stock( true ); $changed = true; }
			if ( $p->get_backorders() !== 'no' ) { $p->set_backorders( 'no' ); $changed = true; }
			if ( ! $p->is_sold_individually() ) { $p->set_sold_individually( true ); $changed = true; }

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
