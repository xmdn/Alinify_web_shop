<?php
/**
 * UpScale test store — catalog seeder. Runs INSIDE the WordPress container:
 *
 *     docker compose cp scripts/seed-catalog.php cli:/tmp/seed-catalog.php
 *     docker compose exec -T cli wp eval-file /tmp/seed-catalog.php
 *
 * Uses the category tree that `scripts/setup-site.php` already created (both read
 * the same `data/catalog.json`) and adds:
 *
 *   1. demo products (own names, deterministic demo prices, placeholder images);
 *   2. demo brands + the storefront's primary navigation menu;
 *   3. the informational pages the footer links to;
 *   4. WooCommerce settings that match the reference storefront (UAH, 4 columns,
 *      reviews and ratings on).
 *
 * Everything it writes is generated demo data: no product names, descriptions or
 * photographs are taken from another site. Idempotent — running it twice does
 * not duplicate products, pages or menu items.
 */

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run this with: wp eval-file\n");
    exit(1);
}

if (!class_exists('WC_Product_Simple')) {
    fwrite(STDERR, "WooCommerce is not active — run scripts/setup.ps1 first.\n");
    exit(2);
}

// Shared demo helpers: Ukrainian labels and category → photo keyword resolution.
require_once '/work/scripts/inc/demo-data.php';

$catalog_file = getenv('UPSCALE_CATALOG_FILE') ?: '/work/data/catalog.json';
if (!is_readable($catalog_file)) {
    fwrite(STDERR, "Catalog file not readable: {$catalog_file}\n");
    exit(3);
}

$catalog = json_decode(file_get_contents($catalog_file), true);
$entries = isset($catalog['categories']) && is_array($catalog['categories']) ? $catalog['categories'] : array();
if (!$entries) {
    fwrite(STDERR, "No categories in {$catalog_file}\n");
    exit(4);
}

$placeholder_ids = array_values(array_filter(array_map('intval', explode(',', (string) getenv('UPSCALE_PLACEHOLDER_IDS')))));

/* Demo brands --------------------------------------------------------------
 * Fictional names on purpose: the demo products must not imply a real brand's
 * affiliation. The strip on the homepage prints whatever ends up here. */
$brand_names = array(
    'BeautyLine', 'ProSalon', 'BarberCraft', 'MasterTools', 'LuxSpa',
    'StudioPro', 'Vesta Beauty', 'NordKit',
);

/**
 * WooCommerce attribute taxonomy for an attribute label, whatever its slug is.
 */
function upscale_seed_attribute( $label ) {
    static $map = null;
    if (null === $map) {
        $map = array();
        foreach (wc_get_attribute_taxonomies() as $taxonomy) {
            $map[$taxonomy->attribute_label] = wc_attribute_taxonomy_name($taxonomy->attribute_name);
        }
    }
    return isset($map[$label]) ? $map[$label] : '';
}

/**
 * Deterministic pseudo-random integer, so re-runs stay stable.
 */
function upscale_seed_number( $seed, $min, $max ) {
    $hash = crc32('upscale-demo-' . $seed);
    return $min + (int) ($hash % max(1, ($max - $min + 1)));
}

$created = array('categories' => 0, 'products' => 0, 'renamed' => 0, 'brands' => 0, 'reviews' => 0, 'pages' => 0, 'menu_items' => 0);


/* 1. Demo brands ---------------------------------------------------------- */

$brand_taxonomies = array_filter(
    array(upscale_seed_attribute('Бренд'), upscale_seed_attribute('Производители'))
);

$brand_ids = array();
foreach ($brand_taxonomies as $taxonomy) {
    foreach ($brand_names as $brand_name) {
        $existing = get_term_by('name', $brand_name, $taxonomy);
        if ($existing) {
            $brand_ids[$brand_name] = (int) $existing->term_id;
            continue;
        }
        $inserted = wp_insert_term($brand_name, $taxonomy);
        if (!is_wp_error($inserted)) {
            $brand_ids[$brand_name] = (int) $inserted['term_id'];
            $created['brands']++;
        }
    }
}

/* 2. Demo products -------------------------------------------------------- */

