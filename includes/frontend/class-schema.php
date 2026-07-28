<?php
/**
 * Modulo Presenter — dati strutturati schema.org (JSON-LD) e meta tag.
 *
 * Il tema (PoeTheme) pubblica il grafo di base del sito: WebSite, publisher
 * (Organization/LocalBusiness/Person) e BreadcrumbList. Questo modulo lo
 * COMPLETA con le entità del dominio immobiliare che solo il plugin conosce:
 *
 *  - Edificio  -> RealEstateListing + ApartmentComplex (indirizzo, geo, unità)
 *  - Unità     -> RealEstateListing + Apartment/Accommodation + Offer
 *  - Scenario  -> RealEstateListing + Offer aggregata (itemOffered = unità)
 *  - Storia    -> AboutPage (about = edificio)
 *  - Territorio-> WebPage (about = Place)
 *  - Archivi   -> ItemList delle schede pubblicate
 *
 * Sulle stesse viste emette anche meta description e Open Graph/Twitter Card
 * (solo se non è attivo un plugin SEO), utili a Google e ai crawler AI.
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Emette JSON-LD e meta tag sulle viste Palladio.
 */
class Palladio_Frontend_Schema {

	/**
	 * Registra gli hook.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_head', array( $this, 'meta_tags' ), 4 );
		add_action( 'wp_head', array( $this, 'output_jsonld' ), 30 );
	}

	/**
	 * La richiesta corrente è una vista Palladio?
	 *
	 * @return bool
	 */
	private function is_palladio_view() {
		if ( is_singular( array( 'pll_edificio', 'pll_unita', 'pll_scenario', 'pll_storia', 'pll_territorio' ) ) ) {
			return true;
		}
		if ( is_post_type_archive( array( 'pll_edificio', 'pll_unita', 'pll_scenario' ) ) ) {
			return true;
		}
		if ( is_front_page() && $this->home_building_id() ) {
			return true;
		}

		return false;
	}

	/**
	 * Edificio impostato come homepage (0 se non attivo).
	 *
	 * @return int
	 */
	private function home_building_id() {
		return function_exists( 'palladio_home_building_id' ) ? palladio_home_building_id() : 0;
	}

	/**
	 * Un plugin SEO dedicato è attivo? (stessa logica del tema: in quel caso
	 * meta description/OG sono di sua competenza).
	 *
	 * @return bool
	 */
	private function seo_plugin_active() {
		return defined( 'WPSEO_VERSION' ) || function_exists( 'wpseo_init' )
			|| defined( 'RANK_MATH_VERSION' ) || function_exists( 'rank_math' )
			|| defined( 'SEOPRESS_VERSION' ) || function_exists( 'seopress_get_service' )
			|| defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' );
	}

	/**
	 * Post "protagonista" della vista corrente (per front page = edificio home).
	 *
	 * @return int
	 */
	private function current_post_id() {
		if ( is_front_page() && $this->home_building_id() ) {
			return $this->home_building_id();
		}

		return is_singular() ? (int) get_queried_object_id() : 0;
	}

	/**
	 * Descrizione breve del post: lead editoriale, poi riassunto, poi contenuto.
	 *
	 * @param int $post_id ID post.
	 * @return string
	 */
	private function description( $post_id ) {
		$ed   = function_exists( 'palladio_editorial' ) ? palladio_editorial( $post_id ) : array( 'lead' => '' );
		$text = $ed['lead'] ? $ed['lead'] : get_the_excerpt( $post_id );
		if ( ! $text ) {
			$text = get_post_field( 'post_content', $post_id );
		}

		return wp_html_excerpt( wp_strip_all_tags( strip_shortcodes( (string) $text ) ), 300, '…' );
	}

	// -------------------------------------------------------------------------
	// Meta description + Open Graph / Twitter Card.
	// -------------------------------------------------------------------------

