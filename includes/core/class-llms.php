<?php
/**
 * Modulo Core — /llms.txt per la navigazione agentica (LLM).
 *
 * Serve /llms.txt in formato Markdown conforme alla specifica llmstxt.org
 * (richiesta anche dalla verifica di Google): intestazione H1, riassunto in
 * blockquote e sezioni di link. Il contenuto è orientato all'obiettivo del
 * sito — la vendita delle unità immobiliari — così un agente arriva subito
 * a unità disponibili, prezzi, scenari di acquisto e modulo di contatto,
 * in tutte le lingue attive.
 *
 * L'intercettazione avviene su `parse_request` a priorità 0 confrontando il
 * percorso della richiesta: prevale su eventuali generatori di llms.txt dei
 * plugin SEO e non richiede il flush delle rewrite rules. Un file llms.txt
 * fisico nella root del sito avrebbe comunque la precedenza (Apache lo serve
 * prima di WordPress): va rimosso se presente.
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generatore llms.txt.
 */
class Palladio_Core_Llms {

	/**
	 * Registra gli hook.
	 *
	 * @return void
	 */
	public function register() {
		if ( ! apply_filters( 'palladio/llms/enabled', true ) ) {
			return;
		}

		add_action( 'parse_request', array( $this, 'maybe_render' ), 0 );
	}

