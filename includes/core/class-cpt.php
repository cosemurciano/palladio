<?php
/**
 * Modulo Core — Custom Post Types e tassonomie.
 *
 * Modello dati a tre livelli (cfr. §3 del progetto):
 *   Edificio (pll_edificio) -> Unità (pll_unita, post_parent = edificio)
 *   Scenario (pll_scenario) — entità trasversale bundle/split.
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registra CPT e tassonomie del modello dati.
 */
class Palladio_Core_CPT {

	/**
	 * Aggancia la registrazione all'hook init.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( __CLASS__, 'register_post_types' ), 5 );
		add_action( 'init', array( __CLASS__, 'register_taxonomies' ), 6 );
		add_action( 'init', array( __CLASS__, 'maybe_flush_rewrites' ), 99 );
	}

	/**
	 * Rigenera le rewrite rules quando cambia la versione del plugin.
	 *
	 * Le modifiche a slug/gerarchie dei CPT diventano così effettive senza
	 * che l'utente debba risalvare i permalink a mano.
	 *
	 * @return void
	 */
	public static function maybe_flush_rewrites() {
		if ( get_option( 'palladio_rewrite_version' ) !== PALLADIO_VERSION ) {
			flush_rewrite_rules( false );
			update_option( 'palladio_rewrite_version', PALLADIO_VERSION );
		}
	}

	/**
	 * Registra i Custom Post Types.
	 *
	 * Statico per essere riutilizzabile in fase di attivazione
	 * (prima del flush delle rewrite rules).
	 *
	 * @return void
	 */
	public static function register_post_types() {
		// --- Edificio: il contenitore-brand. -------------------------------
		register_post_type(
			'pll_edificio',
			array(
				'labels'       => array(
					'name'          => __( 'Edifici', 'palladio' ),
					'singular_name' => __( 'Edificio', 'palladio' ),
					'add_new_item'  => __( 'Aggiungi edificio', 'palladio' ),
					'edit_item'     => __( 'Modifica edificio', 'palladio' ),
					'menu_name'     => __( 'Palladio', 'palladio' ),
				),
				'public'       => true,
				'has_archive'  => true,
				'menu_icon'    => 'dashicons-bank',
				'menu_position' => 26,
				'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields' ),
				'rewrite'      => array( 'slug' => 'edificio' ),
				'show_in_rest' => true,
			)
		);

		// --- Unità: il prodotto in vendita, figlia dell'edificio. ----------
		register_post_type(
			'pll_unita',
			array(
				'labels'       => array(
					'name'          => __( 'Unità', 'palladio' ),
					'singular_name' => __( 'Unità', 'palladio' ),
					'add_new_item'  => __( 'Aggiungi unità', 'palladio' ),
					'edit_item'     => __( 'Modifica unità', 'palladio' ),
				),
				'public'       => true,
				'has_archive'  => true, // Elenco unità su /unita/.
				'show_in_menu' => 'edit.php?post_type=pll_edificio',
				'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields', 'page-attributes' ),
				// NON gerarchico: il genitore (post_parent) è un pll_edificio,
				// cioè un ALTRO post type. Con hierarchical=true WordPress
				// costruiva permalink /unita/{edificio}/{unita}/ che non
				// risolveva mai (il segmento genitore non esiste tra le unità)
				// → 404 su tutte le schede. post_parent resta comunque usato
				// come collegamento logico all'edificio.
				'hierarchical' => false,
				'rewrite'      => array( 'slug' => 'unita' ),
				'show_in_rest' => true,
			)
		);

		// --- Scenario: bundle/split di unità (feature distintiva). ---------
		register_post_type(
			'pll_scenario',
			array(
				'labels'       => array(
					'name'          => __( 'Scenari', 'palladio' ),
					'singular_name' => __( 'Scenario', 'palladio' ),
					'add_new_item'  => __( 'Aggiungi scenario', 'palladio' ),
					'edit_item'     => __( 'Modifica scenario', 'palladio' ),
				),
				'public'       => true,
				'has_archive'  => false,
				'show_in_menu' => 'edit.php?post_type=pll_edificio',
				'supports'     => array( 'title', 'editor', 'thumbnail', 'custom-fields' ),
				'rewrite'      => array( 'slug' => 'scenario' ),
				'show_in_rest' => true,
			)
		);
	}

	/**
	 * Registra le tassonomie delle unità.
	 *
	 * @return void
	 */
	public static function register_taxonomies() {
		// Tipologia: appartamento, locale commerciale, deposito, giardino, box...
		register_taxonomy(
			'pll_tipologia',
			array( 'pll_unita' ),
			array(
				'labels'       => array(
					'name'          => __( 'Tipologie', 'palladio' ),
					'singular_name' => __( 'Tipologia', 'palladio' ),
				),
				'hierarchical' => true,
				'public'       => true,
				'show_admin_column' => true,
				'show_in_rest' => true,
				'rewrite'      => array( 'slug' => 'tipologia' ),
			)
		);

		// Piano.
		register_taxonomy(
			'pll_piano',
			array( 'pll_unita' ),
			array(
				'labels'       => array(
					'name'          => __( 'Piani', 'palladio' ),
					'singular_name' => __( 'Piano', 'palladio' ),
				),
				'hierarchical' => true,
				'public'       => true,
				'show_admin_column' => true,
				'show_in_rest' => true,
				'rewrite'      => array( 'slug' => 'piano' ),
			)
		);

		// Stato di vendita: disponibile | riservata | in_trattativa | venduta | non_in_vendita.
		register_taxonomy(
			'pll_stato',
			array( 'pll_unita' ),
			array(
				'labels'       => array(
					'name'          => __( 'Stati di vendita', 'palladio' ),
					'singular_name' => __( 'Stato di vendita', 'palladio' ),
				),
				'hierarchical' => true,
				'public'       => true,
				'show_admin_column' => true,
				'show_in_rest' => true,
				'rewrite'      => array( 'slug' => 'stato' ),
			)
		);
	}

	/**
	 * Termini di default per lo stato di vendita.
	 *
	 * Usato in fase di attivazione per popolare la tassonomia `pll_stato`.
	 *
	 * @return array<string,string> slug => nome leggibile.
	 */
	public static function default_stato_terms() {
		return array(
			'disponibile'    => __( 'Disponibile', 'palladio' ),
			'riservata'      => __( 'Riservata', 'palladio' ),
			'in_trattativa'  => __( 'In trattativa', 'palladio' ),
			'venduta'        => __( 'Venduta', 'palladio' ),
			'non_in_vendita' => __( 'Non in vendita', 'palladio' ),
		);
	}
}