	/**
	 * Meta tag per motori e crawler AI (solo viste Palladio, senza plugin SEO).
	 *
	 * @return void
	 */
	public function meta_tags() {
		if ( ! $this->is_palladio_view() || $this->seo_plugin_active() ) {
			return;
		}

		$post_id = $this->current_post_id();
		$title   = $post_id ? get_the_title( $post_id ) : wp_get_document_title();
		$desc    = $post_id ? wp_html_excerpt( $this->description( $post_id ), 160, '…' ) : '';
		$url     = $post_id && ! is_front_page() ? get_permalink( $post_id ) : ( function_exists( 'wp_get_canonical_url' ) && wp_get_canonical_url() ? wp_get_canonical_url() : home_url( add_query_arg( array() ) ) );
		$image   = $post_id ? get_the_post_thumbnail_url( $post_id, 'full' ) : '';

		if ( $desc ) {
			echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
		}
		echo '<meta property="og:type" content="website">' . "\n";
		echo '<meta property="og:title" content="' . esc_attr( $title ) . '">' . "\n";
		if ( $desc ) {
			echo '<meta property="og:description" content="' . esc_attr( $desc ) . '">' . "\n";
		}
		echo '<meta property="og:url" content="' . esc_url( $url ) . '">' . "\n";
		echo '<meta property="og:site_name" content="' . esc_attr( get_bloginfo( 'name' ) ) . '">' . "\n";
		echo '<meta property="og:locale" content="' . esc_attr( str_replace( '-', '_', $post_id ? $this->page_language( $post_id ) : get_locale() ) ) . '">' . "\n";
		if ( $image ) {
			echo '<meta property="og:image" content="' . esc_url( $image ) . '">' . "\n";
			echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
			echo '<meta name="twitter:image" content="' . esc_url( $image ) . '">' . "\n";
		} else {
			echo '<meta name="twitter:card" content="summary">' . "\n";
		}
		echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '">' . "\n";
		if ( $desc ) {
			echo '<meta name="twitter:description" content="' . esc_attr( $desc ) . '">' . "\n";
		}
	}

	// -------------------------------------------------------------------------
	// JSON-LD.
	// -------------------------------------------------------------------------

	/**
	 * Emette il grafo JSON-LD della vista corrente.
	 *
	 * @return void
	 */
	public function output_jsonld() {
		if ( ! $this->is_palladio_view() ) {
			return;
		}

		$graph = array();

		if ( is_singular( 'pll_unita' ) ) {
			$graph = $this->graph_unit( get_queried_object_id() );
		} elseif ( is_singular( 'pll_scenario' ) ) {
			$graph = $this->graph_scenario( get_queried_object_id() );
		} elseif ( is_singular( 'pll_edificio' ) || ( is_front_page() && $this->home_building_id() ) ) {
			$graph = $this->graph_building( $this->current_post_id() );
		} elseif ( is_singular( 'pll_storia' ) ) {
			$graph = $this->graph_storia( get_queried_object_id() );
		} elseif ( is_singular( 'pll_territorio' ) ) {
			$graph = $this->graph_territorio( get_queried_object_id() );
		} elseif ( is_post_type_archive( 'pll_unita' ) ) {
			$graph = $this->graph_archive( 'pll_unita', __( 'Unità immobiliari in vendita', 'palladio' ) );
		} elseif ( is_post_type_archive( 'pll_scenario' ) ) {
			$graph = $this->graph_archive( 'pll_scenario', __( 'Soluzioni e opportunità', 'palladio' ) );
		} elseif ( is_post_type_archive( 'pll_edificio' ) ) {
			$graph = $this->graph_archive( 'pll_edificio', __( 'Edifici', 'palladio' ) );
		}

		$graph = array_values( array_filter( $graph ) );
		if ( ! $graph ) {
			return;
		}

		$payload = array(
			'@context' => 'https://schema.org',
			'@graph'   => $graph,
		);

		echo '<script type="application/ld+json">' . wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON già codificato.
	}

