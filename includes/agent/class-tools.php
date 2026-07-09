<?php
/**
 * Modulo Agent — tools (function calling).
 *
 * Definizioni e implementazioni dei tool richiamabili dall'agent. Prezzi e
 * stati NON provengono dalla KB ma sempre dal DB, per non rischiare dati
 * obsoleti (§5.5).
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool dell'agent.
 */
class Palladio_Agent_Tools {

	/**
	 * Definizioni dei tool nel formato OpenAI.
	 *
	 * @return array<int,array>
	 */
	public static function definitions() {
		return array(
			array(
				'type'     => 'function',
				'function' => array(
					'name'        => 'get_unit_details',
					'description' => 'Restituisce prezzo, stato di vendita e caratteristiche aggiornate di una singola unità dato il suo ID.',
					'parameters'  => array(
						'type'       => 'object',
						'properties' => array(
							'unit_id' => array(
								'type'        => 'integer',
								'description' => 'ID dell’unità.',
							),
						),
						'required'   => array( 'unit_id' ),
					),
				),
			),
			array(
				'type'     => 'function',
				'function' => array(
					'name'        => 'list_available_units',
					'description' => 'Elenca le unità disponibili, opzionalmente filtrate per prezzo massimo, camere minime o tipologia.',
					'parameters'  => array(
						'type'       => 'object',
						'properties' => array(
							'max_price'    => array( 'type' => 'number', 'description' => 'Prezzo massimo in EUR.' ),
							'min_rooms'    => array( 'type' => 'integer', 'description' => 'Numero minimo di camere.' ),
							'building_id'  => array( 'type' => 'integer', 'description' => 'Limita a un edificio.' ),
						),
					),
				),
			),
			array(
				'type'     => 'function',
				'function' => array(
					'name'        => 'save_lead',
					'description' => 'Salva i contatti dell’utente come lead. Richiede il consenso esplicito al trattamento dei dati (consent=true).',
					'parameters'  => array(
						'type'       => 'object',
						'properties' => array(
							'nome'    => array( 'type' => 'string' ),
							'email'   => array( 'type' => 'string' ),
							'telefono' => array( 'type' => 'string' ),
							'unit_id' => array( 'type' => 'integer' ),
							'note'    => array( 'type' => 'string' ),
							'consent' => array( 'type' => 'boolean', 'description' => 'True solo se l’utente ha acconsentito esplicitamente.' ),
						),
						'required'   => array( 'nome', 'email', 'consent' ),
					),
				),
			),
		);
	}

	/**
	 * Esegue un tool per nome.
	 *
	 * @param string $name    Nome tool.
	 * @param array  $args    Argomenti.
	 * @param array  $context session_id, lang.
	 * @return array Risultato serializzabile.
	 */
	public static function run( $name, array $args, array $context = array() ) {
		switch ( $name ) {
			case 'get_unit_details':
				return self::get_unit_details( $args );
			case 'list_available_units':
				return self::list_available_units( $args );
			case 'save_lead':
				return self::save_lead( $args, $context );
			default:
				return array( 'error' => 'unknown_tool' );
		}
	}

	/**
	 * Dettagli aggiornati di un'unità.
	 *
	 * @param array $args unit_id.
	 * @return array
	 */
	private static function get_unit_details( $args ) {
		$unit_id = absint( $args['unit_id'] ?? 0 );
		$post    = $unit_id ? get_post( $unit_id ) : null;

		if ( ! $post || 'pll_unita' !== $post->post_type || 'publish' !== $post->post_status ) {
			return array( 'error' => 'not_found' );
		}

		$status = function_exists( 'palladio_get_unit_status' ) ? palladio_get_unit_status( $unit_id ) : array( 'label' => '' );

		return array(
			'unit_id'           => $unit_id,
			'titolo'            => get_the_title( $unit_id ),
			'prezzo_eur'        => (float) get_post_meta( $unit_id, '_pll_prezzo', true ),
			'stato'             => $status['label'],
			'mq_commerciali'    => (float) get_post_meta( $unit_id, '_pll_mq_commerciali', true ),
			'camere'            => (int) get_post_meta( $unit_id, '_pll_camere', true ),
			'bagni'             => (int) get_post_meta( $unit_id, '_pll_bagni', true ),
			'classe_energetica' => (string) get_post_meta( $unit_id, '_pll_classe_energetica', true ),
			'url'               => get_permalink( $unit_id ),
		);
	}

