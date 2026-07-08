<?php
/**
 * Orchestratore principale del plugin.
 *
 * Registra i moduli attivi e i loro hook. Ogni modulo è attivabile via
 * filtro `palladio/modules` per installazioni leggere (cfr. §4 del progetto).
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classe loader/orchestratore.
 */
final class Palladio {

	/**
	 * Istanza singleton.
	 *
	 * @var Palladio|null
	 */
	private static $instance = null;

	/**
	 * Moduli istanziati, per chiave.
	 *
	 * @var array<string,object>
	 */
	private $modules = array();

	/**
	 * Evita l'istanziazione diretta.
	 */
	private function __construct() {}

	/**
	 * Restituisce (creandola se serve) l'istanza singleton.
	 *
	 * @return Palladio
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Avvia il plugin: carica traduzioni e moduli.
	 *
	 * Idempotente: chiamarla più volte non duplica gli hook.
	 *
	 * @return void
	 */
	public function run() {
		static $booted = false;
		if ( $booted ) {
			return;
		}
		$booted = true;

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'plugins_loaded', array( $this, 'load_modules' ) );
	}

	/**
	 * Carica il text domain per le traduzioni dell'interfaccia del plugin.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'palladio', false, dirname( PALLADIO_BASENAME ) . '/languages' );
	}

	/**
	 * Istanzia i moduli abilitati e registra i loro hook.
	 *
	 * @return void
	 */
	public function load_modules() {
		/**
		 * Filtra l'elenco dei moduli attivi.
		 *
		 * @param array<string,string> $modules Mappa chiave => nome classe.
		 */
		$modules = apply_filters(
			'palladio/modules',
			array(
				'core_cpt'      => 'Palladio_Core_CPT',
				'core_meta'     => 'Palladio_Core_Meta',
				'core_scenario' => 'Palladio_Core_Scenario',
			)
		);

		foreach ( $modules as $key => $class ) {
			if ( class_exists( $class ) && method_exists( $class, 'register' ) ) {
				$module = new $class();
				$module->register();
				$this->modules[ $key ] = $module;
			}
		}

		/**
		 * Segnala che i moduli Palladio sono stati caricati.
		 *
		 * @param Palladio $plugin Istanza del plugin.
		 */
		do_action( 'palladio/loaded', $this );
	}

	/**
	 * Restituisce un modulo per chiave, se caricato.
	 *
	 * @param string $key Chiave modulo.
	 * @return object|null
	 */
	public function module( $key ) {
		return $this->modules[ $key ] ?? null;
	}
}
