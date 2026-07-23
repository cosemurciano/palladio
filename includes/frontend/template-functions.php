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
		// Layout automatico della galleria: masonry|grid|mosaic|filmstrip|offset.
		'gallery_layout'  => 'masonry',
		// Campi specifici della landing Edificio.
		'ambient'         => array( 'image' => 0, 'caption' => '' ), // legacy singola immagine
		'ambient_images'  => array(), // [ {image,caption} ] — loop multi-immagine
		'manifesto'       => array(), // [ {text,emphasis} ]
		'timeline'        => array(), // [ {kicker,year,heading,body,image} ]
		'gallery_url'     => '',
		'gallery_count'   => '',
		'units_eyebrow'   => '',
		'units_heading'   => '',
		'units_filters'   => false,
		// Campi della pagina "La Storia" (pll_storia).
		'heraldry'         => array(), // [ {initial,image,name,blazon,note} ]
		'heraldry_eyebrow' => '',
		'heraldry_heading' => '',
		'glossary'         => array(), // [ {image,caption,term,sub,definition} ]
		'glossary_eyebrow' => '',
		'glossary_heading' => '',
		'glossary_text'    => '',
		'closing'          => array( 'kicker' => '', 'heading' => '', 'emphasis' => '', 'primary_label' => '', 'primary_url' => '' ),
	);

	$data              = wp_parse_args( $data, $defaults );
	$data['floorplan'] = wp_parse_args( is_array( $data['floorplan'] ) ? $data['floorplan'] : array(), $defaults['floorplan'] );
	$data['position']  = wp_parse_args( is_array( $data['position'] ) ? $data['position'] : array(), $defaults['position'] );
	$data['ambient']   = wp_parse_args( is_array( $data['ambient'] ) ? $data['ambient'] : array(), $defaults['ambient'] );
	$data['closing']   = wp_parse_args( is_array( $data['closing'] ) ? $data['closing'] : array(), $defaults['closing'] );
	$data['units_filters'] = ! empty( $data['units_filters'] );

	if ( ! in_array( $data['gallery_layout'], array( 'masonry', 'grid', 'mosaic', 'filmstrip', 'offset' ), true ) ) {
		$data['gallery_layout'] = 'masonry';
	}

	foreach ( array( 'chapters', 'narrative', 'tech', 'gallery', 'manifesto', 'timeline', 'ambient_images', 'heraldry', 'glossary' ) as $rep ) {
		if ( ! is_array( $data[ $rep ] ) ) {
			$data[ $rep ] = array();
		}
	}

	// Compat: la vecchia immagine ambient singola confluisce nel loop.
	if ( ! $data['ambient_images'] && ! empty( $data['ambient']['image'] ) ) {
		$data['ambient_images'] = array(
			array(
				'image'   => (int) $data['ambient']['image'],
				'caption' => (string) $data['ambient']['caption'],
			),
		);
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

/**
 * Card unità in stile editoriale (griglia "residenze" e archivio unità).
 *
 * Ogni elemento ha un id stabile (palladio-unit-{ID}, -media, -eyebrow,
 * -title, -excerpt, -price, -status) per la personalizzazione CSS manuale.
 *
 * @param int $unit_id ID unità.
 * @return void
 */
function palladio_render_unit_card_editorial( $unit_id ) {
	$unit_id = (int) $unit_id;
	$status  = palladio_get_unit_status( $unit_id );
	$thumb   = get_the_post_thumbnail_url( $unit_id, 'large' );
	$eyebrow = palladio_unit_eyebrow( $unit_id );
	$excerpt = has_excerpt( $unit_id ) ? get_the_excerpt( $unit_id ) : '';
	$price   = (float) palladio_meta( $unit_id, 'prezzo' );
	$piano   = get_the_terms( $unit_id, 'pll_piano' );
	$piano   = ( ! empty( $piano ) && ! is_wp_error( $piano ) ) ? $piano[0]->slug : '';
	$outside = ( (float) palladio_meta( $unit_id, 'terrazza_mq' ) > 0 || (float) palladio_meta( $unit_id, 'giardino_mq' ) > 0 ) ? '1' : '0';
	$shot    = wp_get_attachment_caption( get_post_thumbnail_id( $unit_id ) );
	?>
	<a class="pll-e-sister pll-e-unit-card" id="palladio-unit-<?php echo esc_attr( $unit_id ); ?>"
		href="<?php echo esc_url( get_permalink( $unit_id ) ); ?>"
		data-piano="<?php echo esc_attr( $piano ); ?>"
		data-prezzo="<?php echo esc_attr( $price ); ?>"
		data-esterno="<?php echo esc_attr( $outside ); ?>">
		<span class="pll-e-sister__media" id="palladio-unit-<?php echo esc_attr( $unit_id ); ?>-media">
			<?php if ( $thumb ) : ?>
				<img src="<?php echo esc_url( $thumb ); ?>" alt="" loading="lazy">
			<?php endif; ?>
			<?php if ( $status['label'] ) : ?>
				<span class="palladio-badge palladio-badge--<?php echo esc_attr( $status['slug'] ); ?>" id="palladio-unit-<?php echo esc_attr( $unit_id ); ?>-status"><?php echo esc_html( $status['label'] ); ?></span>
			<?php endif; ?>
			<?php if ( $shot ) : ?>
				<span class="pll-e-unit-card__shot"><?php echo esc_html( $shot ); ?></span>
			<?php endif; ?>
		</span>
		<span class="pll-e-sister__body">
			<?php if ( $eyebrow ) : ?>
				<span class="pll-e-sister__eyebrow" id="palladio-unit-<?php echo esc_attr( $unit_id ); ?>-eyebrow"><?php echo esc_html( $eyebrow ); ?></span>
			<?php endif; ?>
			<span class="pll-e-sister__title" id="palladio-unit-<?php echo esc_attr( $unit_id ); ?>-title"><?php echo esc_html( get_the_title( $unit_id ) ); ?></span>
			<?php if ( $excerpt ) : ?>
				<span class="pll-e-sister__desc" id="palladio-unit-<?php echo esc_attr( $unit_id ); ?>-excerpt"><?php echo esc_html( wp_trim_words( $excerpt, 26 ) ); ?></span>
			<?php endif; ?>
			<span class="pll-e-sister__price" id="palladio-unit-<?php echo esc_attr( $unit_id ); ?>-price"><?php echo esc_html( palladio_format_price( $price ) ); ?></span>
		</span>
	</a>
	<?php
}

/**
 * Galleria editoriale con layout automatico e lightbox.
 *
 * Layout: masonry (colonne a incastro), grid (celle uniformi), mosaic (una
 * foto grande ogni cinque), filmstrip (pellicola orizzontale a scorrimento),
 * offset (due colonne a quote alternate). Il formato immagine è automatico.
 *
 * @param array  $shots  Righe galleria [{image,caption}].
 * @param string $layout Layout scelto.
 * @param string $id     ID CSS del contenitore.
 * @return void
 */
function palladio_render_gallery( $shots, $layout = 'masonry', $id = 'palladio-gallery' ) {
	if ( ! is_array( $shots ) || ! $shots ) {
		return;
	}
	if ( ! in_array( $layout, array( 'masonry', 'grid', 'mosaic', 'filmstrip', 'offset' ), true ) ) {
		$layout = 'masonry';
	}
	?>
	<div class="pll-e-gallery pll-e-gallery--<?php echo esc_attr( $layout ); ?>" id="<?php echo esc_attr( $id ); ?>" data-pll-lightbox-group>
		<?php foreach ( $shots as $i => $shot ) : ?>
			<?php
			$gi = palladio_image_url( $shot['image'] ?? 0, 'large' );
			if ( ! $gi ) {
				continue;
			}
			$gfull   = palladio_image_url( $shot['image'], 'full' );
			$caption = (string) ( $shot['caption'] ?? '' );
			?>
			<figure class="pll-e-gallery__item" id="<?php echo esc_attr( $id . '-item-' . ( $i + 1 ) ); ?>">
				<a class="pll-e-gallery__zoom" href="<?php echo esc_url( $gfull ? $gfull : $gi ); ?>"
					data-pll-lightbox="<?php echo esc_url( $gfull ? $gfull : $gi ); ?>"
					data-pll-caption="<?php echo esc_attr( $caption ); ?>"
					aria-label="<?php esc_attr_e( 'Ingrandisci immagine', 'palladio' ); ?>">
					<img src="<?php echo esc_url( $gi ); ?>" alt="<?php echo esc_attr( $caption ); ?>" loading="lazy">
				</a>
				<?php if ( '' !== $caption ) : ?><figcaption><?php echo esc_html( $caption ); ?></figcaption><?php endif; ?>
			</figure>
		<?php endforeach; ?>
	</div>
	<?php
}

/**
 * Unità che compongono uno scenario (solo unità valide e pubblicate).
 *
 * @param int $scenario_id ID scenario.
 * @return int[] ID unità.
 */
function palladio_scenario_units( $scenario_id ) {
	$ids = json_decode( (string) get_post_meta( $scenario_id, '_pll_scenario_unita', true ), true );
	$ids = is_array( $ids ) ? array_map( 'absint', $ids ) : array();

	return array_values( array_filter( $ids, static function ( $id ) {
		return 'pll_unita' === get_post_type( $id ) && 'publish' === get_post_status( $id );
	} ) );
}

/**
 * Dati aggregati di uno scenario: somme delle unità, prezzo totale, risparmio.
 *
 * I dati delle unità non cambiano: lo scenario li aggrega e propone un
 * prezzo pacchetto; il risparmio è la differenza rispetto alla somma.
 *
 * @param int $scenario_id ID scenario.
 * @return array{units:int[],count:int,sum:float,price:float,saving:float,saving_pct:float,mq:float,camere:int,bagni:int}
 */
function palladio_scenario_totals( $scenario_id ) {
	$units  = palladio_scenario_units( $scenario_id );
	$sum    = 0.0;
	$mq     = 0.0;
	$camere = 0;
	$bagni  = 0;

	foreach ( $units as $unit_id ) {
		$sum    += (float) get_post_meta( $unit_id, '_pll_prezzo', true );
		$mq     += (float) get_post_meta( $unit_id, '_pll_mq_commerciali', true );
		$camere += (int) get_post_meta( $unit_id, '_pll_camere', true );
		$bagni  += (int) get_post_meta( $unit_id, '_pll_bagni', true );
	}

	$price  = (float) get_post_meta( $scenario_id, '_pll_scenario_prezzo', true );
	$saving = ( $price > 0 && $sum > $price ) ? $sum - $price : 0.0;

	return array(
		'units'      => $units,
		'count'      => count( $units ),
		'sum'        => $sum,
		'price'      => $price > 0 ? $price : $sum,
		'saving'     => $saving,
		'saving_pct' => ( $saving > 0 && $sum > 0 ) ? round( $saving / $sum * 100 ) : 0.0,
		'mq'         => $mq,
		'camere'     => $camere,
		'bagni'      => $bagni,
	);
}

/**
 * Scenari pubblicati, opzionalmente filtrati per edificio o unità inclusa.
 *
 * @param int $building_id Limita agli scenari con unità di questo edificio.
 * @param int $unit_id     Limita agli scenari che includono questa unità.
 * @return int[] ID scenari.
 */
function palladio_get_scenarios( $building_id = 0, $unit_id = 0 ) {
	$scenarios = get_posts( array(
		'post_type'      => 'pll_scenario',
		'post_status'    => 'publish',
		'posts_per_page' => 20,
		'orderby'        => 'menu_order title',
		'order'          => 'ASC',
		'fields'         => 'ids',
		'no_found_rows'  => true,
	) );

	if ( ! $building_id && ! $unit_id ) {
		return $scenarios;
	}

	return array_values( array_filter( $scenarios, static function ( $sid ) use ( $building_id, $unit_id ) {
		$units = palladio_scenario_units( $sid );
		if ( ! $units ) {
			return false;
		}
		if ( $unit_id ) {
			return in_array( (int) $unit_id, $units, true );
		}
		foreach ( $units as $uid ) {
			if ( (int) wp_get_post_parent_id( $uid ) === (int) $building_id ) {
				return true;
			}
		}
		return false;
	} ) );
}

/**
 * Card scenario in stile editoriale: come la card unità ma graficamente
 * distinta (doppia cornice, nastro "Scenario", prezzo con risparmio).
 *
 * @param int $scenario_id ID scenario.
 * @return void
 */
function palladio_render_scenario_card_editorial( $scenario_id ) {
	$scenario_id = (int) $scenario_id;
	$totals      = palladio_scenario_totals( $scenario_id );
	$thumb       = get_the_post_thumbnail_url( $scenario_id, 'large' );

	// Senza immagine propria usa quella della prima unità inclusa.
	if ( ! $thumb && $totals['units'] ) {
		$thumb = get_the_post_thumbnail_url( $totals['units'][0], 'large' );
	}

	$stato   = (string) get_post_meta( $scenario_id, '_pll_scenario_stato', true );
	$excerpt = has_excerpt( $scenario_id ) ? get_the_excerpt( $scenario_id ) : '';

	$eyebrow_parts = array();
	if ( $totals['count'] ) {
		/* translators: %s: numero unità. */
		$eyebrow_parts[] = sprintf( _n( '%s unità', '%s unità', $totals['count'], 'palladio' ), number_format_i18n( $totals['count'] ) );
	}
	if ( $totals['mq'] > 0 ) {
		$eyebrow_parts[] = number_format_i18n( $totals['mq'], 0 ) . ' m²';
	}
	if ( $totals['camere'] > 0 ) {
		/* translators: %s: numero camere. */
		$eyebrow_parts[] = sprintf( __( '%s camere', 'palladio' ), number_format_i18n( $totals['camere'] ) );
	}
	?>
	<a class="pll-e-sister pll-e-scenario-card" id="palladio-scenario-<?php echo esc_attr( $scenario_id ); ?>"
		href="<?php echo esc_url( get_permalink( $scenario_id ) ); ?>">
		<span class="pll-e-sister__media" id="palladio-scenario-<?php echo esc_attr( $scenario_id ); ?>-media">
			<?php if ( $thumb ) : ?>
				<img src="<?php echo esc_url( $thumb ); ?>" alt="" loading="lazy">
			<?php endif; ?>
			<span class="pll-e-scenario-card__ribbon"><?php esc_html_e( 'Scenario', 'palladio' ); ?></span>
			<?php if ( 'non_disponibile' === $stato ) : ?>
				<span class="palladio-badge palladio-badge--non_in_vendita"><?php esc_html_e( 'Non disponibile', 'palladio' ); ?></span>
			<?php endif; ?>
		</span>
		<span class="pll-e-sister__body">
			<?php if ( $eyebrow_parts ) : ?>
				<span class="pll-e-sister__eyebrow" id="palladio-scenario-<?php echo esc_attr( $scenario_id ); ?>-eyebrow"><?php echo esc_html( implode( ' · ', $eyebrow_parts ) ); ?></span>
			<?php endif; ?>
			<span class="pll-e-sister__title" id="palladio-scenario-<?php echo esc_attr( $scenario_id ); ?>-title"><?php echo esc_html( get_the_title( $scenario_id ) ); ?></span>
			<?php if ( $excerpt ) : ?>
				<span class="pll-e-sister__desc" id="palladio-scenario-<?php echo esc_attr( $scenario_id ); ?>-excerpt"><?php echo esc_html( wp_trim_words( $excerpt, 26 ) ); ?></span>
			<?php endif; ?>
			<span class="pll-e-sister__price pll-e-scenario-card__price" id="palladio-scenario-<?php echo esc_attr( $scenario_id ); ?>-price">
				<?php if ( $totals['saving'] > 0 ) : ?>
					<s class="pll-e-scenario-card__sum"><?php echo esc_html( palladio_format_price( $totals['sum'] ) ); ?></s>
				<?php endif; ?>
				<?php echo esc_html( palladio_format_price( $totals['price'] ) ); ?>
				<?php if ( $totals['saving'] > 0 ) : ?>
					<span class="pll-e-scenario-card__saving">
						<?php
						/* translators: 1: risparmio, 2: percentuale. */
						printf( esc_html__( 'Risparmi %1$s (−%2$s%%)', 'palladio' ), esc_html( palladio_format_price( $totals['saving'] ) ), esc_html( number_format_i18n( $totals['saving_pct'] ) ) );
						?>
					</span>
				<?php endif; ?>
			</span>
		</span>
	</a>
	<?php
}
