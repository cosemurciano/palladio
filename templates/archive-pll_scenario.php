<?php
/**
 * Template archivio — Scenari (pagina dedicata /scenario/).
 *
 * Elenco delle soluzioni componibili in stile editoriale, card distinte
 * dalle unità. Sovrascrivibile: {tema}/palladio/archive-pll_scenario.php.
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<div class="palladio-editorial" id="palladio-scenari-archive">

	<section class="pll-e-section pll-e-wrap">
		<div class="pll-e-units-head" id="palladio-scenari-archive-head">
			<div>
				<p class="pll-e-kicker" id="palladio-scenari-archive-eyebrow"><?php esc_html_e( 'Gli scenari', 'palladio' ); ?></p>
				<h1 class="pll-e-h" id="palladio-scenari-archive-title"><?php esc_html_e( 'Soluzioni e opportunità', 'palladio' ); ?></h1>
			</div>
			<p class="pll-e-prose pll-e-gallery-note"><?php esc_html_e( 'Più unità, un unico progetto abitativo o di business: i dati restano quelli delle unità, cambia solo il prezzo del pacchetto.', 'palladio' ); ?></p>
		</div>

		<?php if ( have_posts() ) : ?>

			<div class="pll-e-sisters" id="palladio-scenari-grid">
				<?php
				while ( have_posts() ) :
					the_post();
					palladio_render_scenario_card_editorial( get_the_ID() );
				endwhile;
				?>
			</div>

			<div class="pll-e-archive-pagination" id="palladio-scenari-archive-pagination">
				<?php the_posts_pagination(); ?>
			</div>

		<?php else : ?>
			<p class="pll-e-prose palladio-empty"><?php esc_html_e( 'Nessuno scenario pubblicato al momento.', 'palladio' ); ?></p>
		<?php endif; ?>
	</section>

</div>
<?php
get_footer();
