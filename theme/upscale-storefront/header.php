<?php
/**
 * Header: demo strip, utility top bar, branding row, dark primary navigation.
 *
 * Dropdown menus come from the "Головне меню" WP menu (built by
 * scripts/seed-catalog.php from the store's own category tree).
 *
 * @package upscale-storefront
 */

?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="profile" href="https://gmpg.org/xfn/11">
<?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<?php do_action( 'storefront_before_site' ); ?>

<a class="skip-link screen-reader-text" href="#content"><?php esc_html_e( 'Перейти до вмісту', 'upscale-storefront' ); ?></a>

<div class="ups-demo-note"><?php echo esc_html( upscale_storefront_demo_note() ); ?></div>

<?php $ups_contact = upscale_storefront_contact(); ?>
<div class="ups-topbar">
	<div class="ups-wrap">
		<div class="ups-topbar__left">
			<span class="ups-topbar__tag"><?php esc_html_e( 'Тестовий магазин', 'upscale-storefront' ); ?></span>
			<a class="ups-topbar__phone" href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $ups_contact['phone'] ) ); ?>">
				<?php echo esc_html( $ups_contact['phone'] ); ?>
			</a>
			<span><?php echo esc_html( $ups_contact['email'] ); ?></span>
		</div>
		<div class="ups-topbar__right">
			<?php if ( function_exists( 'wc_get_page_permalink' ) ) : ?>
				<a href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>"><?php esc_html_e( 'Мій кабінет', 'upscale-storefront' ); ?></a>
			<?php endif; ?>
			<?php
			$ups_about = get_page_by_path( 'pro-nas' );
			if ( $ups_about instanceof WP_Post ) :
				?>
				<a href="<?php echo esc_url( get_permalink( $ups_about ) ); ?>"><?php esc_html_e( 'Про нас', 'upscale-storefront' ); ?></a>
			<?php endif; ?>
			<?php if ( function_exists( 'wc_get_cart_url' ) ) : ?>
				<a href="<?php echo esc_url( wc_get_cart_url() ); ?>">
					<?php
					$ups_count = ( function_exists( 'WC' ) && WC()->cart ) ? (int) WC()->cart->get_cart_contents_count() : 0;
					printf(
						/* translators: %d: number of items in the cart. */
						esc_html__( 'Кошик (%d)', 'upscale-storefront' ),
						$ups_count
					);
					?>
				</a>
			<?php endif; ?>
		</div>
	</div>
</div>

<header id="masthead" class="site-header">
	<div class="col-full">
		<div class="ups-header__brand">
			<?php upscale_storefront_logo(); ?>
			<p class="ups-header__tagline"><?php bloginfo( 'description' ); ?></p>
		</div>
		<div class="ups-header__side">
			<?php
			if ( function_exists( 'storefront_product_search' ) ) {
				storefront_product_search();
			}
			?>
		</div>
	</div>
</header>

<nav class="storefront-primary-navigation" aria-label="<?php esc_attr_e( 'Головне меню', 'upscale-storefront' ); ?>">
	<div class="col-full">
		<?php
		if ( function_exists( 'storefront_primary_navigation' ) ) {
			storefront_primary_navigation();
		}
		?>
	</div>
</nav>

<?php do_action( 'storefront_before_content' ); ?>

<div id="content" class="site-content" tabindex="-1">
	<div class="col-full">