$demo_short = 'Це демонстраційний товар тестового магазину: назва, характеристики та ціна вигадані. Він існує, щоб перевірити вигляд каталогу й імпорт товарів через UpScale.';
$demo_long  = '<p>Демонстраційний опис товару для тестового магазину UpScale. Створений локально скриптом <code>scripts/seed-catalog.php</code>, не скопійований із жодного сайту.</p>'
    . '<p>Опис містить кілька абзаців і список, щоб перевірити, як тема відображає довгий текст картки товару.</p>'
    . '<ul><li>Гарантія 12 місяців (умовно, демо).</li><li>Доставка по Україні.</li><li>Оплата карткою або післяплатою.</li></ul>';

$product_ids   = array();
$entry_by_slug = array();

foreach ($entries as $entry) {
    $slug = isset($entry['slug']) ? (string) $entry['slug'] : '';
    if ('' === $slug) {
        continue;
    }

    $term = get_term_by('slug', $slug, 'product_cat');
    if (!$term || is_wp_error($term)) {
        continue;
    }
    $entry_by_slug[$slug] = (int) $term->term_id;

    $count = isset($entry['demo_products']) ? (int) $entry['demo_products'] : 1;
    $count = max(1, min(3, $count));
    $g_category = isset($entry['g_category']) ? (string) $entry['g_category'] : '';

    // The label is the *kind of object* the category represents; the photo pass
    // (scripts/seed-photos.php) attaches a photograph of the same kind, so the
    // card and its image always agree.
    $label = upscale_demo_label_for_keyword(upscale_demo_keyword_for_term($term));

    for ($index = 1; $index <= $count; $index++) {
        $sku = sprintf('DEMO-%d-%d', (int) $entry['source_id'], $index);

        $brand_name = $brand_names[upscale_seed_number($sku . '|brand', 0, count($brand_names) - 1)];
        $model      = 'M-' . upscale_seed_number($sku, 100, 999);
        $price      = upscale_seed_number($sku . '|price', 800, 60000);
        $name       = sprintf('%s %s %s', $label, $brand_name, $model);

        $existing_id = wc_get_product_id_by_sku($sku);
        if ($existing_id) {
            // Re-runs keep the catalogue consistent: only the name is refreshed
            // (the label, and therefore the photo, may have improved).
            $existing = wc_get_product($existing_id);
            if ($existing instanceof WC_Product && $existing->get_name() !== $name) {
                $existing->set_name($name);
                $existing->save();
                $created['renamed']++;
            }
            continue;
        }

        $product = new WC_Product_Simple();
        $product->set_name($name);
        $product->set_sku($sku);
        $product->set_status('publish');
        $product->set_catalog_visibility('visible');
        $product->set_regular_price((string) $price);
        $product->set_short_description($demo_short);
        $product->set_description($demo_long);
        $product->set_category_ids(array((int) $term->term_id));

        $attributes = array();

        foreach (array('Бренд' => $brand_name, 'Производители' => $brand_name) as $label => $value) {
            $taxonomy = upscale_seed_attribute($label);
            if ('' === $taxonomy) {
                continue;
            }
            $brand_term = get_term_by('name', $value, $taxonomy);
            if (!$brand_term) {
                continue;
            }
            $attribute = new WC_Product_Attribute();
            $attribute->set_id(wc_attribute_taxonomy_id_by_name($taxonomy));
            $attribute->set_name($taxonomy);
            $attribute->set_options(array((int) $brand_term->term_id));
            $attribute->set_visible(true);
            $attribute->set_variation(false);
            $attributes[] = $attribute;
        }

        // mpn / gcategory are hidden attributes, exactly like the pipeline sends.
        foreach (array('mpn' => $sku, 'gcategory' => $g_category) as $label => $value) {
            $taxonomy = upscale_seed_attribute($label);
            if ('' === $taxonomy || '' === (string) $value) {
                continue;
            }
            $attribute = new WC_Product_Attribute();
            $attribute->set_id(wc_attribute_taxonomy_id_by_name($taxonomy));
            $attribute->set_name($taxonomy);
            $attribute->set_options(array((string) $value));
            $attribute->set_visible(false);
            $attribute->set_variation(false);
            $attributes[] = $attribute;
        }

        $product->set_attributes($attributes);

        if ($placeholder_ids) {
            $product->set_image_id($placeholder_ids[upscale_seed_number($sku . '|image', 0, count($placeholder_ids) - 1)]);
        }

        $new_id = $product->save();
        if ($new_id) {
            $product_ids[] = (int) $new_id;
            $created['products']++;
        }
    }
}

/* 3. Demo reviews ---------------------------------------------------------
 * Our own short texts so the product cards show stars. Clearly labelled as
 * demo content by the review author name. */

