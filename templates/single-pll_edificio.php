<?php
/**
 * Template singolo — Edificio (landing, direzione visiva editoriale "Sambiasi").
 *
 * Struttura conforme alle immagini di riferimento: hero, manifesto, timeline
 * scroll-telling, ambient loop, sezione unità "Scegli le tue stanze" con
 * filtri, galleria asimmetrica. I campi provengono da palladio_editorial()
 * (metabox "Contenuti della scheda", set Edificio) e dai dati principali.
 *
 * Sovrascrivibile: {tema}/palladio/single-pll_edificio.php.
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

	$loc_parts = array_filter( array( palladio_meta( $building_id, 'indirizzo' ), palladio_meta( $building_id, 'sottotitolo' ) ) );
	$location  = $ed['eyebrow'] ? $ed['eyebrow'] : ( $loc_parts ? implode( ' · ', $loc_parts ) : __( 'L’edificio', 'palladio' ) );
	$lead      = $ed['lead'] ? $ed['lead'] : ( has_excerpt() ? get_the_excerpt() : '' );

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

	$ratio_css = static function ( $ratio ) {
		return in_array( $ratio, array( '3:2', '4:3', '4:5', '1:1', '16:9' ), true ) ? str_replace( ':', ' / ', $ratio ) : '4 / 3';
	};
	?>
	<div class="palladio-editorial palladio-edificio-editorial">

		<header class="pll-e-hero">
			<?php if ( $hero ) : ?>
				<img class="pll-e-hero__img" src="<?php echo esc_url( $hero ); ?>" alt="">
			<?php endif; ?>
			<div class="pll-e-hero__inner">
				<p class="pll-e-eyebrow"><?php echo esc_html( $location ); ?></p>
				<h1 class="pll-e-hero__title"><?php echo $claim ? esc_html( $claim ) : get_the_title(); ?></h1>
				<?php if ( $lead ) : ?><p class="pll-e-hero__lead"><?php echo esc_html( $lead ); ?></p><?php endif; ?>
				<p><a class="pll-e-cta" href="#residenze"><?php esc_html_e( 'Scopri le residenze', 'palladio' ); ?></a></p>
			</div>
		</header>

		<?php if ( $facts ) : ?>
			<div class="pll-e-sticky">
				<div class="pll-e-sticky__inner">
					<div class="pll-e-sticky__facts">
						<?php foreach ( $facts as $fact ) : ?>
							<span class="pll-e-fact"><b><?php echo esc_html( $fact[0] ); ?></b><span><?php echo esc_html( $fact[1] ); ?></span></span>
						<?php endforeach; ?>
					</div>
					<a class="pll-e-cta" href="#residenze"><?php esc_html_e( 'Richiedi il dossier', 'palladio' ); ?></a>
				</div>
			</div>
		<?php endif; ?>

		<?php if ( get_the_content() ) : ?>
			<section class="pll-e-section pll-e-wrap"><div class="pll-e-prose"><?php the_content(); ?></div></section>
		<?php endif; ?>

		<?php // MANIFESTO. ?>
		<?php if ( $ed['manifesto'] ) : ?>
			<section class="pll-e-section pll-e-wrap pll-e-manifesto">
				<span class="pll-e-manifesto__rule" aria-hidden="true"></span>
				<?php foreach ( $ed['manifesto'] as $m ) : ?>
					<p class="pll-e-manifesto__line pll-reveal">
						<?php echo esc_html( $m['text'] ?? '' ); ?>
						<?php if ( ! empty( $m['emphasis'] ) ) : ?><em><?php echo esc_html( $m['emphasis'] ); ?></em><?php endif; ?>
					</p>
				<?php endforeach; ?>
			</section>
		<?php endif; ?>

		<?php // TIMELINE / scroll-telling: media fissato a sinistra, capitoli che avanzano con lo scroll. ?>
		<?php if ( $ed['timeline'] ) : ?>
			<?php
			$chapters = array_values( $ed['timeline'] );
			$roman    = array( 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII' );
			?>
			<section class="pll-e-scrolly" id="palladio-timeline" data-palladio-scrolly>
				<div class="pll-e-scrolly__media" id="palladio-timeline-media" aria-hidden="true">
					<?php foreach ( $chapters as $i => $t ) : ?>
						<?php $timg = palladio_image_url( $t['image'] ?? 0, 'full' ); ?>
						<span class="pll-e-scrolly__frame<?php echo 0 === $i ? ' is-active' : ''; ?>" data-scrolly-frame="<?php echo esc_attr( $i ); ?>"<?php if ( $timg ) : ?> style="background-image:url('<?php echo esc_url( $timg ); ?>')"<?php endif; ?>></span>
					<?php endforeach; ?>
				</div>

				<div class="pll-e-scrolly__chapters" id="palladio-timeline-chapters">
					<?php foreach ( $chapters as $i => $t ) : ?>
						<?php
						$timg   = palladio_image_url( $t['image'] ?? 0, 'large' );
						$anchor = 'palladio-timeline-capitolo-' . ( $i + 1 );
						?>
						<article class="pll-e-scrolly__chapter" id="<?php echo esc_attr( $anchor ); ?>" data-scrolly-chapter="<?php echo esc_attr( $i ); ?>">
							<?php // Figura inline: mobile e degradazione senza JS (figura + testo impilati). ?>
							<?php if ( $timg ) : ?>
								<figure class="pll-e-scrolly__figure"><img src="<?php echo esc_url( $timg ); ?>" alt="" loading="lazy"></figure>
							<?php endif; ?>
							<div class="pll-e-scrolly__body">
								<p class="pll-e-kicker" id="<?php echo esc_attr( $anchor ); ?>-eyebrow">
									<?php
									/* translators: %s: numero romano del capitolo. */
									printf( esc_html__( 'Capitolo %s', 'palladio' ), esc_html( $roman[ $i ] ?? (string) ( $i + 1 ) ) );
									if ( ! empty( $t['kicker'] ) ) {
										echo ' · ' . esc_html( $t['kicker'] );
									}
									?>
								</p>
								<?php if ( ! empty( $t['year'] ) ) : ?><p class="pll-e-scrolly__year" id="<?php echo esc_attr( $anchor ); ?>-year"><?php echo esc_html( $t['year'] ); ?></p><?php endif; ?>
								<?php if ( ! empty( $t['heading'] ) ) : ?><h2 class="pll-e-h pll-e-scrolly__heading" id="<?php echo esc_attr( $anchor ); ?>-title"><?php echo esc_html( $t['heading'] ); ?></h2><?php endif; ?>
								<?php if ( ! empty( $t['body'] ) ) : ?><div class="pll-e-prose" id="<?php echo esc_attr( $anchor ); ?>-body"><?php echo wp_kses_post( wpautop( $t['body'] ) ); ?></div><?php endif; ?>

								<nav class="pll-e-scrolly__nav" aria-label="<?php esc_attr_e( 'Capitoli della timeline', 'palladio' ); ?>">
									<?php foreach ( $chapters as $j => $other ) : ?>
										<?php $label = ! empty( $other['year'] ) ? $other['year'] : ( $roman[ $j ] ?? (string) ( $j + 1 ) ); ?>
										<a href="#palladio-timeline-capitolo-<?php echo esc_attr( $j + 1 ); ?>"
											class="<?php echo $j === $i ? 'is-active' : ''; ?>"
											data-scrolly-goto="<?php echo esc_attr( $j ); ?>"
											<?php echo $j === $i ? 'aria-current="true"' : ''; ?>><?php echo esc_html( $label ); ?></a>
									<?php endforeach; ?>
								</nav>
							</div>
						</article>
					<?php endforeach; ?>
				</div>
			</section>
		<?php endif; ?>

		<?php // AMBIENT LOOP. ?>
		<?php $ambient = palladio_image_url( $ed['ambient']['image'], 'full' ); ?>
		<?php if ( $ambient ) : ?>
			<section class="pll-e-ambient" style="background-image:url('<?php echo esc_url( $ambient ); ?>')">
				<?php if ( $ed['ambient']['caption'] ) : ?><span class="pll-e-ambient__caption"><?php echo esc_html( $ed['ambient']['caption'] ); ?></span><?php endif; ?>
			</section>
		<?php endif; ?>

		<?php // UNITÀ — "Scegli le tue stanze". ?>
		<?php
		$units = palladio_get_building_units( $building_id );
		$range = palladio_units_price_range( $building_id );
		if ( $units->have_posts() ) :
			?>
			<section id="residenze" class="pll-e-section pll-e-wrap">
				<div class="pll-e-units-head">
					<div>
						<p class="pll-e-kicker"><?php echo esc_html( $ed['units_eyebrow'] ? $ed['units_eyebrow'] : __( 'Le residenze', 'palladio' ) ); ?></p>
						<h2 class="pll-e-h"><?php echo esc_html( $ed['units_heading'] ? $ed['units_heading'] : __( 'Scegli le tue stanze', 'palladio' ) ); ?></h2>
					</div>
					<?php if ( $ed['units_filters'] ) : ?>
						<div class="pll-e-filters" data-palladio-unit-filters>
							<button type="button" class="pll-e-chip is-active" data-filter="all"><?php esc_html_e( 'Tutte', 'palladio' ); ?></button>
							<button type="button" class="pll-e-chip" data-filter="piano"><?php esc_html_e( 'Piano', 'palladio' ); ?> ↓</button>
							<button type="button" class="pll-e-chip" data-filter="prezzo"><?php esc_html_e( 'Prezzo', 'palladio' ); ?> ↓</button>
							<button type="button" class="pll-e-chip" data-filter="esterno"><?php esc_html_e( 'Con spazio esterno', 'palladio' ); ?></button>
						</div>
					<?php endif; ?>
				</div>

				<?php if ( $range['min'] > 0 ) : ?>
					<p class="pll-e-prose pll-e-residenze-range">
						<?php
						printf(
							/* translators: 1: n residenze, 2: min, 3: max. */
							esc_html( _n( '%1$s residenza · %2$s – %3$s', '%1$s residenze · %2$s – %3$s', (int) $range['count'], 'palladio' ) ),
							esc_html( number_format_i18n( (int) $range['count'] ) ),
							esc_html( palladio_format_price( $range['min'] ) ),
							esc_html( palladio_format_price( $range['max'] ) )
						);
						?>
					</p>
				<?php endif; ?>

				<div class="pll-e-sisters" id="palladio-units-grid" data-palladio-units>
					<?php
					while ( $units->have_posts() ) :
						$units->the_post();
						palladio_render_unit_card_editorial( get_the_ID() );
					endwhile;
					wp_reset_postdata();
					?>
				</div>
			</section>
		<?php endif; ?>

		<?php // GALLERIA asimmetrica. ?>
		<?php if ( $ed['gallery'] ) : ?>
			<section class="pll-e-section pll-e-wrap">
				<div class="pll-e-units-head">
					<div>
						<p class="pll-e-kicker"><?php esc_html_e( 'Galleria', 'palladio' ); ?></p>
						<h2 class="pll-e-h"><?php esc_html_e( 'Il palazzo in luce', 'palladio' ); ?></h2>
					</div>
				</div>
				<div class="pll-e-gallery" id="palladio-gallery" data-pll-lightbox-group>
					<?php foreach ( $ed['gallery'] as $shot ) : ?>
						<?php
						$gi = palladio_image_url( $shot['image'] ?? 0, 'large' );
						if ( ! $gi ) {
							continue;
						}
						$gfull = palladio_image_url( $shot['image'], 'full' );
						?>
						<figure class="pll-e-gallery__item" style="aspect-ratio:<?php echo esc_attr( $ratio_css( $shot['ratio'] ?? '4:3' ) ); ?>">
							<a class="pll-e-gallery__zoom" href="<?php echo esc_url( $gfull ? $gfull : $gi ); ?>"
								data-pll-lightbox="<?php echo esc_url( $gfull ? $gfull : $gi ); ?>"
								data-pll-caption="<?php echo esc_attr( $shot['caption'] ?? '' ); ?>"
								aria-label="<?php esc_attr_e( 'Ingrandisci immagine', 'palladio' ); ?>">
								<img src="<?php echo esc_url( $gi ); ?>" alt="<?php echo esc_attr( $shot['caption'] ?? '' ); ?>" loading="lazy">
							</a>
							<?php if ( ! empty( $shot['caption'] ) ) : ?><figcaption><?php echo esc_html( $shot['caption'] ); ?></figcaption><?php endif; ?>
						</figure>
					<?php endforeach; ?>
				</div>
				<?php if ( $ed['gallery_url'] ) : ?>
					<p class="pll-e-gallery-more"><a href="<?php echo esc_url( $ed['gallery_url'] ); ?>">
						<?php
						$count = $ed['gallery_count'] ? $ed['gallery_count'] : (string) count( $ed['gallery'] );
						/* translators: %s: numero fotografie. */
						printf( esc_html__( 'Tutta la galleria — %s fotografie →', 'palladio' ), esc_html( $count ) );
						?>
					</a></p>
				<?php endif; ?>
			</section>
		<?php endif; ?>

		<?php
		$vincoli = palladio_meta( $building_id, 'vincoli_note' );
		if ( $vincoli ) :
			?>
			<section class="pll-e-tech">
				<div class="pll-e-wrap pll-e-section">
					<p class="pll-e-kicker"><?php esc_html_e( 'Come funziona l’acquisto', 'palladio' ); ?></p>
					<h2 class="pll-e-h"><?php esc_html_e( 'La chiarezza è parte dell’architettura', 'palladio' ); ?></h2>
					<p class="pll-e-prose"><?php echo esc_html( $vincoli ); ?></p>
				</div>
			</section>
		<?php endif; ?>

		<?php do_action( 'palladio/edificio/after_units', $building_id ); ?>

	</div>
	<?php
endwhile;

get_footer();
