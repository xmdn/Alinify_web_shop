<?php
/**
 * UpScale test store — photo pass. Runs INSIDE the WordPress container:
 *
 *     docker compose cp scripts/seed-photos.php cli:/tmp/seed-photos.php
 *     docker compose exec -T cli wp eval-file /tmp/seed-photos.php
 *
 * It takes the Wikimedia Commons photographs downloaded by
 * `scripts/fetch_product_photos.py` and makes the storefront look finished:
 *
 *   1. imports each photo into the media library (once — the file → attachment
 *      id map is cached in the `upscale_photo_attachments` option);
 *   2. stores `upscale_category_images` (category slug → attachment id) and
 *      `upscale_hero_image`, which the theme reads for the tiles and the banner;
 *   3. gives every demo product a featured image and a small gallery whose
 *      subject matches its name (clipper photo for a clipper, chair for a chair);
 *   4. publishes the "Джерела зображень" page with the author, licence and file
 *      link for every photograph — the attribution CC BY / CC BY-SA requires.
 *
 * Idempotent: re-running imports nothing twice and rewrites the same mappings.
 */

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run this with: wp eval-file\n");
    exit(1);
}

require_once '/work/scripts/inc/demo-data.php';

if (!function_exists('wp_generate_attachment_metadata')) {
    require_once ABSPATH . 'wp-admin/includes/image.php';
}

$sources_file = getenv('UPSCALE_PHOTOS_FILE') ?: '/work/data/photo-sources.json';
$sources      = upscale_demo_photo_sources();
if (empty($sources['keywords'])) {
    fwrite(STDERR, "No photo record in {$sources_file} — run scripts/fetch_product_photos.py first.\n");
    exit(3);
}

$root = getenv('UPSCALE_PROJECT_DIR') ?: '/work';
$created = array('attachments' => 0, 'reused' => 0, 'missing' => 0, 'products_linked' => 0, 'categories_linked' => 0, 'renamed' => 0);

/**
 * Import one photograph into the media library, reusing the cached attachment.
 *
 * @param array  $photo  Record from photo-sources.json.
 * @param string $root   Project root inside the container (the `/work` mount).
 * @param string $alt    Alt text for the attachment.
 * @param array  $cache  Cache of file → attachment id (updated in place).
 * @param array  $counts Summary counters (by reference).
 * @return int Attachment id, 0 on failure.
 */
function upscale_photos_import($photo, $root, $alt, &$cache, &$counts) {
    $relative = isset($photo['file']) ? (string) $photo['file'] : '';
    if ('' === $relative) {
        return 0;
    }

    $key = $relative;
    if (!empty($cache[$key])) {
        $counts['reused']++;
        return (int) $cache[$key];
    }

    $path = $root . '/' . ltrim(str_replace('\\', '/', $relative), '/');
    if (!is_readable($path)) {
        $counts['missing']++;
        return 0;
    }

    $contents = file_get_contents($path);
    if (false === $contents) {
        $counts['missing']++;
        return 0;
    }

    $upload = wp_upload_bits(basename($path), null, $contents);
    if (!empty($upload['error'])) {
        $counts['missing']++;
        return 0;
    }

    $title      = isset($photo['title']) ? preg_replace('/^File:/', '', (string) $photo['title']) : basename($path);
    $author     = isset($photo['author']) ? (string) $photo['author'] : 'невідомий';
    $license    = isset($photo['license']) ? (string) $photo['license'] : '';
    $license_url = isset($photo['license_url']) ? (string) $photo['license_url'] : '';
    $page_url   = isset($photo['page_url']) ? (string) $photo['page_url'] : '';

    $attribution = sprintf('%s — %s, %s', $title, $author, $license);
    if ($page_url) {
        $attribution .= ' (' . $page_url . ')';
    }

    $attachment_id = wp_insert_attachment(
        array(
            'post_mime_type' => 'image/jpeg' === ($photo['mime'] ?? '') ? 'image/jpeg' : 'image/png',
            'post_title'     => $title,
            'post_content'   => $attribution,
            'post_excerpt'   => $license_url ? $license_url : $page_url,
            'post_status'    => 'inherit',
        ),
        $upload['file']
    );

    if (is_wp_error($attachment_id) || !$attachment_id) {
        $counts['missing']++;
        return 0;
    }

    $metadata = wp_generate_attachment_metadata($attachment_id, $upload['file']);
    if (!is_wp_error($metadata) && $metadata) {
        wp_update_attachment_metadata($attachment_id, $metadata);
    }
    update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt ? $alt : $title);
    update_post_meta($attachment_id, 'upscale_source_url', $page_url);
    update_post_meta($attachment_id, 'upscale_license', $license);

    $cache[$key] = (int) $attachment_id;
    $counts['attachments']++;

    return (int) $attachment_id;
}

/* 1. Import every photograph ---------------------------------------------- */

$cache = get_option('upscale_photo_attachments', array());
$cache = is_array($cache) ? $cache : array();

$keyword_ids = array();  // keyword → [attachment ids]
$photo_index = array();  // attachment id → record (for the attribution page)

foreach ($sources['keywords'] as $keyword => $photos) {
    $label = upscale_demo_label_for_keyword($keyword);
    $ids   = array();

    foreach ($photos as $photo) {
        $attachment_id = upscale_photos_import($photo, $root, $label, $cache, $created);
        if ($attachment_id) {
            $ids[] = $attachment_id;
            $photo_index[$attachment_id] = $photo + array('keyword' => $keyword, 'label' => $label);
        }
    }

    if ($ids) {
        $keyword_ids[$keyword] = $ids;
    }
}

