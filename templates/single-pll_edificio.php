<?php
/**
 * Template singolo — Edificio.
 *
 * Sovrascrivibile dal tema: {tema}/palladio/single-pll_edificio.php.
 * Il contenuto è renderizzato direttamente nel <main> del tema, che su
 * PoeTheme fornisce già container, larghezza e padding.
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

	$claim     = palladio_meta( $building_id, 'claim' );
	$indirizzo = palladio_meta( $building_id, 'indirizzo' );

	$facts = array();
	if ( $anno = palladio_meta( $building_id, 'anno_costruzione' ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
		$facts[] = array( __( 'Anno', 'palladio' ), (string) absint( $anno ) );
	}
	if ( $mq = palladio_meta( $building_id, 'mq_totali' ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
		$facts[] = array( __( 'Superficie totale', 'palladio' ), sprintf( __( '%s m²', 'palladio' ), number_format_i18n( (float) $mq, 0 ) ) );
	}
	if ( $piani = palladio_meta( $building_id, 'num_piani' ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
		$facts[] = array( __( 'Piani', 'palladio' ), (string) absint( $piani ) );
	}
	if ( $uv = palladio_meta( $building_id, 'num_unita_vendita' ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
		$facts[] = array( __( 'Unità in vendita', 'palladio' ), (string) absint( $uv ) );
	}
	?>
	<section class="palladio-single palladio-edificio">

		<?php if ( ! palladio_header_owns_title() ) : ?>
			<header class="palladio-edificio__header">
				<h1 class="palladio-edificio__title"><?php the_title(); ?></h1>
				<?php if ( $claim ) : ?>
					<p class="palladio-edificio__claim"><?php echo esc_html( $claim ); ?></p>
				<?php endif; ?>
			</header>
		<?php endif; ?>

		<div class="palladio-panel palladio-edificio__hero">
			<?php if ( has_post_thumbnail() ) : ?>
				<figure class="palladio-edificio__media">
					<?php the_post_thumbnail( 'large', array( 'loading' => 'eager' ) ); ?>
				</figure>
			<?php endif; ?>

			<div class="palladio-edificio__intro">
				<?php if ( $indirizzo ) : ?>
					<p class="palladio-edificio__address"><?php echo esc_html( $indirizzo ); ?></p>
				<?php endif; ?>

				<?php if ( $facts ) : ?>
					<dl class="palladio-facts">
						<?php foreach ( $facts as $fact ) : ?>
							<div class="palladio-facts__item">
								<dt><?php echo esc_html( $fact[0] ); ?></dt>
								<dd><?php echo esc_html( $fact[1] ); ?></dd>
							</div>
						<?php endforeach; ?>
					</dl>
				<?php endif; ?>
			</div>
		</div>

		<?php if ( get_the_content() ) : ?>
			<div class="palladio-panel palladio-content">
				<?php the_content(); ?>
			</div>
		<?php endif; ?>

		<?php
		$vincoli = palladio_meta( $building_id, 'vincoli_note' );
		if ( $vincoli ) :
			?>
			<div class="palladio-panel palladio-edificio__vincoli">
				<h2><?php esc_html_e( 'Vincoli e note legali', 'palladio' ); ?></h2>
				<p><?php echo esc_html( $vincoli ); ?></p>
			</div>
		<?php endif; ?>

		<?php
		$units = palladio_get_building_units( $building_id );
		if ( $units->have_posts() ) :
			?>
			<div class="palladio-units">
				<h2 class="palladio-units__heading"><?php esc_html_e( 'Le unità', 'palladio' ); ?></h2>

				<?php
				// Chip di filtro per stato (progressive enhancement: senza JS mostra tutto).
				$stati = get_terms(
					array(
						'taxonomy'   => 'pll_stato',
						'hide_empty' => true,
						'object_ids' => wp_list_pluck( $units->posts, 'ID' ),
					)
				);
				if ( ! is_wp_error( $stati ) && count( $stati ) > 1 ) :
					?>
					<div class="palladio-filters" data-palladio-filters role="group" aria-label="<?php esc_attr_e( 'Filtra le unità per stato', 'palladio' ); ?>">
						<button type="button" class="palladio-filters__chip is-active" data-filter="stato" data-value="*"><?php esc_html_e( 'Tutte', 'palladio' ); ?></button>
						<?php foreach ( $stati as $stato ) : ?>
							<button type="button" class="palladio-filters__chip" data-filter="stato" data-value="<?php echo esc_attr( $stato->slug ); ?>"><?php echo esc_html( $stato->name ); ?></button>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<div class="palladio-units-grid" data-palladio-grid>
					<?php
					while ( $units->have_posts() ) :
						$units->the_post();
						palladio_render_unit_card( get_the_ID() );
					endwhile;
					wp_reset_postdata();
					?>
				</div>
			</div>
		<?php endif; ?>

		<?php
		/**
		 * Punto di estensione dopo l'elenco unità (scenari, planimetrie, CTA…).
		 *
		 * @param int $building_id ID dell'edificio.
		 */
		do_action( 'palladio/edificio/after_units', $building_id );
		?>

	</section>
	<?php
endwhile;

get_footer();
