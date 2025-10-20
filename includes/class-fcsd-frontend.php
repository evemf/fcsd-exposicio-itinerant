<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'FCSD_Frontend' ) ) :

class FCSD_Frontend {

	private $core;

	public function __construct( FCSD_Core $core ) {
		$this->core = $core;

		add_filter( 'wp_nav_menu_objects',          [ $this, 'hide_landing_from_menus' ], 10, 2 );
		add_action( 'pre_get_posts',                [ $this, 'exclude_landing_from_search' ] );
		add_filter( 'wp_robots',                    [ $this, 'noindex_landing' ] );
		add_filter( 'wp_sitemaps_posts_query_args', [ $this, 'exclude_landing_from_sitemaps' ], 10, 2 );
		add_action( 'template_redirect',            [ $this, 'block_internal_navigation' ], 0 );

		add_action( 'template_redirect',            [ $this, 'maybe_apply_gateway_choice' ], 0 );
		add_action( 'template_redirect',            [ $this, 'render_expo_template' ], 5 );

		add_action( 'woocommerce_product_query',    [ $this, 'exclude_obra_unica_from_catalog' ] );
	}

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
			$not   = (array) $q->get( 'post__not_in' );
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

	public function maybe_apply_gateway_choice() {
		if ( is_admin() || ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
                $choice = isset( $_GET['fcsd_gw'] ) ? sanitize_text_field( wp_unslash( $_GET['fcsd_gw'] ) ) : '';
                if ( ! $choice ) return;
                if ( ! wp_verify_nonce( $_GET['fcsd_nonce'] ?? '', FCSD_Core::NONCE_BUY ) ) return;

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
			:root{
				--bg:#faf7ef; --text:#1f2937; --muted:#556070; --card:#ffffff; --card-b:#e8e6df;
				--accent:#e3c24b; --accent-d:#b89c32; --sold:#b91c1c; --chip:#f1f5f9;
			}
			body.fcsd-expo{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,'Helvetica Neue',Arial,sans-serif;background:var(--bg);color:var(--text);margin:0;}
			.fcsd-wrap{max-width:1200px;width:100%;margin:0 auto;padding:28px}
			.fcsd-h1{margin:.15em 0 6px;font-size:2.25rem;letter-spacing:.3px}
			.fcsd-sub{margin:0 0 24px;color:var(--muted)}
			.fcsd-grid{display:grid;gap:22px;grid-template-columns:repeat(auto-fill,minmax(300px,1fr))}
			.fcsd-card{background:var(--card);border:1px solid var(--card-b);border-radius:16px;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 8px 20px rgba(0,0,0,.06)}
			.fcsd-fig{position:relative;background:linear-gradient(180deg,#f2efe6,#e9e4d7)}
			.fcsd-thumb img{display:block;width:100%;height:auto;aspect-ratio:4/3;object-fit:cover}
			.fcsd-badge{position:absolute;top:12px;left:12px;background:var(--chip);color:#111;padding:6px 10px;border-radius:999px;font-size:.78rem;font-weight:700;box-shadow:0 2px 8px rgba(0,0,0,.08)}
			.fcsd-badge.sold{background:#fee2e2;color:var(--sold)}
			.fcsd-body{padding:16px 16px 18px;display:flex;flex-direction:column;gap:12px}
			.fcsd-ttl{font-size:1.15rem;margin:0;line-height:1.3}
			.fcsd-meta{display:flex;flex-wrap:wrap;gap:8px;font-size:.92rem;color:var(--muted)}
			.fcsd-chip{background:var(--chip);border-radius:999px;padding:4px 10px}
			.fcsd-desc{font-size:.98rem;color:#374151}
			.fcsd-buy{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:auto}
			.fcsd-preu{font-weight:800;font-size:1.1rem}
			.fcsd-btn{display:inline-flex;align-items:center;gap:8px;padding:11px 16px;border-radius:12px;background:var(--accent);color:#111;text-decoration:none;font-weight:800;box-shadow:0 6px 14px rgba(227,194,75,.25);transition:transform .12s ease, box-shadow .12s ease, background .12s ease}
			.fcsd-btn:hover{transform:translateY(-1px);background:var(--accent-d);color:#ffffff;box-shadow:0 8px 20px rgba(184,156,50,.32)}
			.fcsd-sold{display:inline-flex;align-items:center;gap:8px;background:#f1f5f9;color:#0f172a;padding:9px 12px;border-radius:12px;font-weight:700}
			.fcsd-foot{margin-top:34px;text-align:center;color:var(--muted);font-size:.92rem}
			.fcsd-more{color:var(--muted);text-decoration:none;font-size:.9rem}
			.fcsd-more:hover{text-decoration:underline}
			</style>
		</head>
		<body <?php body_class('fcsd-expo'); ?>>
		<main class="fcsd-wrap">
			<h1 class="fcsd-h1"><?php echo esc_html__( "Exposició itinerant", 'fcsd-exposicio' ); ?></h1>
			<p class="fcsd-sub"><?php echo esc_html__( "Obres d’art úniques — edició limitada 1/1. Descobreix, emociona’t i dona suport a la creació contemporània.", 'fcsd-exposicio' ); ?></p>

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
						$thumb   = get_the_post_thumbnail( $pid, 'large', [ 'alt' => esc_attr( $title ) ] );
						$excerpt = wp_trim_words( get_the_excerpt( $pid ), 28, '…' );

						$qty     = (int) $product->get_stock_quantity();
						$can_buy = $product->is_purchasable() && $product->is_in_stock() && $qty > 0;
						?>
						<article class="fcsd-card">
							<figure class="fcsd-fig fcsd-thumb">
								<?php echo $thumb ?: ''; ?>
								<?php if ( ! $can_buy ) : ?>
									<span class="fcsd-badge sold"><?php esc_html_e( 'Esgotada', 'fcsd-exposicio' ); ?></span>
								<?php else : ?>
									<span class="fcsd-badge"><?php echo esc_html_x( '1/1', 'unique piece', 'fcsd-exposicio' ); ?></span>
								<?php endif; ?>
							</figure>
							<div class="fcsd-body">
								<h2 class="fcsd-ttl"><?php echo esc_html( $title ); ?></h2>

								<p class="fcsd-meta">
									<?php if ( $autor )   echo '<span class="fcsd-chip">'.esc_html( $autor ).'</span>'; ?>
									<?php if ( $any )     echo '<span class="fcsd-chip">'.esc_html( $any ).'</span>'; ?>
									<?php if ( $mesures ) echo '<span class="fcsd-chip">'.esc_html( $mesures ).'</span>'; ?>
								</p>

								<div class="fcsd-desc"><?php echo wp_kses_post( wpautop( $excerpt ) ); ?></div>

								<div class="fcsd-buy">
									<?php if ( $can_buy ) : ?>
										<span class="fcsd-preu"><?php echo wp_kses_post( $product->get_price_html() ); ?></span>
										<?php echo $this->core->buy_link( $product, 'default', __( 'Pagar ara', 'fcsd-exposicio' ) ); ?>
									<?php else : ?>
										<span class="fcsd-sold">✦ <?php esc_html_e( 'Esgotada', 'fcsd-exposicio' ); ?></span>
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
				<p><?php esc_html_e( 'Accés privat: comparteix només el teu enllaç o QR.', 'fcsd-exposicio' ); ?></p>
			</footer>
		</main>
		<?php wp_footer(); ?>
		</body>
		</html>
		<?php
		exit;
	}

	public function exclude_obra_unica_from_catalog( $q ) {
		if ( $this->core->is_landing_page() ) return;
		$tax   = (array) $q->get( 'tax_query' );
		$tax[] = [
			'taxonomy' => 'product_type',
			'field'    => 'slug',
			'terms'    => [ FCSD_Core::PRODUCT_TYPE ],
			'operator' => 'NOT IN',
		];
		$q->set( 'tax_query', $tax );
	}
}

endif;
