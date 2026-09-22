<?php
/**
 * Shared demo-data helpers for the test-store seeders.
 *
 * Used by `scripts/seed-catalog.php` (products, pages, menu, options) and
 * `scripts/seed-photos.php` (photographs, category images, attribution page) so
 * both agree on one thing: which *kind of product* a category represents.
 *
 * Data comes from the generated files in `data/` — nothing is hardcoded twice:
 *   data/catalog.json      the mirrored category tree
 *   data/photo-map.json    category slug → keyword
 *   data/photo-sources.json Commons photographs + their licences
 *
 * @package upscale-storefront
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Decode a JSON file, returning an empty array when it is missing or invalid.
 *
 * @param string $path Absolute path.
 * @return array
 */
function upscale_demo_read_json($path) {
	if (!$path || !is_readable($path)) {
		return array();
	}

	$decoded = json_decode(file_get_contents($path), true);

	return is_array($decoded) ? $decoded : array();
}

/**
 * @return array The mirrored catalogue (empty when not generated yet).
 */
function upscale_demo_catalog() {
	static $catalog = null;
	if (null === $catalog) {
		$path    = getenv('UPSCALE_CATALOG_FILE') ?: '/work/data/catalog.json';
		$catalog = upscale_demo_read_json($path);
	}
	return $catalog;
}

/**
 * @return array The photo map: category_keywords + branch_defaults.
 */
function upscale_demo_photo_map() {
	static $map = null;
	if (null === $map) {
		$path = getenv('UPSCALE_PHOTOMAP_FILE') ?: '/work/data/photo-map.json';
		$map  = upscale_demo_read_json($path);
	}
	return $map;
}

/**
 * @return array The generated photo record (keywords + heroes + licences).
 */
function upscale_demo_photo_sources() {
	static $sources = null;
	if (null === $sources) {
		$path    = getenv('UPSCALE_PHOTOS_FILE') ?: '/work/data/photo-sources.json';
		$sources = upscale_demo_read_json($path);
	}
	return $sources;
}

/**
 * Ukrainian product labels per photo keyword.
 *
 * The label and the photograph describe the same kind of object, which is what
 * makes a demo card look plausible instead of mismatched.
 *
 * @return array keyword → label.
 */
function upscale_demo_labels() {
	static $labels = null;

	if (null === $labels) {
		$labels = array(
			'hair_clipper'     => 'Машинка для стрижки',
			'trimmer'          => 'Тример',
			'shaver'           => 'Шейвер (електробритва)',
			'hair_dryer'       => 'Фен для волосся',
			'hair_dryer_stand' => 'Стаціонарний фен',
			'hair_styler'      => 'Стайлер для укладки',
			'straightener'     => 'Випрямляч для волосся',
			'curling_iron'     => 'Плойка для волосся',
			'curlers'          => 'Бигуди',
			'comb'             => 'Гребінець і брашинг',
			'scissors'         => 'Професійні ножиці',
			'barber_chair'     => 'Крісло для барбершопу',
			'salon_chair'      => 'Парикмахерське крісло',
			'wash_unit'        => 'Мийка парикмахерська',
			'mirror'           => 'Дзеркало з підсвіткою',
			'massage_table'    => 'Масажний стіл',
			'cosmetic_couch'   => 'Кушетка косметологічна',
			'salon_table'      => 'Стіл для робочого місця',
			'salon_trolley'    => 'Візок для інструментів',
			'salon_cabinet'    => 'Тумба в салон',
			'salon_sofa'       => 'Диван у зону очікування',
			'salon_stool'      => 'Стілець майстра',
			'reception'        => 'Ресепшн',
			'display_case'     => 'Вітрина для салону',
			'manicure_lamp'    => 'Лампа для манікюру',
			'nail_drill'       => 'Фрезер для манікюру',
			'sterilizer'       => 'Стерилізатор для інструментів',
			'extractor'        => 'Витяжка для манікюру',
			'wax'              => 'Віск для епіляції',
			'wax_heater'       => 'Воскоплав',
			'sugaring'         => 'Паста для шугарингу',
			'bath_accessory'   => 'Аксесуар для душу',
			'care_product'     => 'Засіб для догляду',
			'gift'             => 'Подарунковий набір',
			'spare_part'       => 'Запчастина',
			'accessory'        => 'Аксесуар для салону',
		);
	}

	return $labels;
}

/**
 * The keyword for a product category, walking up the hierarchy when the leaf has
 * none of its own (so every category answers with *something* meaningful).
 *
 * @param WP_Term|int $term Term or term id.
 * @return string Keyword, or an empty string.
 */
function upscale_demo_keyword_for_term($term) {
	static $map = null;

	if (null === $map) {
		$data = upscale_demo_photo_map();
		$map  = isset($data['category_keywords']) && is_array($data['category_keywords'])
			? $data['category_keywords']
			: array();
	}

	if (!$term instanceof WP_Term) {
		$term = get_term((int) $term, 'product_cat');
	}
	if (!$term instanceof WP_Term) {
		return '';
	}

	if (isset($map[$term->slug])) {
		return (string) $map[$term->slug];
	}

	foreach (get_ancestors($term->term_id, 'product_cat', 'taxonomy') as $ancestor_id) {
		$ancestor = get_term($ancestor_id, 'product_cat');
		if ($ancestor instanceof WP_Term && isset($map[$ancestor->slug])) {
			return (string) $map[$ancestor->slug];
		}
	}

	return '';
}

/**
 * Deterministic pseudo-random integer (stable across runs and containers).
 *
 * @param string $seed Seed material.
 * @param int    $min  Inclusive minimum.
 * @param int    $max  Inclusive maximum.
 * @return int
 */
function upscale_demo_number($seed, $min, $max) {
	$hash = crc32('upscale-demo-' . $seed);

	return (int) ($min + ($hash % max(1, $max - $min + 1)));
}

/**
 * Ukrainian label for a keyword, with a safe fallback.
 *
 * @param string $keyword Photo keyword.
 * @return string
 */
function upscale_demo_label_for_keyword($keyword) {
	$labels = upscale_demo_labels();

	return isset($labels[$keyword]) ? $labels[$keyword] : 'Товар для салону';
}
