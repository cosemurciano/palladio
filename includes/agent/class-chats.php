<?php
/**
 * Modulo Agent — log delle conversazioni.
 *
 * Ogni sessione di chat è una riga in palladio_chats con lo storico dei
 * messaggi (json). Consultabile dalla regia: le domande reali dei prospect
 * sono la migliore fonte per migliorare schede e FAQ (§5.5).
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Repository delle conversazioni.
 */
class Palladio_Agent_Chats {

	const DB_VERSION = 1;

	/**
	 * Registra il controllo schema.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_upgrade' ) );
	}

	/**
	 * Nome tabella.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'palladio_chats';
	}

	/**
	 * Crea/aggiorna la tabella.
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
			session_id VARCHAR(64) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			lang VARCHAR(10) NOT NULL DEFAULT '',
			messages LONGTEXT NULL,
			lead_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY session_id (session_id)
		) {$collate};";

		dbDelta( $sql );
		update_option( 'palladio_chats_db_version', self::DB_VERSION );
	}

	/**
	 * Aggiorna lo schema se necessario.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( (int) get_option( 'palladio_chats_db_version', 0 ) < self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Recupera la riga di una sessione.
	 *
	 * @param string $session_id ID sessione.
	 * @return object|null
	 */
	public static function get( $session_id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE session_id = %s", $session_id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Restituisce i messaggi di una sessione.
	 *
	 * @param string $session_id ID sessione.
	 * @return array<int,array{role:string,content:string}>
	 */
	public static function messages( $session_id ) {
		$row = self::get( $session_id );
		if ( ! $row ) {
			return array();
		}
		$messages = json_decode( (string) $row->messages, true );
		return is_array( $messages ) ? $messages : array();
	}

	/**
	 * Aggiunge un messaggio alla sessione (creandola se serve).
	 *
	 * @param string $session_id ID sessione.
	 * @param string $role       Ruolo (user|assistant).
	 * @param string $content    Contenuto.
	 * @param string $lang       Lingua.
	 * @return void
	 */
	public static function append( $session_id, $role, $content, $lang = '' ) {
		global $wpdb;
		$table = self::table();
		$now   = current_time( 'mysql' );

		$row = self::get( $session_id );

		$message = array(
			'role'    => in_array( $role, array( 'user', 'assistant' ), true ) ? $role : 'user',
			'content' => $content,
			'ts'      => $now,
		);

		if ( $row ) {
			$messages   = json_decode( (string) $row->messages, true );
			$messages   = is_array( $messages ) ? $messages : array();
			$messages[] = $message;

			// Limite di storico per sessione (controllo dimensione/costi).
			if ( count( $messages ) > 100 ) {
				$messages = array_slice( $messages, -100 );
			}

			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'messages'   => wp_json_encode( $messages ),
					'updated_at' => $now,
					'lang'       => $lang ? $lang : $row->lang,
				),
				array( 'session_id' => $session_id ),
				array( '%s', '%s', '%s' ),
				array( '%s' )
			);
		} else {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'session_id' => $session_id,
					'created_at' => $now,
					'updated_at' => $now,
					'lang'       => $lang,
					'messages'   => wp_json_encode( array( $message ) ),
					'lead_id'    => 0,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%d' )
			);
		}
	}

	/**
	 * Collega un lead alla sessione.
	 *
	 * @param string $session_id ID sessione.
	 * @param int    $lead_id    ID lead.
	 * @return void
	 */
	public static function set_lead( $session_id, $lead_id ) {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array( 'lead_id' => (int) $lead_id ),
			array( 'session_id' => $session_id ),
			array( '%d' ),
			array( '%s' )
		);
	}
}
