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
	// Nel modello a pagine clone ogni post è nativo nella propria lingua:
	// il meta si legge direttamente, senza risoluzione di traduzione.
	return get_post_meta( $post_id, '_pll_' . $key, true );
}

/**
 * Struttura editoriale del post (campi strutturati del template Sambiasi).
 *
 * Ritorna sempre l'array completo con i default, così i template possono
 * iterare senza controlli difensivi.
 *
 * @param int $post_id ID post.
 * @return array{eyebrow:string,lead:string,walkthrough_url:string,chapters:array,narrative:array,tech:array,gallery:array,floorplan:array,position:array}
 */
function palladio_editorial( $post_id ) {
	$raw  = get_post_meta( $post_id, '_pll_editorial', true );
	$data = is_array( $raw ) ? $raw : array();

	$defaults = array(
		'eyebrow'         => '',
		'lead'            => '',
		'walkthrough_url' => '',
		'chapters'        => array(), // [ {time,label} ]  (unità)
		'narrative'       => array(), // [ {kicker,heading,body,image,caption,layout} ]
		'tech'            => array(), // [ {label,value} ]  (unità)
		'gallery'         => array(), // [ {image,caption,ratio} ]
		'floorplan'       => array( 'image' => 0, 'caption' => '', 'notes' => '' ), // unità
		'position'        => array( 'heading' => '', 'text' => '' ), // unità
		// Campi specifici della landing Edificio.
		'ambient'         => array( 'image' => 0, 'caption' => '' ),
		'manifesto'       => array(), // [ {text,emphasis} ]
		'timeline'        => array(), // [ {kicker,year,heading,body,image} ]
		'gallery_url'     => '',
		'gallery_count'   => '',
		'units_eyebrow'   => '',
		'units_heading'   => '',
		'units_filters'   => false,
	);

	$data              = wp_parse_args( $data, $defaults );
	$data['floorplan'] = wp_parse_args( is_array( $data['floorplan'] ) ? $data['floorplan'] : array(), $defaults['floorplan'] );
	$data['position']  = wp_parse_args( is_array( $data['position'] ) ? $data['position'] : array(), $defaults['position'] );
	$data['ambient']   = wp_parse_args( is_array( $data['ambient'] ) ? $data['ambient'] : array(), $defaults['ambient'] );
	$data['units_filters'] = ! empty( $data['units_filters'] );

	foreach ( array( 'chapters', 'narrative', 'tech', 'gallery', 'manifesto', 'timeline' ) as $rep ) {
		if ( ! is_array( $data[ $rep ] ) ) {
			$data[ $rep ] = array();
		}
	}

	return $data;
}

/**
 * Fascia prezzi delle unità in vendita di un edificio.
 *
 * @param int $building_id ID edificio.
 * @return array{min:float,max:float,count:int}
 */
function palladio_units_price_range( $building_id ) {
	$units = palladio_get_building_units(
		$building_id,
		array(
			'tax_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'taxonomy' => 'pll_stato',
					'field'    => 'slug',
					'terms'    => array( 'disponibile', 'riservata', 'in_trattativa' ),
				),
			),
		)
	);

	$prices = array();
	foreach ( $units->posts as $post ) {
		$p = (float) get_post_meta( $post->ID, '_pll_prezzo', true );
		if ( $p > 0 ) {
			$prices[] = $p;
		}
	}
	wp_reset_postdata();

	return array(
		'min'   => $prices ? min( $prices ) : 0.0,
		'max'   => $prices ? max( $prices ) : 0.0,
		'count' => (int) $units->post_count,
	);
}

/**
 * Occhiello di una card unità: piano · superficie · codice.
 *
 * @param int $unit_id ID unità.
 * @return string
 */
function palladio_unit_eyebrow( $unit_id ) {
	$parts = array();

	$piano = get_the_terms( $unit_id, 'pll_piano' );
	if ( ! empty( $piano ) && ! is_wp_error( $piano ) ) {
		$parts[] = $piano[0]->name;
	}
	$mq = palladio_meta( $unit_id, 'mq_commerciali' );
	if ( $mq ) {
		$parts[] = number_format_i18n( (float) $mq, 0 ) . ' m²';
	}
	$codice = palladio_meta( $unit_id, 'codice' );
	if ( $codice ) {
		$parts[] = $codice;
	}

	return implode( ' · ', $parts );
}

/**
 * Restituisce l'URL immagine da un attachment id, con dimensione.
 *
 * @param int    $id   Attachment ID.
 * @param string $size Dimensione.
 * @return string
 */
function palladio_image_url( $id, $size = 'large' ) {
	$id = absint( $id );
	if ( ! $id ) {
		return '';
	}
	$url = wp_get_attachment_image_url( $id, $size );
	return $url ? $url : '';
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
