<?php
/**
 * Footer: four columns (about, catalog, help, info) + the demo disclaimer.
 *
 * @package upscale-storefront
 */

?>
	</div><!-- .col-full -->
</div><!-- #content -->

<?php do_action( 'storefront_before_footer' ); ?>

<footer id="colophon" class="site-footer" role="contentinfo">
	<div class="col-full">
		<div class="ups-footer__grid">
			<div class="ups-footer__column">
				<?php upscale_storefront_logo(); ?>
				<p class="ups-footer__text"><?php bloginfo( 'description' ); ?></p>
				<?php $ups_contact = upscale_storefront_contact(); ?>
				<ul class="ups-footer__list">
					<li><a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $ups_contact['phone'] ) ); ?>"><?php echo esc_html( $ups_contact['phone'] ); ?></a></li>
					<li><?php echo esc_html( $ups_contact['email'] ); ?></li>
				</ul>
			</div>

			<div class="ups-footer__column">
				<h2 class="ups-footer__title"><?php esc_html_e( 'Каталог', 'upscale-storefront' ); ?></h2>
				<ul class="ups-footer__list">
					<?php foreach ( upscale_storefront_top_categories( 6 ) as $ups_term ) : ?>
						<li>
							<a href="<?php echo esc_url( get_term_link( $ups_term ) ); ?>">
								<?php echo esc_html( $ups_term->name ); ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>

			<div class="ups-footer__column">
				<h2 class="ups-footer__title"><?php esc_html_e( 'Допомога', 'upscale-storefront' ); ?></h2>
				<ul class="ups-footer__list">
					<?php
					foreach ( array( 'oplata-i-dostavka', 'povernennya-i-obmin', 'pro-nas', 'kontakty' ) as $ups_slug ) {
						$ups_page = get_page_by_path( $ups_slug );
						if ( ! $ups_page instanceof WP_Post ) {
							continue;
						}
						printf(
							'<li><a href="%s">%s</a></li>',
							esc_url( get_permalink( $ups_page ) ),
							esc_html( get_the_title( $ups_page ) )
						);
					}
					?>
				</ul>
			</div>

			<div class="ups-footer__column">
				<h2 class="ups-footer__title"><?php esc_html_e( 'Корисна інформація', 'upscale-storefront' ); ?></h2>
				<ul class="ups-footer__list">
					<?php foreach ( upscale_storefront_trust_points() as $ups_point ) : ?>
						<li><?php echo esc_html( $ups_point['title'] ); ?></li>
					<?php endforeach; ?>
					<?php
					$ups_sources = get_page_by_path( 'dzherela-zobrazhen' );
					if ( $ups_sources instanceof WP_Post ) :
						?>
						<li><a href="<?php echo esc_url( get_permalink( $ups_sources ) ); ?>"><?php esc_html_e( 'Джерела зображень', 'upscale-storefront' ); ?></a></li>
					<?php endif; ?>
					<li>
						<a href="<?php echo esc_url( get_privacy_policy_url() ? get_privacy_policy_url() : home_url( '/' ) ); ?>">
							<?php esc_html_e( 'Політика конфіденційності', 'upscale-storefront' ); ?>
						</a>
					</li>
				</ul>
			</div>
		</div>

		<div class="ups-footer__note">
			<?php
			printf(
				/* translators: %s: current year. */
				esc_html__( '© %s — тестовий магазин UpScale. Усі товари, ціни, зображення та відгуки тут демонстраційні.', 'upscale-storefront' ),
				esc_html( gmdate( 'Y' ) )
			);
			?>
		</div>
	</div>
</footer>

<?php do_action( 'storefront_after_footer' ); ?>
<?php wp_footer(); ?>
</body>
</html>
