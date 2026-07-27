<?php
/**
 * Modulo Core — homepage con lo standard WordPress (Impostazioni → Lettura).
 *
 * Gli edifici compaiono nell'elenco "La homepage mostra → Una pagina statica"
 * di Impostazioni → Lettura: la scelta usa le option standard show_on_front /
 * page_on_front, senza flag proprietari nell'editor dell'edificio.
 *
 * Il modulo:
 *  - aggiunge gli edifici pubblicati al menu a tendina "Homepage";
 *  - corregge la query principale della front page quando page_on_front è un
 *    edificio (WordPress altrimenti cercherebbe una "page" con quell'ID);
 *  - migra una tantum la vecchia option palladio_home_building alle option
 *    standard di lettura.
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Homepage-edificio via Impostazioni → Lettura.
 */
class Palladio_Core_Home {

	/**
	 * Registra gli hook.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( $this, 'maybe_migrate_legacy_option' ), 20 );
		add_filter( 'wp_dropdown_pages', array( $this, 'add_buildings_to_front_dropdown' ), 10, 2 );
		add_action( 'pre_get_posts', array( $this, 'fix_front_page_query' ) );
		add_action( 'template_redirect', array( $this, 'canonical_home_redirect' ), 1 );
	}

	/**
	 * La scheda dell'edificio homepage vive alla radice: il suo permalink
	 * CPT (/edificio/slug/) fa 301 su /, e le versioni in lingua sulla home
	 * di lingua /{lang}/ — un solo URL indicizzato, niente duplicati.
	 *
	 * @return void
	 */
	public function canonical_home_redirect() {
		if ( ! is_singular( 'pll_edificio' ) || is_front_page() ) {
			return;
		}
		// /{lang}/ è già l'URL canonico della richiesta corrente.
		if ( get_query_var( 'palladio_lang_home' ) ) {
			return;
		}

		$home = function_exists( 'palladio_home_building_id' ) ? palladio_home_building_id() : 0;
		if ( ! $home ) {
			return;
		}

		$id = (int) get_queried_object_id();
		if ( $id === $home ) {
			wp_safe_redirect( home_url( '/' ), 301 );
			exit;
		}

		if ( class_exists( 'Palladio_I18n_Translator' ) ) {
			$lang = Palladio_I18n_Translator::get_lang( $id );
			if (
				$lang !== Palladio_I18n_Languages::source()
				&& Palladio_I18n_Translator::sibling_in( $home, $lang, array( 'publish' ) ) === $id
			) {
				wp_safe_redirect( home_url( '/' . $lang . '/' ), 301 );
				exit;
			}
		}
	}

	/**
	 * Migra la vecchia option palladio_home_building alle option standard
	 * di lettura (show_on_front/page_on_front). Eseguita una sola volta.
	 *
	 * @return void
	 */
	public function maybe_migrate_legacy_option() {
		$legacy = (int) get_option( 'palladio_home_building', 0 );
		if ( ! $legacy ) {
			return;
		}

		if ( 'pll_edificio' === get_post_type( $legacy ) ) {
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', $legacy );
		}

		delete_option( 'palladio_home_building' );
	}

	/**
	 * Aggiunge gli edifici pubblicati al menu a tendina "Homepage" di
	 * Impostazioni → Lettura (name="page_on_front").
	 *
	 * @param string $output      HTML del select.
	 * @param array  $parsed_args Argomenti di wp_dropdown_pages.
	 * @return string
	 */
	public function add_buildings_to_front_dropdown( $output, $parsed_args ) {
		if ( empty( $parsed_args['name'] ) || 'page_on_front' !== $parsed_args['name'] || false === strpos( (string) $output, '</select>' ) ) {
			return $output;
		}

		$buildings = get_posts( array(
			'post_type'      => 'pll_edificio',
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		) );
		if ( ! $buildings ) {
			return $output;
		}

		$selected = isset( $parsed_args['selected'] ) ? (int) $parsed_args['selected'] : 0;
		$options  = '';
		foreach ( $buildings as $building ) {
			$options .= sprintf(
				'<option class="level-0" value="%1$d"%2$s>%3$s</option>',
				(int) $building->ID,
				selected( $selected, (int) $building->ID, false ),
				/* translators: %s: titolo edificio. */
				esc_html( sprintf( __( '%s — Edificio (Palladio)', 'palladio' ), $building->post_title ) )
			);
		}

		return str_replace( '</select>', $options . '</select>', $output );
	}

	/**
	 * Quando page_on_front è un edificio, la query principale della front page
	 * deve cercare tra i pll_edificio (WordPress assume post_type "page").
	 *
	 * @param WP_Query $query Query in preparazione.
	 * @return void
	 */
	public function fix_front_page_query( $query ) {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( 'page' !== get_option( 'show_on_front' ) ) {
			return;
		}

		$front = (int) get_option( 'page_on_front', 0 );
		if ( ! $front || (int) $query->get( 'page_id' ) !== $front ) {
			return;
		}
		if ( 'pll_edificio' !== get_post_type( $front ) ) {
			return;
		}

		$query->set( 'post_type', 'pll_edificio' );
	}
}
