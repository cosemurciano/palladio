<?php
/**
 * Attivazione del plugin.
 *
 * Registra i CPT (necessari prima del flush), popola i termini di default,
 * assegna le capability al ruolo administrator e salva la versione.
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routine di attivazione.
 */
class Palladio_Activator {

	/**
	 * Eseguita all'attivazione del plugin.
	 *
	 * @return void
	 */
	public static function activate() {
		// I CPT devono essere registrati prima del flush delle rewrite rules.
		if ( class_exists( 'Palladio_Core_CPT' ) ) {
			Palladio_Core_CPT::register_post_types();
			Palladio_Core_CPT::register_taxonomies();
			self::seed_stato_terms();
		}

		// Tabella lead (modulo Regia).
		if ( class_exists( 'Palladio_Leads_Store' ) ) {
			Palladio_Leads_Store::install();
		}

		// Tabelle Agent (KB + conversazioni).
		if ( class_exists( 'Palladio_Agent_KB' ) ) {
			Palladio_Agent_KB::install();
		}
		if ( class_exists( 'Palladio_Agent_Chats' ) ) {
			Palladio_Agent_Chats::install();
		}

		self::add_capabilities();

		// Registra la rewrite del feed prima del flush.
		if ( class_exists( 'Palladio_Feeds_Manager' ) ) {
			( new Palladio_Feeds_Manager() )->rewrite();
		}

		update_option( 'palladio_version', PALLADIO_VERSION );

		flush_rewrite_rules();
	}

	/**
	 * Popola la tassonomia degli stati di vendita con i termini di default.
	 *
	 * @return void
	 */
	private static function seed_stato_terms() {
		foreach ( Palladio_Core_CPT::default_stato_terms() as $slug => $name ) {
			if ( ! term_exists( $slug, 'pll_stato' ) ) {
				wp_insert_term( $name, 'pll_stato', array( 'slug' => $slug ) );
			}
		}
	}

	/**
	 * Assegna le capability di regia al ruolo administrator.
	 *
	 * `manage_palladio` (regia) — cfr. §6 del progetto.
	 *
	 * @return void
	 */
	private static function add_capabilities() {
		$role = get_role( 'administrator' );
		if ( $role ) {
			$role->add_cap( 'manage_palladio' );
			$role->add_cap( 'edit_palladio_content' );
		}
	}
}