	/**
	 * Se la richiesta è /llms.txt, emette il Markdown e termina.
	 *
	 * @return void
	 */
	public function maybe_render() {
		$request = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
		$target  = (string) wp_parse_url( home_url( '/llms.txt' ), PHP_URL_PATH );

		if ( '' === $request || untrailingslashit( $request ) !== untrailingslashit( $target ) ) {
			return;
		}

		status_header( 200 );
		header( 'Content-Type: text/plain; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex' );
		nocache_headers();

		echo $this->build(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markdown testuale costruito da fonti interne.
		exit;
	}

	/**
	 * Costruisce il documento Markdown.
	 *
	 * @return string
	 */
	private function build() {
		$name   = get_bloginfo( 'name' );
		$source = class_exists( 'Palladio_I18n_Languages' ) ? Palladio_I18n_Languages::source() : 'it';
		$lines  = array();

		// ------------------------------------------------------ intestazione
		$lines[] = '# ' . $name . ' — ' . __( 'unità immobiliari in vendita a Lecce', 'palladio' );
		$lines[] = '';
		$lines[] = '> ' . sprintf(
			/* translators: %s: nome del sito. */
			__( 'Vendita frazionata delle unità immobiliari di %s, palazzo storico nel centro di Lecce (Puglia, Italia): appartamenti acquistabili singolarmente oppure combinati in scenari. L\'obiettivo del sito è la vendita degli appartamenti: le pagine più rilevanti sono le unità disponibili con i prezzi, gli scenari di acquisto e il modulo di contatto per richiedere una visita.', 'palladio' ),
			$name
		);
		$lines[] = '';
		$lines[] = __( 'I contenuti sono disponibili in italiano (lingua principale), inglese, tedesco e francese; ogni pagina espone dati strutturati schema.org (RealEstateListing, Apartment, Offer). I prezzi sono in euro.', 'palladio' );
		$lines[] = '';

		// ------------------------------------------------- unità in vendita
		$lines[] = '## ' . __( 'Unità immobiliari in vendita', 'palladio' );
		$lines[] = '';

		foreach ( $this->posts( 'pll_unita', $source ) as $unit ) {
			$lines[] = $this->unit_line( $unit );
		}

		$archive = get_post_type_archive_link( 'pll_unita' );
		if ( $archive ) {
			$lines[] = '- [' . __( 'Tutte le unità in vendita', 'palladio' ) . '](' . $archive . '): ' . __( 'elenco completo e aggiornato, con filtri per piano e prezzo.', 'palladio' );
		}
		$lines[] = '';

		// ------------------------------------------------ scenari di acquisto
		$scenarios = $this->posts( 'pll_scenario', $source );
		if ( $scenarios ) {
			$lines[] = '## ' . __( 'Scenari di acquisto (più unità combinate)', 'palladio' );
			$lines[] = '';
			foreach ( $scenarios as $scenario ) {
				$lines[] = $this->scenario_line( $scenario );
			}
			$archive = get_post_type_archive_link( 'pll_scenario' );
			if ( $archive ) {
				$lines[] = '- [' . __( 'Tutti gli scenari', 'palladio' ) . '](' . $archive . '): ' . __( 'le combinazioni proposte, con il prezzo del pacchetto.', 'palladio' );
			}
			$lines[] = '';
		}

		// ------------------------------------------------------- il palazzo
		$lines[] = '## ' . __( 'Il palazzo e il contesto', 'palladio' );
		$lines[] = '';
		$lines[] = '- [' . __( 'Il palazzo', 'palladio' ) . '](' . home_url( '/' ) . '): ' . __( 'presentazione dell\'edificio, delle residenze e del progetto di vendita.', 'palladio' );

		foreach ( array( 'pll_storia' => __( 'la storia del palazzo e delle famiglie che lo hanno abitato.', 'palladio' ), 'pll_territorio' => __( 'Lecce e il Salento: posizione, territorio e mercati immobiliari.', 'palladio' ) ) as $type => $note ) {
			foreach ( $this->posts( $type, $source ) as $post ) {
				$lines[] = '- [' . $this->title( $post ) . '](' . get_permalink( $post ) . '): ' . $note;
			}
		}
		$lines[] = '';

		// ---------------------------------------------------------- contatti
		$lines[] = '## ' . __( 'Richiedere una visita o informazioni', 'palladio' );
		$lines[] = '';
		$lines[] = '- [' . __( 'Modulo di contatto', 'palladio' ) . '](' . home_url( '/#palladio-contact' ) . '): ' . __( 'per richiedere una visita, il dossier di un\'unità o una proposta: si viene ricontattati nel minor tempo possibile.', 'palladio' );
		$lines[] = '';

		// -------------------------------------------------- versioni in lingua
		$lang_lines = $this->language_lines( $source );
		if ( $lang_lines ) {
			$lines[] = '## ' . __( 'Versioni in lingua', 'palladio' );
			$lines[] = '';
			foreach ( $lang_lines as $line ) {
				$lines[] = $line;
			}
			$lines[] = '';
		}

		return implode( "\n", $lines );
	}

	/**
	 * Post pubblicati di un CPT nella lingua indicata.
	 *
	 * @param string $post_type CPT.
	 * @param string $lang      Lingua.
	 * @return WP_Post[]
	 */
	private function posts( $post_type, $lang ) {
		$args = array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'orderby'        => 'menu_order title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		);

		if ( class_exists( 'Palladio_I18n_Languages' ) ) {
			$args['meta_query'] = Palladio_I18n_Languages::lang_meta_query( $lang ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}

		return get_posts( $args );
	}

	/**
	 * Titolo senza markup problematico per il Markdown.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private function title( $post ) {
		return str_replace( array( '[', ']' ), '', wp_strip_all_tags( get_the_title( $post ) ) );
	}

	/**
	 * Riga Markdown di un'unità: dati essenziali per la decisione d'acquisto.
	 *
	 * @param WP_Post $unit Unità.
	 * @return string
	 */
	private function unit_line( $unit ) {
		$facts = array();

		$tipologia = get_the_terms( $unit->ID, 'pll_tipologia' );
		if ( ! empty( $tipologia ) && ! is_wp_error( $tipologia ) ) {
			$facts[] = $tipologia[0]->name;
		}

		$mq = (float) get_post_meta( $unit->ID, '_pll_mq_commerciali', true );
		if ( $mq > 0 ) {
			$facts[] = number_format( $mq, 0, ',', '.' ) . ' m²';
		}

		$camere = (int) get_post_meta( $unit->ID, '_pll_camere', true );
		if ( $camere > 0 ) {
			/* translators: %d: numero camere. */
			$facts[] = sprintf( _n( '%d camera', '%d camere', $camere, 'palladio' ), $camere );
		}

		$price = (float) get_post_meta( $unit->ID, '_pll_prezzo', true );
		$facts[] = $price > 0 ? '€ ' . number_format( $price, 0, ',', '.' ) : __( 'prezzo su richiesta', 'palladio' );

		$status = function_exists( 'palladio_get_unit_status' ) ? palladio_get_unit_status( $unit->ID ) : array( 'label' => '' );
		if ( ! empty( $status['label'] ) ) {
			$facts[] = $status['label'];
		}

		$note = implode( ', ', $facts );

		$excerpt = has_excerpt( $unit ) ? wp_strip_all_tags( get_the_excerpt( $unit ) ) : '';
		if ( $excerpt ) {
			$note .= '. ' . wp_trim_words( $excerpt, 24, '…' );
		}

		return '- [' . $this->title( $unit ) . '](' . get_permalink( $unit ) . '): ' . $note;
	}

	/**
	 * Riga Markdown di uno scenario.
	 *
	 * @param WP_Post $scenario Scenario.
	 * @return string
	 */
	private function scenario_line( $scenario ) {
		$facts = array();

		if ( function_exists( 'palladio_scenario_totals' ) ) {
			$totals = palladio_scenario_totals( $scenario->ID );
			if ( ! empty( $totals['count'] ) ) {
				/* translators: %d: numero unità. */
				$facts[] = sprintf( _n( '%d unità', '%d unità', $totals['count'], 'palladio' ), $totals['count'] );
			}
			if ( ! empty( $totals['mq'] ) && $totals['mq'] > 0 ) {
				$facts[] = number_format( (float) $totals['mq'], 0, ',', '.' ) . ' m²';
			}
			if ( ! empty( $totals['price'] ) && $totals['price'] > 0 ) {
				$facts[] = '€ ' . number_format( (float) $totals['price'], 0, ',', '.' );
			}
		}

		$note    = implode( ', ', $facts );
		$excerpt = has_excerpt( $scenario ) ? wp_strip_all_tags( get_the_excerpt( $scenario ) ) : '';
		if ( $excerpt ) {
			$note .= ( $note ? '. ' : '' ) . wp_trim_words( $excerpt, 24, '…' );
		}

		return '- [' . $this->title( $scenario ) . '](' . get_permalink( $scenario ) . ')' . ( $note ? ': ' . $note : '' );
	}

	/**
	 * Righe della sezione "Versioni in lingua".
	 *
	 * @param string $source Lingua sorgente.
	 * @return string[]
	 */
	private function language_lines( $source ) {
		if ( ! class_exists( 'Palladio_I18n_Languages' ) ) {
			return array();
		}

		$labels = array(
			'en' => 'English',
			'de' => 'Deutsch',
			'fr' => 'Français',
		);
		$notes  = array(
			'en' => 'apartments for sale in the historic Palazzo Sambiasi, Lecce (Italy).',
			'de' => 'Wohnungen zum Verkauf im historischen Palazzo Sambiasi, Lecce (Italien).',
			'fr' => 'appartements à vendre dans le palais historique Sambiasi, Lecce (Italie).',
		);
		$units_labels = array(
			'en' => 'Apartments for sale',
			'de' => 'Wohnungen zum Verkauf',
			'fr' => 'Appartements à vendre',
		);

		$lines = array();
		foreach ( Palladio_I18n_Languages::active() as $lang ) {
			if ( $lang === $source ) {
				continue;
			}

			$label = $labels[ $lang ] ?? strtoupper( $lang );
			$note  = $notes[ $lang ] ?? '';
			$home  = home_url( '/' . $lang . '/' );

			$lines[] = '- [' . $label . '](' . $home . ')' . ( $note ? ': ' . $note : '' );

			if ( class_exists( 'Palladio_I18n_Urls' ) ) {
				$base    = Palladio_I18n_Urls::base_for( 'pll_unita', $lang );
				$ulabel  = $units_labels[ $lang ] ?? __( 'Unità in vendita', 'palladio' );
				$lines[] = '  - [' . $ulabel . '](' . home_url( '/' . $lang . '/' . $base . '/' ) . ')';
			}
		}

		return $lines;
	}
}
