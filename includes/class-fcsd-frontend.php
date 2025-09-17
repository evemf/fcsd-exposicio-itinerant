<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Landing privada: ocultación, bloqueo de navegación interna y plantilla.
 */
if ( ! class_exists( 'FCSD_Frontend' ) ) :

class FCSD_Frontend {

	/** @var FCSD_Core */
	private $core;

	public function __construct( FCSD_Core $core ) {
		$this->core = $core;

		// Privar la landing
		add_filter( 'wp_nav_menu_objects',          [ $this, 'hide_landing_from_menus' ], 10, 2 );
		add_action( 'pre_get_posts',                [ $this, 'exclude_landing_from_search' ] );
		add_filter( 'wp_robots',                    [ $this, 'noindex_landing' ] );
		add_filter( 'wp_sitemaps_posts_query_args', [ $this, 'exclude_landing_from_sitemaps' ], 10, 2 );
		add_action( 'template_redirect',            [ $this, 'block_internal_navigation' ], 0 );

		// Aplicar selección de pasarela (en checkout) desde el enlace de compra
		add_action( 'template_redirect',            [ $this, 'maybe_apply_gateway_choice' ], 0 );

		// Render de la landing (HTML propio)
		add_action( 'template_redirect',            [ $this, 'render_expo_template' ], 5 );

		// Excluir del catálogo/loop estándar
		add_action( 'woocommerce_product_query',    [ $this, 'exclude_obra_unica_from_catalog' ] );
	}

	/* ---- Privacidad / visibilidad ---- */

	public function hide_landing_from_menus( $items, $args ) {
		foreach ( $items as $k => $item ) {
			if ( $item->object === 'page' ) {
				$p = get_post( $item->object_id );
				if ( $p && ( $p->post_name === FCSD_Core::LANDING_SLUG || strpos( get_page_uri( $p ), FCSD_Core::LANDING_LOCALE . '/' . FCSD_Core::LANDING_SLUG ) !== false ) ) {
					unset( $items[$k] );
				}
			}
		}
		return $items;
	}

	public function exclude_landing_from_search( $q ) {
		if ( is_admin() || ! $q->is_main_query() || ! $q->is_search() ) return;
		$page = get_page_by_path( FCSD_Core::LANDING_LOCALE . '/' . FCSD_Core::LANDING_SLUG ) ?: get_page_by_path( FCSD_Core::LANDING_SLUG );
		if ( $page ) {
			$not = (array) $q->get( 'post__not_in' );
			$not[] = (int) $page->ID;
			$q->set( 'post__not_in', $not );
		}
	}