	/**
	 * Rimuove le chiavi vuote (ricorsivo, primo livello di annidamento incluso).
	 *
	 * @param array $node Nodo schema.
	 * @return array
	 */
	private function clean( $node ) {
		return array_filter( $node, static function ( $value ) {
			return '' !== $value && null !== $value && array() !== $value;
		} );
	}

	/**
	 * Nodo RealEstateAgent dai contatti agenzia (Impostazioni + edificio).
	 *
	 * @param int $building_id Edificio di riferimento (per il nome agenzia).
	 * @return array
	 */
	private function agent_node( $building_id = 0 ) {
		$name  = $building_id ? (string) palladio_meta( $building_id, 'contatto_agenzia' ) : '';
		$phone = '';
		$email = '';

		if ( class_exists( 'Palladio_Admin_Settings' ) ) {
			$phone  = (string) Palladio_Admin_Settings::get( 'agency_phone' );
			$emails = Palladio_Admin_Settings::agency_emails();
			$email  = $emails ? $emails[0] : '';
		}
		if ( ! $email && $building_id ) {
			$email = (string) palladio_meta( $building_id, 'contatto_email' );
		}
		if ( ! $phone && $building_id ) {
			$phone = (string) palladio_meta( $building_id, 'contatto_tel' );
		}

		return $this->clean( array(
			'@type'     => 'RealEstateAgent',
			'@id'       => home_url( '/#palladio-agenzia' ),
			'name'      => $name ? $name : get_bloginfo( 'name' ),
			'url'       => home_url( '/' ),
			'telephone' => $phone,
			'email'     => $email,
		) );
	}

	/**
	 * Nodo ApartmentComplex dell'edificio.
	 *
	 * @param int $building_id ID edificio.
	 * @return array
	 */
	private function building_node( $building_id ) {
		$url     = get_permalink( $building_id );
		$claim   = (string) palladio_meta( $building_id, 'claim' );
		$lat     = palladio_meta( $building_id, 'geo_lat' );
		$lng     = palladio_meta( $building_id, 'geo_lng' );
		$address = (string) palladio_meta( $building_id, 'indirizzo' );

		$images = array();
		$hero   = get_the_post_thumbnail_url( $building_id, 'full' );
		if ( $hero ) {
			$images[] = $hero;
		}
		$ed = palladio_editorial( $building_id );
		foreach ( array_slice( $ed['gallery'], 0, 3 ) as $shot ) {
			$gi = palladio_image_url( $shot['image'] ?? 0, 'full' );
			if ( $gi ) {
				$images[] = $gi;
			}
		}

		return $this->clean( array(
			'@type'                               => 'ApartmentComplex',
			'@id'                                 => trailingslashit( $url ) . '#edificio',
			'name'                                => $claim ? $claim : get_the_title( $building_id ),
			'alternateName'                       => (string) palladio_meta( $building_id, 'sottotitolo' ),
			'description'                         => $this->description( $building_id ),
			'url'                                 => $url,
			'image'                               => array_values( array_unique( $images ) ),
			'address'                             => $address ? array(
				'@type'          => 'PostalAddress',
				'streetAddress'  => $address,
				'addressCountry' => 'IT',
			) : null,
			'geo'                                 => ( '' !== (string) $lat && '' !== (string) $lng ) ? array(
				'@type'     => 'GeoCoordinates',
				'latitude'  => (float) $lat,
				'longitude' => (float) $lng,
			) : null,
			'numberOfAvailableAccommodationUnits' => absint( palladio_meta( $building_id, 'num_unita_vendita' ) ) ?: null,
		) );
	}

