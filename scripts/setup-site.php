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
 *   2. ensures one test product category;
 *   3. creates a WooCommerce REST key pair (read/write).
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

/* 2. Test product category ------------------------------------------------ */
if (!term_exists($category_slug, 'product_cat')) {
    wp_insert_term($category_name, 'product_cat', array('slug' => $category_slug));
}
$term        = get_term_by('slug', $category_slug, 'product_cat');
$category_id = $term && !is_wp_error($term) ? (int) $term->term_id : 0;

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
    'gcategories_json' => array(
        $category_slug => array(
            'category_id' => $category_id,
            'g_category'  => (int) $google_category,
        ),
    ),
);

$mock_categories = array(
    array(
        'id'       => $category_id,
        'name'     => $category_name,
        'slug'     => $category_slug,
        'keywords' => 'тест, товар, купити, ціна, доставка, гарантія',
        'icp'      => 'Власник WooCommerce-магазину, який шукає товар із доставкою та гарантією і порівнює пропозиції конкурентів.',
    ),
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
