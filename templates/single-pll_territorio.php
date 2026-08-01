<?php
/**
 * Template — Pagina "Il Territorio" (Lecce e Salento).
 *
 * Hero, mappa "tavola" con squadrette oro e chip dei luoghi, la città a due
 * colonne con lista di prossimità, contatori con count-up al reveal, i mercati
 * in schede con icona lineare, galleria fotografica e chiusura con CTA doppia.
 * Ogni campo è editabile dal metabox "Palladio — Contenuti della scheda".
 * Sovrascrivibile dal tema: {tema}/palladio/single-pll_territorio.php.
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

while ( have_posts() ) :
	the_post();
	$territorio_id = get_the_ID();
	$ed            = palladio_editorial( $territorio_id );
	$hero          = get_the_post_thumbnail_url( $territorio_id, 'palladio-hero' );
	$lead          = $ed['lead'] ? $ed['lead'] : get_the_excerpt();

	$dossier_label = class_exists( 'Palladio_Admin_Settings' ) ? Palladio_Admin_Settings::get( 'dossier_label' ) : __( 'Richiedi una visita', 'palladio' );
	$dossier_url   = class_exists( 'Palladio_Admin_Settings' ) ? Palladio_Admin_Settings::get( 'dossier_url' ) : '';
	?>
	<div class="palladio-editorial palladio-territorio-editorial">

		<header class="pll-e-hero pll-e-territorio-hero" id="palladio-territorio-hero">
			<?php if ( $hero ) : ?>
				<img class="pll-e-hero__img" src="<?php echo esc_url( $hero ); ?>" alt="" fetchpriority="high">
			<?php endif; ?>
			<div class="pll-e-hero__inner">
				<?php if ( $ed['eyebrow'] ) : ?>
					<p class="pll-e-eyebrow" id="palladio-territorio-eyebrow"><?php echo esc_html( $ed['eyebrow'] ); ?></p>
				<?php endif; ?>
				<h1 class="pll-e-hero__title" id="palladio-territorio-title"><?php the_title(); ?></h1>
				<?php if ( $lead ) : ?>
					<p class="pll-e-hero__lead" id="palladio-territorio-lead"><?php echo esc_html( $lead ); ?></p>
				<?php endif; ?>
				<p><a class="pll-e-cta" href="#palladio-contact"><?php echo esc_html( $dossier_label ); ?></a></p>
			</div>
		</header>

		<?php if ( get_the_content() ) : ?>
			<section class="pll-e-section pll-e-wrap"><div class="pll-e-prose" id="palladio-territorio-intro"><?php the_content(); ?></div></section>
		<?php endif; ?>

		<?php // LA POSIZIONE — mappa come tavola d'archivio. ?>
		<?php
		$map_img = palladio_image_url( $ed['map_image'], 'palladio-hero' );
		if ( $ed['map_embed'] || $map_img ) :
			?>
			<section class="pll-e-section pll-e-wrap" id="palladio-territorio-mappa">
				<p class="pll-e-kicker" id="palladio-territorio-mappa-eyebrow"><?php echo esc_html( $ed['map_eyebrow'] ? $ed['map_eyebrow'] : __( 'La posizione', 'palladio' ) ); ?></p>
				<h2 class="pll-e-h" id="palladio-territorio-mappa-titolo"><?php echo esc_html( $ed['map_heading'] ? $ed['map_heading'] : __( 'Nel cuore del centro storico', 'palladio' ) ); ?></h2>
				<div class="pll-e-map pll-reveal" id="palladio-territorio-mappa-tavola">
					<span class="pll-e-map__corner pll-e-map__corner--tl" aria-hidden="true"></span>
					<span class="pll-e-map__corner pll-e-map__corner--tr" aria-hidden="true"></span>
					<span class="pll-e-map__corner pll-e-map__corner--bl" aria-hidden="true"></span>
					<span class="pll-e-map__corner pll-e-map__corner--br" aria-hidden="true"></span>
					<div class="pll-e-map__canvas">
						<?php if ( $ed['map_embed'] ) : ?>
							<iframe id="palladio-territorio-mappa-embed" src="<?php echo esc_url( $ed['map_embed'] ); ?>" loading="lazy" allowfullscreen referrerpolicy="no-referrer-when-downgrade" title="<?php esc_attr_e( 'Mappa della posizione', 'palladio' ); ?>"></iframe>
						<?php else : ?>
							<img id="palladio-territorio-mappa-immagine" src="<?php echo esc_url( $map_img ); ?>" alt="<?php echo esc_attr( $ed['map_label'] ); ?>" loading="lazy">
						<?php endif; ?>
					</div>
					<?php if ( $ed['map_label'] ) : ?>
						<p class="pll-e-map__pin" id="palladio-territorio-mappa-pin"><span class="pll-e-map__pin-dot" aria-hidden="true"></span><?php echo esc_html( $ed['map_label'] ); ?></p>
					<?php endif; ?>
				</div>
				<?php if ( $ed['map_pois'] ) : ?>
					<ul class="pll-e-map__pois" id="palladio-territorio-mappa-luoghi">
						<?php foreach ( array_values( $ed['map_pois'] ) as $i => $poi ) : ?>
							<?php if ( ! empty( $poi['label'] ) ) : ?>
								<li class="pll-e-map__poi" id="palladio-territorio-mappa-luogo-<?php echo esc_attr( $i + 1 ); ?>"><?php echo esc_html( $poi['label'] ); ?></li>
							<?php endif; ?>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</section>
		<?php endif; ?>

		<?php // LA CITTÀ — due colonne: racconto + prossimità | fotografia. ?>
		<?php
		$city_img  = palladio_image_url( $ed['city_image'], 'large' );
		$city_full = palladio_image_url( $ed['city_image'], 'full' );
		if ( $ed['city_text'] || $ed['proximity'] || $city_img ) :
			?>
			<section class="pll-e-tech">
				<div class="pll-e-wrap pll-e-section" id="palladio-territorio-citta" data-pll-lightbox-group>
					<p class="pll-e-kicker" id="palladio-territorio-citta-eyebrow"><?php echo esc_html( $ed['city_eyebrow'] ? $ed['city_eyebrow'] : __( 'La città', 'palladio' ) ); ?></p>
					<h2 class="pll-e-h" id="palladio-territorio-citta-titolo"><?php echo esc_html( $ed['city_heading'] ? $ed['city_heading'] : __( 'Lecce, la Firenze del Sud', 'palladio' ) ); ?></h2>
					<div class="pll-e-city">
						<div class="pll-e-city__col" id="palladio-territorio-citta-racconto">
							<?php if ( $ed['city_text'] ) : ?>
								<div class="pll-e-prose" id="palladio-territorio-citta-testo"><?php echo wp_kses_post( wpautop( $ed['city_text'] ) ); ?></div>
							<?php endif; ?>
							<?php if ( $ed['proximity'] ) : ?>
								<ul class="pll-e-proximity" id="palladio-territorio-prossimita">
									<?php foreach ( array_values( $ed['proximity'] ) as $i => $row ) : ?>
										<li class="pll-e-proximity__row pll-reveal" id="palladio-territorio-prossimita-<?php echo esc_attr( $i + 1 ); ?>">
											<span class="pll-e-proximity__value"><?php echo esc_html( $row['value'] ?? '' ); ?></span>
											<span class="pll-e-proximity__label"><?php echo esc_html( $row['label'] ?? '' ); ?></span>
										</li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
							<?php if ( $ed['city_paradox'] ) : ?>
								<p class="pll-e-city__paradox" id="palladio-territorio-citta-paradosso"><?php echo esc_html( $ed['city_paradox'] ); ?></p>
							<?php endif; ?>
						</div>
						<?php if ( $city_img ) : ?>
							<figure class="pll-e-city__media pll-reveal" id="palladio-territorio-citta-foto">
								<a class="pll-e-gallery__zoom" href="<?php echo esc_url( $city_full ? $city_full : $city_img ); ?>"
									data-pll-lightbox="<?php echo esc_url( $city_full ? $city_full : $city_img ); ?>"
									data-pll-caption="<?php echo esc_attr( $ed['city_caption'] ); ?>"
									aria-label="<?php esc_attr_e( 'Ingrandisci immagine', 'palladio' ); ?>">
									<img src="<?php echo esc_url( $city_img ); ?>" alt="<?php echo esc_attr( $ed['city_caption'] ); ?>" loading="lazy">
								</a>
								<?php if ( $ed['city_caption'] ) : ?><figcaption><?php echo esc_html( $ed['city_caption'] ); ?></figcaption><?php endif; ?>
							</figure>
						<?php endif; ?>
					</div>
				</div>
			</section>
		<?php endif; ?>

		<?php // I NUMERI — contatori con count-up al reveal. ?>
		<?php if ( $ed['stats'] ) : ?>
			<section class="pll-e-section pll-e-wrap" id="palladio-territorio-numeri">
				<p class="pll-e-kicker" id="palladio-territorio-numeri-eyebrow"><?php echo esc_html( $ed['stats_eyebrow'] ? $ed['stats_eyebrow'] : __( 'Un territorio in crescita', 'palladio' ) ); ?></p>
				<h2 class="pll-e-h" id="palladio-territorio-numeri-titolo"><?php echo esc_html( $ed['stats_heading'] ? $ed['stats_heading'] : __( 'Il Salento, destinazione in piena espansione', 'palladio' ) ); ?></h2>
				<div class="pll-e-stats">
					<?php foreach ( array_values( $ed['stats'] ) as $i => $stat ) : ?>
						<div class="pll-e-stats__cell pll-reveal" id="palladio-territorio-numero-<?php echo esc_attr( $i + 1 ); ?>">
							<span class="pll-e-stats__value" data-pll-count="<?php echo esc_attr( $stat['value'] ?? '' ); ?>"><?php echo esc_html( $stat['value'] ?? '' ); ?></span>
							<span class="pll-e-stats__label"><?php echo esc_html( $stat['label'] ?? '' ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
				<?php if ( $ed['stats_source'] ) : ?>
					<p class="pll-e-stats__source" id="palladio-territorio-numeri-fonte"><?php echo esc_html( $ed['stats_source'] ); ?></p>
				<?php endif; ?>
			</section>
		<?php endif; ?>

		<?php // I MERCATI — schede con icona lineare selezionabile. ?>
		<?php if ( $ed['markets'] ) : ?>
			<section class="pll-e-tech">
				<div class="pll-e-wrap pll-e-section" id="palladio-territorio-mercati">
					<p class="pll-e-kicker" id="palladio-territorio-mercati-eyebrow"><?php echo esc_html( $ed['markets_eyebrow'] ? $ed['markets_eyebrow'] : __( 'Un territorio, molti mercati', 'palladio' ) ); ?></p>
					<h2 class="pll-e-h" id="palladio-territorio-mercati-titolo"><?php echo esc_html( $ed['markets_heading'] ? $ed['markets_heading'] : __( 'Cinque ragioni per investire qui', 'palladio' ) ); ?></h2>
					<div class="pll-e-markets">
						<?php foreach ( array_values( $ed['markets'] ) as $i => $market ) : ?>
							<article class="pll-e-markets__card pll-reveal" id="palladio-territorio-mercato-<?php echo esc_attr( $i + 1 ); ?>">
								<span class="pll-e-markets__icon" aria-hidden="true"><?php echo palladio_territory_icon_svg( $market['icon'] ?? '' ); // phpcs:ignore WordPress.Security.EscapeOutput -- SVG interno di libreria. ?></span>
								<?php if ( ! empty( $market['title'] ) ) : ?><h3 class="pll-e-markets__title"><?php echo esc_html( $market['title'] ); ?></h3><?php endif; ?>
								<?php if ( ! empty( $market['text'] ) ) : ?><p class="pll-e-markets__text"><?php echo esc_html( $market['text'] ); ?></p><?php endif; ?>
							</article>
						<?php endforeach; ?>
					</div>
				</div>
			</section>
		<?php endif; ?>

		<?php // GALLERIA — dopo "Un territorio, molti mercati". ?>
		<?php if ( $ed['gallery'] ) : ?>
			<section class="pll-e-section pll-e-wrap" id="palladio-territorio-galleria">
				<?php palladio_render_gallery( $ed['gallery'], $ed['gallery_layout'], 'palladio-territorio-gallery' ); ?>
			</section>
		<?php endif; ?>

		<?php // CHIUSURA — investire o vivere, qui. ?>
		<section class="pll-e-section pll-e-closing" id="palladio-territorio-chiusura">
			<div class="pll-e-wrap">
				<p class="pll-e-kicker" id="palladio-territorio-chiusura-eyebrow"><?php echo esc_html( $ed['closing']['kicker'] ? $ed['closing']['kicker'] : __( 'Investire o vivere, qui', 'palladio' ) ); ?></p>
				<h2 class="pll-e-closing__heading" id="palladio-territorio-chiusura-titolo">
					<?php echo esc_html( $ed['closing']['heading'] ? $ed['closing']['heading'] : __( 'Un valore che cresce,', 'palladio' ) ); ?>
					<em><?php echo esc_html( $ed['closing']['emphasis'] ? $ed['closing']['emphasis'] : __( 'una vita che rallenta.', 'palladio' ) ); ?></em>
				</h2>
				<?php if ( $ed['closing']['text'] ) : ?>
					<p class="pll-e-closing__text" id="palladio-territorio-chiusura-testo"><?php echo esc_html( $ed['closing']['text'] ); ?></p>
				<?php endif; ?>
				<p class="pll-e-closing__actions">
					<a class="pll-e-cta" href="<?php echo esc_url( $ed['closing']['primary_url'] ? $ed['closing']['primary_url'] : get_post_type_archive_link( 'pll_unita' ) ); ?>"><?php echo esc_html( $ed['closing']['primary_label'] ? $ed['closing']['primary_label'] : __( 'Vedi le residenze', 'palladio' ) ); ?></a>
					<a class="pll-e-cta pll-e-cta--ghost" href="<?php echo esc_url( $dossier_url ? $dossier_url : '#palladio-contact' ); ?>"><?php echo esc_html( $dossier_label ); ?></a>
				</p>
			</div>
		</section>

	</div>
	<?php
endwhile;

get_footer();