	/**
	 * Nodo Accommodation/Apartment di un'unità.
	 *
	 * @param int  $unit_id ID unità.
	 * @param bool $brief   Solo i campi essenziali (per gli elenchi).
	 * @return array
	 */
	private function unit_node( $unit_id, $brief = false ) {
		$url         = get_permalink( $unit_id );
		$building_id = wp_get_post_parent_id( $unit_id );

		$tipologia = get_the_terms( $unit_id, 'pll_tipologia' );
		$tipologia = ( ! empty( $tipologia ) && ! is_wp_error( $tipologia ) ) ? $tipologia[0]->slug : '';
		$type      = ( false !== strpos( $tipologia, 'appartament' ) ) ? 'Apartment' : 'Accommodation';

		$piano = get_the_terms( $unit_id, 'pll_piano' );
		$piano = ( ! empty( $piano ) && ! is_wp_error( $piano ) ) ? $piano[0]->name : '';

		$mq = palladio_meta( $unit_id, 'mq_commerciali' );

		$node = array(
			'@type'     => $type,
			'@id'       => trailingslashit( $url ) . '#unita',
			'name'      => get_the_title( $unit_id ),
			'url'       => $url,
			'floorSize' => ( '' !== (string) $mq && (float) $mq > 0 ) ? array(
				'@type'    => 'QuantitativeValue',
				'value'    => (float) $mq,
				'unitCode' => 'MTK',
			) : null,
		);

		if ( ! $brief ) {
			// Immagini: in evidenza + prime foto della galleria editoriale.
			$images = array();
			$hero   = get_the_post_thumbnail_url( $unit_id, 'full' );
			if ( $hero ) {
				$images[] = $hero;
			}
			$ed = palladio_editorial( $unit_id );
			foreach ( array_slice( $ed['gallery'], 0, 4 ) as $shot ) {
				$gi = palladio_image_url( $shot['image'] ?? 0, 'full' );
				if ( $gi ) {
					$images[] = $gi;
				}
			}

			// Indirizzo, geo e anno di costruzione ereditati dall'edificio, così
			// il nodo unità resta comprensibile anche estratto dal grafo.
			$address    = $building_id ? (string) palladio_meta( $building_id, 'indirizzo' ) : '';
			$lat        = $building_id ? palladio_meta( $building_id, 'geo_lat' ) : '';
			$lng        = $building_id ? palladio_meta( $building_id, 'geo_lng' ) : '';
			$year_built = $building_id ? absint( palladio_meta( $building_id, 'anno_costruzione' ) ) : 0;

			$node += array(
				'description'            => $this->description( $unit_id ),
				'image'                  => array_values( array_unique( $images ) ),
				// D4: "Vani/stanze" -> numberOfRooms; omesso se vuoto o zero.
				'numberOfRooms'          => ( (float) palladio_meta( $unit_id, 'vani' ) > 0 ) ? (float) palladio_meta( $unit_id, 'vani' ) : null,
				'numberOfBedrooms'       => absint( palladio_meta( $unit_id, 'camere' ) ) ?: null,
				'numberOfBathroomsTotal' => absint( palladio_meta( $unit_id, 'bagni' ) ) ?: null,
				'floorLevel'             => $piano,
				'permittedUsage'         => (string) palladio_meta( $unit_id, 'destinazione_uso' ),
				'yearBuilt'              => $year_built ?: null,
				'tourBookingPage'        => (string) palladio_meta( $unit_id, 'virtual_tour_url' ),
				'address'                => $address ? array(
					'@type'          => 'PostalAddress',
					'streetAddress'  => $address,
					'addressCountry' => 'IT',
				) : null,
				'geo'                    => ( '' !== (string) $lat && '' !== (string) $lng ) ? array(
					'@type'     => 'GeoCoordinates',
					'latitude'  => (float) $lat,
					'longitude' => (float) $lng,
				) : null,
				'containedInPlace'       => $building_id ? array( '@id' => trailingslashit( get_permalink( $building_id ) ) . '#edificio' ) : null,
				'additionalProperty'     => $this->unit_properties( $unit_id ),
			);
		}

		return $this->clean( $node );
	}

