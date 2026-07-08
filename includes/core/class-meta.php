<?php
/**
 * Modulo Core — registrazione dei campi meta strutturati.
 *
 * Tutti i meta sono `show_in_rest => true` (headless-ready, cfr. §4).
 * Set rappresentativo dei campi descritti in §3.1 (Edificio) e §3.2 (Unità);
 * estendibile via filtro `palladio/meta/{cpt}`.
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registra i post meta di edificio e unità.
 */
class Palladio_Core_Meta {

	/**
	 * Aggancia la registrazione all'hook init.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( $this, 'register_meta' ), 7 );
	}

	/**
	 * Registra tutti i meta dei CPT del Core.
	 *
	 * @return void
	 */
	public function register_meta() {
		$this->register_for( 'pll_edificio', $this->edificio_fields() );
		$this->register_for( 'pll_unita', $this->unita_fields() );
	}

	/**
	 * Registra un set di campi per un dato post type.
	 *
	 * @param string                     $post_type Slug del CPT.
	 * @param array<string,array<string,mixed>> $fields    Definizione campi.
	 * @return void
	 */
	private function register_for( $post_type, $fields ) {
		/**
		 * Filtra i campi meta di un CPT prima della registrazione.
		 *
		 * @param array  $fields    Definizione campi.
		 * @param string $post_type Slug del CPT.
		 */
		$fields = apply_filters( "palladio/meta/{$post_type}", $fields, $post_type );

		foreach ( $fields as $key => $args ) {
			$type     = $args['type'] ?? 'string';
			$sanitize = $args['sanitize_callback'] ?? $this->default_sanitizer( $type );

			register_post_meta(
				$post_type,
				$key,
				array(
					'type'              => $type,
					'single'            => true,
					'default'           => $args['default'] ?? $this->default_value( $type ),
					'show_in_rest'      => true,
					'sanitize_callback' => $sanitize,
					'auth_callback'     => function ( $allowed, $meta_key, $post_id ) {
						return current_user_can( 'edit_post', $post_id );
					},
				)
			);
		}
	}

	/**
	 * Campi meta dell'edificio (identità, dati, vincoli, contatti).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function edificio_fields() {
		return array(
			'_pll_claim'            => array( 'type' => 'string' ),
			'_pll_indirizzo'        => array( 'type' => 'string' ),
			'_pll_geo_lat'          => array( 'type' => 'number' ),
			'_pll_geo_lng'          => array( 'type' => 'number' ),
			'_pll_anno_costruzione' => array( 'type' => 'integer' ),
			'_pll_mq_totali'        => array( 'type' => 'number' ),
			'_pll_num_piani'        => array( 'type' => 'integer' ),
			'_pll_num_unita_totali' => array( 'type' => 'integer' ),
			'_pll_num_unita_vendita' => array( 'type' => 'integer' ),
			'_pll_vincoli_note'     => array( 'type' => 'string' ),
			'_pll_contatto_agenzia' => array( 'type' => 'string' ),
			'_pll_contatto_email'   => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_email' ),
			'_pll_contatto_tel'     => array( 'type' => 'string' ),
		);
	}

	/**
	 * Campi meta dell'unità (commerciali e fisici).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function unita_fields() {
		return array(
			// Commerciali.
			'_pll_prezzo'            => array( 'type' => 'number' ),
			'_pll_prezzo_trattabile' => array( 'type' => 'boolean' ),
			'_pll_millesimi'         => array( 'type' => 'number' ),
			'_pll_spese_condominiali' => array( 'type' => 'number' ),
			'_pll_classe_energetica' => array( 'type' => 'string' ),
			'_pll_stato_consegna'    => array( 'type' => 'string' ),
			// Fisici.
			'_pll_mq_commerciali'    => array( 'type' => 'number' ),
			'_pll_mq_coperti'        => array( 'type' => 'number' ),
			'_pll_vani'              => array( 'type' => 'number' ),
			'_pll_camere'            => array( 'type' => 'integer' ),
			'_pll_bagni'             => array( 'type' => 'integer' ),
			'_pll_esposizione'       => array( 'type' => 'string' ),
			'_pll_terrazza_mq'       => array( 'type' => 'number' ),
			'_pll_giardino_mq'       => array( 'type' => 'number' ),
			// Destinazione d'uso.
			'_pll_destinazione_uso'  => array( 'type' => 'string' ),
			'_pll_cambio_uso_verificato' => array( 'type' => 'boolean' ),
			'_pll_cambio_uso_nota'   => array( 'type' => 'string' ),
			// Media.
			'_pll_virtual_tour_url'  => array( 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ),
			'_pll_video_url'         => array( 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ),
		);
	}

	/**
	 * Restituisce il sanitizer di default per un tipo scalare.
	 *
	 * @param string $type Tipo del meta.
	 * @return callable
	 */
	private function default_sanitizer( $type ) {
		switch ( $type ) {
			case 'integer':
				return 'absint';
			case 'number':
				return function ( $value ) {
					return is_numeric( $value ) ? (float) $value : 0.0;
				};
			case 'boolean':
				return function ( $value ) {
					return (bool) $value;
				};
			default:
				return 'sanitize_text_field';
		}
	}

	/**
	 * Valore di default coerente col tipo.
	 *
	 * @param string $type Tipo del meta.
	 * @return mixed
	 */
	private function default_value( $type ) {
		switch ( $type ) {
			case 'integer':
				return 0;
			case 'number':
				return 0.0;
			case 'boolean':
				return false;
			default:
				return '';
		}
	}
}
