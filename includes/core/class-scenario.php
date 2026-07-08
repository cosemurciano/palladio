<?php
/**
 * Modulo Core — Scenari (bundle/split di unità).
 *
 * Feature distintiva (§3.3): configurazioni alternative di vendita.
 * In questo scaffold: registrazione dei meta dello scenario e hook della
 * regola di coerenza (bundle -> non_disponibile quando un'unità è venduta).
 * La logica completa è demandata alla Fase 1 (cfr. Roadmap §7).
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registra i meta dello scenario e la regola di coerenza.
 */
class Palladio_Core_Scenario {

	/**
	 * Aggancia registrazione meta e hook di coerenza.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( $this, 'register_meta' ), 7 );
		add_action( 'set_object_terms', array( $this, 'maybe_sync_scenarios' ), 10, 6 );
	}

	/**
	 * Registra i meta dello scenario.
	 *
	 * @return void
	 */
	public function register_meta() {
		$fields = array(
			// 'bundle' | 'split'.
			'_pll_scenario_tipo'   => array( 'type' => 'string', 'sanitize' => 'sanitize_key' ),
			// Elenco ID unità coinvolte (json array serializzato).
			'_pll_scenario_unita'  => array( 'type' => 'string', 'sanitize' => 'sanitize_text_field' ),
			'_pll_scenario_prezzo' => array( 'type' => 'number', 'sanitize' => array( $this, 'sanitize_float' ) ),
			// 'disponibile' | 'non_disponibile'.
			'_pll_scenario_stato'  => array( 'type' => 'string', 'sanitize' => 'sanitize_key' ),
		);

		foreach ( $fields as $key => $args ) {
			register_post_meta(
				'pll_scenario',
				$key,
				array(
					'type'              => $args['type'],
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => $args['sanitize'],
					'auth_callback'     => function ( $allowed, $meta_key, $post_id ) {
						return current_user_can( 'edit_post', $post_id );
					},
				)
			);
		}
	}

	/**
	 * Quando un'unità passa a "venduta", segnala gli scenari da rivedere.
	 *
	 * Reazione allo stato: se un'unità che compare in uno scenario bundle
	 * diventa `venduta`, lo scenario va portato a `non_disponibile` e il
	 * regista notificato. Qui emettiamo l'evento; la sincronizzazione
	 * completa (query inversa unità -> scenari) arriva in Fase 1.
	 *
	 * @param int    $object_id  ID del post (unità).
	 * @param array  $terms      Termini assegnati.
	 * @param array  $tt_ids     Term taxonomy IDs.
	 * @param string $taxonomy   Tassonomia.
	 * @param bool   $append     Se in append.
	 * @param array  $old_tt_ids Term taxonomy IDs precedenti.
	 * @return void
	 */
	public function maybe_sync_scenarios( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {
		if ( 'pll_stato' !== $taxonomy || 'pll_unita' !== get_post_type( $object_id ) ) {
			return;
		}

		$slugs = wp_get_object_terms( $object_id, 'pll_stato', array( 'fields' => 'slugs' ) );
		if ( is_wp_error( $slugs ) || ! in_array( 'venduta', (array) $slugs, true ) ) {
			return;
		}

		/**
		 * Un'unità è passata a "venduta": gli scenari che la includono
		 * potrebbero non essere più validi.
		 *
		 * @param int $unita_id ID dell'unità venduta.
		 */
		do_action( 'palladio/unit_status_changed', $object_id );
	}

	/**
	 * Sanitizza un valore in float.
	 *
	 * @param mixed $value Valore grezzo.
	 * @return float
	 */
	public function sanitize_float( $value ) {
		return is_numeric( $value ) ? (float) $value : 0.0;
	}
}
