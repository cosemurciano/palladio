<?php
/**
 * Template singolo — Scenario (soluzione che aggrega più unità).
 *
 * Layout affine alla scheda unità ma graficamente distinto: i dati delle
 * unità non cambiano, lo scenario li aggrega e propone un prezzo totale;
 * il risparmio rispetto alla somma è messo in evidenza. Il testo descrittivo
 * è il contenuto classico del post.
 * Sovrascrivibile: {tema}/palladio/single-pll_scenario.php.
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

while ( have_posts() ) :
	the_post();
	$scenario_id = get_the_ID();
	$totals      = palladio_scenario_totals( $scenario_id );
	$stato       = (string) get_post_meta( $scenario_id, '_pll_scenario_stato', true );

	$hero = get_the_post_thumbnail_url( $scenario_id, 'full' );
	if ( ! $hero && $totals['units'] ) {
		$hero = get_the_post_thumbnail_url( $totals['units'][0], 'full' );
	}

	$facts = array();
	if ( $totals['count'] ) {
		$facts[] = array( number_format_i18n( $totals['count'] ), __( 'Unità', 'palladio' ) );
	}
	if ( $totals['mq'] > 0 ) {
		$facts[] = array( number_format_i18n( $totals['mq'], 0 ) . ' m²', __( 'Superficie totale', 'palladio' ) );
	}
	if ( $totals['camere'] > 0 ) {
		$facts[] = array( number_format_i18n( $totals['camere'] ), __( 'Camere', 'palladio' ) );
	}
	if ( $totals['bagni'] > 0 ) {
		$facts[] = array( number_format_i18n( $totals['bagni'] ), __( 'Bagni', 'palladio' ) );
	}
	?>
	<div class="palladio-editorial palladio-scenario-editorial">

		<header class="pll-e-hero pll-e-scenario-hero" id="palladio-scenario-hero">
			<?php if ( $hero ) : ?>
				<img class="pll-e-hero__img" src="<?php echo esc_url( $hero ); ?>" alt="">
			<?php endif; ?>
			<div class="pll-e-hero__inner">
				<p class="pll-e-eyebrow" id="palladio-scenario-eyebrow">
					<?php esc_html_e( 'Scenario', 'palladio' ); ?>
					<?php if ( $totals['count'] ) : ?>
						· <?php /* translators: %s: numero unità. */ printf( esc_html( _n( '%s unità', '%s unità', $totals['count'], 'palladio' ) ), esc_html( number_format_i18n( $totals['count'] ) ) ); ?>
					<?php endif; ?>
				</p>
				<h1 class="pll-e-hero__title" id="palladio-scenario-title"><?php the_title(); ?></h1>
				<?php if ( has_excerpt() ) : ?>
					<p class="pll-e-hero__lead" id="palladio-scenario-lead"><?php echo esc_html( get_the_excerpt() ); ?></p>
				<?php endif; ?>
			</div>
		</header>

		<div class="pll-e-sticky">
			<div class="pll-e-sticky__inner">
				<div class="pll-e-sticky__facts">
					<span class="pll-e-sticky__price">
						<?php if ( $totals['saving'] > 0 ) : ?>
							<s class="pll-e-scenario-card__sum"><?php echo esc_html( palladio_format_price( $totals['sum'] ) ); ?></s>
						<?php endif; ?>
						<?php echo esc_html( palladio_format_price( $totals['price'] ) ); ?>
					</span>
					<?php if ( $totals['saving'] > 0 ) : ?>
						<span class="pll-e-scenario-saving" id="palladio-scenario-risparmio">
							<?php
							/* translators: 1: risparmio, 2: percentuale. */
							printf( esc_html__( 'Risparmi %1$s (−%2$s%%)', 'palladio' ), esc_html( palladio_format_price( $totals['saving'] ) ), esc_html( number_format_i18n( $totals['saving_pct'] ) ) );
							?>
						</span>
					<?php endif; ?>
					<?php foreach ( $facts as $fact ) : ?>
						<span class="pll-e-fact"><b><?php echo esc_html( $fact[0] ); ?></b><span><?php echo esc_html( $fact[1] ); ?></span></span>
					<?php endforeach; ?>
					<?php if ( 'non_disponibile' === $stato ) : ?>
						<span class="palladio-badge palladio-badge--non_in_vendita" style="position:static;"><?php esc_html_e( 'Non disponibile', 'palladio' ); ?></span>
					<?php endif; ?>
				</div>
				<a class="pll-e-cta" href="#palladio-contact"><?php esc_html_e( 'Richiedi una visita', 'palladio' ); ?></a>
			</div>
		</div>

		<?php // Testo descrittivo: contenuto classico del post. ?>
		<?php if ( get_the_content() ) : ?>
			<section class="pll-e-section pll-e-wrap">
				<div class="pll-e-prose" id="palladio-scenario-testo"><?php the_content(); ?></div>
			</section>
		<?php endif; ?>

		<?php // Dati aggregati delle unità selezionate. ?>
		<?php if ( $totals['count'] ) : ?>
			<section class="pll-e-tech">
				<div class="pll-e-wrap pll-e-section" id="palladio-scenario-dati">
					<p class="pll-e-kicker"><?php esc_html_e( 'Lo scenario in numeri', 'palladio' ); ?></p>
					<h2 class="pll-e-h"><?php esc_html_e( 'I dati, aggregati', 'palladio' ); ?></h2>
					<dl class="pll-e-tech__grid">
						<div class="pll-e-tech__row"><dt><?php esc_html_e( 'Unità comprese', 'palladio' ); ?></dt><dd><?php echo esc_html( number_format_i18n( $totals['count'] ) ); ?></dd></div>
						<?php if ( $totals['mq'] > 0 ) : ?>
							<div class="pll-e-tech__row"><dt><?php esc_html_e( 'Superficie complessiva', 'palladio' ); ?></dt><dd><?php echo esc_html( number_format_i18n( $totals['mq'], 0 ) ); ?> m²</dd></div>
						<?php endif; ?>
						<?php if ( $totals['camere'] > 0 ) : ?>
							<div class="pll-e-tech__row"><dt><?php esc_html_e( 'Camere totali', 'palladio' ); ?></dt><dd><?php echo esc_html( number_format_i18n( $totals['camere'] ) ); ?></dd></div>
						<?php endif; ?>
						<?php if ( $totals['bagni'] > 0 ) : ?>
							<div class="pll-e-tech__row"><dt><?php esc_html_e( 'Bagni totali', 'palladio' ); ?></dt><dd><?php echo esc_html( number_format_i18n( $totals['bagni'] ) ); ?></dd></div>
						<?php endif; ?>
						<?php if ( $totals['saving'] > 0 ) : ?>
							<div class="pll-e-tech__row"><dt><?php esc_html_e( 'Somma prezzi unità', 'palladio' ); ?></dt><dd><s><?php echo esc_html( palladio_format_price( $totals['sum'] ) ); ?></s></dd></div>
						<?php endif; ?>
						<div class="pll-e-tech__row"><dt><?php esc_html_e( 'Prezzo dello scenario', 'palladio' ); ?></dt><dd><?php echo esc_html( palladio_format_price( $totals['price'] ) ); ?></dd></div>
						<?php if ( $totals['saving'] > 0 ) : ?>
							<div class="pll-e-tech__row pll-e-tech__row--saving"><dt><?php esc_html_e( 'Il tuo vantaggio', 'palladio' ); ?></dt><dd><?php echo esc_html( palladio_format_price( $totals['saving'] ) ); ?> <small>(−<?php echo esc_html( number_format_i18n( $totals['saving_pct'] ) ); ?>%)</small></dd></div>
						<?php endif; ?>
					</dl>
				</div>
			</section>
		<?php endif; ?>

		<?php // Le unità che compongono lo scenario. ?>
		<?php if ( $totals['units'] ) : ?>
			<section class="pll-e-section pll-e-wrap" id="palladio-scenario-unita">
				<p class="pll-e-kicker"><?php esc_html_e( 'La composizione', 'palladio' ); ?></p>
				<h2 class="pll-e-h"><?php esc_html_e( 'Le unità dello scenario', 'palladio' ); ?></h2>
				<div class="pll-e-sisters" id="palladio-scenario-unita-grid">
					<?php foreach ( $totals['units'] as $uid ) : ?>
						<?php palladio_render_unit_card_editorial( $uid ); ?>
					<?php endforeach; ?>
				</div>
			</section>
		<?php endif; ?>

	</div>
	<?php
endwhile;

get_footer();