	/**
	 * Scheda tecnica dell'unità come PropertyValue (per Google e crawler AI).
	 *
	 * @param int $unit_id ID unità.
	 * @return array
	 */
	private function unit_properties( $unit_id ) {
		$map = array(
			'codice'             => array( __( 'Codice', 'palladio' ), '' ),
			'mq_coperti'         => array( __( 'Superficie coperta', 'palladio' ), 'MTK' ),
			'terrazza_mq'        => array( __( 'Terrazza', 'palladio' ), 'MTK' ),
			'giardino_mq'        => array( __( 'Giardino', 'palladio' ), 'MTK' ),
			'esposizione'        => array( __( 'Esposizione', 'palladio' ), '' ),
			'classe_energetica'  => array( __( 'Classe energetica', 'palladio' ), '' ),
			'millesimi'          => array( __( 'Millesimi', 'palladio' ), '' ),
			'spese_condominiali' => array( __( 'Spese condominiali (EUR)', 'palladio' ), '' ),
			'stato_consegna'     => array( __( 'Stato di consegna', 'palladio' ), '' ),
		);

		$props = array();
		foreach ( $map as $key => $conf ) {
			$value = palladio_meta( $unit_id, $key );
			// Un campo non compilato non deve diventare un "0" pubblico:
			// escludi vuoti, null e valori numerici a zero.
			if ( '' === (string) $value || ( is_numeric( $value ) && 0.0 === (float) $value ) ) {
				continue;
			}
			$props[] = $this->clean( array(
				'@type'    => 'PropertyValue',
				'name'     => $conf[0],
				'value'    => is_numeric( $value ) ? (float) $value : (string) $value,
				'unitCode' => $conf[1],
			) );
		}

		return $props;
	}

	/**
	 * Tag BCP47 della LINGUA DELLA PAGINA (non il locale del sito).
	 *
	 * @param int $post_id ID post.
	 * @return string
	 */
	private function page_language( $post_id ) {
		$map  = array( 'it' => 'it-IT', 'en' => 'en-US', 'de' => 'de-DE', 'fr' => 'fr-FR' );
		$lang = class_exists( 'Palladio_I18n_Translator' ) ? Palladio_I18n_Translator::get_lang( $post_id ) : 'it';

		return isset( $map[ $lang ] ) ? $map[ $lang ] : str_replace( '_', '-', get_locale() );
	}

	/**
	 * Mappa lo stato di vendita su schema.org/ItemAvailability.
	 *
	 * @param string $slug Slug pll_stato.
	 * @return string
	 */
	private function availability( $slug ) {
		switch ( $slug ) {
			case 'venduta':
				return 'https://schema.org/SoldOut';
			case 'riservata':
			case 'in_trattativa':
				return 'https://schema.org/LimitedAvailability';
			case 'non_in_vendita':
			case 'non_disponibile':
				return 'https://schema.org/OutOfStock';
			default:
				return 'https://schema.org/InStock';
		}
	}

	/**
	 * Nodo RealEstateListing della pagina corrente.
	 *
	 * @param int   $post_id ID post.
	 * @param array $extra   Proprietà aggiuntive.
	 * @return array
	 */
	private function listing_node( $post_id, $extra = array() ) {
		$url = get_permalink( $post_id );

		return $this->clean( array_merge( array(
			'@type'        => 'RealEstateListing',
			'@id'          => trailingslashit( $url ) . '#listing',
			'url'          => $url,
			'name'         => get_the_title( $post_id ),
			'description'  => $this->description( $post_id ),
			'inLanguage'   => $this->page_language( $post_id ),
			'datePosted'   => get_the_date( 'c', $post_id ),
			'dateModified' => get_the_modified_date( 'c', $post_id ),
			'image'        => get_the_post_thumbnail_url( $post_id, 'full' ) ?: null,
		), $extra ) );
	}