	/**
	 * Elenca le unità disponibili.
	 *
	 * @param array $args Filtri.
	 * @return array
	 */
	private static function list_available_units( $args ) {
		$meta_query = array( 'relation' => 'AND' );

		if ( ! empty( $args['max_price'] ) ) {
			$meta_query[] = array(
				'key'     => '_pll_prezzo',
				'value'   => (float) $args['max_price'],
				'compare' => '<=',
				'type'    => 'NUMERIC',
			);
		}
		if ( ! empty( $args['min_rooms'] ) ) {
			$meta_query[] = array(
				'key'     => '_pll_camere',
				'value'   => (int) $args['min_rooms'],
				'compare' => '>=',
				'type'    => 'NUMERIC',
			);
		}

		$query_args = array(
			'post_type'      => 'pll_unita',
			'post_status'    => 'publish',
			'posts_per_page' => 10,
			'tax_query'      => array(
				array(
					'taxonomy' => 'pll_stato',
					'field'    => 'slug',
					'terms'    => array( 'disponibile' ),
				),
			),
			'no_found_rows'  => true,
		);

		if ( count( $meta_query ) > 1 ) {
			$query_args['meta_query'] = $meta_query;
		}
		if ( ! empty( $args['building_id'] ) ) {
			$query_args['post_parent'] = absint( $args['building_id'] );
		}

		$query = new WP_Query( $query_args );
		$units = array();

		foreach ( $query->posts as $post ) {
			$units[] = array(
				'unit_id'    => $post->ID,
				'titolo'     => get_the_title( $post ),
				'prezzo_eur' => (float) get_post_meta( $post->ID, '_pll_prezzo', true ),
				'camere'     => (int) get_post_meta( $post->ID, '_pll_camere', true ),
				'url'        => get_permalink( $post ),
			);
		}

		return array( 'count' => count( $units ), 'units' => $units );
	}

	/**
	 * Salva un lead dai dati raccolti in chat.
	 *
	 * @param array $args    Campi lead.
	 * @param array $context session_id, lang.
	 * @return array
	 */
	private static function save_lead( $args, $context ) {
		if ( empty( $args['consent'] ) ) {
			return array( 'saved' => false, 'reason' => 'consent_required' );
		}

		$email = sanitize_email( $args['email'] ?? '' );
		$nome  = sanitize_text_field( $args['nome'] ?? '' );

		if ( '' === $nome || ! is_email( $email ) ) {
			return array( 'saved' => false, 'reason' => 'invalid_fields' );
		}

		if ( ! class_exists( 'Palladio_Leads_Store' ) ) {
			return array( 'saved' => false, 'reason' => 'unavailable' );
		}

		$unit_id = absint( $args['unit_id'] ?? 0 );

		$lead_id = Palladio_Leads_Store::insert(
			array(
				'source'         => 'agent',
				'lang'           => $context['lang'] ?? '',
				'nome'           => $nome,
				'email'          => $email,
				'telefono'       => sanitize_text_field( $args['telefono'] ?? '' ),
				'note'           => sanitize_textarea_field( $args['note'] ?? '' ),
				'unita_ids'      => $unit_id ? array( $unit_id ) : array(),
				'consenso_gdpr'  => true,
				'consenso_testo' => __( 'Consenso raccolto in chat con l’assistente AI.', 'palladio' ),
			)
		);

		if ( ! $lead_id ) {
			return array( 'saved' => false, 'reason' => 'db_error' );
		}

		if ( ! empty( $context['session_id'] ) ) {
			Palladio_Agent_Chats::set_lead( $context['session_id'], $lead_id );
		}

		/**
		 * Nuovo lead dall'agent.
		 *
		 * @param int   $lead_id ID lead.
		 * @param array $args    Dati raccolti.
		 */
		do_action( 'palladio/lead_created', $lead_id, $args );

		return array( 'saved' => true, 'lead_id' => $lead_id );
	}
}