update_option('upscale_photo_attachments', $cache, false);

/* 2. Hero banner ----------------------------------------------------------- */

foreach ((array) ($sources['heroes'] ?? array()) as $hero) {
    $hero_id = upscale_photos_import($hero, $root, 'Демонстраційний банер головної', $cache, $created);
    if ($hero_id) {
        $photo_index[$hero_id] = $hero + array('keyword' => 'hero', 'label' => 'Банер головної сторінки');
        update_option('upscale_hero_image', $hero_id, false);
        break;
    }
}

/* 3. Category → photograph ------------------------------------------------- */

$placeholder_ids = array_values(
    array_filter(array_map('intval', explode(',', (string) get_option('upscale_placeholder_ids', ''))))
);
$fallback = $placeholder_ids ? (int) $placeholder_ids[0] : 0;

$category_images = array();
foreach (get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false)) as $term) {
    if (is_wp_error($term) || 'uncategorized' === $term->slug) {
        continue;
    }

    $keyword = upscale_demo_keyword_for_term($term);
    $image_id = ($keyword && !empty($keyword_ids[$keyword])) ? (int) $keyword_ids[$keyword][0] : 0;

    if (!$image_id) {
        $image_id = $fallback; // the generated tile is better than an empty box
    }
    if ($image_id) {
        $category_images[$term->slug] = $image_id;
        $created['categories_linked']++;
    }
}

update_option('upscale_category_images', $category_images, false);

/* 4. Demo products: featured image + gallery ------------------------------- */

$product_ids = get_posts(
    array(
        'post_type'   => 'product',
        'post_status' => 'any',
        'numberposts' => -1,
        'fields'      => 'ids',
    )
);

foreach ($product_ids as $product_id) {
    $product = wc_get_product($product_id);
    if (!$product instanceof WC_Product) {
        continue;
    }

    // Only the demo catalogue is touched — an imported UpScale product keeps its
    // own images.
    if (0 !== strpos((string) $product->get_sku(), 'DEMO-')) {
        continue;
    }

    $keyword = '';
    foreach (wp_get_post_terms($product_id, 'product_cat') as $term) {
        if ($term instanceof WP_Term) {
            $keyword = upscale_demo_keyword_for_term($term);
            if ($keyword) {
                break;
            }
        }
    }

    if ('' === $keyword || empty($keyword_ids[$keyword])) {
        continue;
    }

    $ids = $keyword_ids[$keyword];
    if (count($ids) > 1) {
        $offset  = upscale_demo_number($product_id . '|photo', 0, count($ids) - 1);
        $ids     = array_merge(array_slice($ids, $offset), array_slice($ids, 0, $offset));
    }

    $product->set_image_id((int) $ids[0]);
    $product->set_gallery_image_ids(array_map('intval', array_slice($ids, 1)));
    $product->save();

    $created['products_linked']++;
}

/* 5. Attribution page ------------------------------------------------------ */

$rows = '';
foreach ($photo_index as $photo) {
    $title       = preg_replace('/^File:/', '', (string) ($photo['title'] ?? ''));
    $license     = (string) ($photo['license'] ?? '');
    $license_url = (string) ($photo['license_url'] ?? '');

    $license_cell = $license_url
        ? sprintf('<a href="%s" rel="nofollow noopener" target="_blank">%s</a>', esc_url($license_url), esc_html($license))
        : esc_html($license);

    $source_cell = !empty($photo['page_url'])
        ? sprintf('<a href="%s" rel="nofollow noopener" target="_blank">Wikimedia Commons</a>', esc_url((string) $photo['page_url']))
        : '—';

    $rows .= sprintf(
        '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
        esc_html((string) ($photo['label'] ?? '')),
        esc_html($title),
        esc_html((string) ($photo['author'] ?? '')),
        $license_cell,
        $source_cell
    );
}

$content  = '<p>Усі фотографії на цьому сайті — реальні знімки реальних товарів, узяті з бази '
    . '<strong>Wikimedia Commons</strong>. Це демонстраційний магазин, тому фото показують тип товару, '
    . 'а не конкретну модель із каталогу.</p>';
$content .= '<p>Файли під ліцензіями <em>CC BY</em> та <em>CC BY-SA</em> вимагають зазначення автора й '
    . 'ліцензії, тому нижче наведено повний список: файл, автор, ліцензія та посилання на сторінку файлу. '
    . 'Твори в суспільному надбанні та <em>CC0</em> наведено для повноти.</p>';
$content .= '<table class="ups-sources"><thead><tr><th>Тип товару</th><th>Файл</th><th>Автор</th>'
    . '<th>Ліцензія</th><th>Джерело</th></tr></thead><tbody>' . $rows . '</tbody></table>';

$sources_page = get_page_by_path('dzherela-zobrazhen');
if ($sources_page instanceof WP_Post) {
    wp_update_post(
        array(
            'ID'           => $sources_page->ID,
            'post_title'   => 'Джерела зображень',
            'post_content' => $content,
        )
    );
} else {
    wp_insert_post(
        array(
            'post_title'   => 'Джерела зображень',
            'post_name'    => 'dzherela-zobrazhen',
            'post_content' => $content,
            'post_status'  => 'publish',
            'post_type'    => 'page',
        )
    );
}

/* 6. Summary --------------------------------------------------------------- */

echo wp_json_encode(
    array(
        'imported'               => $created,
        'photos'                 => count($photo_index),
        'keywords_with_photos'   => count($keyword_ids),
        'categories_with_photos' => count($category_images),
        'hero_image'             => (int) get_option('upscale_hero_image', 0),
        'products'               => count($product_ids),
    ),
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
echo "\n";
