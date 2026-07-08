<?php
/**
 * Modulo Presenter — shortcode.
 *
 * `[palladio_edifici]` — griglia degli edifici pubblicati, inseribile in
 * qualsiasi pagina. Riusa la card dell'archivio via markup coerente.
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registra gli shortcode del plugin.
 */
class Palladio_Frontend_Shortcodes {

	/**
	 * Registra gli shortcode.
	 *
	 * @return void
	 */
	public function register() {
		require_once PALLADIO_DIR . 'includes/frontend/template-functions.php';
		add_shortcode( 'palladio_edifici', array( $this, 'edifici' ) );
	}

	/**
	 * Renderizza la griglia degli edifici.
	 *
	 * @param array $atts Attributi shortcode.
	 * @return string
	 */
	public function edifici( $atts ) {
		$atts = shortcode_atts(
			array(
				'limit'   => 12,
				'orderby' => 'title',
				'order'   => 'ASC',
			),
			$atts,
			'palladio_edifici'
		);

		$query = new WP_Query(
			array(
				'post_type'      => 'pll_edificio',
				'post_status'    => 'publish',
				'posts_per_page' => (int) $atts['limit'],
				'orderby'        => sanitize_key( $atts['orderby'] ),
				'order'          => ( 'DESC' === strtoupper( $atts['order'] ) ) ? 'DESC' : 'ASC',
				'no_found_rows'  => true,
			)
		);

		if ( ! $query->have_posts() ) {
			return '';
		}

		ob_start();
		echo '<div class="palladio palladio-buildings-grid">';
		while ( $query->have_posts() ) {
			$query->the_post();
			$building_id = get_the_ID();
			$claim       = palladio_meta( $building_id, 'claim' );
			?>
			<article class="palladio-building-card">
				<a class="palladio-building-card__link" href="<?php the_permalink(); ?>">
					<div class="palladio-building-card__media">
						<?php if ( has_post_thumbnail() ) : ?>
							<?php the_post_thumbnail( 'large', array( 'loading' => 'lazy' ) ); ?>
						<?php else : ?>
							<span class="palladio-building-card__placeholder" aria-hidden="true"></span>
						<?php endif; ?>
					</div>
					<div class="palladio-building-card__body">
						<h3 class="palladio-building-card__title"><?php the_title(); ?></h3>
						<?php if ( $claim ) : ?>
							<p class="palladio-building-card__claim"><?php echo esc_html( $claim ); ?></p>
						<?php endif; ?>
					</div>
				</a>
			</article>
			<?php
		}
		echo '</div>';
		wp_reset_postdata();

		return (string) ob_get_clean();
	}
}
