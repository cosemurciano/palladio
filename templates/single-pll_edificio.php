<?php
/**
 * Template singolo — Edificio (direzione visiva editoriale "Sambiasi").
 *
 * Sovrascrivibile dal tema: {tema}/palladio/single-pll_edificio.php.
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

while ( have_posts() ) :
	the_post();
	$building_id = get_the_ID();
	$claim       = palladio_meta( $building_id, 'claim' );
	$hero        = get_the_post_thumbnail_url( $building_id, 'full' );
	$ed          = palladio_editorial( $building_id );

	$facts = array();
	if ( $anno = palladio_meta( $building_id, 'anno_costruzione' ) ) { // phpcs:ignore
		$facts[] = array( (string) absint( $anno ), __( 'Anno', 'palladio' ) );
	}
	if ( $mq = palladio_meta( $building_id, 'mq_totali' ) ) { // phpcs:ignore
		$facts[] = array( number_format_i18n( (float) $mq, 0 ) . ' m²', __( 'Superficie', 'palladio' ) );
	}
	if ( $piani = palladio_meta( $building_id, 'num_piani' ) ) { // phpcs:ignore
		$facts[] = array( (string) absint( $piani ), __( 'Piani', 'palladio' ) );
	}
	if ( $uv = palladio_meta( $building_id, 'num_unita_vendita' ) ) { // phpcs:ignore
		$facts[] = array( (string) absint( $uv ), __( 'Unità in vendita', 'palladio' ) );
	}
	?>
	<div class="palladio-editorial palladio-edificio-editorial">

		<header class="pll-e-hero">
			<?php if ( $hero ) : ?>
				<img class="pll-e-hero__img" src="<?php echo esc_url( $hero ); ?>" alt="">
			<?php endif; ?>
			<div class="pll-e-hero__inner">
				<?php
				$loc_parts = array_filter( array( palladio_meta( $building_id, 'indirizzo' ), palladio_meta( $building_id, 'sottotitolo' ) ) );
				$location  = $loc_parts ? implode( ' · ', $loc_parts ) : __( 'L’edificio', 'palladio' );
				?>
				<p class="pll-e-eyebrow">
					<?php echo esc_html( $location ); ?>
					<?php echo do_shortcode( '[palladio_lang_switcher]' ); ?>
				</p>
				<h1 class="pll-e-hero__title"><?php the_title(); ?></h1>
				<?php if ( $claim ) : ?>
					<p class="pll-e-hero__lead"><?php echo esc_html( $claim ); ?></p>
				<?php endif; ?>
			</div>
		</header>

		<?php if ( $facts ) : ?>
			<div class="pll-e-sticky">
				<div class="pll-e-sticky__inner">
					<div class="pll-e-sticky__facts">
						<?php
						$indirizzo = palladio_meta( $building_id, 'indirizzo' );
						if ( $indirizzo ) :
							?>
							<span class="pll-e-fact"><b><?php echo esc_html( $indirizzo ); ?></b><span><?php esc_html_e( 'Indirizzo', 'palladio' ); ?></span></span>
						<?php endif; ?>
						<?php foreach ( $facts as $fact ) : ?>
							<span class="pll-e-fact"><b><?php echo esc_html( $fact[0] ); ?></b><span><?php echo esc_html( $fact[1] ); ?></span></span>
						<?php endforeach; ?>
					</div>
				</div>
			</div>
		<?php endif; ?>

		<?php if ( get_the_content() ) : ?>
			<section class="pll-e-section pll-e-wrap">
				<div class="pll-e-prose"><?php the_content(); ?></div>
			</section>
		<?php endif; ?>

		<?php foreach ( $ed['narrative'] as $block ) : ?>
			<?php $img = palladio_image_url( $block['image'] ?? 0, 'large' ); ?>
			<section class="pll-e-section pll-e-wrap">
				<div class="pll-e-narrative pll-e-narrative--<?php echo esc_attr( 'left' === ( $block['layout'] ?? 'right' ) ? 'left' : 'right' ); ?>">
					<div class="pll-e-narrative__text">
						<?php if ( ! empty( $block['kicker'] ) ) : ?><p class="pll-e-kicker"><?php echo esc_html( $block['kicker'] ); ?></p><?php endif; ?>
						<?php if ( ! empty( $block['heading'] ) ) : ?><h2 class="pll-e-h"><?php echo esc_html( $block['heading'] ); ?></h2><?php endif; ?>
						<?php if ( ! empty( $block['body'] ) ) : ?><div><?php echo wp_kses_post( wpautop( $block['body'] ) ); ?></div><?php endif; ?>
					</div>
					<?php if ( $img ) : ?>
						<figure class="pll-e-narrative__media"><img src="<?php echo esc_url( $img ); ?>" alt="" loading="lazy">
							<?php if ( ! empty( $block['caption'] ) ) : ?><figcaption class="pll-e-sister__eyebrow"><?php echo esc_html( $block['caption'] ); ?></figcaption><?php endif; ?>
						</figure>
					<?php endif; ?>
				</div>
			</section>
		<?php endforeach; ?>

		<?php if ( $ed['gallery'] ) : ?>
			<section class="pll-e-section pll-e-wrap">
				<p class="pll-e-kicker"><?php esc_html_e( 'Galleria', 'palladio' ); ?></p>
				<h2 class="pll-e-h"><?php esc_html_e( 'L’edificio in luce', 'palladio' ); ?></h2>
				<div class="pll-e-sisters">
					<?php foreach ( $ed['gallery'] as $shot ) : ?>
						<?php $gi = palladio_image_url( $shot['image'] ?? 0, 'large' ); if ( ! $gi ) { continue; } ?>
						<figure class="pll-e-sister"><span class="pll-e-sister__media"><img src="<?php echo esc_url( $gi ); ?>" alt="" loading="lazy"></span>
							<?php if ( ! empty( $shot['caption'] ) ) : ?><figcaption class="pll-e-sister__body pll-e-sister__eyebrow"><?php echo esc_html( $shot['caption'] ); ?></figcaption><?php endif; ?>
						</figure>
					<?php endforeach; ?>
				</div>
			</section>
		<?php endif; ?>

		<?php
		$vincoli = palladio_meta( $building_id, 'vincoli_note' );
		if ( $vincoli ) :
			?>
			<section class="pll-e-tech">
				<div class="pll-e-wrap pll-e-section">
					<p class="pll-e-kicker"><?php esc_html_e( 'Vincoli e note legali', 'palladio' ); ?></p>
					<h2 class="pll-e-h"><?php esc_html_e( 'Trasparenza', 'palladio' ); ?></h2>
					<p class="pll-e-prose"><?php echo esc_html( $vincoli ); ?></p>
				</div>
			</section>
		<?php endif; ?>

		<?php
		$units = palladio_get_building_units( $building_id );
		$range = palladio_units_price_range( $building_id );
		if ( $units->have_posts() ) :
			?>
			<section class="pll-e-section pll-e-wrap">
				<p class="pll-e-kicker"><?php esc_html_e( 'Le residenze', 'palladio' ); ?></p>
				<h2 class="pll-e-h"><?php esc_html_e( 'Le unità', 'palladio' ); ?></h2>
				<?php if ( $range['min'] > 0 ) : ?>
					<p class="pll-e-prose pll-e-residenze-range">
						<?php
						printf(
							/* translators: 1: numero residenze, 2: prezzo minimo, 3: prezzo massimo. */
							esc_html( _n( '%1$s residenza · %2$s – %3$s', '%1$s residenze · %2$s – %3$s', (int) $range['count'], 'palladio' ) ),
							esc_html( number_format_i18n( (int) $range['count'] ) ),
							esc_html( palladio_format_price( $range['min'] ) ),
							esc_html( palladio_format_price( $range['max'] ) )
						);
						?>
					</p>
				<?php endif; ?>
				<div class="pll-e-sisters">
					<?php
					while ( $units->have_posts() ) :
						$units->the_post();
						$uid     = get_the_ID();
						$ustat   = palladio_get_unit_status( $uid );
						$uthumb  = get_the_post_thumbnail_url( $uid, 'medium_large' );
						$ueye    = palladio_unit_eyebrow( $uid );
						$uexc    = has_excerpt( $uid ) ? get_the_excerpt( $uid ) : '';
						?>
						<a class="pll-e-sister" href="<?php the_permalink(); ?>">
							<span class="pll-e-sister__media">
								<?php if ( $uthumb ) : ?><img src="<?php echo esc_url( $uthumb ); ?>" alt="" loading="lazy"><?php endif; ?>
								<?php if ( $ustat['label'] ) : ?><span class="palladio-badge palladio-badge--<?php echo esc_attr( $ustat['slug'] ); ?>"><?php echo esc_html( $ustat['label'] ); ?></span><?php endif; ?>
							</span>
							<span class="pll-e-sister__body">
								<?php if ( $ueye ) : ?><span class="pll-e-sister__eyebrow"><?php echo esc_html( $ueye ); ?></span><?php endif; ?>
								<span class="pll-e-sister__title"><?php echo esc_html( get_the_title( $uid ) ); ?></span>
								<?php if ( $uexc ) : ?><span class="pll-e-sister__desc"><?php echo esc_html( wp_trim_words( $uexc, 26 ) ); ?></span><?php endif; ?>
								<span class="pll-e-sister__price"><?php echo esc_html( palladio_format_price( palladio_meta( $uid, 'prezzo' ) ) ); ?></span>
							</span>
						</a>
					<?php endwhile; wp_reset_postdata(); ?>
				</div>
			</section>
		<?php endif; ?>

		<?php
		/** Estensione dopo l'elenco unità (scenari, planimetrie…). */
		do_action( 'palladio/edificio/after_units', $building_id );
		?>

	</div>
	<?php
endwhile;

get_footer();
