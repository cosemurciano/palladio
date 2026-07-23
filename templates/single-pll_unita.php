<?php
/**
 * Template singolo — Unità (direzione visiva editoriale "Sambiasi").
 *
 * Legge i campi strutturati (`palladio_editorial()`) popolati dal metabox
 * "Contenuti della scheda"; ricade sul contenuto libero solo dove i campi
 * dedicati sono vuoti. Sovrascrivibile: {tema}/palladio/single-pll_unita.php.
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

while ( have_posts() ) :
	the_post();
	$unit_id     = get_the_ID();
	$building_id = wp_get_post_parent_id( $unit_id );
	$price       = palladio_meta( $unit_id, 'prezzo' );
	$status      = palladio_get_unit_status( $unit_id );
	$piano       = get_the_terms( $unit_id, 'pll_piano' );
	$piano       = ( ! empty( $piano ) && ! is_wp_error( $piano ) ) ? $piano[0]->name : '';
	$ed          = palladio_editorial( $unit_id );

	$facts = array();
	if ( $mq = palladio_meta( $unit_id, 'mq_commerciali' ) ) { // phpcs:ignore
		$facts[] = array( number_format_i18n( (float) $mq, 0 ) . ' m²', __( 'Superficie', 'palladio' ) );
	}
	if ( $camere = palladio_meta( $unit_id, 'camere' ) ) { // phpcs:ignore
		$facts[] = array( (string) absint( $camere ), __( 'Camere', 'palladio' ) );
	}
	if ( $bagni = palladio_meta( $unit_id, 'bagni' ) ) { // phpcs:ignore
		$facts[] = array( (string) absint( $bagni ), __( 'Bagni', 'palladio' ) );
	}
	if ( $status['label'] ) {
		$facts[] = array( $status['label'], __( 'Stato', 'palladio' ) );
	}

	$tech = array(
		'mq_commerciali'     => array( __( 'Superficie commerciale', 'palladio' ), 'mq' ),
		'mq_coperti'         => array( __( 'Superficie coperta', 'palladio' ), 'mq' ),
		'terrazza_mq'        => array( __( 'Terrazze', 'palladio' ), 'mq' ),
		'giardino_mq'        => array( __( 'Giardino', 'palladio' ), 'mq' ),
		'vani'               => array( __( 'Stanze', 'palladio' ), 'num' ),
		'camere'             => array( __( 'Camere', 'palladio' ), 'int' ),
		'bagni'              => array( __( 'Bagni', 'palladio' ), 'int' ),
		'esposizione'        => array( __( 'Esposizione', 'palladio' ), 'text' ),
		'classe_energetica'  => array( __( 'Classe energetica', 'palladio' ), 'text' ),
		'millesimi'          => array( __( 'Millesimi', 'palladio' ), 'num' ),
		'spese_condominiali' => array( __( 'Spese condominiali', 'palladio' ), 'euro' ),
		'stato_consegna'     => array( __( 'Consegna', 'palladio' ), 'text' ),
		'destinazione_uso'   => array( __( 'Uso attuale', 'palladio' ), 'text' ),
	);
	$fmt = static function ( $value, $type ) {
		switch ( $type ) {
			case 'mq':
				return sprintf( __( '%s m²', 'palladio' ), number_format_i18n( (float) $value, 0 ) );
			case 'euro':
				return palladio_format_price( $value );
			case 'int':
				return (string) absint( $value );
			case 'num':
				return number_format_i18n( (float) $value, ( (float) $value == (int) $value ) ? 0 : 1 );
			default:
				return (string) $value;
		}
	};

	$hero    = get_the_post_thumbnail_url( $unit_id, 'full' );
	$eyebrow = $ed['eyebrow'] ? $ed['eyebrow'] : trim( ( $building_id ? get_the_title( $building_id ) : '' ) . ( $piano ? ' · ' . $piano : '' ), ' ·' );
	$lead    = $ed['lead'] ? $ed['lead'] : ( has_excerpt() ? get_the_excerpt() : '' );
	?>
	<div class="palladio-editorial palladio-unita-editorial" itemscope itemtype="https://schema.org/Product">

		<header class="pll-e-hero">
			<?php if ( $hero ) : ?>
				<img class="pll-e-hero__img" src="<?php echo esc_url( $hero ); ?>" alt="" itemprop="image">
			<?php endif; ?>
			<div class="pll-e-hero__inner">
				<p class="pll-e-eyebrow"><?php echo esc_html( $eyebrow ); ?></p>
				<h1 class="pll-e-hero__title" itemprop="name"><?php the_title(); ?></h1>
				<?php if ( $lead ) : ?>
					<p class="pll-e-hero__lead"><?php echo esc_html( $lead ); ?></p>
				<?php endif; ?>
				<?php if ( $ed['walkthrough_url'] ) : ?>
					<p><a class="pll-e-cta pll-e-cta--ghost" href="<?php echo esc_url( $ed['walkthrough_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Guarda il walkthrough', 'palladio' ); ?></a></p>
				<?php endif; ?>
			</div>
		</header>

		<div class="pll-e-sticky">
			<div class="pll-e-sticky__inner">
				<div class="pll-e-sticky__facts">
					<span class="pll-e-sticky__price" itemprop="offers" itemscope itemtype="https://schema.org/Offer">
						<span itemprop="price" content="<?php echo esc_attr( is_numeric( $price ) ? (float) $price : '' ); ?>"><?php echo esc_html( palladio_format_price( $price ) ); ?></span>
						<meta itemprop="priceCurrency" content="EUR">
					</span>
					<?php foreach ( $facts as $fact ) : ?>
						<span class="pll-e-fact"><b><?php echo esc_html( $fact[0] ); ?></b><span><?php echo esc_html( $fact[1] ); ?></span></span>
					<?php endforeach; ?>
				</div>
				<a class="pll-e-cta" href="#palladio-contact"><?php esc_html_e( 'Richiedi una visita', 'palladio' ); ?></a>
			</div>
		</div>

		<?php if ( get_the_content() ) : ?>
			<section class="pll-e-section pll-e-wrap">
				<div class="pll-e-prose" itemprop="description"><?php the_content(); ?></div>
			</section>
		<?php endif; ?>

		<?php // Narrazione asimmetrica (blocchi strutturati). ?>
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
						<figure class="pll-e-narrative__media">
							<img src="<?php echo esc_url( $img ); ?>" alt="" loading="lazy">
							<?php if ( ! empty( $block['caption'] ) ) : ?><figcaption class="pll-e-sister__eyebrow"><?php echo esc_html( $block['caption'] ); ?></figcaption><?php endif; ?>
						</figure>
					<?php endif; ?>
				</div>
			</section>
		<?php endforeach; ?>

		<?php // Scheda tecnica: dati strutturati fissi + voci personalizzate. ?>
		<section class="pll-e-tech">
			<div class="pll-e-wrap pll-e-section">
				<p class="pll-e-kicker"><?php esc_html_e( 'Scheda tecnica', 'palladio' ); ?></p>
				<h2 class="pll-e-h"><?php esc_html_e( 'I dati, con precisione', 'palladio' ); ?></h2>
				<dl class="pll-e-tech__grid">
					<?php foreach ( $tech as $key => $def ) : ?>
						<?php
						$value = palladio_meta( $unit_id, $key );
						if ( '' === $value || null === $value || '0' === (string) $value ) {
							continue;
						}
						?>
						<div class="pll-e-tech__row"><dt><?php echo esc_html( $def[0] ); ?></dt><dd><?php echo esc_html( $fmt( $value, $def[1] ) ); ?></dd></div>
					<?php endforeach; ?>
					<?php foreach ( $ed['tech'] as $row ) : ?>
						<div class="pll-e-tech__row"><dt><?php echo esc_html( $row['label'] ?? '' ); ?></dt><dd><?php echo esc_html( $row['value'] ?? '' ); ?></dd></div>
					<?php endforeach; ?>
					<div class="pll-e-tech__row"><dt><?php esc_html_e( 'Prezzo', 'palladio' ); ?></dt><dd><?php echo esc_html( palladio_format_price( $price ) ); ?></dd></div>
				</dl>
			</div>
		</section>

		<?php // Walkthrough con capitoli. ?>
		<?php if ( $ed['walkthrough_url'] || $ed['chapters'] ) : ?>
			<section class="pll-e-tour">
				<div class="pll-e-tour__inner">
					<p class="pll-e-kicker"><?php esc_html_e( 'Il film dell’unità', 'palladio' ); ?></p>
					<h2 class="pll-e-h"><?php esc_html_e( 'Cammina nell’unità', 'palladio' ); ?></h2>
					<?php if ( $ed['chapters'] ) : ?>
						<p>
							<?php foreach ( $ed['chapters'] as $ch ) : ?>
								<span class="pll-e-fact" style="margin:0 .6rem;"><b><?php echo esc_html( $ch['time'] ?? '' ); ?></b><span><?php echo esc_html( $ch['label'] ?? '' ); ?></span></span>
							<?php endforeach; ?>
						</p>
					<?php endif; ?>
					<?php if ( $ed['walkthrough_url'] ) : ?>
						<a class="pll-e-cta" href="<?php echo esc_url( $ed['walkthrough_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Avvia il virtual tour', 'palladio' ); ?></a>
					<?php endif; ?>
				</div>
			</section>
		<?php endif; ?>

		<?php // Galleria. ?>
		<?php if ( $ed['gallery'] ) : ?>
			<section class="pll-e-section pll-e-wrap">
				<p class="pll-e-kicker"><?php esc_html_e( 'Galleria', 'palladio' ); ?></p>
				<h2 class="pll-e-h"><?php esc_html_e( 'In luce', 'palladio' ); ?></h2>
				<?php palladio_render_gallery( $ed['gallery'], $ed['gallery_layout'], 'palladio-unit-gallery' ); ?>
			</section>
		<?php endif; ?>

		<?php // Planimetria (dopo la galleria fotografica). ?>
		<?php $fp = palladio_image_url( $ed['floorplan']['image'], 'full' ); ?>
		<?php if ( $fp || $ed['floorplan']['notes'] ) : ?>
			<section class="pll-e-section pll-e-wrap" id="palladio-floorplan">
				<p class="pll-e-kicker"><?php esc_html_e( 'Planimetria', 'palladio' ); ?></p>
				<h2 class="pll-e-h"><?php echo esc_html( $ed['floorplan']['caption'] ? $ed['floorplan']['caption'] : __( 'La pianta, quotata', 'palladio' ) ); ?></h2>
				<?php if ( $fp ) : ?><figure class="pll-e-narrative__media"><img src="<?php echo esc_url( $fp ); ?>" alt="" loading="lazy"></figure><?php endif; ?>
				<?php if ( $ed['floorplan']['notes'] ) : ?><p class="pll-e-prose"><?php echo esc_html( $ed['floorplan']['notes'] ); ?></p><?php endif; ?>
			</section>
		<?php endif; ?>

		<?php // Posizione nell'edificio. ?>
		<?php if ( $building_id || $ed['position']['text'] ) : ?>
			<section class="pll-e-section pll-e-wrap">
				<p class="pll-e-kicker"><?php esc_html_e( 'Posizione nell’edificio', 'palladio' ); ?></p>
				<h2 class="pll-e-h"><?php echo esc_html( $ed['position']['heading'] ? $ed['position']['heading'] : ( $building_id ? get_the_title( $building_id ) : '' ) ); ?></h2>
				<?php if ( $ed['position']['text'] ) : ?><p class="pll-e-prose"><?php echo esc_html( $ed['position']['text'] ); ?></p><?php endif; ?>
				<?php if ( $building_id ) : ?><p><a class="pll-e-cta pll-e-cta--ghost" href="<?php echo esc_url( get_permalink( $building_id ) ); ?>"><?php esc_html_e( 'Esplora l’edificio', 'palladio' ); ?></a></p><?php endif; ?>
			</section>

			<?php
			$siblings = $building_id ? palladio_get_building_units( $building_id, array( 'post__not_in' => array( $unit_id ), 'posts_per_page' => 3 ) ) : null;
			if ( $siblings && $siblings->have_posts() ) :
				?>
				<section class="pll-e-section pll-e-wrap">
					<p class="pll-e-kicker"><?php esc_html_e( 'Unità sorelle', 'palladio' ); ?></p>
					<h2 class="pll-e-h"><?php esc_html_e( 'Nella stessa storia', 'palladio' ); ?></h2>
					<div class="pll-e-sisters">
						<?php
						while ( $siblings->have_posts() ) :
							$siblings->the_post();
							$sid    = get_the_ID();
							$sstat  = palladio_get_unit_status( $sid );
							$sthumb = get_the_post_thumbnail_url( $sid, 'medium_large' );
							?>
							<a class="pll-e-sister" href="<?php the_permalink(); ?>">
								<span class="pll-e-sister__media"><?php if ( $sthumb ) : ?><img src="<?php echo esc_url( $sthumb ); ?>" alt="" loading="lazy"><?php endif; ?></span>
								<span class="pll-e-sister__body">
									<?php if ( $sstat['label'] ) : ?><span class="pll-e-sister__eyebrow"><?php echo esc_html( $sstat['label'] ); ?></span><?php endif; ?>
									<span class="pll-e-sister__title"><?php echo esc_html( get_the_title( $sid ) ); ?></span>
									<span class="pll-e-sister__price"><?php echo esc_html( palladio_format_price( palladio_meta( $sid, 'prezzo' ) ) ); ?></span>
								</span>
							</a>
						<?php endwhile; wp_reset_postdata(); ?>
					</div>
				</section>
			<?php endif; ?>
		<?php endif; ?>

		<?php // SCENARI che includono questa unità. ?>
		<?php $scenari = palladio_get_scenarios( 0, $unit_id ); ?>
		<?php if ( $scenari ) : ?>
			<section class="pll-e-section pll-e-wrap" id="palladio-scenari">
				<p class="pll-e-kicker" id="palladio-scenari-eyebrow"><?php esc_html_e( 'Gli scenari', 'palladio' ); ?></p>
				<h2 class="pll-e-h" id="palladio-scenari-titolo"><?php esc_html_e( 'Questa unità fa parte di', 'palladio' ); ?></h2>
				<div class="pll-e-sisters" id="palladio-scenari-grid">
					<?php foreach ( $scenari as $sid ) : ?>
						<?php palladio_render_scenario_card_editorial( $sid ); ?>
					<?php endforeach; ?>
				</div>
			</section>
		<?php endif; ?>

		<section class="pll-e-dossier" id="dossier">
			<div class="pll-e-wrap pll-e-section">
				<p class="pll-e-kicker"><?php esc_html_e( 'Il dossier', 'palladio' ); ?></p>
				<h2 class="pll-e-h"><?php esc_html_e( 'Tutto, per iscritto', 'palladio' ); ?></h2>
				<p class="pll-e-prose"><?php esc_html_e( 'Planimetrie quotate, prezzi, millesimi e note sul vincolo. Lascia i tuoi contatti: nessuna telefonata se non la chiedi tu.', 'palladio' ); ?></p>
				<?php do_action( 'palladio/unita/after_contact', $unit_id ); ?>
			</div>
		</section>

	</div>
	<?php
endwhile;

get_footer();
