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
				<h1 class="pll-e-h" id="palladio-units-archive-title"><?php post_type_archive_title(); ?></h1>
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

</div>
<?php
get_footer();
