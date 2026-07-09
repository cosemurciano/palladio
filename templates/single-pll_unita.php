<?php
/**
 * Template singolo — Unità (direzione visiva editoriale "Sambiasi").
 *
 * Sovrascrivibile dal tema: {tema}/palladio/single-pll_unita.php.
 * Recupera i dati con gli helper del Presenter; lo stile vive in
 * assets/css/palladio-editorial.css.
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

	// Fatti chiave per la barra sticky.
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

	// Scheda tecnica completa.
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

	$hero = get_the_post_thumbnail_url( $unit_id, 'full' );
	?>
	<div class="palladio-editorial palladio-unita-editorial" itemscope itemtype="https://schema.org/Product">

		<header class="pll-e-hero">
			<?php if ( $hero ) : ?>
				<img class="pll-e-hero__img" src="<?php echo esc_url( $hero ); ?>" alt="" itemprop="image">
			<?php endif; ?>
			<div class="pll-e-hero__inner">
				<p class="pll-e-eyebrow">
					<?php
					echo esc_html( trim( ( $building_id ? get_the_title( $building_id ) : '' ) . ( $piano ? ' · ' . $piano : '' ), ' ·' ) );
					?>
					<?php echo do_shortcode( '[palladio_lang_switcher]' ); ?>
				</p>
				<h1 class="pll-e-hero__title" itemprop="name"><?php the_title(); ?></h1>
				<?php if ( has_excerpt() ) : ?>
					<p class="pll-e-hero__lead"><?php echo esc_html( get_the_excerpt() ); ?></p>
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
				<a class="pll-e-cta" href="#dossier"><?php esc_html_e( 'Richiedi una visita', 'palladio' ); ?></a>
			</div>
		</div>

		<?php if ( get_the_content() ) : ?>
			<section class="pll-e-section pll-e-wrap">
				<div class="pll-e-prose" itemprop="description"><?php the_content(); ?></div>
			</section>
		<?php endif; ?>

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
						<div class="pll-e-tech__row">
							<dt><?php echo esc_html( $def[0] ); ?></dt>
							<dd><?php echo esc_html( $fmt( $value, $def[1] ) ); ?></dd>
						</div>
					<?php endforeach; ?>
					<div class="pll-e-tech__row">
						<dt><?php esc_html_e( 'Prezzo', 'palladio' ); ?></dt>
						<dd><?php echo esc_html( palladio_format_price( $price ) ); ?></dd>
					</div>
				</dl>
			</div>
		</section>

		<?php
		$tour = palladio_meta( $unit_id, 'virtual_tour_url' );
		if ( $tour ) :
			?>
			<section class="pll-e-tour">
				<div class="pll-e-tour__inner">
					<p class="pll-e-kicker"><?php esc_html_e( 'Virtual tour 3D', 'palladio' ); ?></p>
					<h2 class="pll-e-h"><?php esc_html_e( 'Cammina nell’unità', 'palladio' ); ?></h2>
					<a class="pll-e-cta" href="<?php echo esc_url( $tour ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Avvia il virtual tour', 'palladio' ); ?></a>
				</div>
			</section>
		<?php endif; ?>

		<?php if ( $building_id ) : ?>
			<section class="pll-e-section pll-e-wrap">
				<p class="pll-e-kicker"><?php esc_html_e( 'Posizione nell’edificio', 'palladio' ); ?></p>
				<h2 class="pll-e-h"><?php echo esc_html( get_the_title( $building_id ) ); ?></h2>
				<p class="pll-e-prose"><a class="pll-e-cta pll-e-cta--ghost" href="<?php echo esc_url( get_permalink( $building_id ) ); ?>"><?php esc_html_e( 'Esplora l’edificio', 'palladio' ); ?></a></p>
			</section>

			<?php
			$siblings = palladio_get_building_units(
				$building_id,
				array(
					'post__not_in'   => array( $unit_id ),
					'posts_per_page' => 3,
				)
			);
			if ( $siblings->have_posts() ) :
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
								<span class="pll-e-sister__media">
									<?php if ( $sthumb ) : ?><img src="<?php echo esc_url( $sthumb ); ?>" alt="" loading="lazy"><?php endif; ?>
								</span>
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

		<section class="pll-e-dossier" id="dossier">
			<div class="pll-e-wrap pll-e-section">
				<p class="pll-e-kicker"><?php esc_html_e( 'Il dossier', 'palladio' ); ?></p>
				<h2 class="pll-e-h"><?php esc_html_e( 'Tutto, per iscritto', 'palladio' ); ?></h2>
				<p class="pll-e-prose"><?php esc_html_e( 'Planimetrie quotate, prezzi, millesimi e note sul vincolo. Lascia i tuoi contatti: nessuna telefonata se non la chiedi tu.', 'palladio' ); ?></p>
				<?php
				/** Il form lead viene iniettato qui dal modulo Regia. */
				do_action( 'palladio/unita/after_contact', $unit_id );
				?>
			</div>
		</section>

	</div>
	<?php
endwhile;

get_footer();