	/**
	 * Grafo della scheda unità.
	 *
	 * @param int $unit_id ID unità.
	 * @return array
	 */
	private function graph_unit( $unit_id ) {
		$building_id = wp_get_post_parent_id( $unit_id );
		$price       = palladio_meta( $unit_id, 'prezzo' );
		$status      = palladio_get_unit_status( $unit_id );
		$unit        = $this->unit_node( $unit_id );

		$offer = $this->clean( array(
			'@type'         => 'Offer',
			'url'           => get_permalink( $unit_id ),
			'price'         => is_numeric( $price ) ? (float) $price : null,
			'priceCurrency' => 'EUR',
			'availability'  => $this->availability( $status['slug'] ),
			'itemOffered'   => array( '@id' => $unit['@id'] ),
			'seller'        => array( '@id' => home_url( '/#palladio-agenzia' ) ),
		) );

		$graph   = array();
		$graph[] = $this->listing_node( $unit_id, array( 'mainEntity' => array( '@id' => $unit['@id'] ) ) );
		$graph[] = $unit;
		$graph[] = $offer;
		if ( $building_id ) {
			$graph[] = $this->building_node( $building_id );
		}
		$graph[] = $this->agent_node( $building_id );

		return $graph;
	}

	/**
	 * Grafo della scheda scenario (offerta aggregata di più unità).
	 *
	 * @param int $scenario_id ID scenario.
	 * @return array
	 */
	private function graph_scenario( $scenario_id ) {
		$totals      = palladio_scenario_totals( $scenario_id );
		$stato       = (string) get_post_meta( $scenario_id, '_pll_scenario_stato', true );
		$building_id = 0;

		$units = array();
		foreach ( $totals['units'] as $uid ) {
			$units[] = $this->unit_node( $uid, true );
			if ( ! $building_id ) {
				$building_id = wp_get_post_parent_id( $uid );
			}
		}

		// Il vantaggio dell'aggregazione, leggibile anche dai crawler AI.
		$offer_desc = '';
		if ( $totals['saving'] > 0 ) {
			$offer_desc = sprintf(
				/* translators: 1: n unità, 2: risparmio, 3: percentuale, 4: somma prezzi. */
				__( 'Offerta aggregata di %1$d unità: risparmio di %2$s (−%3$s%%) rispetto alla somma dei singoli prezzi (%4$s).', 'palladio' ),
				(int) $totals['count'],
				palladio_format_price( $totals['saving'] ),
				number_format_i18n( $totals['saving_pct'] ),
				palladio_format_price( $totals['sum'] )
			);
		}

		$offer = $this->clean( array(
			'@type'         => 'Offer',
			'url'           => get_permalink( $scenario_id ),
			'name'          => get_the_title( $scenario_id ),
			'description'   => $offer_desc,
			'price'         => $totals['price'] > 0 ? (float) $totals['price'] : null,
			'priceCurrency' => 'EUR',
			'availability'  => $this->availability( $stato ),
			'itemOffered'   => $units ? array_map( static function ( $u ) {
				return array( '@id' => $u['@id'] );
			}, $units ) : null,
			'seller'        => array( '@id' => home_url( '/#palladio-agenzia' ) ),
		) );

		// Immagine del listing: in evidenza dello scenario o della prima unità
		// (stesso fallback del template).
		$image = get_the_post_thumbnail_url( $scenario_id, 'full' );
		if ( ! $image && $totals['units'] ) {
			$image = get_the_post_thumbnail_url( $totals['units'][0], 'full' );
		}

		$graph   = array();
		$graph[] = $this->listing_node( $scenario_id, $image ? array( 'image' => $image ) : array() );
		$graph[] = $offer;
		foreach ( $units as $u ) {
			$graph[] = $u;
		}
		if ( $building_id ) {
			$graph[] = $this->building_node( $building_id );
		}
		$graph[] = $this->agent_node( $building_id );

		return $graph;
	}