$review_texts = array(
    'Замовляли для нового салону — доставили вчасно, товар відповідає опису. (демо-відгук)',
    'Гарна якість за свою ціну, користуємось щодня. (демо-відгук)',
    'Допомогли з вибором і підтвердили наявність. (демо-відгук)',
    'Пакування ціле, жодних подряпин. (демо-відгук)',
    'Друга покупка в цьому магазині, все гаразд. (демо-відгук)',
);

foreach ($product_ids as $position => $product_id) {
    if (0 !== $position % 6) {
        continue;
    }
    $product = wc_get_product($product_id);
    if (!$product) {
        continue;
    }
    $comment_id = wp_insert_comment(
        array(
            'comment_post_ID'      => $product_id,
            'comment_author'       => 'Демо-покупець',
            'comment_author_email' => 'demo-customer@example.com',
            'comment_content'      => $review_texts[$position % count($review_texts)],
            'comment_type'         => 'review',
            'comment_approved'     => 1,
        )
    );
    if ($comment_id) {
        update_comment_meta($comment_id, 'rating', 5);
        update_comment_meta($comment_id, 'verified', 1);
        $created['reviews']++;
    }
}

if ($created['reviews'] && class_exists('WC_Comments')) {
    foreach ($product_ids as $product_id) {
        WC_Comments::clear_transients($product_id);
    }
}

/* 4. Informational pages the footer links to ------------------------------ */

$pages = array(
    'pro-nas' => array(
        'title'   => 'Про нас',
        'content' => '<p>Це тестовий магазин проєкту UpScale. Він існує, щоб перевіряти імпорт товарів у WooCommerce, а не для продажу.</p><p>Усі товари, ціни, зображення та відгуки тут демонстраційні.</p>',
    ),
    'oplata-i-dostavka' => array(
        'title'   => 'Оплата і доставка',
        'content' => '<p>Демонстраційна сторінка. У справжньому магазині тут описані способи оплати та доставки:</p><ul><li>оплата карткою онлайн;</li><li>оплата післяплатою у відділенні;</li><li>доставка службою доставки або самовивіз.</li></ul>',
    ),
    'povernennya-i-obmin' => array(
        'title'   => 'Повернення і обмін',
        'content' => '<p>Демонстраційна сторінка. Зазвичай тут описують умови повернення протягом 14 днів, вимоги до пакування та комплектності товару.</p>',
    ),
    'kontakty' => array(
        'title'   => 'Контакти',
        'content' => '<p>Демонстраційні контакти: +38 (000) 000 00 00, test@example.com.</p><p>Магазин тестовий, тому номери та адреси навмисно вигадані.</p>',
    ),
);

foreach ($pages as $slug => $page) {
    if (get_page_by_path($slug)) {
        continue;
    }
    $page_id = wp_insert_post(
        array(
            'post_title'   => $page['title'],
            'post_name'    => $slug,
            'post_content' => $page['content'],
            'post_status'  => 'publish',
            'post_type'    => 'page',
        )
    );
    if ($page_id && !is_wp_error($page_id)) {
        $created['pages']++;
    }
}

/* 5. Primary navigation menu ----------------------------------------------
 * Mirrors the catalog shape: top-level branches with their first children as
 * dropdowns. Rebuilt only when this script created the menu, so a menu you
 * edit by hand is never overwritten. */

$menu_name = 'Головне меню';
$menu_obj  = wp_get_nav_menu_object($menu_name);
$menu_id   = $menu_obj ? (int) $menu_obj->term_id : (int) wp_create_nav_menu($menu_name);
$owned     = (int) get_option('upscale_primary_menu_id');

