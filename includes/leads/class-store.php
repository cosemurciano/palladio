<?php
/**
 * Modulo Regia — data layer dei lead.
 *
 * I lead vivono in una tabella custom (§3.4): volumi, query e privacy non
 * si adattano bene a un CPT. Questa classe gestisce creazione tabella,
 * inserimento sanitizzato, aggiornamento stato e query aggregate.
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Repository dei lead.
 */
class Palladio_Leads_Store {

	/**
	 * Versione dello schema DB.
	 */
	const DB_VERSION = 1;

	/**
	 * Aggancia il controllo di aggiornamento schema.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_upgrade' ) );
	}

	/**
	 * Nome completo della tabella lead.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'palladio_leads';
	}

	/**
	 * Stati del lead (§3.4) con etichette leggibili.
	 *
	 * @return array<string,string>
	 */
	public static function statuses() {
		return array(
			'nuovo'           => __( 'Nuovo', 'palladio' ),
			'qualificato'     => __( 'Qualificato', 'palladio' ),
			'inviato_agenzia' => __( 'Inviato all’agenzia', 'palladio' ),
			'visita'          => __( 'Visita', 'palladio' ),
			'trattativa'      => __( 'Trattativa', 'palladio' ),
			'chiuso_vinto'    => __( 'Chiuso — vinto', 'palladio' ),
			'chiuso_perso'    => __( 'Chiuso — perso', 'palladio' ),
		);
	}

	/**
	 * Valori ammessi per l'uso previsto.
	 *
	 * @return array<string,string>
	 */
	public static function usi() {
		return array(
			'residenza'   => __( 'Residenza', 'palladio' ),
			'investimento' => __( 'Investimento', 'palladio' ),
			'ricettivo'   => __( 'Ricettivo', 'palladio' ),
			'commerciale' => __( 'Commerciale', 'palladio' ),
		);
	}

