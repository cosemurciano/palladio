<?php
/**
 * Template — Pagina "La Storia" (tavola d'archivio).
 *
 * Hero, affermazioni introduttive, cronologia con asse del tempo centrale
 * (alternanza destra/sinistra, anno grande come pietra miliare), araldica,
 * lessico della pietra (glossario illustrato), chiusura con CTA. Ogni campo
 * è editabile dal metabox "Palladio — Contenuti della scheda".
 * Sovrascrivibile dal tema: {tema}/palladio/single-pll_storia.php.
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

while ( have_posts() ) :
	the_post();
	$storia_id = get_the_ID();
	$ed        = palladio_editorial( $storia_id );
	$hero      = get_the_post_thumbnail_url( $storia_id, 'full' );
	$lead      = $ed['lead'] ? $ed['lead'] : get_the_excerpt();

	$dossier_label = class_exists( 'Palladio_Admin_Settings' ) ? Palladio_Admin_Settings::get( 'dossier_label' ) : __( 'Richiedi una visita', 'palladio' );
	$dossier_url   = class_exists( 'Palladio_Admin_Settings' ) ? Palladio_Admin_Settings::get( 'dossier_url' ) : '';
	?>
	<div class="palladio-editorial palladio-storia-editorial">

		<header class="pll-e-hero pll-e-storia-hero" id="palladio-storia-hero">
			<?php if ( $hero ) : ?>
				<img class="pll-e-hero__img" src="<?php echo esc_url( $hero ); ?>" alt="">
			<?php endif; ?>
			<div class="pll-e-hero__inner">
				<?php if ( $ed['eyebrow'] ) : ?>
					<p class="pll-e-eyebrow" id="palladio-storia-eyebrow"><?php echo esc_html( $ed['eyebrow'] ); ?></p>
				<?php endif; ?>
				<h1 class="pll-e-hero__title" id="palladio-storia-title"><?php the_title(); ?></h1>
				<?php if ( $lead ) : ?>
					<p class="pll-e-hero__lead" id="palladio-storia-lead"><?php echo esc_html( $lead ); ?></p>
				<?php endif; ?>
			</div>
		</header>

		<?php if ( get_the_content() ) : ?>
			<section class="pll-e-section pll-e-wrap"><div class="pll-e-prose" id="palladio-storia-intro"><?php the_content(); ?></div></section>
		<?php endif; ?>

		<?php // AFFERMAZIONI introduttive (manifesto). ?>
		<?php if ( $ed['manifesto'] ) : ?>
			<section class="pll-e-section pll-e-wrap pll-e-manifesto" id="palladio-storia-manifesto">
				<span class="pll-e-manifesto__rule" aria-hidden="true"></span>
				<?php foreach ( $ed['manifesto'] as $m ) : ?>
					<p class="pll-e-manifesto__line pll-reveal">
						<?php echo esc_html( $m['text'] ?? '' ); ?>
						<?php if ( ! empty( $m['emphasis'] ) ) : ?><em><?php echo esc_html( $m['emphasis'] ); ?></em><?php endif; ?>
					</p>
				<?php endforeach; ?>
			</section>
		<?php endif; ?>

		<?php // CRONOLOGIA — tavola d'archivio con asse del tempo. ?>
		<?php if ( $ed['timeline'] ) : ?>
			<section class="pll-e-section pll-e-archive" id="palladio-storia-cronologia" data-pll-lightbox-group>
				<div class="pll-e-wrap">
					<div class="pll-e-archive__axis" aria-hidden="true"></div>
					<?php foreach ( array_values( $ed['timeline'] ) as $i => $event ) : ?>
						<?php
						$eimg   = palladio_image_url( $event['image'] ?? 0, 'large' );
						$efull  = palladio_image_url( $event['image'] ?? 0, 'full' );
						$anchor = 'palladio-storia-evento-' . ( $i + 1 );
						?>
						<article class="pll-e-archive__event pll-e-archive__event--<?php echo 0 === $i % 2 ? 'right' : 'left'; ?> pll-reveal" id="<?php echo esc_attr( $anchor ); ?>">
							<div class="pll-e-archive__milestone" id="<?php echo esc_attr( $anchor ); ?>-anno">
								<?php if ( ! empty( $event['year'] ) ) : ?><span class="pll-e-archive__year"><?php echo esc_html( $event['year'] ); ?></span><?php endif; ?>
								<?php if ( ! empty( $event['year_sub'] ) ) : ?><span class="pll-e-archive__year-sub"><?php echo esc_html( $event['year_sub'] ); ?></span><?php endif; ?>
							</div>
							<div class="pll-e-archive__body">
								<?php if ( ! empty( $event['kicker'] ) ) : ?><p class="pll-e-kicker" id="<?php echo esc_attr( $anchor ); ?>-era"><?php echo esc_html( $event['kicker'] ); ?></p><?php endif; ?>
								<?php if ( ! empty( $event['heading'] ) ) : ?><h2 class="pll-e-h pll-e-archive__heading" id="<?php echo esc_attr( $anchor ); ?>-titolo"><?php echo esc_html( $event['heading'] ); ?></h2><?php endif; ?>
								<?php if ( ! empty( $event['body'] ) ) : ?><div class="pll-e-prose" id="<?php echo esc_attr( $anchor ); ?>-racconto"><?php echo wp_kses_post( wpautop( $event['body'] ) ); ?></div><?php endif; ?>
							</div>
							<?php if ( $eimg ) : ?>
								<figure class="pll-e-archive__media" id="<?php echo esc_attr( $anchor ); ?>-media">
									<a class="pll-e-gallery__zoom" href="<?php echo esc_url( $efull ? $efull : $eimg ); ?>"
										data-pll-lightbox="<?php echo esc_url( $efull ? $efull : $eimg ); ?>"
										data-pll-caption="<?php echo esc_attr( $event['caption'] ?? '' ); ?>"
										aria-label="<?php esc_attr_e( 'Ingrandisci immagine', 'palladio' ); ?>">
										<img src="<?php echo esc_url( $eimg ); ?>" alt="<?php echo esc_attr( $event['caption'] ?? '' ); ?>" loading="lazy">
									</a>
									<?php if ( ! empty( $event['caption'] ) ) : ?><figcaption><?php echo esc_html( $event['caption'] ); ?></figcaption><?php endif; ?>
								</figure>
							<?php endif; ?>
						</article>
					<?php endforeach; ?>
				</div>
			</section>
		<?php endif; ?>

		<?php // ARALDICA. ?>
		<?php if ( $ed['heraldry'] ) : ?>
			<section class="pll-e-tech">
				<div class="pll-e-wrap pll-e-section" id="palladio-storia-araldica">
					<p class="pll-e-kicker" id="palladio-storia-araldica-eyebrow"><?php echo esc_html( $ed['heraldry_eyebrow'] ? $ed['heraldry_eyebrow'] : __( 'L’araldica', 'palladio' ) ); ?></p>
					<h2 class="pll-e-h" id="palladio-storia-araldica-titolo"><?php echo esc_html( $ed['heraldry_heading'] ? $ed['heraldry_heading'] : __( 'Tre blasoni, una dimora', 'palladio' ) ); ?></h2>
					<div class="pll-e-heraldry">
						<?php foreach ( array_values( $ed['heraldry'] ) as $i => $shield ) : ?>
							<?php $simg = palladio_image_url( $shield['image'] ?? 0, 'medium' ); ?>
							<article class="pll-e-heraldry__card pll-reveal" id="palladio-storia-blasone-<?php echo esc_attr( $i + 1 ); ?>">
								<span class="pll-e-heraldry__shield">
									<?php if ( $simg ) : ?>
										<img src="<?php echo esc_url( $simg ); ?>" alt="">
									<?php else : ?>
										<span class="pll-e-heraldry__initial"><?php echo esc_html( $shield['initial'] ?? '' ); ?></span>
									<?php endif; ?>
								</span>
								<?php if ( ! empty( $shield['name'] ) ) : ?><h3 class="pll-e-heraldry__name"><?php echo esc_html( $shield['name'] ); ?></h3><?php endif; ?>
								<?php if ( ! empty( $shield['blazon'] ) ) : ?><p class="pll-e-heraldry__blazon"><?php echo esc_html( $shield['blazon'] ); ?></p><?php endif; ?>
								<?php if ( ! empty( $shield['note'] ) ) : ?><p class="pll-e-heraldry__note"><?php echo esc_html( $shield['note'] ); ?></p><?php endif; ?>
							</article>
						<?php endforeach; ?>
					</div>
				</div>
			</section>
		<?php endif; ?>

		<?php // LESSICO DELLA PIETRA — glossario illustrato. ?>
		<?php if ( $ed['glossary'] ) : ?>
			<section class="pll-e-section pll-e-wrap" id="palladio-storia-lessico" data-pll-lightbox-group>
				<p class="pll-e-kicker" id="palladio-storia-lessico-eyebrow"><?php echo esc_html( $ed['glossary_eyebrow'] ? $ed['glossary_eyebrow'] : __( 'Il lessico della pietra', 'palladio' ) ); ?></p>
				<h2 class="pll-e-h" id="palladio-storia-lessico-titolo"><?php echo esc_html( $ed['glossary_heading'] ? $ed['glossary_heading'] : __( 'Le parole per capirlo', 'palladio' ) ); ?></h2>
				<?php if ( $ed['glossary_text'] ) : ?>
					<p class="pll-e-prose" id="palladio-storia-lessico-testo"><?php echo esc_html( $ed['glossary_text'] ); ?></p>
				<?php endif; ?>
				<div class="pll-e-glossary">
					<?php foreach ( array_values( $ed['glossary'] ) as $i => $entry ) : ?>
						<?php
						$gimg  = palladio_image_url( $entry['image'] ?? 0, 'large' );
						$gfull = palladio_image_url( $entry['image'] ?? 0, 'full' );
						?>
						<article class="pll-e-glossary__card pll-reveal" id="palladio-storia-termine-<?php echo esc_attr( $i + 1 ); ?>">
							<?php if ( $gimg ) : ?>
								<figure class="pll-e-glossary__media">
									<a class="pll-e-gallery__zoom" href="<?php echo esc_url( $gfull ? $gfull : $gimg ); ?>"
										data-pll-lightbox="<?php echo esc_url( $gfull ? $gfull : $gimg ); ?>"
										data-pll-caption="<?php echo esc_attr( $entry['caption'] ?? '' ); ?>"
										aria-label="<?php esc_attr_e( 'Ingrandisci immagine', 'palladio' ); ?>">
										<img src="<?php echo esc_url( $gimg ); ?>" alt="<?php echo esc_attr( $entry['term'] ?? '' ); ?>" loading="lazy">
									</a>
									<?php if ( ! empty( $entry['caption'] ) ) : ?><figcaption><?php echo esc_html( $entry['caption'] ); ?></figcaption><?php endif; ?>
								</figure>
							<?php endif; ?>
							<?php if ( ! empty( $entry['term'] ) ) : ?><h3 class="pll-e-glossary__term"><?php echo esc_html( $entry['term'] ); ?></h3><?php endif; ?>
							<?php if ( ! empty( $entry['sub'] ) ) : ?><p class="pll-e-glossary__sub"><?php echo esc_html( $entry['sub'] ); ?></p><?php endif; ?>
							<?php if ( ! empty( $entry['definition'] ) ) : ?><p class="pll-e-glossary__definition"><?php echo esc_html( $entry['definition'] ); ?></p><?php endif; ?>
						</article>
					<?php endforeach; ?>
				</div>
			</section>
		<?php endif; ?>

		<?php // CHIUSURA — il prossimo capitolo. ?>
		<section class="pll-e-section pll-e-closing" id="palladio-storia-chiusura">
			<div class="pll-e-wrap">
				<p class="pll-e-kicker" id="palladio-storia-chiusura-eyebrow"><?php echo esc_html( $ed['closing']['kicker'] ? $ed['closing']['kicker'] : __( 'Il prossimo capitolo', 'palladio' ) ); ?></p>
				<h2 class="pll-e-closing__heading" id="palladio-storia-chiusura-titolo">
					<?php echo esc_html( $ed['closing']['heading'] ? $ed['closing']['heading'] : __( 'La storia continua', 'palladio' ) ); ?>
					<em><?php echo esc_html( $ed['closing']['emphasis'] ? $ed['closing']['emphasis'] : __( 'con chi la abiterà.', 'palladio' ) ); ?></em>
				</h2>
				<p class="pll-e-closing__actions">
					<a class="pll-e-cta" href="<?php echo esc_url( $ed['closing']['primary_url'] ? $ed['closing']['primary_url'] : get_post_type_archive_link( 'pll_unita' ) ); ?>"><?php echo esc_html( $ed['closing']['primary_label'] ? $ed['closing']['primary_label'] : __( 'Vedi le residenze', 'palladio' ) ); ?></a>
					<a class="pll-e-cta pll-e-cta--ghost" href="<?php echo esc_url( $dossier_url ? $dossier_url : home_url( '/' ) ); ?>"><?php echo esc_html( $dossier_label ); ?></a>
				</p>
			</div>
		</section>

	</div>
	<?php
endwhile;

get_footer();
