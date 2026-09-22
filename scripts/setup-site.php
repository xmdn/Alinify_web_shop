<?php
/**
 * UpScale test-store configuration. Runs INSIDE the WordPress container:
 *
 *     docker compose cp scripts/setup-site.php cli:/tmp/setup-site.php
 *     docker compose exec -T cli wp eval-file /tmp/setup-site.php
 *
 * Does three idempotent things and prints a JSON summary on stdout:
 *   1. ensures the global attributes the pipeline expects (calls the mu-plugin's
 *      Section A directly, so it also works if `init` already ran);
 *   2. ensures the product categories — normally the whole tree from
 *      `data/catalog.json` (scripts/build_catalog.py), or the single legacy test
 *      category when that file is absent;
 *   3. creates a WooCommerce REST key pair (read/write).
 *
 * It also writes the two generated artifacts:
 *   config/mapping.json       `gcategories_json` for every category
 *   mock-ups/categories.json  the "UPS" mock's list, with `icp` / `keywords`
 *
 * The keys are printed exactly once — WooCommerce stores the consumer key hashed
 * and never shows the secret again.
 */

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run this with: wp eval-file\n");
    exit(1);
}

$category_name = getenv('UPSCALE_TEST_CATEGORY') ?: 'Test Category';
$category_slug = getenv('UPSCALE_TEST_CATEGORY_SLUG') ?: 'test-category';
$admin_login   = getenv('UPSCALE_ADMIN_LOGIN') ?: 'admin';

/* 1. Global attributes ---------------------------------------------------- */
if (function_exists('upscale_register_required_attributes')) {
    upscale_register_required_attributes();
}

$attributes = array();
if (function_exists('wc_get_attribute_taxonomies')) {
    foreach (wc_get_attribute_taxonomies() as $tax) {
        $attributes[] = array(
            'id'   => (int) $tax->attribute_id,
            'name' => $tax->attribute_label,
            'slug' => $tax->attribute_name,
        );
    }
}

/* 2. Product categories ---------------------------------------------------
 * Preferred path: `data/catalog.json` — the category tree that
 * scripts/build_catalog.py mirrored from the reference storefront (names,
 * slugs, hierarchy, Google category) with this project's own keywords/icp.
 * Entries are created parent-first so WooCommerce gets the same hierarchy.
 * Without that file the original single test category is created, which keeps
 * the script backwards compatible. */

$catalog_file    = getenv('UPSCALE_CATALOG_FILE') ?: '';
$catalog_entries = array();

if ($catalog_file && is_readable($catalog_file)) {
    $decoded = json_decode(file_get_contents($catalog_file), true);
    if (isset($decoded['categories']) && is_array($decoded['categories'])) {
        $catalog_entries = $decoded['categories'];
    }
}

$category_map    = array(); // slug => term_id
$mock_categories = array(); // the UPS mock payload
$gcategories     = array(); // config/mapping.json → gcategories_json

foreach ($catalog_entries as $entry) {
    $slug = isset($entry['slug']) ? (string) $entry['slug'] : '';
    if ('' === $slug) {
        continue;
    }

    $parent_id = 0;
    if (!empty($entry['parent_slug']) && isset($category_map[$entry['parent_slug']])) {
        $parent_id = (int) $category_map[$entry['parent_slug']];
    }

    $existing = get_term_by('slug', $slug, 'product_cat');
    if ($existing && !is_wp_error($existing)) {
        $term_id = (int) $existing->term_id;
        if ($parent_id && (int) $existing->parent !== $parent_id) {
            wp_update_term($term_id, 'product_cat', array('parent' => $parent_id));
        }
    } else {
        $inserted = wp_insert_term(
            (string) $entry['name'],
            'product_cat',
            array('slug' => $slug, 'parent' => $parent_id)
        );
        $term_id = is_wp_error($inserted) ? 0 : (int) $inserted['term_id'];
    }

    if (!$term_id) {
        continue;
    }

    $category_map[$slug] = $term_id;

    // The mock must expose `icp` and `keywords`: `process` refuses to run
    // without them (backend D2/D3), so every category carries its own.
    $mock_categories[] = array(
        'id'       => $term_id,
        'name'     => (string) $entry['name'],
        'slug'     => $slug,
        'keywords' => (string) $entry['keywords'],
        'icp'      => (string) $entry['icp'],
    );

    $gcategories[$slug] = array(
        'category_id' => $term_id,
        'g_category'  => (int) $entry['g_category'],
    );
}

if (!$mock_categories) {
    /* Legacy fallback: one test category, as before data/catalog.json existed. */
    if (!term_exists($category_slug, 'product_cat')) {
        wp_insert_term($category_name, 'product_cat', array('slug' => $category_slug));
    }
    $term        = get_term_by('slug', $category_slug, 'product_cat');
    $category_id = $term && !is_wp_error($term) ? (int) $term->term_id : 0;

    $mock_categories[] = array(
        'id'       => $category_id,
        'name'     => $category_name,
        'slug'     => $category_slug,
        'keywords' => 'тест, товар, купити, ціна, доставка, гарантія',
        'icp'      => 'Власник WooCommerce-магазину, який шукає товар із доставкою та гарантією і порівнює пропозиції конкурентів.',
    );
    $gcategories[$category_slug] = array(
        'category_id' => $category_id,
        'g_category'  => (int) (getenv('UPSCALE_GOOGLE_CATEGORY') ?: '4508'),
    );
} else {
    $first               = $mock_categories[0];
    $category_id         = (int) $first['id'];
    $category_name       = (string) $first['name'];
    $category_slug       = (string) $first['slug'];
}