	/**
	 * Crea o aggiorna la tabella lead.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			source VARCHAR(100) NOT NULL DEFAULT '',
			utm_source VARCHAR(100) NOT NULL DEFAULT '',
			utm_medium VARCHAR(100) NOT NULL DEFAULT '',
			utm_campaign VARCHAR(150) NOT NULL DEFAULT '',
			lang VARCHAR(10) NOT NULL DEFAULT '',
			nome VARCHAR(150) NOT NULL DEFAULT '',
			email VARCHAR(190) NOT NULL DEFAULT '',
			telefono VARCHAR(50) NOT NULL DEFAULT '',
			paese VARCHAR(100) NOT NULL DEFAULT '',
			unita_ids TEXT NULL,
			scenario_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			budget_range VARCHAR(50) NOT NULL DEFAULT '',
			uso_previsto VARCHAR(50) NOT NULL DEFAULT '',
			timeline VARCHAR(50) NOT NULL DEFAULT '',
			note TEXT NULL,
			consenso_gdpr TINYINT(1) NOT NULL DEFAULT 0,
			consenso_ts DATETIME NULL,
			consenso_testo VARCHAR(255) NOT NULL DEFAULT '',
			stato VARCHAR(30) NOT NULL DEFAULT 'nuovo',
			chat_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			score TINYINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY stato (stato),
			KEY created_at (created_at),
			KEY email (email)
		) {$collate};";

		dbDelta( $sql );

		update_option( 'palladio_db_version', self::DB_VERSION );
	}

	/**
	 * Esegue l'installazione se lo schema è più vecchio.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( (int) get_option( 'palladio_db_version', 0 ) < self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Inserisce un nuovo lead da dati grezzi (già validati a monte).
	 *
	 * @param array $data Coppie campo => valore.
	 * @return int ID inserito, o 0 in caso di errore.
	 */
	public static function insert( array $data ) {
		global $wpdb;

		$now = current_time( 'mysql' );

		$row = array(
			'created_at'     => $now,
			'updated_at'     => $now,
			'source'         => sanitize_text_field( $data['source'] ?? 'form' ),
			'utm_source'     => sanitize_text_field( $data['utm_source'] ?? '' ),
			'utm_medium'     => sanitize_text_field( $data['utm_medium'] ?? '' ),
			'utm_campaign'   => sanitize_text_field( $data['utm_campaign'] ?? '' ),
			'lang'           => sanitize_text_field( $data['lang'] ?? '' ),
			'nome'           => sanitize_text_field( $data['nome'] ?? '' ),
			'email'          => sanitize_email( $data['email'] ?? '' ),
			'telefono'       => sanitize_text_field( $data['telefono'] ?? '' ),
			'paese'          => sanitize_text_field( $data['paese'] ?? '' ),
			'unita_ids'      => wp_json_encode( array_map( 'absint', (array) ( $data['unita_ids'] ?? array() ) ) ),
			'scenario_id'    => absint( $data['scenario_id'] ?? 0 ),
			'budget_range'   => sanitize_text_field( $data['budget_range'] ?? '' ),
			'uso_previsto'   => sanitize_key( $data['uso_previsto'] ?? '' ),
			'timeline'       => sanitize_text_field( $data['timeline'] ?? '' ),
			'note'           => sanitize_textarea_field( $data['note'] ?? '' ),
			'consenso_gdpr'  => ! empty( $data['consenso_gdpr'] ) ? 1 : 0,
			'consenso_ts'    => ! empty( $data['consenso_gdpr'] ) ? $now : null,
			'consenso_testo' => sanitize_text_field( $data['consenso_testo'] ?? '' ),
			'stato'          => 'nuovo',
			'chat_id'        => absint( $data['chat_id'] ?? 0 ),
			'score'          => min( 100, absint( $data['score'] ?? 0 ) ),
		);

		$formats = array(
			'%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
			'%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d',
		);

		$ok = $wpdb->insert( self::table(), $row, $formats ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Recupera un lead per ID.
	 *
	 * @param int $id ID lead.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = self::table();

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Aggiorna lo stato di un lead.
	 *
	 * @param int    $id     ID lead.
	 * @param string $status Nuovo stato (deve essere valido).
	 * @return bool
	 */
	public static function update_status( $id, $status ) {
		if ( ! array_key_exists( $status, self::statuses() ) ) {
			return false;
		}

		global $wpdb;

		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'stato'      => $status,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false !== $updated ) {
			/**
			 * Stato lead cambiato.
			 *
			 * @param int    $id     ID lead.
			 * @param string $status Nuovo stato.
			 */
			do_action( 'palladio/lead_status_changed', (int) $id, $status );
		}

		return false !== $updated;
	}

	/**
	 * Elenca i lead con filtri e paginazione.
	 *
	 * @param array $args stato, search, orderby, order, per_page, offset.
	 * @return array{items:array<object>,total:int}
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$table = self::table();

		$args = wp_parse_args(
			$args,
			array(
				'stato'    => '',
				'search'   => '',
				'orderby'  => 'created_at',
				'order'    => 'DESC',
				'per_page' => 20,
				'offset'   => 0,
			)
		);

		$where  = 'WHERE 1=1';
		$params = array();

		if ( $args['stato'] && array_key_exists( $args['stato'], self::statuses() ) ) {
			$where   .= ' AND stato = %s';
			$params[] = $args['stato'];
		}

		if ( '' !== $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where   .= ' AND ( nome LIKE %s OR email LIKE %s OR telefono LIKE %s )';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		// Whitelist di orderby e order per evitare injection.
		$orderby = in_array( $args['orderby'], array( 'created_at', 'stato', 'nome', 'score' ), true ) ? $args['orderby'] : 'created_at';
		$order   = ( 'ASC' === strtoupper( $args['order'] ) ) ? 'ASC' : 'DESC';

		// Totale.
		$count_sql = "SELECT COUNT(*) FROM {$table} {$where}";
		$total     = (int) ( $params
			? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) // phpcs:ignore WordPress.DB
			: $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB

		// Pagina.
		$list_params = $params;
		$list_params[] = (int) $args['per_page'];
		$list_params[] = (int) $args['offset'];
		$list_sql = "SELECT * FROM {$table} {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$items    = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ) ); // phpcs:ignore WordPress.DB

		return array(
			'items' => $items ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * Conta i lead per stato.
	 *
	 * @return array<string,int> stato => conteggio (0 per stati assenti).
	 */
	public static function counts_by_status() {
		global $wpdb;
		$table = self::table();

		$counts = array_fill_keys( array_keys( self::statuses() ), 0 );

		$rows = $wpdb->get_results( "SELECT stato, COUNT(*) AS n FROM {$table} GROUP BY stato" ); // phpcs:ignore WordPress.DB
		if ( $rows ) {
			foreach ( $rows as $row ) {
				if ( isset( $counts[ $row->stato ] ) ) {
					$counts[ $row->stato ] = (int) $row->n;
				}
			}
		}

		return $counts;
	}

	/**
	 * Conta i lead raggruppati per fonte (top N).
	 *
	 * @param int $limit Numero massimo di fonti.
	 * @return array<string,int>
	 */
	public static function counts_by_source( $limit = 8 ) {
		global $wpdb;
		$table = self::table();

		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"SELECT source, COUNT(*) AS n FROM {$table} GROUP BY source ORDER BY n DESC LIMIT %d",
			(int) $limit
		) );

		$out = array();
		if ( $rows ) {
			foreach ( $rows as $row ) {
				$label         = '' !== $row->source ? $row->source : __( '(sconosciuta)', 'palladio' );
				$out[ $label ] = (int) $row->n;
			}
		}

		return $out;
	}

	/**
	 * Totale lead.
	 *
	 * @return int
	 */
	public static function count_all() {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB
	}
}
