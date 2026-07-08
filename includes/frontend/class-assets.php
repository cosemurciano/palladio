<?php
/**
 * Modulo Presenter — asset frontend.
 *
 * Carica CSS/JS solo sulle viste del plugin, versionati con filemtime
 * (fallback a PALLADIO_VERSION). Lo stile consuma le variabili di palette
 * del tema con fallback neutri (cfr. assets/css/palladio.css).
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gestisce l'enqueue degli asset frontend.
 */
class Palladio_Frontend_Assets {

	/**
	 * Registra gli hook.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Accoda gli asset dove servono.
	 *
	 * @return void
	 */
	public function enqueue() {
		if ( ! $this->should_load() ) {
			return;
		}

		wp_enqueue_style(
			'palladio',
			PALLADIO_URI . 'assets/css/palladio.css',
			array(),
			$this->asset_version( 'assets/css/palladio.css' )
		);

		wp_enqueue_script(
			'palladio-filters',
			PALLADIO_URI . 'assets/js/palladio.js',
			array(),
			$this->asset_version( 'assets/js/palladio.js' ),
			true
		);
	}

	/**
	 * Decide se caricare gli asset nella richiesta corrente.
	 *
	 * @return bool
	 */
	private function should_load() {
		if ( is_singular( array( 'pll_edificio', 'pll_unita', 'pll_scenario' ) ) ) {
			return true;
		}

		if ( is_post_type_archive( 'pll_edificio' ) ) {
			return true;
		}

		// Presente uno shortcode del plugin nel contenuto.
		$post = get_post();
		if ( $post instanceof WP_Post && has_shortcode( $post->post_content, 'palladio_edifici' ) ) {
			return true;
		}

		return (bool) apply_filters( 'palladio/assets/should_load', false );
	}

	/**
	 * Versione dell'asset da filemtime, con fallback alla versione plugin.
	 *
	 * @param string $relative Percorso relativo alla root del plugin.
	 * @return string
	 */
	private function asset_version( $relative ) {
		$path = PALLADIO_DIR . $relative;

		if ( is_readable( $path ) ) {
			$mtime = filemtime( $path );
			if ( $mtime ) {
				return (string) $mtime;
			}
		}

		return PALLADIO_VERSION;
	}
}