	public function noindex_landing( $robots ) {
		if ( $this->core->is_landing_page() ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}

	public function exclude_landing_from_sitemaps( $args, $post_type ) {
		if ( 'page' !== $post_type ) return $args;
		$page = get_page_by_path( FCSD_Core::LANDING_LOCALE . '/' . FCSD_Core::LANDING_SLUG ) ?: get_page_by_path( FCSD_Core::LANDING_SLUG );
		if ( $page ) {
			$args['post__not_in'] = array_merge( $args['post__not_in'] ?? [], [ (int) $page->ID ] );
		}
		return $args;
	}

	public function block_internal_navigation() {
		if ( ! $this->core->is_landing_page() ) return;
		if ( isset( $_GET['fcsd_buy'] ) ) return;
		$ref = $_SERVER['HTTP_REFERER'] ?? '';
		$ref = is_string( $ref ) ? $ref : '';
		if ( $ref ) {
			$ref_host  = parse_url( $ref, PHP_URL_HOST );
			$site_host = parse_url( home_url(), PHP_URL_HOST );
			if ( $ref_host && $site_host && strcasecmp( $ref_host, $site_host ) === 0 ) {
				status_header( 404 ); nocache_headers();
				wp_die( esc_html__( 'No disponible', 'fcsd-exposicio' ), 404 );
			}
		}
	}

	/* ---- Pasarela elegida desde la landing (en checkout) ---- */

	public function maybe_apply_gateway_choice() {
		if ( is_admin() || ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
		$choice = isset( $_GET['fcsd_gw'] ) ? sanitize_text_field( wp_unslash( $_GET['fcsd_gw'] ) ) : '';
		if ( ! $choice ) return;
		if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', FCSD_Core::NONCE_BUY ) ) return;

		$map = [
			'default' => get_option( FCSD_Core::OPT_GW_DEFAULT, '' ),
			'bizum'   => get_option( FCSD_Core::OPT_GW_BIZUM, '' ),
		];
		$gateway_id = sanitize_text_field( $map[ $choice ] ?? '' );
		if ( WC()->session && $gateway_id ) {
			WC()->session->set( FCSD_Core::SESSION_GW, $gateway_id );
			WC()->session->set( 'chosen_payment_method', $gateway_id );
		}
	}

	/* ---- Render de la landing ---- */

	public function render_expo_template() {
		if ( ! $this->core->is_landing_page() ) return;
		if ( ! class_exists( 'WooCommerce' ) ) return;

		$args = [
			'post_type'           => 'product',
			'posts_per_page'      => -1,
			'fields'              => 'ids',
			'tax_query'           => [[
				'taxonomy' => 'product_type',
				'field'    => 'slug',
				'terms'    => [ FCSD_Core::PRODUCT_TYPE ],
			]],
			'orderby'             => 'date',
			'order'               => 'DESC',
			'post_status'         => 'publish',
			'suppress_filters'    => false,
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
		];
		if ( function_exists( 'pll_current_language' ) ) {
			$args['lang'] = pll_current_language( 'slug' );
		}
		$ids = get_posts( $args );

		$this->core->normalize_unique_stock( $ids );

		?><!doctype html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo('charset'); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1" />
			<title><?php echo esc_html__( "Exposició itinerant — Obres d’art úniques", 'fcsd-exposicio' ); ?></title>
			<?php wp_head(); ?>
			<style>
			body.fcsd-expo{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,'Helvetica Neue',Arial,sans-serif;background:#0b0b0b;color:#f5f5f5;margin:0;}
			.fcsd-wrap{max-width:1100px;width:100%;margin:0 auto;padding:24px}
			.fcsd-h1{margin:.2em 0 6px;font-size:2.1rem;color:#ffffff}
			.fcsd-sub{opacity:.8;margin:0 0 16px}
			.fcsd-grid{display:grid;gap:18px;grid-template-columns:repeat(auto-fill,minmax(280px,1fr))}
			.fcsd-card{background:#141414;border:1px solid #222;border-radius:14px;overflow:hidden;display:flex;flex-direction:column}
			.fcsd-thumb img{display:block;width:100%;height:auto;aspect-ratio:4/3;object-fit:cover}
			.fcsd-body{padding:14px;display:flex;flex-direction:column;gap:8px}
			.fcsd-ttl{font-size:1.12rem;margin:0;color:#e3c24b}
			.fcsd-meta{font-size:.9rem;color:#ddd;margin:0}
			.fcsd-desc{font-size:.95rem;color:#eaeaea}
			.fcsd-buy{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:4px}
			.fcsd-preu{font-weight:700;font-size:1.05rem}
			.fcsd-btn{display:inline-block;padding:10px 14px;border-radius:10px;background:#e3c24b;color:#000;text-decoration:none;font-weight:700}
			.fcsd-btn:hover{filter:brightness(.95)}
			.fcsd-btn.bizum{background:#ddd}
			.fcsd-sold{display:inline-block;background:#333;color:#bbb;padding:8px 10px;border-radius:10px;font-weight:700}
			.fcsd-foot{margin-top:28px;text-align:center;opacity:.7}
			</style>
		</head>
		<body <?php body_class('fcsd-expo'); ?>>
		<main class="fcsd-wrap">
			<h1 class="fcsd-h1"><?php echo esc_html__( "Exposició itinerant", 'fcsd-exposicio' ); ?></h1>
			<p class="fcsd-sub"><?php echo esc_html__( "Obres d’art úniques — edició limitada 1/1", 'fcsd-exposicio' ); ?></p>

			<section class="fcsd-grid">
				<?php
				if ( $ids ) :
					foreach ( $ids as $pid ) :
						$product = wc_get_product( $pid );
						if ( ! $product ) continue;

						$autor   = get_post_meta( $pid, FCSD_Core::META_AUTOR, true );
						$any     = get_post_meta( $pid, FCSD_Core::META_ANY, true );
						$mesures = get_post_meta( $pid, FCSD_Core::META_MESURES, true );
						$title   = get_the_title( $pid );
						$link    = get_permalink( $pid );
						$thumb   = get_the_post_thumbnail( $pid, 'large' );
						$excerpt = get_the_excerpt( $pid );

						$qty      = (int) $product->get_stock_quantity();
						$can_buy  = $product->is_purchasable() && $product->is_in_stock() && $qty > 0;
						?>
						<article class="fcsd-card">
							<a class="fcsd-thumb" href="<?php echo esc_url( $link ); ?>" target="_blank" rel="noopener">
								<?php echo $thumb ?: ''; ?>
							</a>
							<div class="fcsd-body">
								<h2 class="fcsd-ttl"><?php echo esc_html( $title ); ?></h2>
								<p class="fcsd-meta">
									<strong><?php esc_html_e('Autor/a','fcsd-exposicio'); ?>:</strong> <?php echo esc_html( $autor ?: '—' ); ?>
									&nbsp;•&nbsp;<strong><?php esc_html_e('Any','fcsd-exposicio'); ?>:</strong> <?php echo esc_html( $any ?: '—' ); ?>
									<?php if ( $mesures ) : ?>&nbsp;•&nbsp;<strong><?php esc_html_e('Mesures','fcsd-exposicio'); ?>:</strong> <?php echo esc_html( $mesures ); ?><?php endif; ?>
								</p>
								<div class="fcsd-desc"><?php echo wp_kses_post( wpautop( $excerpt ) ); ?></div>
								<div class="fcsd-buy">
									<?php if ( $can_buy ) : ?>
										<span class="fcsd-preu"><?php echo wp_kses_post( $product->get_price_html() ); ?></span>
										<?php echo $this->core->buy_link( $product, 'default', __( 'Pagar ara', 'fcsd-exposicio' ) ); ?>
										<?php if ( get_option( FCSD_Core::OPT_GW_BIZUM, '' ) ) echo $this->core->buy_link( $product, 'bizum', __( 'Pagar amb Bizum', 'fcsd-exposicio' ), 'bizum' ); ?>
									<?php else : ?>
										<span class="fcsd-sold"><?php esc_html_e( 'Esgotada', 'fcsd-exposicio' ); ?></span>
									<?php endif; ?>
								</div>
							</div>
						</article>
						<?php
					endforeach;
				else :
					echo '<p>'.esc_html__( 'Encara no hi ha obres disponibles.', 'fcsd-exposicio' ).'</p>';
				endif;
				?>
			</section>

			<footer class="fcsd-foot">
				<meta name="robots" content="noindex,nofollow" />
				<p><?php esc_html_e( 'Landing privada; accés només per URL/QR directa.', 'fcsd-exposicio' ); ?></p>
			</footer>
		</main>
		<?php wp_footer(); ?>
		</body>
		</html>
		<?php
		exit;
	}

	/* ---- Catálogo ---- */
	public function exclude_obra_unica_from_catalog( $q ) {
		if ( $this->core->is_landing_page() ) return;
		$tax = (array) $q->get( 'tax_query' );
		$tax[] = [
			'taxonomy' => 'product_type',
			'field'    => 'slug',
			'terms'    => [ FCSD_Core::PRODUCT_TYPE ],
			'operator' => 'NOT IN',
		];
		$q->set( 'tax_query', $tax );
	}
}

endif; // class_exists
