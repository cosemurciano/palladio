<?php
/**
 * Modulo Presenter — routing dei template.
 *
 * Carica i template di edificio/unità/scenario dal plugin, ma lascia sempre
 * la precedenza al tema: `{tema}/palladio/single-pll_unita.php` o
 * `{tema}/single-pll_unita.php` sovrascrivono il default (cfr. §4/§5.2).
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Instrada i template dei CPT del plugin.
 */
class Palladio_Frontend_Templates {

	/**
	 * Registra i filtri.
	 *
	 * @return void
	 */
	public function register() {
		require_once PALLADIO_DIR . 'includes/frontend/template-functions.php';

		add_filter( 'template_include', array( $this, 'template_include' ) );
		add_filter( 'body_class', array( $this, 'body_class' ) );
	}

	/**
	 * Sceglie il template per i CPT del plugin, con override dal tema.
	 *
	 * @param string $template Percorso template scelto da WordPress.
	 * @return string
	 */
	public function template_include( $template ) {
		$map = array();

		// Edificio come homepage del sito: mostra la sua landing alla radice,
		// distinta dalle schede delle singole unità.
		$home_id = (int) get_option( 'palladio_home_building', 0 );
		if ( $home_id && is_front_page() && 'pll_edificio' === get_post_type( $home_id ) && 'publish' === get_post_status( $home_id ) ) {
			global $wp_query;
			$wp_query = new WP_Query( // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				array(
					'post_type'      => 'pll_edificio',
					'p'              => $home_id,
					'posts_per_page' => 1,
				)
			);

			$map              = array( 'single-pll_edificio.php' );
			$theme_candidates = array( 'palladio/' . $map[0], $map[0] );
			$located          = locate_template( $theme_candidates );
			if ( $located ) {
				return $located;
			}
			$candidate = PALLADIO_DIR . 'templates/' . $map[0];
			return is_readable( $candidate ) ? $candidate : $template;
		}

		if ( is_singular( 'pll_edificio' ) ) {
			$map = array( 'single-pll_edificio.php' );
		} elseif ( is_singular( 'pll_unita' ) ) {
			$map = array( 'single-pll_unita.php' );
		} elseif ( is_singular( 'pll_scenario' ) ) {
			$map = array( 'single-pll_scenario.php' );
		} elseif ( is_post_type_archive( 'pll_edificio' ) ) {
			$map = array( 'archive-pll_edificio.php' );
		} elseif ( is_post_type_archive( 'pll_unita' ) ) {
			$map = array( 'archive-pll_unita.php' );
		}

		if ( empty( $map ) ) {
			return $template;
		}

		// 1) Override dal tema: prima palladio/<file>, poi <file> in root tema.
		$theme_candidates = array();
		foreach ( $map as $file ) {
			$theme_candidates[] = 'palladio/' . $file;
			$theme_candidates[] = $file;
		}

		$located = locate_template( $theme_candidates );
		if ( $located ) {
			return $located;
		}

		// 2) Default del plugin.
		foreach ( $map as $file ) {
			$candidate = PALLADIO_DIR . 'templates/' . $file;
			if ( is_readable( $candidate ) ) {
				return $candidate;
			}
		}

		return $template;
	}

	/**
	 * Aggiunge classi body per lo scoping degli stili.
	 *
	 * @param array $classes Classi correnti.
	 * @return array
	 */
	public function body_class( $classes ) {
		if (
			is_singular( array( 'pll_edificio', 'pll_unita', 'pll_scenario' ) )
			|| is_post_type_archive( array( 'pll_edificio', 'pll_unita' ) )
		) {
			$classes[] = 'palladio';
			if ( palladio_is_poetheme() ) {
				$classes[] = 'palladio--poetheme';
			}
		}

		return $classes;
	}
}
