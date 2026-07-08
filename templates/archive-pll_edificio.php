<?php
/**
 * Template archivio — Edifici.
 *
 * Sovrascrivibile dal tema: {tema}/palladio/archive-pll_edificio.php.
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<section class="palladio-single palladio-archive">

	<?php if ( ! palladio_header_owns_title() ) : ?>
		<header class="palladio-archive__header">
			<h1 class="palladio-archive__title"><?php post_type_archive_title(); ?></h1>
		</header>
	<?php endif; ?>

	<?php if ( have_posts() ) : ?>
		<div class="palladio-buildings-grid">
			<?php
			while ( have_posts() ) :
				the_post();
				$building_id = get_the_ID();
				$claim       = palladio_meta( $building_id, 'claim' );
				$uv          = palladio_meta( $building_id, 'num_unita_vendita' );
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
							<h2 class="palladio-building-card__title"><?php the_title(); ?></h2>
							<?php if ( $claim ) : ?>
								<p class="palladio-building-card__claim"><?php echo esc_html( $claim ); ?></p>
							<?php endif; ?>
							<?php if ( $uv ) : ?>
								<p class="palladio-building-card__meta">
									<?php
									/* translators: %s: numero di unità in vendita. */
									printf( esc_html( _n( '%s unità in vendita', '%s unità in vendita', (int) $uv, 'palladio' ) ), esc_html( number_format_i18n( (int) $uv ) ) );
									?>
								</p>
							<?php endif; ?>
						</div>
					</a>
				</article>
				<?php
			endwhile;
			?>
		</div>

		<div class="palladio-archive__pagination">
			<?php the_posts_pagination(); ?>
		</div>
	<?php else : ?>
		<p class="palladio-empty"><?php esc_html_e( 'Nessun edificio pubblicato.', 'palladio' ); ?></p>
	<?php endif; ?>

</section>
<?php
get_footer();