/* 3. WooCommerce REST key pair (mirrors WC_API_Keys::create_keys) ----------
 * WooCommerce stores the consumer key hashed (wc_api_hash) and the consumer
 * secret in plain text. The plain values exist only in this output.          */
$user = get_user_by('login', $admin_login);
if (!$user) {
    $user = get_user_by('id', 1);
}

$consumer_key    = 'ck_' . wc_rand_hash();
$consumer_secret = 'cs_' . wc_rand_hash();

global $wpdb;
$inserted = $wpdb->insert(
    $wpdb->prefix . 'woocommerce_api_keys',
    array(
        'user_id'         => $user ? $user->ID : 0,
        'description'     => 'UpScale test key',
        'permissions'     => 'read_write',
        'consumer_key'    => wc_api_hash($consumer_key),
        'consumer_secret' => $consumer_secret,
        'truncated_key'   => substr($consumer_key, -7),
    ),
    array('%d', '%s', '%s', '%s', '%s', '%s')
);

if ($inserted === false) {
    fwrite(STDERR, "Failed to insert the WooCommerce API key: " . $wpdb->last_error . "\n");
    exit(2);
}

/* 4. Spec example using THIS store's real ids ------------------------------
 * The production `spec_example` hardcodes another store's ids; showing those to
 * the model on a fresh store invites it to copy ids that do not exist here.
 * Build the example from the attributes that actually exist.                 */
$google_category = getenv('UPSCALE_GOOGLE_CATEGORY') ?: '4508';
$by_name         = array();
foreach ($attributes as $attribute) {
    $by_name[$attribute['name']] = $attribute;
}

$preferred = array('Бренд', 'Производители', 'Цвет', 'Материал', 'mpn', 'gcategory');
$spec      = array();
$position  = 0;

foreach ($preferred as $name) {
    if (!isset($by_name[$name])) {
        continue;
    }

    $attribute = $by_name[$name];
    $lower     = mb_strtolower($attribute['name']);
    $hidden    = in_array($lower, array('mpn', 'gcategory'), true);

    if ('mpn' === $lower) {
        $options = array('TEST-MPN-001');
    } elseif ('gcategory' === $lower) {
        $options = array((string) $google_category);
    } else {
        $options = array('Пример значения');
    }

    $spec[] = array(
        'id'        => (int) $attribute['id'],
        'name'      => $attribute['name'],
        'position'  => $position++,
        'visible'   => !$hidden,
        'variation' => false,
        'options'   => $options,
    );
}

/* 5. Write the two generated artifacts ------------------------------------ */
$out_dir = getenv('UPSCALE_OUT_DIR') ?: '';

$mapping = array(
    'actual_attributes' => array_map(
        function ($a) {
            return array('id' => (int) $a['id'], 'name' => $a['name']);
        },
        $attributes
    ),
    'spec_example'     => $spec,
    'gcategories_json' => $gcategories,
);

$written = array('mapping' => false, 'categories' => false);
if ($out_dir && is_dir($out_dir) && is_writable($out_dir)) {
    $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    if (!is_dir($out_dir . '/config')) {
        @mkdir($out_dir . '/config', 0775, true);
    }
    $written['mapping'] = false !== @file_put_contents(
        $out_dir . '/config/mapping.json',
        wp_json_encode($mapping, $flags) . "\n"
    );

    if (is_dir($out_dir . '/mock-ups')) {
        $written['categories'] = false !== @file_put_contents(
            $out_dir . '/mock-ups/categories.json',
            wp_json_encode($mock_categories, $flags) . "\n"
        );
    }
}

/* 6. Summary -------------------------------------------------------------- */
echo wp_json_encode(
    array(
        'site_url'        => get_option('siteurl'),
        'home_url'        => get_option('home'),
        'permalink'       => get_option('permalink_structure'),
        'plugins'         => array_values(array_filter(array_map(
            function ($p) {
                return is_plugin_active($p) ? $p : null;
            },
            array(
                'woocommerce/woocommerce.php',
                'wordpress-seo/wp-seo.php',
                'yikes-inc-easy-custom-woocommerce-product-tabs/yikes-inc-easy-custom-woocommerce-product-tabs.php',
            )
        ))),
        'attributes'      => $attributes,
        'attribute_count' => count($attributes),
        'spec_example'    => $spec,
        'category'        => array(
            'id'   => $category_id,
            'name' => $category_name,
            'slug' => $category_slug,
        ),
        'category_count'  => count($mock_categories),
        'catalog_file'    => $catalog_file,
        'rest'            => array(
            'consumer_key'    => $consumer_key,
            'consumer_secret' => $consumer_secret,
        ),
        'written'         => $written,
        'out_dir'         => $out_dir,
    ),
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
echo "\n";
