<?php
/**
 * Front page: hero, category tiles, popular products, brands, trust, reviews.
 *
 * All text, prices and reviews here are this project's own demo copy.
 *
 * @package upscale-storefront
 */

get_header();

$ups_shop_url     = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );
$ups_about        = get_page_by_path( 'pro-nas' );
$ups_about_url    = $ups_about instanceof WP_Post ? get_permalink( $ups_about ) : $ups_shop_url;
$ups_delivery     = get_page_by_path( 'oplata-i-dostavka' );
$ups_delivery_url = $ups_delivery instanceof WP_Post ? get_permalink( $ups_delivery ) : $ups_shop_url;
$ups_hero         = upscale_storefront_hero_image_url();
?>

<main id="main" class="ups-home" role="main">

	<section class="ups-hero"<?php echo $ups_hero ? ' style="--ups-hero-image:url(' . esc_url( $ups_hero ) . ')"' : ''; ?>>
		<div class="ups-wrap ups-hero__grid">
			<div class="ups-hero__intro">
				<span class="ups-hero__eyebrow"><?php esc_html_e( 'Обладнання для б\'юті-індустрії', 'upscale-storefront' ); ?></span>
				<h1 class="ups-hero__title"><?php esc_html_e( 'Меблі та техніка для салонів краси і барбершопів', 'upscale-storefront' ); ?></h1>
				<p class="ups-hero__text">
					<?php esc_html_e( 'Демонстраційний каталог тестового магазину: структура категорій і наповнення зроблені для перевірки імпорту товарів, а не для продажу.', 'upscale-storefront' ); ?>
				</p>
				<div class="ups-hero__actions">
					<a class="button" href="<?php echo esc_url( $ups_shop_url ); ?>"><?php esc_html_e( 'До каталогу', 'upscale-storefront' ); ?></a>
					<a class="button ups-ghost" href="<?php echo esc_url( $ups_about_url ); ?>"><?php esc_html_e( 'Про магазин', 'upscale-storefront' ); ?></a>
				</div>
			</div>

			<div class="ups-promo">
				<span class="ups-promo__label"><?php esc_html_e( 'Акція тижня (демо)', 'upscale-storefront' ); ?></span>
				<h2 class="ups-promo__title"><?php esc_html_e( 'Знижка на парикмахерський інструмент', 'upscale-storefront' ); ?></h2>
				<p class="ups-promo__text">
					<?php esc_html_e( 'Блок повторює структуру реальної вітрини. Товари, ціни та знижки в ньому — тестові дані.', 'upscale-storefront' ); ?>
				</p>
				<a class="button" href="<?php echo esc_url( $ups_shop_url ); ?>"><?php esc_html_e( 'Переглянути товари', 'upscale-storefront' ); ?></a>
			</div>
		</div>
	</section>

	<section class="ups-section">
		<div class="ups-wrap">
			<div class="ups-section__head">
				<h2 class="ups-section__title"><?php esc_html_e( 'Категорії каталогу', 'upscale-storefront' ); ?></h2>
				<a class="ups-section__link" href="<?php echo esc_url( $ups_shop_url ); ?>"><?php esc_html_e( 'Усі товари', 'upscale-storefront' ); ?></a>
			</div>

			<div class="ups-tiles">
				<?php foreach ( upscale_storefront_top_categories( 8 ) as $ups_term ) : ?>
					<?php $ups_tile_image = upscale_storefront_category_image_id( $ups_term ); ?>
					<a class="ups-tile" href="<?php echo esc_url( get_term_link( $ups_term ) ); ?>">
						<?php if ( $ups_tile_image ) : ?>
							<span class="ups-tile__media">
								<?php
								echo wp_get_attachment_image(
									$ups_tile_image,
									'medium_large',
									false,
									array(
										'class'   => 'ups-tile__img',
										'loading' => 'lazy',
										'alt'     => $ups_term->name,
									)
								);
								?>
							</span>
						<?php endif; ?>
						<span class="ups-tile__body">
							<span class="ups-tile__name"><?php echo esc_html( $ups_term->name ); ?></span>
							<span class="ups-tile__children"><?php echo esc_html( upscale_storefront_child_names( $ups_term ) ); ?></span>
							<span class="ups-tile__count">
								<?php
								printf(
									/* translators: %d: number of products in the category. */
									esc_html__( '%d товарів у категорії', 'upscale-storefront' ),
									(int) $ups_term->count
								);
								?>
							</span>
						</span>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
	</section>

	<section class="ups-section">
		<div class="ups-wrap">
			<div class="ups-section__head">
				<h2 class="ups-section__title"><?php esc_html_e( 'Наші найпопулярніші продукти', 'upscale-storefront' ); ?></h2>
				<a class="ups-section__link" href="<?php echo esc_url( $ups_shop_url ); ?>"><?php esc_html_e( 'Перейти в каталог', 'upscale-storefront' ); ?></a>
			</div>
			<?php echo do_shortcode( '[products limit="8" columns="4" orderby="popularity" order="DESC"]' ); ?>
		</div>
	</section>

	<?php $ups_brands = upscale_storefront_brands( 14 ); ?>
	<?php if ( $ups_brands ) : ?>
		<section class="ups-section">
			<div class="ups-wrap">
				<div class="ups-section__head">
					<h2 class="ups-section__title"><?php esc_html_e( 'Бренди, з якими ми працюємо', 'upscale-storefront' ); ?></h2>
				</div>
				<div class="ups-brands">
					<?php foreach ( $ups_brands as $ups_brand ) : ?>
						<span class="ups-brand"><?php echo esc_html( $ups_brand ); ?></span>
					<?php endforeach; ?>
				</div>
			</div>
		</section>
	<?php endif; ?>

	<section class="ups-section">
		<div class="ups-wrap">
			<div class="ups-section__head">
				<h2 class="ups-section__title"><?php esc_html_e( 'Чому купують у нас', 'upscale-storefront' ); ?></h2>
			</div>
			<div class="ups-trust">
				<?php foreach ( upscale_storefront_trust_points() as $ups_point ) : ?>
					<div class="ups-trust__item">
						<h3 class="ups-trust__title"><?php echo esc_html( $ups_point['title'] ); ?></h3>
						<p class="ups-trust__text"><?php echo esc_html( $ups_point['text'] ); ?></p>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
	</section>

	<section class="ups-section">
		<div class="ups-wrap">
			<div class="ups-section__head">
				<h2 class="ups-section__title"><?php esc_html_e( 'Відгуки клієнтів', 'upscale-storefront' ); ?></h2>
				<a class="ups-section__link" href="<?php echo esc_url( $ups_delivery_url ); ?>"><?php esc_html_e( 'Оплата і доставка', 'upscale-storefront' ); ?></a>
			</div>
			<div class="ups-reviews">
				<?php foreach ( upscale_storefront_testimonials() as $ups_review ) : ?>
					<blockquote class="ups-review">
						<span class="ups-review__stars" aria-hidden="true">★★★★★</span>
						<p class="ups-review__text"><?php echo esc_html( $ups_review['text'] ); ?></p>
						<p class="ups-review__author"><?php echo esc_html( $ups_review['author'] ); ?></p>
					</blockquote>
				<?php endforeach; ?>
			</div>
		</div>
	</section>

</main>

<?php
get_footer();
