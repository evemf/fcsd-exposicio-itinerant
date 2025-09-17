<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WooCommerce: tipo de producto, metadatos, carrito, pasarelas, checkout,
 * emails extra y ajustes de admin.
 */
if ( ! class_exists( 'FCSD_Commerce' ) ) :

class FCSD_Commerce {

	/** @var FCSD_Core */
	private $core;

	public function __construct( FCSD_Core $core ) {
		$this->core = $core;

		/* ---- Tipo de producto y metadatos ---- */
		add_filter( 'product_type_selector',                 [ $this, 'register_product_type_in_selector' ] );
		add_filter( 'woocommerce_product_class',             [ $this, 'map_product_type_class' ], 10, 2 );
		add_filter( 'woocommerce_product_data_tabs',         [ $this, 'add_product_data_tab' ] );
		add_action( 'woocommerce_product_data_panels',       [ $this, 'render_product_data_panel' ] );
		add_action( 'woocommerce_admin_process_product_object', [ $this, 'save_meta_and_lock_stock' ] );
		add_action( 'woocommerce_process_product_meta',      [ $this, 'save_meta_legacy' ] );

		/* ---- Forzado tempranísimo del tipo en el request ---- */
		add_action( 'admin_init', [ $this, 'force_request_product_type_early' ], 0 );

		/* ---- Forzados de tipo (lectura + guardar + quick edit) ---- */
		add_filter( 'woocommerce_product_type_query',        [ $this, 'filter_product_type_query' ], PHP_INT_MAX, 2 ); // << clave
		add_action( 'load-post.php',                         [ $this, 'maybe_fix_type_on_editor_load' ] );
		add_action( 'woocommerce_after_product_object_save', [ $this, 'force_type_after_product_object_save' ], PHP_INT_MAX, 2 );
		add_action( 'save_post_product',                     [ $this, 'force_type_on_save_post' ],            PHP_INT_MAX, 3 );
		add_action( 'woocommerce_product_quick_edit_save',   [ $this, 'force_type_on_quick_edit' ],           PHP_INT_MAX, 1 );

		add_action( 'admin_footer', [ $this, 'admin_js_show_price_for_obra_unica' ] );

		/* ---- Gateways ---- */
		add_filter( 'woocommerce_default_gateway',            [ $this, 'maybe_force_default_gateway' ] );
		add_filter( 'woocommerce_available_payment_gateways', [ $this, 'maybe_limit_gateways' ] );

		/* ---- Carrito ---- */
		add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'enforce_single_item_cart_for_unique' ], 0, 6 );
		add_action( 'init',                             [ $this, 'maybe_preemptively_flush_cart' ], 1 );
		add_action( 'woocommerce_check_cart_items',     [ $this, 'enforce_only_unique_in_cart' ], 0 );

		/* ---- Emails extra ---- */
		foreach ( [
			'woocommerce_email_recipient_new_order',
			'woocommerce_email_recipient_cancelled_order',
			'woocommerce_email_recipient_failed_order',
			'woocommerce_email_recipient_processing_order',
			'woocommerce_email_recipient_completed_order',
		] as $hook ) {
			add_filter( $hook, [ $this, 'add_extra_recipient_if_obra_unica' ], 10, 2 );
		}

		/* ---- Ajustes de admin ---- */
		add_action( 'admin_menu', [ $this, 'add_admin_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );

		/* ---- Checkout mínimo ---- */
		add_filter( 'woocommerce_checkout_fields',                 [ $this, 'unique_minimal_checkout' ], 20 );
		add_filter( 'woocommerce_billing_fields',                  [ $this, 'unique_relax_billing_required' ], 20 );
		add_filter( 'woocommerce_cart_needs_shipping',             [ $this, 'unique_no_shipping' ], 10 );
		add_filter( 'woocommerce_cart_needs_shipping_address',     [ $this, 'unique_no_shipping_address' ], 10 );
		add_filter( 'woocommerce_checkout_posted_data',            [ $this, 'unique_autofill_country' ], 20 );
		add_filter( 'pre_option_woocommerce_enable_guest_checkout', [ $this, 'unique_force_guest_checkout' ] );

		/* ---- Evita “stock retenido” en checkout para obra_unica ---- */
		add_filter( 'pre_option_woocommerce_hold_stock_minutes', [ $this, 'disable_hold_stock_for_unique_checkout_only' ] );
	}

	/* ========= Tipo de producto y metadatos ========= */

	public function register_product_type_in_selector( $types ) {
		$types[ FCSD_Core::PRODUCT_TYPE ] = __( "Obra d’art única", 'fcsd-exposicio' );
		return $types;
	}

	public function map_product_type_class( $classname, $type ) {
		if ( $type === FCSD_Core::PRODUCT_TYPE ) $classname = 'WC_Product_FCSD_Obra_Unica';
		return $classname;
	}

	public function add_product_data_tab( $tabs ) {
		$tabs['fcsd_obra_unica'] = [
			'label'    => __( "Obra d’art única", 'fcsd-exposicio' ),
			'target'   => 'fcsd_obra_unica_data',
			'class'    => [ 'show_if_' . FCSD_Core::PRODUCT_TYPE ],
			'priority' => 21,
		];
		if ( isset( $tabs['general'] ) )   $tabs['general']['class'][]   = 'show_if_' . FCSD_Core::PRODUCT_TYPE;
		if ( isset( $tabs['inventory'] ) ) $tabs['inventory']['class'][] = 'show_if_' . FCSD_Core::PRODUCT_TYPE;
		return $tabs;
	}

	public function render_product_data_panel() {
		?>
		<div id="fcsd_obra_unica_data" class="panel woocommerce_options_panel hidden">
			<input type="hidden" name="_fcsd_force_unique" value="1" />
			<?php
			woocommerce_wp_text_input( [
				'id'            => FCSD_Core::META_AUTOR,
				'label'         => __( 'Autor/a', 'fcsd-exposicio' ),
				'desc_tip'      => true,
				'description'   => __( 'Nom de l’autor/a o creador/a de l’obra.', 'fcsd-exposicio' ),
				'wrapper_class' => 'show_if_' . FCSD_Core::PRODUCT_TYPE,
			] );
			woocommerce_wp_text_input( [
				'id'            => FCSD_Core::META_ANY,
				'label'         => __( 'Any de creació', 'fcsd-exposicio' ),
				'desc_tip'      => true,
				'description'   => __( 'Any de creació de l’obra (numèric).', 'fcsd-exposicio' ),
				'type'          => 'number',
				'wrapper_class' => 'show_if_' . FCSD_Core::PRODUCT_TYPE,
			] );
			woocommerce_wp_text_input( [
				'id'            => FCSD_Core::META_MESURES,
				'label'         => __( 'Mesures', 'fcsd-exposicio' ),
				'desc_tip'      => true,
				'description'   => __( 'Exemple: 50×70 cm, 30×50 cm…', 'fcsd-exposicio' ),
				'wrapper_class' => 'show_if_' . FCSD_Core::PRODUCT_TYPE,
			] );
			?>
			<p class="form-field">
				<strong><?php esc_html_e( 'Estoc', 'fcsd-exposicio' ); ?>:</strong>
				<?php esc_html_e( 'Les obres úniques tenen màxim 1 unitat i es venen de forma individual. Un cop venudes, queden “Esgotades”.', 'fcsd-exposicio' ); ?>
			</p>
		</div>
		<?php
	}

	public function save_meta_and_lock_stock( $product ) {
		$posted_type = isset($_POST['product-type']) ? sanitize_text_field( wp_unslash($_POST['product-type']) ) : '';
		$has_fields  = ( ! empty($_POST[FCSD_Core::META_AUTOR]) || ! empty($_POST[FCSD_Core::META_ANY]) || ! empty($_POST[FCSD_Core::META_MESURES]) );
		$make_unique = ( $posted_type === FCSD_Core::PRODUCT_TYPE ) || $has_fields;
		if ( ! $make_unique ) return;

		// Metadatos
		if ( isset( $_POST[ FCSD_Core::META_AUTOR ] ) )   $product->update_meta_data( FCSD_Core::META_AUTOR,   sanitize_text_field( wp_unslash( $_POST[ FCSD_Core::META_AUTOR ] ) ) );
		if ( isset( $_POST[ FCSD_Core::META_ANY ] ) )     $product->update_meta_data( FCSD_Core::META_ANY,     intval( $_POST[ FCSD_Core::META_ANY ] ) );
		if ( isset( $_POST[ FCSD_Core::META_MESURES ] ) ) $product->update_meta_data( FCSD_Core::META_MESURES, sanitize_text_field( wp_unslash( $_POST[ FCSD_Core::META_MESURES ] ) ) );

		// Config base del tipo único
		$product->set_manage_stock( true );
		$product->set_backorders( 'no' );
		$product->set_sold_individually( true );
		$product->set_catalog_visibility( 'hidden' );

		// ——— CLAVE: respetar 0 cuando el usuario lo guarda ———
		// Woo no envía _stock_status cuando manage_stock = true, así que tomamos la cantidad del POST.
		$posted_qty = isset($_POST['_stock']) ? wc_stock_amount( wp_unslash( $_POST['_stock'] ) ) : null;

		if ( $posted_qty !== null ) {
			$qty = (int) $posted_qty;
		} else {
			$qty = (int) $product->get_stock_quantity();
		}

		if ( $qty <= 0 ) {
			$product->set_stock_quantity( 0 );
			$product->set_stock_status( 'outofstock' );
		} else {
			// Para obra única, si hay stock siempre es 1.
			$product->set_stock_quantity( 1 );
			$product->set_stock_status( 'instock' );
		}

		// Asegura el término/tipo
		wp_set_object_terms( $product->get_id(), FCSD_Core::PRODUCT_TYPE, 'product_type', false );
		update_post_meta( $product->get_id(), '_product_type', FCSD_Core::PRODUCT_TYPE );
	}


	public function save_meta_legacy( $post_id ) {
		// Guardado clásico: sólo meta; el tipo lo forzamos aparte.
		if ( isset( $_POST[ FCSD_Core::META_AUTOR ] ) )   update_post_meta( $post_id, FCSD_Core::META_AUTOR,   sanitize_text_field( wp_unslash( $_POST[ FCSD_Core::META_AUTOR ] ) ) );
		if ( isset( $_POST[ FCSD_Core::META_ANY ] ) )     update_post_meta( $post_id, FCSD_Core::META_ANY,     intval( $_POST[ FCSD_Core::META_ANY ] ) );
		if ( isset( $_POST[ FCSD_Core::META_MESURES ] ) ) update_post_meta( $post_id, FCSD_Core::META_MESURES, sanitize_text_field( wp_unslash( $_POST[ FCSD_Core::META_MESURES ] ) ) );
	}

	/* ==== Forzado tempranísimo del tipo en el request ==== */

	public function force_request_product_type_early() {
		if ( ! is_admin() || empty($_POST) ) return;
		if ( ! isset($_POST['post_type']) || $_POST['post_type'] !== 'product' ) return;

		$nonce = isset($_POST['woocommerce_meta_nonce']) ? $_POST['woocommerce_meta_nonce'] : '';
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'woocommerce_save_data' ) ) return;

		$posted_type = isset($_POST['product-type']) ? sanitize_text_field( wp_unslash($_POST['product-type']) ) : '';
		$has_fields  = ( ! empty($_POST[FCSD_Core::META_AUTOR]) || ! empty($_POST[FCSD_Core::META_ANY]) || ! empty($_POST[FCSD_Core::META_MESURES]) );
		$panel_flag  = isset($_POST['_fcsd_force_unique']) && $_POST['_fcsd_force_unique'] === '1';

		if ( $posted_type === FCSD_Core::PRODUCT_TYPE || $has_fields || $panel_flag ) {
			$_POST['product-type'] = FCSD_Core::PRODUCT_TYPE; // Woo guardará este tipo
		}
	}

	/* ==== Forzados de tipo (lectura + distintas rutas de guardado) ==== */

	/** Fija el tipo cuando WooCommerce lo consulta para construir el objeto/selector. */
	public function filter_product_type_query( $type, $product_id ) {
		if ( ! $product_id ) return $type;

		// Term o meta explícitos → nuestro tipo
		if ( has_term( FCSD_Core::PRODUCT_TYPE, 'product_type', $product_id ) ) {
			return FCSD_Core::PRODUCT_TYPE;
		}
		$meta_type = get_post_meta( $product_id, '_product_type', true );
		if ( $meta_type === FCSD_Core::PRODUCT_TYPE ) {
			return FCSD_Core::PRODUCT_TYPE;
		}
		// Metacampos presentes → también nuestro tipo
		if ( get_post_meta( $product_id, FCSD_Core::META_AUTOR, true )
		  || get_post_meta( $product_id, FCSD_Core::META_ANY, true )
		  || get_post_meta( $product_id, FCSD_Core::META_MESURES, true ) ) {
			return FCSD_Core::PRODUCT_TYPE;
		}
		return $type;
	}

	public function maybe_fix_type_on_editor_load() {
		$pid = isset($_GET['post']) ? absint($_GET['post']) : 0;
		if ( ! $pid || get_post_type( $pid ) !== 'product' ) return;

		$should_be = apply_filters( 'woocommerce_product_type_query', 'simple', $pid );
		if ( $should_be === FCSD_Core::PRODUCT_TYPE && ! has_term( FCSD_Core::PRODUCT_TYPE, 'product_type', $pid ) ) {
			$this->force_product_type_now( $pid );
		}
	}

	public function force_type_after_product_object_save( $product, $data_store ) {
		$id = $product instanceof WC_Product ? $product->get_id() : 0;
		if ( ! $id ) return;
		$this->force_product_type_now( $id );
	}

	public function force_type_on_save_post( $post_id, $post, $update ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
		if ( 'product' !== $post->post_type ) return;
		$this->force_product_type_now( $post_id );
	}

	public function force_type_on_quick_edit( $product ) {
		if ( $product instanceof WC_Product ) {
			$this->force_product_type_now( $product->get_id() );
		}
	}

	private function force_product_type_now( $product_id ) : void {
		if ( ! term_exists( FCSD_Core::PRODUCT_TYPE, 'product_type' ) ) {
			wp_insert_term( __( "Obra d’art única", 'fcsd-exposicio' ), 'product_type', [ 'slug' => FCSD_Core::PRODUCT_TYPE ] );
		}
		wp_set_object_terms( $product_id, FCSD_Core::PRODUCT_TYPE, 'product_type', false );
		update_post_meta( $product_id, '_product_type', FCSD_Core::PRODUCT_TYPE );

		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $product_id );
		}
		clean_post_cache( $product_id );
	}

	public function admin_js_show_price_for_obra_unica() {
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if ( ! $screen || $screen->id !== 'product' ) return; ?>
		<script>
		jQuery(function($){
			function fcsd_apply(){
				$('.options_group.pricing, ._regular_price_field, ._sale_price_field').addClass('show_if_obra_unica');
			}
			$(document.body).on('woocommerce_init woocommerce_product_type_changed', fcsd_apply);
			fcsd_apply();
		});
		</script><?php
	}

	/* ========= Gateways ========= */

	public function maybe_force_default_gateway( $default ) {
		$forced = WC()->session ? WC()->session->get( FCSD_Core::SESSION_GW ) : '';
		if ( $forced ) return $forced;
		$pref = sanitize_text_field( get_option( FCSD_Core::OPT_GW_DEFAULT, '' ) );
		return $pref ?: $default;
	}

	public function maybe_limit_gateways( $gateways ) {
		if ( ! WC()->cart ) return $gateways;
		$contains_unique = false;
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( isset( $item['data'] ) && $item['data'] instanceof WC_Product && $item['data']->get_type() === FCSD_Core::PRODUCT_TYPE ) {
				$contains_unique = true; break;
			}
		}
		$forced = WC()->session ? WC()->session->get( FCSD_Core::SESSION_GW ) : '';
		if ( ! $contains_unique || ! $forced ) return $gateways;
		foreach ( $gateways as $id => $gw ) {
			if ( $id !== $forced ) unset( $gateways[$id] );
		}
		return $gateways;
	}

	/* ========= Carrito ========= */

	public function enforce_single_item_cart_for_unique( $passed, $product_id, $quantity, $variation_id = 0, $variations = [], $cart_item_data = [] ) {
		$p = wc_get_product( $product_id );
		if ( $p && $p->get_type() === FCSD_Core::PRODUCT_TYPE ) {
			if ( WC()->cart && ! WC()->cart->is_empty() ) {
				WC()->cart->empty_cart();
			}
		}
		return $passed;
	}

	public function maybe_preemptively_flush_cart() {
		$id = isset($_GET['add-to-cart']) ? absint($_GET['add-to-cart']) : 0;
		if ( ! $id ) return;
		$p = wc_get_product( $id );
		if ( $p && $p->get_type() === FCSD_Core::PRODUCT_TYPE ) {
			if ( WC()->cart && ! WC()->cart->is_empty() ) {
				WC()->cart->empty_cart();
			}
		}
	}

	public function enforce_only_unique_in_cart() {
		if ( ! WC()->cart ) return;
		$has_unique = false;
		foreach ( WC()->cart->get_cart() as $k => $it ) {
			$p = isset($it['data']) ? $it['data'] : null;
			if ( $p instanceof WC_Product && $p->get_type() === FCSD_Core::PRODUCT_TYPE ) { $has_unique = true; break; }
		}
		if ( ! $has_unique ) return;
		foreach ( WC()->cart->get_cart() as $k => $it ) {
			$p = isset($it['data']) ? $it['data'] : null;
			if ( ! $p instanceof WC_Product ) continue;
			if ( $p->get_type() !== FCSD_Core::PRODUCT_TYPE ) {
				WC()->cart->remove_cart_item( $k );
			} else {
				if ( (int) $it['quantity'] !== 1 ) {
					WC()->cart->set_quantity( $k, 1, true );
				}
			}
		}
	}

	/* ========= Emails extra ========= */

	public function add_extra_recipient_if_obra_unica( $recipient, $order ) {
		if ( ! $order instanceof WC_Order ) return $recipient;
		$has = false;
		foreach ( $order->get_items() as $it ) {
			$p = $it->get_product();
			if ( $p && $p->get_type() === FCSD_Core::PRODUCT_TYPE ) { $has = true; break; }
		}
		if ( $has ) {
			$extra = sanitize_email( get_option( FCSD_Core::OPT_EXTRA_EMAIL, '' ) );
			if ( $extra ) {
				$emails = array_unique( array_filter( array_map( 'trim', explode( ',', $recipient . ',' . $extra ) ) ) );
				$recipient = implode( ',', $emails );
			}
		}
		return $recipient;
	}

	/* ========= Ajustes de admin ========= */

	public function add_admin_page() {
		add_submenu_page(
			'woocommerce',
			__( 'Exposició Itinerant', 'fcsd-exposicio' ),
			__( 'Exposició Itinerant', 'fcsd-exposicio' ),
			'manage_woocommerce',
			'fcsd-exposicio',
			[ $this, 'render_admin_page' ]
		);
	}

	public function register_settings() {
		register_setting( 'fcsd_expo_group', FCSD_Core::OPT_EXTRA_EMAIL, [ 'type' => 'string', 'sanitize_callback' => 'sanitize_email' ] );
		register_setting( 'fcsd_expo_group', FCSD_Core::OPT_GW_DEFAULT,  [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ] );
		register_setting( 'fcsd_expo_group', FCSD_Core::OPT_GW_BIZUM,    [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ] );
	}

	public function render_admin_page() {
		$gw_default = esc_attr( get_option( FCSD_Core::OPT_GW_DEFAULT, '' ) );
		$gw_bizum   = esc_attr( get_option( FCSD_Core::OPT_GW_BIZUM, '' ) );
		$gateways   = function_exists('WC') && WC()->payment_gateways() ? WC()->payment_gateways->payment_gateways() : [];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Exposició Itinerant — Configuració', 'fcsd-exposicio' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'fcsd_expo_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="<?php echo esc_attr(FCSD_Core::OPT_EXTRA_EMAIL); ?>"><?php esc_html_e( 'Email addicional d’avisos', 'fcsd-exposicio' ); ?></label></th>
						<td>
							<input type="email" class="regular-text" name="<?php echo esc_attr(FCSD_Core::OPT_EXTRA_EMAIL); ?>" id="<?php echo esc_attr(FCSD_Core::OPT_EXTRA_EMAIL); ?>" value="<?php echo esc_attr( get_option(FCSD_Core::OPT_EXTRA_EMAIL, '') ); ?>" placeholder="artsales@exemple.com" />
							<p class="description"><?php esc_html_e( 'Aquest email també rebrà els avisos de compra de les obres úniques.', 'fcsd-exposicio' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Passarel·les preferides', 'fcsd-exposicio' ); ?></th>
						<td>
							<p><label for="<?php echo esc_attr(FCSD_Core::OPT_GW_DEFAULT); ?>"><strong><?php esc_html_e('Predeterminada (Targeta/MONEI)','fcsd-exposicio'); ?></strong></label><br>
								<select name="<?php echo esc_attr(FCSD_Core::OPT_GW_DEFAULT); ?>" id="<?php echo esc_attr(FCSD_Core::OPT_GW_DEFAULT); ?>">
									<option value=""><?php esc_html_e('— Sense forçar —','fcsd-exposicio'); ?></option>
									<?php foreach ( $gateways as $id => $gw ) : ?>
										<option value="<?php echo esc_attr($id); ?>" <?php selected($gw_default,$id); ?>><?php echo esc_html($gw->get_title().' ('.$id.')'); ?></option>
									<?php endforeach; ?>
								</select>
							</p>
							<p><label for="<?php echo esc_attr(FCSD_Core::OPT_GW_BIZUM); ?>"><strong><?php esc_html_e('Bizum','fcsd-exposicio'); ?></strong></label><br>
								<select name="<?php echo esc_attr(FCSD_Core::OPT_GW_BIZUM); ?>" id="<?php echo esc_attr(FCSD_Core::OPT_GW_BIZUM); ?>">
									<option value=""><?php esc_html_e('— Cap —','fcsd-exposicio'); ?></option>
									<?php foreach ( $gateways as $id => $gw ) : ?>
										<option value="<?php echo esc_attr($id); ?>" <?php selected($gw_bizum,$id); ?>><?php echo esc_html($gw->get_title().' ('.$id.')'); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e('Amb MONEI sol ser "monei" i "monei_bizum".','fcsd-exposicio'); ?></p>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/* ========= Checkout mínim (obra_unica) ========= */

	private function is_unique_checkout_context() : bool {
		if ( ! function_exists('is_checkout') || ! WC()->cart ) return false;
		$at_checkout = is_checkout() || ( isset($_GET['add-to-cart']) && ! empty($_GET['fcsd_gw']) );
		if ( ! $at_checkout ) return false;

		$items = WC()->cart->get_cart();
		if ( empty( $items ) ) return false;

		foreach ( $items as $it ) {
			$p = $it['data'] ?? null;
			if ( ! ( $p instanceof WC_Product ) ) return false;
			if ( $p->get_type() !== FCSD_Core::PRODUCT_TYPE ) return false;
			if ( (int) ($it['quantity'] ?? 1) !== 1 ) return false;
		}
		return true;
	}

	public function unique_relax_billing_required( $fields ) {
		if ( ! $this->is_unique_checkout_context() ) return $fields;
		foreach ( $fields as &$f ) { $f['required'] = false; }
		return $fields;
	}

	public function unique_minimal_checkout( $fields ) {
		if ( ! $this->is_unique_checkout_context() ) return $fields;

		$keep = [ 'billing_email', 'billing_first_name', 'billing_last_name' ];

		foreach ( ['billing','shipping'] as $section ) {
			if ( isset( $fields[ $section ] ) ) {
				foreach ( array_keys( $fields[ $section ] ) as $key ) {
					if ( ! in_array( $key, $keep, true ) ) {
						unset( $fields[ $section ][ $key ] );
					}
				}
			}
		}

		unset( $fields['order']['order_comments'], $fields['account'] );

		if ( isset( $fields['billing']['billing_email'] ) ) {
			$fields['billing']['billing_email']['required'] = true;
		}
		if ( isset( $fields['billing']['billing_first_name'] ) ) {
			$fields['billing']['billing_first_name']['required'] = false;
		}
		if ( isset( $fields['billing']['billing_last_name'] ) ) {
			$fields['billing']['billing_last_name']['required'] = false;
		}

		return $fields;
	}

	public function unique_no_shipping( $needs_shipping ) {
		return $this->is_unique_checkout_context() ? false : $needs_shipping;
	}

	public function unique_no_shipping_address( $needs_address ) {
		return $this->is_unique_checkout_context() ? false : $needs_address;
	}

	public function unique_autofill_country( $data ) {
		if ( ! $this->is_unique_checkout_context() ) return $data;
		if ( empty( $data['billing_country'] ) ) {
			$base = wc_get_base_location();
			$data['billing_country'] = is_array($base) && ! empty($base['country']) ? $base['country'] : $data['billing_country'];
		}
		return $data;
	}

	public function unique_force_guest_checkout( $pre ) {
		return $this->is_unique_checkout_context() ? 'yes' : $pre;
	}

	public function disable_hold_stock_for_unique_checkout_only( $pre ) {
		if ( is_admin() || ! WC()->cart ) return $pre;
		foreach ( WC()->cart->get_cart() as $it ) {
			$p = $it['data'] ?? null;
			if ( $p instanceof WC_Product && $p->get_type() === FCSD_Core::PRODUCT_TYPE ) {
				return '0'; // no retener stock
			}
		}
		return $pre;
	}
}

endif; // class_exists

/**
 * Subclase de producto.
 */
add_action( 'plugins_loaded', function () {
	if ( class_exists( 'WC_Product_Simple' ) && ! class_exists( 'WC_Product_FCSD_Obra_Unica' ) ) {
		class WC_Product_FCSD_Obra_Unica extends WC_Product_Simple {
			public function get_type() { return FCSD_Core::PRODUCT_TYPE; }
			public function set_stock_quantity( $qty ) { parent::set_stock_quantity( max( 0, min( 1, (int) $qty ) ) ); }
			public function set_manage_stock( $manage ) { parent::set_manage_stock( true ); }
			public function set_backorders( $back ) { parent::set_backorders( 'no' ); }
			public function set_sold_individually( $si ) { parent::set_sold_individually( true ); }
		}
	}
}, 12 );
