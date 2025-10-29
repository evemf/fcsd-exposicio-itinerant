<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'FCSD_Commerce' ) ) :

class FCSD_Commerce {
	private $core;

	public function __construct( FCSD_Core $core ) {
		$this->core = $core;

		add_filter( 'product_type_selector', [ $this, 'register_product_type_in_selector' ] );
		add_filter( 'woocommerce_product_class', [ $this, 'map_product_type_class' ], 10, 2 );
		add_filter( 'woocommerce_product_data_tabs', [ $this, 'add_product_data_tab' ], PHP_INT_MAX );
		add_action( 'woocommerce_product_data_panels', [ $this, 'render_product_data_panel' ] );
		add_action( 'woocommerce_admin_process_product_object', [ $this, 'save_meta_and_lock_stock' ] );
		add_action( 'woocommerce_process_product_meta', [ $this, 'save_meta_legacy' ] );
		add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'enforce_line_item_price' ], 20, 4 );

		add_action( 'admin_init', [ $this, 'force_request_product_type_early' ], 0 );

		add_filter( 'woocommerce_product_type_query', [ $this, 'filter_product_type_query' ], PHP_INT_MAX, 2 );
		add_filter( 'woocommerce_get_product_type', [ $this, 'filter_product_type_query' ], PHP_INT_MAX, 2 );
		add_action( 'load-post.php', [ $this, 'maybe_fix_type_on_editor_load' ] );
		add_action( 'woocommerce_after_product_object_save', [ $this, 'force_type_after_product_object_save' ], PHP_INT_MAX, 2 );
		add_action( 'save_post_product', [ $this, 'force_type_on_save_post' ], PHP_INT_MAX, 3 );
		add_action( 'woocommerce_product_quick_edit_save', [ $this, 'force_type_on_quick_edit' ], PHP_INT_MAX, 1 );

		add_action( 'admin_footer', [ $this, 'admin_js_show_price_for_obra_unica' ] );

		add_filter( 'woocommerce_default_gateway', [ $this, 'maybe_force_default_gateway' ] );
		add_filter( 'woocommerce_available_payment_gateways', [ $this, 'maybe_limit_gateways' ] );

		add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'enforce_single_item_cart_for_unique' ], 0, 6 );
		add_action( 'init', [ $this, 'maybe_preemptively_flush_cart' ], 1 );
		add_action( 'woocommerce_check_cart_items', [ $this, 'enforce_only_unique_in_cart' ], 0 );

		add_action( 'woocommerce_before_calculate_totals', [ $this, 'ensure_unique_product_price_in_cart' ], 20 );

		// === Email "Nuevo pedido" para obras únicas ===
		add_filter( 'woocommerce_email_recipient_new_order', [ $this, 'add_extra_recipient_if_obra_unica' ], PHP_INT_MAX, 2 );
		add_filter( 'woocommerce_email_enabled_new_order', [ $this, 'enable_new_order_email_for_unique' ], 10, 2 );
		add_action( 'woocommerce_checkout_order_processed', [ $this, 'ensure_new_order_email_sent' ], 999, 3 );
		add_action( 'woocommerce_payment_complete',        [ $this, 'ensure_new_order_email_sent' ], 5,   1 );
		add_action( 'woocommerce_thankyou',                [ $this, 'ensure_new_order_email_sent' ], 5,   1 );
		add_action( 'woocommerce_order_status_changed',    [ $this, 'ensure_new_order_email_on_status' ], 5, 4 );

		add_action( 'admin_menu', [ $this, 'add_admin_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );

		// [CHECKOUT SIMPLE] – mantener checkout mínimo para obras únicas
		add_filter( 'woocommerce_checkout_fields', [ $this, 'unique_minimal_checkout' ], 20 );
		add_filter( 'woocommerce_billing_fields', [ $this, 'unique_relax_billing_required' ], 20 );
		add_filter( 'woocommerce_cart_needs_shipping', [ $this, 'unique_no_shipping' ], 10 );
		add_filter( 'woocommerce_cart_needs_shipping_address', [ $this, 'unique_no_shipping_address' ], 10 );
		add_filter( 'woocommerce_checkout_posted_data', [ $this, 'unique_autofill_country' ], 20 );
		add_filter( 'pre_option_woocommerce_enable_guest_checkout', [ $this, 'unique_force_guest_checkout' ] );
		add_filter( 'pre_option_woocommerce_hold_stock_minutes', [ $this, 'disable_hold_stock_for_unique_checkout_only' ] );
		add_filter( 'woocommerce_enable_order_notes_field', [ $this, 'unique_disable_order_notes' ], 99 );
		add_action( 'wp_head', [ $this, 'unique_hide_additional_heading_css' ] );

		add_action( 'woocommerce_reduce_order_stock', [ $this, 'sync_unique_stock_after_reduction' ], 20, 1 );
		add_action( 'woocommerce_payment_complete', [ $this, 'sync_unique_stock_on_paid' ], 20, 1 );
		add_action( 'woocommerce_order_status_processing', [ $this, 'sync_unique_stock_on_paid' ], 20, 1 );
		add_action( 'woocommerce_order_status_completed', [ $this, 'sync_unique_stock_on_paid' ], 20, 1 );
	}

	public function register_product_type_in_selector( $types ) {
		$types[ FCSD_Core::PRODUCT_TYPE ] = __( "Obra d’art única", 'fcsd-exposicio' );
		return $types;
	}

	public function map_product_type_class( $classname, $type ) {
		if ( $type === FCSD_Core::PRODUCT_TYPE ) $classname = 'WC_Product_FCSD_Obra_Unica';
		return $classname;
	}

	/**
	 * Tabs de datos de producto:
	 * - No encerramos "General" ni "Inventario" solo para nuestro tipo.
	 * - Si ya están condicionados por show_if_*, añadimos nuestro show_if_{tipo}.
	 * - Creamos "Inventario" si algún plugin lo quitó.
	 */
	public function add_product_data_tab( $tabs ) {
		// Asegurar General (estructura estándar). NO añadir show_if_* aquí directamente.
		if ( empty( $tabs['general'] ) ) {
			$tabs['general'] = [
				'label'    => __( 'General', 'woocommerce' ),
				'target'   => 'general_product_data',
				'class'    => [ 'general_options' ],
				'priority' => 10,
			];
		}
		// Si algún entorno añadió show_if_* a General, extender a nuestro tipo.
		$tabs['general']['class'] = $this->add_unique_type_class( $tabs['general']['class'] ?? [] );

		// Asegurar Inventario (core suele traer show_if_* aquí)
		if ( empty( $tabs['inventory'] ) ) {
			$tabs['inventory'] = [
				'label'    => __( 'Inventario', 'woocommerce' ),
				'target'   => 'inventory_product_data',
				'class'    => [ 'inventory_options', 'show_if_simple', 'show_if_variable', 'show_if_grouped' ],
				'priority' => 20,
			];
		}
		$tabs['inventory']['class'] = $this->add_unique_type_class( $tabs['inventory']['class'] ?? [] );

		// Pestaña propia del tipo
		$tabs['fcsd_obra_unica'] = [
			'label'    => __( "Obra d’art única", 'fcsd-exposicio' ),
			'target'   => 'fcsd_obra_unica_data',
			'class'    => [ 'show_if_' . FCSD_Core::PRODUCT_TYPE ],
			'priority' => 21,
		];

		return $tabs;
	}

	/**
	 * Añade 'show_if_{tipo}' SOLO si ya hay alguna clase 'show_if_*' en el destino,
	 * para no ocultar el elemento globalmente a otros tipos.
	 * Mantiene el tipo de entrada (array|string).
	 */
    private function add_unique_type_class( $classes ) {
		$original_is_array = is_array( $classes );
		$normalized = $this->normalize_tab_classes( $original_is_array ? $classes : (array) $classes );

		$has_show_if = false;
		foreach ( $normalized as $c ) {
			if ( strpos( $c, 'show_if_' ) === 0 ) { $has_show_if = true; break; }
		}

		if ( $has_show_if ) {
			$needle = 'show_if_' . FCSD_Core::PRODUCT_TYPE;
			if ( ! in_array( $needle, $normalized, true ) ) {
				$normalized[] = $needle;
			}
		}

		return $original_is_array ? $normalized : implode( ' ', $normalized );
	}

    private function normalize_tab_classes( array $classes ) {
        $normalized = [];
        foreach ( $classes as $class ) {
            $parts = preg_split( '/\s+/', (string) $class );
            if ( ! $parts ) continue;
            foreach ( $parts as $part ) {
                $part = trim( $part );
                if ( $part !== '' && ! in_array( $part, $normalized, true ) ) {
                    $normalized[] = $part;
                }
            }
        }
        return $normalized;
    }

	public function render_product_data_panel() {
		?>
		<div id="fcsd_obra_unica_data" class="panel woocommerce_options_panel hidden">
			<input type="hidden" name="_fcsd_force_unique" value="1" />
			<?php
			woocommerce_wp_text_input( [
				'id' => FCSD_Core::META_AUTOR,
				'label' => __( 'Autor/a', 'fcsd-exposicio' ),
				'desc_tip' => true,
				'description' => __( 'Nom de l’autor/a o creador/a de l’obra.', 'fcsd-exposicio' ),
				'wrapper_class' => 'show_if_' . FCSD_Core::PRODUCT_TYPE,
			] );
			woocommerce_wp_text_input( [
				'id' => FCSD_Core::META_ANY,
				'label' => __( 'Any de creació', 'fcsd-exposicio' ),
				'desc_tip' => true,
				'description' => __( 'Any de creació de l’obra (numèric).', 'fcsd-exposicio' ),
				'type' => 'number',
				'wrapper_class' => 'show_if_' . FCSD_Core::PRODUCT_TYPE,
			] );
			woocommerce_wp_text_input( [
				'id' => FCSD_Core::META_MESURES,
				'label' => __( 'Mesures', 'fcsd-exposicio' ),
				'desc_tip' => true,
				'description' => __( 'Exemple: 50×70 cm, 30×50 cm…', 'fcsd-exposicio' ),
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
		$posted_type = $this->get_requested_product_type();
		$has_fields  = ( ! empty($_POST[FCSD_Core::META_AUTOR]) || ! empty($_POST[FCSD_Core::META_ANY]) || ! empty($_POST[FCSD_Core::META_MESURES]) );
		$panel_flag  = isset($_POST['_fcsd_force_unique']) && $_POST['_fcsd_force_unique'] === '1';

		if ( $posted_type && $posted_type !== FCSD_Core::PRODUCT_TYPE ) return;
		if ( ! $panel_flag && ! $has_fields && $posted_type !== FCSD_Core::PRODUCT_TYPE ) return;

		if ( isset( $_POST[ FCSD_Core::META_AUTOR ] ) )   $product->update_meta_data( FCSD_Core::META_AUTOR,   sanitize_text_field( wp_unslash( $_POST[ FCSD_Core::META_AUTOR ] ) ) );
		if ( isset( $_POST[ FCSD_Core::META_ANY ] ) )     $product->update_meta_data( FCSD_Core::META_ANY,     intval( wp_unslash( $_POST[ FCSD_Core::META_ANY ] ) ) );
		if ( isset( $_POST[ FCSD_Core::META_MESURES ] ) ) $product->update_meta_data( FCSD_Core::META_MESURES, sanitize_text_field( wp_unslash( $_POST[ FCSD_Core::META_MESURES ] ) ) );

		if ( isset($_POST['_regular_price']) ) $product->set_regular_price( wc_clean( wp_unslash( $_POST['_regular_price'] ) ) );
		if ( isset($_POST['_sale_price']) )    $product->set_sale_price( wc_clean( wp_unslash( $_POST['_sale_price'] ) ) );
		$price = $product->get_sale_price() !== '' ? $product->get_sale_price() : $product->get_regular_price();
		if ( $price !== '' ) $product->set_price( $price );

		$product->set_manage_stock( true );
		$product->set_backorders( 'no' );
		$product->set_sold_individually( true );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_virtual( true );

		$posted_qty = isset($_POST['_stock']) ? wc_stock_amount( wp_unslash( $_POST['_stock'] ) ) : null;
		$qty = $posted_qty !== null ? (int) $posted_qty : (int) $product->get_stock_quantity();
		if ( $qty <= 0 ) {
			$product->set_stock_quantity( 0 );
			$product->set_stock_status( 'outofstock' );
		} else {
			$product->set_stock_quantity( 1 );
			$product->set_stock_status( 'instock' );
		}

		wp_set_object_terms( $product->get_id(), FCSD_Core::PRODUCT_TYPE, 'product_type', false );
		update_post_meta( $product->get_id(), '_product_type', FCSD_Core::PRODUCT_TYPE );
	}

	public function save_meta_legacy( $post_id ) {
		if ( isset( $_POST[ FCSD_Core::META_AUTOR ] ) )   update_post_meta( $post_id, FCSD_Core::META_AUTOR,   sanitize_text_field( wp_unslash( $_POST[ FCSD_Core::META_AUTOR ] ) ) );
		if ( isset( $_POST[ FCSD_Core::META_ANY ] ) )     update_post_meta( $post_id, FCSD_Core::META_ANY,     intval( wp_unslash( $_POST[ FCSD_Core::META_ANY ] ) ) );
		if ( isset( $_POST[ FCSD_Core::META_MESURES ] ) ) update_post_meta( $post_id, FCSD_Core::META_MESURES, sanitize_text_field( wp_unslash( $_POST[ FCSD_Core::META_MESURES ] ) ) );
	}

	public function enforce_line_item_price( $item, $cart_item_key, $values, $order ) {
		$p = $item->get_product();
		if ( ! ( $p instanceof WC_Product ) ) return;
		if ( ! $this->product_is_unique( $p ) ) return;

		if ( $order instanceof WC_Order ) $order->update_meta_data( '_fcsd_has_unique', 'yes' );

		$line_total = (float) $item->get_total();
		$qty        = max( 1, (int) $item->get_quantity() );

		if ( $line_total <= 0 ) {
			$price = $p->get_price();
			if ( $price === '' || $price === null || (float) $price <= 0 ) {
				$fallback = $p->get_sale_price() !== '' ? $p->get_sale_price() : $p->get_regular_price();
				$price    = $fallback !== '' ? $fallback : 0;
			}
			if ( (float) $price > 0 ) {
				$item->set_subtotal( (float) $price * $qty );
				$item->set_total( (float) $price * $qty );
			}
		}
	}

	public function force_request_product_type_early() {
		if ( ! is_admin() || empty($_POST) ) return;
		if ( ! isset($_POST['post_type']) || $_POST['post_type'] !== 'product' ) return;
		$nonce = isset($_POST['woocommerce_meta_nonce']) ? $_POST['woocommerce_meta_nonce'] : '';
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'woocommerce_save_data' ) ) return;
		$posted_type = $this->get_requested_product_type();
		if ( $posted_type && $posted_type !== FCSD_Core::PRODUCT_TYPE ) return;

		$has_fields = ( ! empty($_POST[FCSD_Core::META_AUTOR]) || ! empty($_POST[FCSD_Core::META_ANY]) || ! empty($_POST[FCSD_Core::META_MESURES]) );
		$panel_flag = isset($_POST['_fcsd_force_unique']) && $_POST['_fcsd_force_unique'] === '1';

		if ( $posted_type === FCSD_Core::PRODUCT_TYPE || ( ! $posted_type && ( $has_fields || $panel_flag ) ) ) {
			$_POST['product-type'] = FCSD_Core::PRODUCT_TYPE;
		}
	}

	public function filter_product_type_query( $type, $product_id ) {
		if ( ! $product_id ) return $type;
		if ( has_term( FCSD_Core::PRODUCT_TYPE, 'product_type', $product_id ) ) return FCSD_Core::PRODUCT_TYPE;
		$meta_type = get_post_meta( $product_id, '_product_type', true );
		if ( $meta_type === FCSD_Core::PRODUCT_TYPE ) return FCSD_Core::PRODUCT_TYPE;
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
		if ( $this->should_treat_as_unique( $id, $product ) ) {
			$this->force_product_type_now( $id );
		} elseif ( $this->request_is_explicitly_non_unique() ) {
			$this->clear_unique_product_type( $id );
		}
	}

	public function force_type_on_save_post( $post_id, $post, $update ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
		if ( 'product' !== $post->post_type ) return;
		if ( $this->should_treat_as_unique( $post_id ) ) {
			$this->force_product_type_now( $post_id );
		} elseif ( $this->request_is_explicitly_non_unique() ) {
			$this->clear_unique_product_type( $post_id );
		}
	}

	public function force_type_on_quick_edit( $product ) {
		if ( ! ( $product instanceof WC_Product ) ) return;
		if ( $this->should_treat_as_unique( $product->get_id(), $product ) ) {
			$this->force_product_type_now( $product->get_id() );
		} elseif ( $this->request_is_explicitly_non_unique() ) {
			$this->clear_unique_product_type( $product->get_id() );
		}
	}

	private function force_product_type_now( $product_id ) : void {
		if ( ! term_exists( FCSD_Core::PRODUCT_TYPE, 'product_type' ) ) {
			wp_insert_term( __( "Obra d’art única", 'fcsd-exposicio' ), 'product_type', [ 'slug' => FCSD_Core::PRODUCT_TYPE ] );
		}
		wp_set_object_terms( $product_id, FCSD_Core::PRODUCT_TYPE, 'product_type', false );
		update_post_meta( $product_id, '_product_type', FCSD_Core::PRODUCT_TYPE );
		update_post_meta( $product_id, '_virtual', 'yes' );
		if ( function_exists( 'wc_delete_product_transients' ) ) wc_delete_product_transients( $product_id );
		clean_post_cache( $product_id );
	}

	private function clear_unique_product_type( $product_id ) : void {
		wp_remove_object_terms( $product_id, FCSD_Core::PRODUCT_TYPE, 'product_type' );
		delete_post_meta( $product_id, '_product_type' );
	}

	private function should_treat_as_unique( $product_id = 0, $product = null ) : bool {
		$posted_type = $this->get_requested_product_type();
		if ( $posted_type && $posted_type !== FCSD_Core::PRODUCT_TYPE ) return false;
		if ( $posted_type === FCSD_Core::PRODUCT_TYPE ) return true;

		if ( isset( $_POST['_fcsd_force_unique'] ) && '1' === $_POST['_fcsd_force_unique'] ) {
			return ! $posted_type;
		}

		if ( isset( $_POST[ FCSD_Core::META_AUTOR ] ) && $_POST[ FCSD_Core::META_AUTOR ] !== '' ) return true;
		if ( isset( $_POST[ FCSD_Core::META_ANY ] )   && $_POST[ FCSD_Core::META_ANY ]   !== '' ) return true;
		if ( isset( $_POST[ FCSD_Core::META_MESURES ] ) && $_POST[ FCSD_Core::META_MESURES ] !== '' ) return true;

		if ( ! ( $product instanceof WC_Product ) && $product_id ) $product = wc_get_product( $product_id );

		if ( $product instanceof WC_Product ) {
			if ( $this->product_is_unique( $product ) ) return true;
		}

		if ( $product_id ) {
			if ( has_term( FCSD_Core::PRODUCT_TYPE, 'product_type', $product_id ) ) return true;
			if ( get_post_meta( $product_id, '_product_type', true ) === FCSD_Core::PRODUCT_TYPE ) return true;
		}

		return false;
	}

	private function request_is_explicitly_non_unique() : bool {
		$posted_type = $this->get_requested_product_type();
		return (bool) ( $posted_type && $posted_type !== FCSD_Core::PRODUCT_TYPE );
	}

	private function get_requested_product_type() : string {
		if ( isset( $_POST['product-type'] ) ) {
			return sanitize_text_field( wp_unslash( $_POST['product-type'] ) );
		}
		if ( isset( $_REQUEST['product_type'] ) ) {
			return sanitize_text_field( wp_unslash( $_REQUEST['product_type'] ) );
		}
		return '';
	}

	/**
	 * Solo añadimos show_if_{tipo} a elementos que YA usan show_if_*
	 * (precio, fechas, panel inventario...). No tocamos General si no tiene show_if_*.
	 */
	public function admin_js_show_price_for_obra_unica() {
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if ( ! $screen || $screen->id !== 'product' ) return;

		$is_obra = false;
		if ( isset( $_GET['post'] ) ) {
			$p = wc_get_product( (int) $_GET['post'] );
			$is_obra = $p && $p->get_type() === FCSD_Core::PRODUCT_TYPE;
		}
		?>
		<script>
		jQuery(function($){
			var uniqueSlug  = '<?php echo esc_js( FCSD_Core::PRODUCT_TYPE ); ?>';
			var uniqueClass = 'show_if_' + uniqueSlug;

			function hasShowIf($el){ return /\bshow_if_/.test(($el.attr('class')||'')); }

			function ensureUniqueSupport() {
				var $wrap            = $('#woocommerce-product-data');

				// GENERAL – grupos/campos de precio (en core usan show_if_*: simple, external, etc.)
				var $pricingGroup    = $wrap.find('.options_group.pricing');
				var $priceFields     = $wrap.find('._regular_price_field, ._sale_price_field');
				var $saleDates       = $wrap.find('._sale_price_dates_fields, .sale_price_dates_fields');

				// Pestaña/panel General: solo si ya tienen show_if_ (por terceros)
				var $generalPanel    = $('#general_product_data');
				var $generalTab      = $wrap.find('ul.wc-tabs li.general_options, ul.wc-tabs li.general_tab');

				// INVENTARIO – panel y tab (core ya traen show_if_*)
				var $inventoryPanel  = $('#inventory_product_data');
				var $inventoryTab    = $wrap.find('ul.wc-tabs li.inventory_options, ul.wc-tabs li.inventory_tab');

				[
					$pricingGroup, $priceFields, $saleDates,
					$generalPanel, $generalTab,
					$inventoryPanel, $inventoryTab
				].forEach(function($el){
					$el.each(function(){
						var $t = $(this);
						if ($t.length && hasShowIf($t) && !$t.hasClass(uniqueClass)) {
							$t.addClass(uniqueClass);
						}
					});
				});
			}

			function refreshProductType() {
				var currentType = $('#product-type').val();
				$(document.body).trigger('woocommerce_product_type_changed', [ currentType ] );
			}

			$(document.body).on('woocommerce_init', ensureUniqueSupport);
			$(document.body).on('woocommerce_product_type_changed', ensureUniqueSupport);

			ensureUniqueSupport();
			refreshProductType();

			<?php if ( $is_obra ) : ?>
			var $sel = $('#product-type');
			if ( $sel.length ) {
				if ( $sel.val() !== uniqueSlug ) {
					$sel.val( uniqueSlug ).trigger('change');
				} else {
					ensureUniqueSupport();
					$(document.body).trigger('woocommerce_init');
					$(document.body).trigger('woocommerce_product_type_changed', [ uniqueSlug ] );
				}
			}
			<?php endif; ?>
		});
		</script>
		<?php
	}

	/* ======= Helpers checkout/pagos ======= */

	private function is_order_pay_context() : bool {
		if ( function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() ) return true;
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) return true;
		return false;
	}

	public function maybe_force_default_gateway( $default ) {
		if ( $this->is_order_pay_context() ) return $default;

		$forced = WC()->session ? WC()->session->get( FCSD_Core::SESSION_GW ) : '';
		if ( $forced ) return $forced;

		$pref = sanitize_text_field( get_option( FCSD_Core::OPT_GW_DEFAULT, '' ) );
		return $pref ?: $default;
	}

	public function maybe_limit_gateways( $gateways ) {
		return $gateways;
	}

	public function enforce_single_item_cart_for_unique( $passed, $product_id, $quantity, $variation_id = 0, $variations = [], $cart_item_data = [] ) {
		$p = wc_get_product( $product_id );
		if ( $p && $p->get_type() === FCSD_Core::PRODUCT_TYPE ) {
			if ( WC()->cart && ! WC()->cart->is_empty() ) WC()->cart->empty_cart();
		}
		return $passed;
	}

	public function maybe_preemptively_flush_cart() {
		$id = isset($_GET['add-to-cart']) ? absint($_GET['add-to-cart']) : 0;
		if ( ! $id ) return;
		$p = wc_get_product( $id );
		if ( $p && $p->get_type() === FCSD_Core::PRODUCT_TYPE ) {
			if ( WC()->cart && ! WC()->cart->is_empty() ) WC()->cart->empty_cart();
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
				if ( (int) $it['quantity'] !== 1 ) WC()->cart->set_quantity( $k, 1, true );
			}
		}
	}

	public function ensure_unique_product_price_in_cart( WC_Cart $cart ) {
		if ( is_admin() && ! defined('DOING_AJAX') ) return;
		foreach ( $cart->get_cart() as $cart_item ) {
			$p = $cart_item['data'] ?? null;
			if ( ! ( $p instanceof WC_Product ) ) continue;
			if ( $p->get_type() !== FCSD_Core::PRODUCT_TYPE ) continue;
			$price = (float) $p->get_price();
			if ( $price > 0 ) continue;
			$sale = $p->get_sale_price();
			$reg  = $p->get_regular_price();
			$fallback = $sale !== '' ? (float) $sale : ( $reg !== '' ? (float) $reg : 0 );
			if ( $fallback > 0 ) $p->set_price( $fallback );
		}
	}

	public function add_extra_recipient_if_obra_unica( $recipient, $order ) {
		if ( ! $order instanceof WC_Order ) return (string) $recipient;

		if ( ! $this->order_has_unique_item( $order ) ) return (string) $recipient;

		$opt = (string) get_option( FCSD_Core::OPT_EXTRA_EMAIL, '' );
		$extra_list = array_filter( array_map( 'sanitize_email', array_map( 'trim', explode( ',', $opt ) ) ) );

		if ( empty( $extra_list ) ) return (string) $recipient;

		$base_list = array_filter( array_map( 'sanitize_email', array_map( 'trim', explode( ',', (string) $recipient ) ) ) );
		$emails = array_unique( array_filter( array_merge( $base_list, $extra_list ) ) );

		return implode( ',', $emails );
	}

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
		register_setting( 'fcsd_expo_group', FCSD_Core::OPT_EXTRA_EMAIL, [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ] );
		register_setting( 'fcsd_expo_group', FCSD_Core::OPT_GW_DEFAULT, [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ] );
		register_setting( 'fcsd_expo_group', FCSD_Core::OPT_GW_BIZUM, [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ] );
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
							<input type="text" class="regular-text" name="<?php echo esc_attr(FCSD_Core::OPT_EXTRA_EMAIL); ?>" id="<?php echo esc_attr(FCSD_Core::OPT_EXTRA_EMAIL); ?>" value="<?php echo esc_attr( get_option(FCSD_Core::OPT_EXTRA_EMAIL, '') ); ?>" placeholder="artsales@exemple.com" />
							<p class="description"><?php esc_html_e( 'Aquest(s) email(s) també rebran els avisos de compra de les obres úniques. Pots posar-ne diversos separats per comes.', 'fcsd-exposicio' ); ?></p>
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

    private function is_unique_checkout_context( bool $strict = true ) : bool {
	    if ( ! function_exists( 'WC' ) ) return false;

	    $has_unique = false;

	    if ( WC()->cart && is_callable( [ WC()->cart, 'get_cart' ] ) ) {
		    $items = WC()->cart->get_cart();
		    if ( $items ) {
			    foreach ( $items as $it ) {
				    $p = $it['data'] ?? null;
				    if ( ! ( $p instanceof WC_Product ) ) { $has_unique = false; break; }
				    if ( $p->get_type() !== FCSD_Core::PRODUCT_TYPE ) { $has_unique = false; break; }
				    if ( (int) ( $it['quantity'] ?? 1 ) !== 1 ) { $has_unique = false; break; }
				    $has_unique = true;
			    }
		    }
	    }

	    if ( ! $has_unique ) {
		    $request_id = isset( $_REQUEST['add-to-cart'] ) ? absint( wp_unslash( $_REQUEST['add-to-cart'] ) ) : 0;
		    if ( ! $request_id && isset( $_REQUEST['product_id'] ) ) {
			    $request_id = absint( wp_unslash( $_REQUEST['product_id'] ) );
		    }
		    if ( $request_id ) {
			    $p = wc_get_product( $request_id );
			    if ( $p instanceof WC_Product && $p->get_type() === FCSD_Core::PRODUCT_TYPE ) {
				    $has_unique = true;
			    }
		    }
	    }

	    if ( ! $has_unique && WC()->session && is_callable( [ WC()->session, 'get' ] ) ) {
		    $session_cart = WC()->session->get( 'cart' );
		    if ( is_array( $session_cart ) ) {
			    foreach ( $session_cart as $values ) {
				    $pid = isset( $values['product_id'] ) ? (int) $values['product_id'] : 0;
				    $qty = isset( $values['quantity'] ) ? (int) $values['quantity'] : 0;
				    if ( ! $pid ) continue;
				    $p = wc_get_product( $pid );
				    if ( ! ( $p instanceof WC_Product ) ) continue;
				    if ( $p->get_type() !== FCSD_Core::PRODUCT_TYPE ) { $has_unique = false; break; }
				    if ( $qty && $qty !== 1 ) { $has_unique = false; break; }
				    $has_unique = true;
			    }
		    }
	    }

	    if ( ! $has_unique ) return false;

	    if ( ! $strict ) return true;

	    $at_checkout = false;
	    if ( function_exists( 'is_checkout' ) ) {
			if ( is_checkout() ) $at_checkout = true;
	    }
	    if ( !$at_checkout && isset( $_GET['add-to-cart'] ) && ! empty( $_GET['fcsd_gw'] ) ) {
		    $at_checkout = true;
	    }

	    return $at_checkout;
    }

	/* ======= CHECKOUT SIMPLE (Nombre, Apellidos, Email) ======= */

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
					if ( ! in_array( $key, $keep, true ) ) unset( $fields[ $section ][ $key ] );
				}
			}
		}
		unset( $fields['order']['order_comments'], $fields['account'] );

		if ( isset( $fields['billing']['billing_email'] ) ) $fields['billing']['billing_email']['required'] = true;
		if ( isset( $fields['billing']['billing_first_name'] ) ) $fields['billing']['billing_first_name']['required'] = false;
		if ( isset( $fields['billing']['billing_last_name'] ) )  $fields['billing']['billing_last_name']['required'] = false;

		foreach ( ['billing_email','billing_first_name','billing_last_name'] as $k ) {
			if ( isset( $fields['billing'][ $k ] ) ) {
				$fields['billing'][ $k ]['class'] = [ 'form-row-wide' ];
			}
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

		$base = wc_get_base_location();
		$country = ( is_array($base) && ! empty($base['country']) ) ? $base['country'] : 'ES';
		$state   = ( is_array($base) && ! empty($base['state']) )   ? $base['state']   : '';

		$defaults = [
			'billing_country'   => $country,
			'billing_state'     => $state,
			'billing_address_1' => 'N/A',
			'billing_city'      => 'Local',
			'billing_postcode'  => '00000',
		];

		foreach ( $defaults as $k => $v ) {
			if ( empty( $data[ $k ] ) ) $data[ $k ] = $v;
		}

		if ( empty( $data['billing_first_name'] ) ) $data['billing_first_name'] = 'Visitant';
		if ( empty( $data['billing_last_name'] ) )  $data['billing_last_name']  = 'Expo';

		return $data;
	}

    public function unique_force_guest_checkout( $pre ) {
		return $this->is_unique_checkout_context( false ) ? 'yes' : $pre;
    }

	public function disable_hold_stock_for_unique_checkout_only( $pre ) {
		if ( is_admin() || ! WC()->cart ) return $pre;
		foreach ( WC()->cart->get_cart() as $it ) {
			$p = $it['data'] ?? null;
			if ( $p instanceof WC_Product && $p->get_type() === FCSD_Core::PRODUCT_TYPE ) return '0';
		}
		return $pre;
	}

	public function unique_disable_order_notes( $enabled ) {
		return $this->is_unique_checkout_context() ? false : $enabled;
	}

	public function unique_hide_additional_heading_css() {
		if ( ! function_exists('is_checkout') || ! is_checkout() ) return;
		if ( ! $this->is_unique_checkout_context() ) return;
		?>
		<style>
			.woocommerce-checkout .woocommerce-additional-fields > h3{display:none!important}
			#customer_details .col-2 h3{display:none!important}
			.woocommerce-checkout .woocommerce-additional-fields:empty{display:none!important}
		</style>
		<?php
	}

	public function sync_unique_stock_after_reduction( $order ) {
		if ( ! $order instanceof WC_Order ) return;
		$this->sync_unique_items_of_order( $order );
	}

	public function sync_unique_stock_on_paid( $order_id ) {
		$order = is_numeric( $order_id ) ? wc_get_order( $order_id ) : ( $order_id instanceof WC_Order ? $order_id : null );
		if ( ! $order ) return;
		$this->sync_unique_items_of_order( $order );
	}

    private function sync_unique_items_of_order( WC_Order $order ) {
	    foreach ( $order->get_items() as $item ) {
		    $p = $item->get_product();
		    if ( ! $p instanceof WC_Product ) continue;
		    if ( $p->get_type() !== FCSD_Core::PRODUCT_TYPE ) continue;

		    $changed = false;

		    if ( (int) $p->get_stock_quantity() !== 0 ) {
			    $p->set_stock_quantity( 0 );
			    $changed = true;
		    }

		    if ( $p->get_stock_status() !== 'outofstock' ) {
			    $p->set_stock_status( 'outofstock' );
			    $changed = true;
		    }

		    if ( $changed ) {
			    $p->save();
			    if ( function_exists( 'wc_delete_product_transients' ) ) wc_delete_product_transients( $p->get_id() );
			    clean_post_cache( $p->get_id() );
		    }
	    }
    }

	/* ======= Email: forzar envío de "Nueva comanda" si es obra única ======= */

	public function enable_new_order_email_for_unique( $enabled, $order ) {
		if ( $order instanceof WC_Order && $this->order_has_unique_item( $order ) ) {
			return true;
		}
		return $enabled;
	}

	public function ensure_new_order_email_sent( $maybe_order ) {
		$order = $maybe_order instanceof WC_Order ? $maybe_order : ( $maybe_order ? wc_get_order( $maybe_order ) : null );
		if ( ! $order ) return;

		if ( 'yes' === $order->get_meta( '_fcsd_new_order_email_sent', true ) ) return;
		if ( ! $this->order_has_unique_item( $order ) ) return;

		$order->update_meta_data( '_fcsd_new_order_email_sent', 'yes' );
		$order->save();

		if ( function_exists( 'WC' ) && WC()->mailer() ) {
			$emails = WC()->mailer()->get_emails();
			if ( is_array( $emails ) ) {
				if ( isset( $emails['WC_Email_New_Order'] ) && $emails['WC_Email_New_Order'] instanceof WC_Email_New_Order ) {
					$emails['WC_Email_New_Order']->trigger( $order->get_id(), $order );
				} else {
					foreach ( $emails as $email ) {
						if ( $email instanceof WC_Email_New_Order ) {
							$email->trigger( $order->get_id(), $order );
							break;
						}
					}
				}
			}
		}

		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->info( 'FCSD: New Order email triggered for unique order ID ' . $order->get_id(), [ 'source' => 'fcsd-commerce' ] );
		}
	}

	public function ensure_new_order_email_on_status( $order_id, $from, $to, $order ) {
		$interesting = array( 'processing', 'on-hold', 'completed', 'pending' );
		if ( in_array( $to, $interesting, true ) ) {
			$this->ensure_new_order_email_sent( $order );
		}
	}

	private function order_has_unique_item( WC_Order $order ) : bool {
		if ( 'yes' === $order->get_meta( '_fcsd_has_unique', true ) ) {
			return true;
		}

		$found = false;

		foreach ( $order->get_items() as $it ) {
			if ( ! ( $it instanceof WC_Order_Item_Product ) ) continue;

			if ( $this->order_item_is_unique( $it ) ) {
				$found = true;
				break;
			}
		}

		if ( $found ) {
			$order->update_meta_data( '_fcsd_has_unique', 'yes' );
			$order->save();
			return true;
		}

		return false;
	}

	private function order_item_is_unique( WC_Order_Item_Product $item ) : bool {
		$product = $item->get_product();

		if ( $product instanceof WC_Product && $this->product_is_unique( $product ) ) {
			return true;
		}

		$product_id = $product instanceof WC_Product ? $product->get_id() : $item->get_product_id();
		if ( $product_id ) {
			if ( get_post_meta( $product_id, '_product_type', true ) === FCSD_Core::PRODUCT_TYPE ) {
				return true;
			}

			if (
				get_post_meta( $product_id, FCSD_Core::META_AUTOR, true )
				|| get_post_meta( $product_id, FCSD_Core::META_ANY, true )
				|| get_post_meta( $product_id, FCSD_Core::META_MESURES, true )
			) {
				return true;
			}
		}

		$item_type = $item->get_meta( '_product_type', true );
		if ( FCSD_Core::PRODUCT_TYPE === $item_type ) {
			return true;
		}

		return false;
	}

	private function product_is_unique( WC_Product $product ) : bool {
		if ( method_exists( $product, 'get_type' ) && FCSD_Core::PRODUCT_TYPE === $product->get_type() ) {
			return true;
		}

		if (
			get_post_meta( $product->get_id(), '_product_type', true ) === FCSD_Core::PRODUCT_TYPE
			|| get_post_meta( $product->get_id(), FCSD_Core::META_AUTOR, true )
			|| get_post_meta( $product->get_id(), FCSD_Core::META_ANY, true )
			|| get_post_meta( $product->get_id(), FCSD_Core::META_MESURES, true )
		) {
			return true;
		}

		return false;
	}
}

endif;

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
