<?php
/**
 * Modulo Agent — widget frontend.
 *
 * Inietta il widget di chat sulle viste del progetto, dichiarando che si
 * tratta di un assistente AI (trasparenza, AI Act §6) e passando l'endpoint
 * REST e il nonce. Nessuna chiamata AI dal browser: il widget parla solo col
 * proprio endpoint REST.
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Widget conversazionale.
 */
class Palladio_Agent_Widget {

	/**
	 * Registra hook frontend.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'wp_footer', array( $this, 'markup' ) );
	}

	/**
	 * Decide se mostrare il widget nella richiesta corrente.
	 *
	 * @return bool
	 */
	private function should_display() {
		if ( ! Palladio_Agent_Rest::is_ready() ) {
			return false;
		}

		$display = is_singular( array( 'pll_edificio', 'pll_unita', 'pll_scenario' ) )
			|| is_post_type_archive( 'pll_edificio' );

		/**
		 * Filtra la visibilità del widget agent.
		 *
		 * @param bool $display Visibilità.
		 */
		return (bool) apply_filters( 'palladio/agent/should_display', $display );
	}

	/**
	 * Accoda asset e configurazione.
	 *
	 * @return void
	 */
	public function assets() {
		if ( ! $this->should_display() ) {
			return;
		}

		$css = 'assets/css/palladio-agent.css';
		$js  = 'assets/js/agent-widget.js';

		wp_enqueue_style( 'palladio-agent', PALLADIO_URI . $css, array(), $this->ver( $css ) );
		wp_enqueue_script( 'palladio-agent', PALLADIO_URI . $js, array(), $this->ver( $js ), true );

		$config = Palladio_Agent_Rest::config();

		wp_localize_script(
			'palladio-agent',
			'PalladioAgent',
			array(
				'endpoint'   => esc_url_raw( rest_url( 'palladio/v1/agent/chat' ) ),
				'nonce'      => wp_create_nonce( 'palladio_agent' ),
				'unitId'     => is_singular( 'pll_unita' ) ? get_the_ID() : 0,
				'disclaimer' => $config['disclaimer'],
				'i18n'       => array(
					'title'       => __( 'Assistente', 'palladio' ),
					'open'        => __( 'Apri la chat', 'palladio' ),
					'close'       => __( 'Chiudi', 'palladio' ),
					'placeholder' => __( 'Scrivi un messaggio…', 'palladio' ),
					'send'        => __( 'Invia', 'palladio' ),
					'error'       => __( 'Si è verificato un errore. Riprova.', 'palladio' ),
					'greeting'    => __( 'Ciao! Come posso aiutarti su questo progetto?', 'palladio' ),
				),
			)
		);
	}

	/**
	 * Stampa il contenitore del widget.
	 *
	 * @return void
	 */
	public function markup() {
		if ( ! $this->should_display() ) {
			return;
		}
		echo '<div id="palladio-agent-root" class="palladio"></div>';
	}

	/**
	 * Versione asset da filemtime.
	 *
	 * @param string $rel Percorso relativo.
	 * @return string
	 */
	private function ver( $rel ) {
		$path = PALLADIO_DIR . $rel;
		return ( is_readable( $path ) && filemtime( $path ) ) ? (string) filemtime( $path ) : PALLADIO_VERSION;
	}
}