if ($menu_id && (0 === $owned || $owned === $menu_id)) {
    foreach ((array) wp_get_nav_menu_items($menu_id) as $item) {
        wp_delete_post($item->ID, true);
    }

    $tops = array_values(array_filter($entries, static function ($entry) use ($entry_by_slug) {
        return 0 === (int) $entry['parent_source_id'] && isset($entry_by_slug[$entry['slug']]);
    }));

    // The mirrored catalogue sits under one wrapper root ("Каталог продукции"),
    // so the navigable branches are that root's children.
    if (1 === count($tops)) {
        $root_source_id = (int) $tops[0]['source_id'];
        $tops = array_values(array_filter($entries, static function ($entry) use ($root_source_id, $entry_by_slug) {
            return $root_source_id === (int) $entry['parent_source_id'] && isset($entry_by_slug[$entry['slug']]);
        }));
    }

    $max_top = 9;
    $max_children = 6;

    foreach (array_slice($tops, 0, $max_top) as $top) {
        $parent_item = wp_update_nav_menu_item(
            $menu_id,
            0,
            array(
                'menu-item-title'     => $top['name'],
                'menu-item-object'    => 'product_cat',
                'menu-item-object-id' => $entry_by_slug[$top['slug']],
                'menu-item-type'      => 'taxonomy',
                'menu-item-status'    => 'publish',
            )
        );
        if (is_wp_error($parent_item)) {
            continue;
        }
        $created['menu_items']++;

        $children = array_slice(array_values(array_filter($entries, static function ($entry) use ($top, $entry_by_slug) {
            return (int) $entry['parent_source_id'] === (int) $top['source_id'] && isset($entry_by_slug[$entry['slug']]);
        })), 0, $max_children);

        foreach ($children as $child) {
            $child_item = wp_update_nav_menu_item(
                $menu_id,
                0,
                array(
                    'menu-item-title'     => $child['name'],
                    'menu-item-object'    => 'product_cat',
                    'menu-item-object-id' => $entry_by_slug[$child['slug']],
                    'menu-item-type'      => 'taxonomy',
                    'menu-item-parent-id' => (int) $parent_item,
                    'menu-item-status'    => 'publish',
                )
            );
            if (!is_wp_error($child_item)) {
                $created['menu_items']++;
            }
        }
    }

    update_option('upscale_primary_menu_id', $menu_id);
    $locations              = get_theme_mod('nav_menu_locations', array());
    $locations              = is_array($locations) ? $locations : array();
    $locations['primary']   = $menu_id;
    // Storefront prints the `handheld` location for mobile; without a menu there
    // it falls back to a plain list of WordPress pages, which looks unfinished.
    $locations['handheld']  = $menu_id;
    set_theme_mod('nav_menu_locations', $locations);
}

/* 6. Store settings that match the reference layout ----------------------- */

update_option('blogname', 'UpScale Beauty Demo');
update_option('blogdescription', 'Обладнання для салонів краси та барбершопів — тестовий магазин');

update_option('woocommerce_currency', 'UAH');
update_option('woocommerce_currency_pos', 'right_space');
update_option('woocommerce_price_num_decimals', 0);
update_option('woocommerce_default_country', 'UA');
update_option('woocommerce_catalog_columns', 4);
update_option('woocommerce_catalog_rows', 3);
update_option('woocommerce_enable_reviews', 'yes');
update_option('woocommerce_enable_review_rating', 'yes');
update_option('woocommerce_review_rating_required', 'no');
update_option('woocommerce_thumbnail_cropping', '1:1');

/*
 * WooCommerce creates its pages (and WordPress its sample page) with English
 * titles; a storefront that mixes English chrome with Ukrainian copy looks
 * unfinished, so they are named explicitly here.
 */
$pages_to_rename = array(
    // Keep the shop slug as `shop`: the catalogue root category is `katalog`.
    'woocommerce_shop_page_id'      => array('Каталог', 'shop'),
    'woocommerce_cart_page_id'      => array('Кошик', 'cart'),
    'woocommerce_checkout_page_id'  => array('Оформлення замовлення', 'checkout'),
    'woocommerce_myaccount_page_id' => array('Мій кабінет', 'my-account'),
);

foreach ($pages_to_rename as $option_name => $titles) {
    $page_id = (int) get_option($option_name);
    if ($page_id) {
        wp_update_post(array('ID' => $page_id, 'post_title' => $titles[0], 'post_name' => $titles[1]));
    }
}

$sample_page = get_page_by_path('sample-page');
if ($sample_page instanceof WP_Post) {
    wp_update_post(
        array(
            'ID'        => $sample_page->ID,
            'post_title' => 'Демо-сторінка WordPress',
            'post_name'  => 'demo-storinka',
        )
    );
}

$product_count = (int) wp_count_posts('product')->publish;

/* 7. Summary -------------------------------------------------------------- */

echo wp_json_encode(
    array(
        'catalog_file'  => $catalog_file,
        'created'       => $created,
        'products_total' => $product_count,
        'categories'    => count($entry_by_slug),
        'brands'        => count($brand_ids),
        'menu_id'       => $menu_id,
        'shop_url'      => home_url('/?post_type=product'),
        'home_url'      => home_url('/'),
    ),
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
echo "\n";
