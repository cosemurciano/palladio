<?php
/**
 * Template singolo — Unità.
 *
 * Sovrascrivibile dal tema: {tema}/palladio/single-pll_unita.php.
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

	// Tabella specifiche: chiave meta => [etichetta, formattatore].
	$rows = array(
		'mq_commerciali'     => array( __( 'Superficie commerciale', 'palladio' ), 'mq' ),
		'mq_coperti'         => array( __( 'Superficie coperta', 'palladio' ), 'mq' ),
		'vani'               => array( __( 'Vani', 'palladio' ), 'num' ),
		'camere'             => array( __( 'Camere', 'palladio' ), 'int' ),
		'bagni'              => array( __( 'Bagni', 'palladio' ), 'int' ),
		'esposizione'        => array( __( 'Esposizione', 'palladio' ), 'text' ),
		'classe_energetica'  => array( __( 'Classe energetica', 'palladio' ), 'text' ),
		'millesimi'          => array( __( 'Millesimi', 'palladio' ), 'num' ),
		'spese_condominiali' => array( __( 'Spese condominiali', 'palladio' ), 'euro' ),
		'terrazza_mq'        => array( __( 'Terrazza', 'palladio' ), 'mq' ),
		'giardino_mq'        => array( __( 'Giardino', 'palladio' ), 'mq' ),
		'stato_consegna'     => array( __( 'Stato di consegna', 'palladio' ), 'text' ),
		'destinazione_uso'   => array( __( 'Destinazione d’uso', 'palladio' ), 'text' ),
	);

	$format = static function ( $value, $type ) {
		switch ( $type ) {
			case 'mq':
				/* translators: %s: metri quadri. */
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
	?>
	<article id="unita-<?php echo esc_attr( $unit_id ); ?>" <?php post_class( 'palladio-single palladio-unita' ); ?> itemscope itemtype="https://schema.org/Product">

		<?php if ( ! palladio_header_owns_title() ) : ?>
			<header class="palladio-unita__header">
				<h1 class="palladio-unita__title" itemprop="name"><?php the_title(); ?></h1>
			</header>
		<?php endif; ?>

		<div class="palladio-unita__topline">
			<p class="palladio-price" itemprop="offers" itemscope itemtype="https://schema.org/Offer">
				<span itemprop="price" content="<?php echo esc_attr( is_numeric( $price ) ? (float) $price : '' ); ?>"><?php echo esc_html( palladio_format_price( $price ) ); ?></span>
				<meta itemprop="priceCurrency" content="EUR">
			</p>
			<?php palladio_status_badge( $unit_id ); ?>
			<?php if ( $building_id ) : ?>
				<a class="palladio-unita__building-link" href="<?php echo esc_url( get_permalink( $building_id ) ); ?>">
					<?php echo esc_html( get_the_title( $building_id ) ); ?>
				</a>
			<?php endif; ?>
		</div>

		<div class="palladio-unita__layout">
			<div class="palladio-unita__main">
				<?php if ( has_post_thumbnail() ) : ?>
					<figure class="palladio-panel palladio-unita__media">
						<?php the_post_thumbnail( 'large', array( 'itemprop' => 'image' ) ); ?>
					</figure>
				<?php endif; ?>

				<?php if ( get_the_content() ) : ?>
					<div class="palladio-panel palladio-content" itemprop="description">
						<?php the_content(); ?>
					</div>
				<?php endif; ?>

				<?php
				$tour  = palladio_meta( $unit_id, 'virtual_tour_url' );
				$video = palladio_meta( $unit_id, 'video_url' );
				if ( $tour || $video ) :
					?>
					<div class="palladio-unita__media-links">
						<?php if ( $tour ) : ?>
							<a class="palladio-cta palladio-cta--ghost" href="<?php echo esc_url( $tour ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Virtual tour', 'palladio' ); ?></a>
						<?php endif; ?>
						<?php if ( $video ) : ?>
							<a class="palladio-cta palladio-cta--ghost" href="<?php echo esc_url( $video ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Video', 'palladio' ); ?></a>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>

			<aside class="palladio-unita__aside">
				<div class="palladio-panel palladio-specs">
					<h2 class="palladio-specs__heading"><?php esc_html_e( 'Caratteristiche', 'palladio' ); ?></h2>
					<dl class="palladio-specs__list">
						<?php foreach ( $rows as $key => $def ) : ?>
							<?php
							$value = palladio_meta( $unit_id, $key );
							if ( '' === $value || null === $value || 0 === $value || '0' === $value ) {
								continue;
							}
							?>
							<div class="palladio-specs__row">
								<dt><?php echo esc_html( $def[0] ); ?></dt>
								<dd><?php echo esc_html( $format( $value, $def[1] ) ); ?></dd>
							</div>
						<?php endforeach; ?>
					</dl>
				</div>

				<?php
				// CTA: contatta l'agenzia/regia dell'edificio (fallback: admin).
				$email = $building_id ? palladio_meta( $building_id, 'contatto_email' ) : '';
				if ( ! is_email( $email ) ) {
					$email = get_option( 'admin_email' );
				}
				$subject = rawurlencode( sprintf( __( 'Richiesta informazioni: %s', 'palladio' ), get_the_title( $unit_id ) ) );
				?>
				<div class="palladio-panel palladio-unita__contact">
					<h2><?php esc_html_e( 'Interessato a questa unità?', 'palladio' ); ?></h2>
					<p><?php esc_html_e( 'Richiedi informazioni o prenota una visita.', 'palladio' ); ?></p>
					<a class="palladio-cta" href="<?php echo esc_url( 'mailto:' . antispambot( $email ) . '?subject=' . $subject ); ?>">
						<?php esc_html_e( 'Richiedi informazioni', 'palladio' ); ?>
					</a>
					<?php
					/**
					 * Punto di innesto per il form lead / widget agent (Fase 1).
					 *
					 * @param int $unit_id ID dell'unità.
					 */
					do_action( 'palladio/unita/after_contact', $unit_id );
					?>
				</div>
			</aside>
		</div>

		<?php
		// Altre unità dello stesso edificio.
		if ( $building_id ) :
			$siblings = palladio_get_building_units(
				$building_id,
				array(
					'post__not_in'   => array( $unit_id ),
					'posts_per_page' => 3,
				)
			);
			if ( $siblings->have_posts() ) :
				?>
				<div class="palladio-units palladio-unita__siblings">
					<h2 class="palladio-units__heading"><?php esc_html_e( 'Altre unità dell’edificio', 'palladio' ); ?></h2>
					<div class="palladio-units-grid">
						<?php
						while ( $siblings->have_posts() ) :
							$siblings->the_post();
							palladio_render_unit_card( get_the_ID() );
						endwhile;
						wp_reset_postdata();
						?>
					</div>
				</div>
				<?php
			endif;
		endif;
		?>

	</article>
	<?php
endwhile;

get_footer();
