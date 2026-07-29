<?php
/**
 * Template archivio — Unità (elenco residenze, /unita/).
 *
 * Stile editoriale "Sambiasi", card condivise con la landing edificio.
 * Sovrascrivibile dal tema: {tema}/palladio/archive-pll_unita.php.
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<div class="palladio-editorial" id="palladio-units-archive">

	<section class="pll-e-section pll-e-wrap">
		<div class="pll-e-units-head" id="palladio-units-archive-head">
			<div>
				<p class="pll-e-kicker" id="palladio-units-archive-eyebrow"><?php esc_html_e( 'Le residenze', 'palladio' ); ?></p>
				<h1 class="pll-e-h" id="palladio-units-archive-title"><?php esc_html_e( 'Unità immobiliari in vendita', 'palladio' ); ?></h1>
			</div>
		</div>

		<?php if ( have_posts() ) : ?>

			<div class="pll-e-sisters" id="palladio-units-grid" data-palladio-units>
				<?php
				while ( have_posts() ) :
					the_post();
					palladio_render_unit_card_editorial( get_the_ID() );
				endwhile;
				?>
			</div>

			<div class="pll-e-archive-pagination" id="palladio-units-archive-pagination">
				<?php the_posts_pagination(); ?>
			</div>

		<?php else : ?>
			<p class="pll-e-prose palladio-empty"><?php esc_html_e( 'Nessuna unità pubblicata al momento.', 'palladio' ); ?></p>
		<?php endif; ?>
	</section>

	<?php $palladio_archive_scenarios = function_exists( 'palladio_get_scenarios' ) ? palladio_get_scenarios() : array(); ?>
	<?php if ( $palladio_archive_scenarios ) : ?>
	<section class="pll-e-section pll-e-wrap" id="palladio-units-archive-scenari">
		<div class="pll-e-units-head" id="palladio-units-archive-scenari-head">
			<div>
				<p class="pll-e-kicker" id="palladio-units-archive-scenari-eyebrow"><?php esc_html_e( 'Gli scenari', 'palladio' ); ?></p>
				<h2 class="pll-e-h" id="palladio-units-archive-scenari-title"><?php esc_html_e( 'Soluzioni e opportunità', 'palladio' ); ?></h2>
			</div>
			<p class="pll-e-prose pll-e-gallery-note"><?php esc_html_e( 'Più unità, un unico progetto abitativo o di business: i dati restano quelli delle unità, cambia solo il prezzo del pacchetto.', 'palladio' ); ?></p>
		</div>

		<div class="pll-e-sisters" id="palladio-units-archive-scenari-grid">
			<?php
			foreach ( $palladio_archive_scenarios as $palladio_scenario_id ) {
				palladio_render_scenario_card_editorial( $palladio_scenario_id );
			}
			?>
		</div>
	</section>
	<?php endif; ?>

</div>
<?php
get_footer();
