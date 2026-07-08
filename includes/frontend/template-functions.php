<?php
/**
 * Modulo Presenter — funzioni di presentazione condivise.
 *
 * Helper usati dai template (single/archivio) e dagli shortcode per
 * renderizzare unità, prezzi, stati e specifiche. Pensati per integrarsi
 * con qualsiasi tema; l'aspetto segue le variabili di palette di PoeTheme
 * quando presenti (vedi assets/css/palladio.css).
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verifica se il tema attivo è PoeTheme (o un suo child).
 *
 * @return bool
 */
function palladio_is_poetheme() {
	return function_exists( 'poetheme_get_layout_container_classes' )
		|| 'poetheme' === get_template();
}

/**
 * Indica se l'header del tema stampa già il titolo della pagina.
 *
 * Wrapper difensivo attorno alla funzione di PoeTheme: su altri temi
 * restituisce sempre false, così il template stampa il proprio H1.
 *
 * @return bool
 */
function palladio_header_owns_title() {
	if ( function_exists( 'poetheme_header_owns_page_title' ) ) {
		return (bool) poetheme_header_owns_page_title();
	}

	return false;
}

/**
 * Legge un meta del plugin (prefisso _pll_).
 *
 * @param int    $post_id ID del post.
 * @param string $key     Chiave meta senza prefisso (es. 'prezzo').
 * @return mixed
 */
function palladio_meta( $post_id, $key ) {
	$value = get_post_meta( $post_id, '_pll_' . $key, true );

	// Applica la traduzione del meta nella lingua corrente, se disponibile.
	if ( class_exists( 'Palladio_I18n_Translator' ) ) {
		$value = Palladio_I18n_Translator::translate_meta( $post_id, $key, $value );
	}

	return $value;
}

/**
 * Formatta un prezzo in stile italiano, o "su richiesta" se assente.
 *
 * @param mixed $value Prezzo grezzo.
 * @return string
 */
function palladio_format_price( $value ) {
	$value = is_numeric( $value ) ? (float) $value : 0.0;

	if ( $value <= 0 ) {
		return esc_html__( 'Prezzo su richiesta', 'palladio' );
	}

	/* translators: %s: prezzo formattato. */
	return sprintf( esc_html__( '€ %s', 'palladio' ), number_format_i18n( $value, 0 ) );
}

/**
 * Restituisce lo stato di vendita di un'unità.
 *
 * @param int $unit_id ID dell'unità.
 * @return array{slug:string,label:string} Slug e nome leggibile; slug vuoto se assente.
 */
function palladio_get_unit_status( $unit_id ) {
	$terms = get_the_terms( $unit_id, 'pll_stato' );

	if ( empty( $terms ) || is_wp_error( $terms ) ) {
		return array(
			'slug'  => '',
			'label' => '',
		);
	}

	$term = array_shift( $terms );

	return array(
		'slug'  => $term->slug,
		'label' => $term->name,
	);
}

/**
 * Stampa il badge dello stato di vendita.
 *
 * @param int $unit_id ID dell'unità.
 * @return void
 */
function palladio_status_badge( $unit_id ) {
	$status = palladio_get_unit_status( $unit_id );

	if ( '' === $status['slug'] ) {
		return;
	}

	printf(
		'<span class="palladio-badge palladio-badge--%1$s">%2$s</span>',
		esc_attr( $status['slug'] ),
		esc_html( $status['label'] )
	);
}

/**
 * Restituisce le specifiche sintetiche di un'unità (per le card).
 *
 * @param int $unit_id ID dell'unità.
 * @return array<int,array{label:string,value:string}>
 */
function palladio_unit_quick_specs( $unit_id ) {
	$specs = array();

	$mq = palladio_meta( $unit_id, 'mq_commerciali' );
	if ( $mq ) {
		$specs[] = array(
			'label' => __( 'Superficie', 'palladio' ),
			/* translators: %s: metri quadri. */
			'value' => sprintf( __( '%s m²', 'palladio' ), number_format_i18n( (float) $mq, 0 ) ),
		);
	}

	$camere = palladio_meta( $unit_id, 'camere' );
	if ( $camere ) {
		$specs[] = array(
			'label' => __( 'Camere', 'palladio' ),
			'value' => (string) absint( $camere ),
		);
	}

	$bagni = palladio_meta( $unit_id, 'bagni' );
	if ( $bagni ) {
		$specs[] = array(
			'label' => __( 'Bagni', 'palladio' ),
			'value' => (string) absint( $bagni ),
		);
	}

	$piani = get_the_terms( $unit_id, 'pll_piano' );
	if ( ! empty( $piani ) && ! is_wp_error( $piani ) ) {
		$piano   = array_shift( $piani );
		$specs[] = array(
			'label' => __( 'Piano', 'palladio' ),
			'value' => $piano->name,
		);
	}

	return $specs;
}

/**
 * Interroga le unità figlie di un edificio.
 *
 * @param int   $building_id ID dell'edificio.
 * @param array $args        Override per WP_Query.
 * @return WP_Query
 */
function palladio_get_building_units( $building_id, $args = array() ) {
	$defaults = array(
		'post_type'      => 'pll_unita',
		'post_parent'    => (int) $building_id,
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'orderby'        => array(
			'menu_order' => 'ASC',
			'title'      => 'ASC',
		),
		'no_found_rows'  => true,
	);

	return new WP_Query( wp_parse_args( $args, $defaults ) );
}

/**
 * Stampa la card di un'unità (riusata da template e shortcode).
 *
 * @param int $unit_id ID dell'unità.
 * @return void
 */
function palladio_render_unit_card( $unit_id ) {
	$status = palladio_get_unit_status( $unit_id );
	$piano  = get_the_terms( $unit_id, 'pll_piano' );
	$piano  = ( ! empty( $piano ) && ! is_wp_error( $piano ) ) ? $piano[0]->slug : '';
	$price  = palladio_meta( $unit_id, 'prezzo' );
	$specs  = palladio_unit_quick_specs( $unit_id );
	?>
	<article class="palladio-unit-card"
		data-stato="<?php echo esc_attr( $status['slug'] ); ?>"
		data-piano="<?php echo esc_attr( $piano ); ?>">
		<a class="palladio-unit-card__link" href="<?php echo esc_url( get_permalink( $unit_id ) ); ?>">
			<div class="palladio-unit-card__media">
				<?php if ( has_post_thumbnail( $unit_id ) ) : ?>
					<?php echo get_the_post_thumbnail( $unit_id, 'medium_large', array( 'loading' => 'lazy' ) ); ?>
				<?php else : ?>
					<span class="palladio-unit-card__placeholder" aria-hidden="true"></span>
				<?php endif; ?>
				<?php palladio_status_badge( $unit_id ); ?>
			</div>
			<div class="palladio-unit-card__body">
				<h3 class="palladio-unit-card__title"><?php echo esc_html( get_the_title( $unit_id ) ); ?></h3>
				<p class="palladio-unit-card__price"><?php echo esc_html( palladio_format_price( $price ) ); ?></p>
				<?php if ( $specs ) : ?>
					<ul class="palladio-unit-card__specs">
						<?php foreach ( $specs as $spec ) : ?>
							<li>
								<span class="palladio-unit-card__spec-value"><?php echo esc_html( $spec['value'] ); ?></span>
								<span class="palladio-unit-card__spec-label"><?php echo esc_html( $spec['label'] ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</a>
	</article>
	<?php
}
