<?php
/**
 * Layout helpers and hooks for the UpScale test storefront.
 *
 * Everything here reads the local store only — no remote calls, no copied copy.
 *
 * @package upscale-storefront
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Top-level product categories for the homepage tiles and the footer.
 *
 * @param int $limit How many to return (0 = all).
 * @return WP_Term[]
 */
function upscale_storefront_top_categories( $limit = 8 ) {
	$terms = get_terms(
		array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
			'parent'     => 0,
			'orderby'    => 'name',
			'order'      => 'ASC',
		)
	);

	if ( is_wp_error( $terms ) ) {
		return array();
	}

	// WooCommerce always ships an empty default term ("Uncategorized") next to
	// the catalogue's own wrapper root. Drop it before deciding what the
	// navigable branches are.
	$terms = array_values(
		array_filter(
			$terms,
			static function ( $term ) {
				return 'uncategorized' !== $term->slug;
			}
		)
	);

	// The mirrored catalog has a single wrapper root ("Каталог продукции"); the
	// branches a shopper navigates by are its children, so use those instead.
	if ( 1 === count( $terms ) ) {
		$children = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'parent'     => (int) $terms[0]->term_id,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		if ( ! is_wp_error( $children ) && $children ) {
			$terms = $children;
		}
	}

	// Put the branches that actually hold products first.
	usort(
		$terms,
		static function ( $a, $b ) {
			if ( (int) $b->count === (int) $a->count ) {
				return strcasecmp( $a->name, $b->name );
			}
			return (int) $b->count <=> (int) $a->count;
		}
	);

	return $limit > 0 ? array_slice( $terms, 0, $limit ) : $terms;
}

/**
 * Child category names of a category, trimmed for a tile.
 *
 * @param WP_Term $term   Parent term.
 * @param int     $limit  How many child names to show.
 * @return string Comma separated names.
 */
function upscale_storefront_child_names( $term, $limit = 4 ) {
	$children = get_terms(
		array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
			'parent'     => (int) $term->term_id,
			'orderby'    => 'name',
			'order'      => 'ASC',
		)
	);

	if ( is_wp_error( $children ) || empty( $children ) ) {
		return '';
	}

	$names = wp_list_pluck( array_slice( $children, 0, $limit ), 'name' );

	if ( count( $children ) > $limit ) {
		$names[] = '…';
	}

	return implode( ', ', $names );
}

/**
 * Brand names for the homepage brand strip, most used first.
 *
 * @param int $limit How many names.
 * @return string[] Brand names.
 */
function upscale_storefront_brands( $limit = 12 ) {
	$taxonomy = upscale_storefront_brand_taxonomy();
	if ( '' === $taxonomy ) {
		return array();
	}

	$terms = get_terms(
		array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'orderby'    => 'count',
			'order'      => 'DESC',
			'number'     => $limit,
		)
	);

	if ( is_wp_error( $terms ) || empty( $terms ) ) {
		return array();
	}

	return wp_list_pluck( $terms, 'name' );
}

/**
 * Demo testimonials. Clearly fictional, written for this project.
 *
 * @return array[] Each item: text, author.
 */
function upscale_storefront_testimonials() {
	return array(
		array(
			'text'   => 'Замовляли кресла для нового барбершопу: допомогли підібрати комплект під бюджет, доставили без пошкоджень.',
			'author' => 'Олег, власник барбершопу (демо-відгук)',
		),
		array(
			'text'   => 'Потрібна була заміна гідравліки на парикмахерському кріслі — знайшли потрібну запчастину за артикулом.',
			'author' => 'Ірина, салон краси (демо-відгук)',
		),
		array(
			'text'   => 'Перевіряли ціни в кількох магазинах: тут була зрозуміла сторінка з характеристиками й гарантією 12 місяців.',
			'author' => 'Дмитро, студія манікюру (демо-відгук)',
		),
	);
}

/**
 * The three trust points shown on the homepage and in the footer.
 *
 * @return array[] Each item: title, text.
 */
function upscale_storefront_trust_points() {
	return array(
		array(
			'title' => __( 'Офіційна гарантія 12 місяців', 'upscale-storefront' ),
			'text'  => __( 'Обслуговування в авторизованому сервісному центрі.', 'upscale-storefront' ),
		),
		array(
			'title' => __( 'Оригінальна продукція', 'upscale-storefront' ),
			'text'  => __( 'Характеристики відповідають заявленим у картці товару.', 'upscale-storefront' ),
		),
		array(
			'title' => __( 'Зручні умови покупки', 'upscale-storefront' ),
			'text'  => __( 'Оплата карткою або післяплатою, доставка по Україні.', 'upscale-storefront' ),
		),
	);
}

/**
 * Attachment id of the photo assigned to a product category.
 *
 * The seeder stores `upscale_category_images` (slug → attachment id) for the
 * categories that have a photograph of their own; a leaf without one inherits
 * the nearest ancestor's photo, so every card still shows something real.
 *
 * @param WP_Term $term Category term.
 * @return int Attachment id (0 when nothing is mapped).
 */
function upscale_storefront_category_image_id( $term ) {
	static $map = null;

	if ( null === $map ) {
		$stored = get_option( 'upscale_category_images', array() );
		$map    = is_array( $stored ) ? $stored : array();
	}

	if ( ! $term instanceof WP_Term || empty( $map ) ) {
		return 0;
	}

	if ( isset( $map[ $term->slug ] ) ) {
		return (int) $map[ $term->slug ];
	}

	foreach ( get_ancestors( $term->term_id, 'product_cat', 'taxonomy' ) as $ancestor_id ) {
		$ancestor = get_term( $ancestor_id, 'product_cat' );
		if ( $ancestor instanceof WP_Term && isset( $map[ $ancestor->slug ] ) ) {
			return (int) $map[ $ancestor->slug ];
		}
	}

	return 0;
}

/**
 * URL of the hero banner photograph (empty when none was imported).
 *
 * @return string
 */
function upscale_storefront_hero_image_url() {
	$attachment_id = (int) get_option( 'upscale_hero_image', 0 );
	if ( ! $attachment_id ) {
		return '';
	}

	$url = wp_get_attachment_image_url( $attachment_id, 'full' );

	return $url ? $url : '';
}

/**
 * Replace Storefront's credit line with the test-store disclaimer.
 *
 * @param string $text Default credit text.
 * @return string
 */
function upscale_storefront_credit_text( $text ) {
	return sprintf(
		/* translators: %s: site name. */
		esc_html__( '© %s — демонстраційний магазин для тестування імпорту товарів.', 'upscale-storefront' ),
		esc_html( get_bloginfo( 'name' ) )
	);
}
add_filter( 'storefront_credit_text', 'upscale_storefront_credit_text', 20 );
