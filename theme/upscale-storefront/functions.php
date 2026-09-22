<?php
/**
 * UpScale Test Storefront — child theme of Storefront.
 *
 * Layout parity with a professional beauty/barber supply storefront, built from
 * this project's own copy and demo data. The theme never fetches anything from
 * another site: categories, products and images are all local (see
 * scripts/seed-catalog.php and assets/placeholders).
 *
 * @package upscale-storefront
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'UPSCALE_STOREFRONT_VERSION', '1.0.0' );

/**
 * The demo/top bar text. Kept in one place so it is obvious this is a test store.
 */
function upscale_storefront_demo_note() {
	return __( 'Демонстраційний тестовий магазин UpScale — товари, ціни та відгуки вигадані й призначені лише для перевірки імпорту.', 'upscale-storefront' );
}

/**
 * Contact details shown in the utility bar. Deliberately fake (000) numbers.
 */
function upscale_storefront_contact() {
	return array(
		'phone' => '+38 (000) 000 00 00',
		'email' => 'test@example.com',
	);
}

if ( ! function_exists( 'upscale_storefront_setup' ) ) {
	/**
	 * Child theme setup: text domain, logo support, extra menus, grid parity.
	 */
	function upscale_storefront_setup() {
		load_child_theme_textdomain( 'upscale-storefront', get_stylesheet_directory() . '/languages' );

		add_theme_support(
			'custom-logo',
			array(
				'height'      => 64,
				'width'       => 260,
				'flex-height' => true,
				'flex-width'  => true,
			)
		);

		register_nav_menus(
			array(
				'ups_footer_help' => __( 'Footer — Допомога', 'upscale-storefront' ),
				'ups_footer_info' => __( 'Footer — Інформація', 'upscale-storefront' ),
			)
		);

		// Same grid as the reference layout: four columns, twelve products.
		add_filter( 'loop_shop_columns', 'upscale_storefront_loop_columns', 20 );
		add_filter( 'loop_shop_per_page', 'upscale_storefront_loop_per_page', 20 );
	}
}
add_action( 'after_setup_theme', 'upscale_storefront_setup', 20 );

/**
 * @return int Product columns on shop/archive pages.
 */
function upscale_storefront_loop_columns() {
	return 4;
}

/**
 * @return int Products per page on shop/archive pages.
 */
function upscale_storefront_loop_per_page() {
	return 12;
}

if ( ! function_exists( 'upscale_storefront_scripts' ) ) {
	/**
	 * Parent stylesheet first, then the child overrides.
	 */
	function upscale_storefront_scripts() {
		wp_enqueue_style(
			'upscale-storefront',
			get_stylesheet_uri(),
			array( 'storefront-style' ),
			UPSCALE_STOREFRONT_VERSION
		);
	}
}
add_action( 'wp_enqueue_scripts', 'upscale_storefront_scripts', 20 );

/**
 * The reference store prints prices as "20 881 грн", not with the ₴ glyph.
 *
 * @param string $symbol   Symbol WooCommerce would use.
 * @param string $currency Currency code.
 * @return string
 */
function upscale_storefront_currency_symbol( $symbol, $currency ) {
	if ( 'UAH' === $currency ) {
		return 'грн';
	}
	return $symbol;
}
add_filter( 'woocommerce_currency_symbol', 'upscale_storefront_currency_symbol', 10, 2 );

/**
 * The store logo: our own SVG mark (the fixture in assets/logo.svg).
 *
 * A real `custom_logo` wins when one is set, otherwise the bundled SVG is used.
 */
function upscale_storefront_logo() {
	if ( function_exists( 'has_custom_logo' ) && has_custom_logo() ) {
		the_custom_logo();
		return;
	}

	$file = get_stylesheet_directory() . '/assets/logo.svg';
	if ( ! file_exists( $file ) ) {
		printf( '<a class="ups-logo ups-logo--text" href="%s">%s</a>', esc_url( home_url( '/' ) ), esc_html( get_bloginfo( 'name' ) ) );
		return;
	}

	printf(
		'<a class="ups-logo" href="%s" rel="home"><img src="%s" alt="%s" width="260" height="64" loading="eager"></a>',
		esc_url( home_url( '/' ) ),
		esc_url( get_stylesheet_directory_uri() . '/assets/logo.svg' ),
		esc_attr( get_bloginfo( 'name' ) )
	);
}

/**
 * Ukrainian storefront labels.
 *
 * Skipped entirely once a real Ukrainian translation is installed (`WPLANG=uk`),
 * so this is only a safety net for a store still running on the English locale.
 *
 * @param string $translated Translated text.
 * @param string $original   Source text.
 * @param string $domain     Text domain.
 * @return string
 */
function upscale_storefront_ua_strings( $translated, $original, $domain ) {
	if ( 0 === strpos( (string) get_locale(), 'uk' ) ) {
		return $translated;
	}

	static $map = array(
		'Add to cart'          => 'До кошика',
		'Add to Cart'          => 'До кошика',
		'View cart'            => 'Кошик',
		'Cart'                 => 'Кошик',
		'Checkout'             => 'Оформлення',
		'My account'           => 'Мій кабінет',
		'Shop'                 => 'Каталог',
		'Search for:'          => 'Пошук товарів',
		'Search'               => 'Пошук',
		'Home'                 => 'Головна',
		'Related products'     => 'Схожі товари',
		'Description'          => 'Опис',
		'Additional information' => 'Характеристики',
		'Reviews'              => 'Відгуки',
		'Out of stock'         => 'Немає в наявності',
		'Select options'       => 'Обрати опції',
	);

	return isset( $map[ $original ] ) ? $map[ $original ] : $translated;
}
add_filter( 'gettext', 'upscale_storefront_ua_strings', 20, 3 );

/**
 * Resolve the "Бренд" attribute taxonomy whatever slug the store uses.
 *
 * @return string Taxonomy name, e.g. `pa_brend`; empty when not registered.
 */
function upscale_storefront_brand_taxonomy() {
	static $taxonomy = null;

	if ( null !== $taxonomy ) {
		return $taxonomy;
	}

	$taxonomy = '';
	if ( function_exists( 'wc_get_attribute_taxonomies' ) && function_exists( 'wc_attribute_taxonomy_name' ) ) {
		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			if ( in_array( $attribute->attribute_label, array( 'Бренд', 'Производители' ), true ) ) {
				$taxonomy = wc_attribute_taxonomy_name( $attribute->attribute_name );
				break;
			}
		}
	}

	return $taxonomy;
}

/**
 * Print the brand under the product title in the loop, like the reference layout.
 */
function upscale_storefront_loop_brand() {
	global $product;

	if ( ! $product instanceof WC_Product ) {
		return;
	}

	$taxonomy = upscale_storefront_brand_taxonomy();
	if ( '' === $taxonomy ) {
		return;
	}

	$terms = wc_get_product_terms( $product->get_id(), $taxonomy, array( 'fields' => 'names' ) );
	if ( empty( $terms ) || is_wp_error( $terms ) ) {
		return;
	}

	printf(
		'<span class="woocommerce-loop-product__brand">%s</span>',
		esc_html( $terms[0] )
	);
}
add_action( 'woocommerce_after_shop_loop_item_title', 'upscale_storefront_loop_brand', 5 );

require_once get_stylesheet_directory() . '/inc/layout.php';