	/**
	 * Grafo della landing edificio (anche come homepage).
	 *
	 * @param int $building_id ID edificio.
	 * @return array
	 */
	private function graph_building( $building_id ) {
		$building = $this->building_node( $building_id );

		$graph   = array();
		$graph[] = $this->listing_node( $building_id, array( 'mainEntity' => array( '@id' => $building['@id'] ) ) );
		$graph[] = $building;

		// Elenco delle unità pubblicate (aiuta la scoperta delle schede).
		$units = get_posts( array(
			'post_type'      => 'pll_unita',
			'post_parent'    => $building_id,
			'posts_per_page' => 50,
			'orderby'        => 'menu_order',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		) );
		if ( $units ) {
			$graph[] = array(
				'@type'           => 'ItemList',
				'@id'             => trailingslashit( get_permalink( $building_id ) ) . '#unita-list',
				'name'            => __( 'Unità immobiliari in vendita', 'palladio' ),
				'numberOfItems'   => count( $units ),
				'itemListElement' => array_map( function ( $uid, $i ) {
					return array(
						'@type'    => 'ListItem',
						'position' => $i + 1,
						'name'     => get_the_title( $uid ),
						'url'      => get_permalink( $uid ),
					);
				}, $units, array_keys( $units ) ),
			);
		}

		$graph[] = $this->agent_node( $building_id );

		return $graph;
	}

	/**
	 * Grafo della pagina Storia (AboutPage sull'edificio).
	 *
	 * @param int $post_id ID pagina storia.
	 * @return array
	 */
	private function graph_storia( $post_id ) {
		$home  = $this->home_building_id();
		$graph = array();

		$graph[] = $this->clean( array(
			'@type'              => 'AboutPage',
			'@id'                => trailingslashit( get_permalink( $post_id ) ) . '#webpage',
			'url'                => get_permalink( $post_id ),
			'name'               => get_the_title( $post_id ),
			'description'        => $this->description( $post_id ),
			'inLanguage'         => $this->page_language( $post_id ),
			'datePublished'      => get_the_date( 'c', $post_id ),
			'dateModified'       => get_the_modified_date( 'c', $post_id ),
			'primaryImageOfPage' => get_the_post_thumbnail_url( $post_id, 'full' ) ?: null,
			'about'              => $home ? array( '@id' => trailingslashit( get_permalink( $home ) ) . '#edificio' ) : null,
		) );

		if ( $home ) {
			$graph[] = $this->building_node( $home );
		}

		return $graph;
	}

	/**
	 * Grafo della pagina Territorio (WebPage su un luogo).
	 *
	 * @param int $post_id ID pagina territorio.
	 * @return array
	 */
	private function graph_territorio( $post_id ) {
		$ed = palladio_editorial( $post_id );

		return array(
			$this->clean( array(
				'@type'              => 'WebPage',
				'@id'                => trailingslashit( get_permalink( $post_id ) ) . '#webpage',
				'url'                => get_permalink( $post_id ),
				'name'               => get_the_title( $post_id ),
				'description'        => $this->description( $post_id ),
				'inLanguage'         => $this->page_language( $post_id ),
				'datePublished'      => get_the_date( 'c', $post_id ),
				'dateModified'       => get_the_modified_date( 'c', $post_id ),
				'primaryImageOfPage' => get_the_post_thumbnail_url( $post_id, 'full' ) ?: null,
				'about'              => $this->clean( array(
					'@type' => 'Place',
					'name'  => $ed['city_heading'] ? $ed['city_heading'] : get_the_title( $post_id ),
				) ),
			) ),
		);
	}

	/**
	 * Grafo degli archivi: ItemList delle schede in elenco.
	 *
	 * @param string $post_type CPT.
	 * @param string $name      Nome dell'elenco.
	 * @return array
	 */
	private function graph_archive( $post_type, $name ) {
		global $wp_query;

		$ids = wp_list_pluck( is_array( $wp_query->posts ) ? $wp_query->posts : array(), 'ID' );
		if ( ! $ids ) {
			return array();
		}

		$url = get_post_type_archive_link( $post_type );

		return array(
			array(
				'@type'           => 'ItemList',
				'@id'             => trailingslashit( $url ) . '#itemlist',
				'name'            => $name,
				'url'             => $url,
				'numberOfItems'   => count( $ids ),
				'itemListElement' => array_map( function ( $pid, $i ) {
					return array(
						'@type'    => 'ListItem',
						'position' => $i + 1,
						'name'     => get_the_title( $pid ),
						'url'      => get_permalink( $pid ),
					);
				}, $ids, array_keys( $ids ) ),
			),
		);
	}
}
