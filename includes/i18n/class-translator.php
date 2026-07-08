<?php
/**
 * Modulo Lingue — storage e risoluzione delle traduzioni.
 *
 * Modalità nativa a zero dipendenze (§5.4.A): le traduzioni dei campi
 * testuali vivono in un meta per lingua `_pll_i18n_{lang}` (json), con uno
 * stato per lingua `_pll_i18n_status_{lang}`. Il frontend serve la
 * traduzione solo se "pubblicata", con fallback alla lingua sorgente.
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Helper statici per leggere/scrivere e risolvere le traduzioni.
 */
class Palladio_I18n_Translator {

	/**
	 * Stati possibili di una traduzione (§5.4).
	 *
	 * @return array<string,string>
	 */
	public static function statuses() {
		return array(
			'assente'     => __( 'Assente', 'palladio' ),
			'generata'    => __( 'Generata', 'palladio' ),
			'revisionata' => __( 'Revisionata', 'palladio' ),
			'pubblicata'  => __( 'Pubblicata', 'palladio' ),
		);
	}

	/**
	 * Campi meta traducibili per tipo di post.
	 *
	 * @param string $post_type Slug del CPT.
	 * @return array<string,string> chiave meta (senza prefisso) => etichetta.
	 */
	public static function meta_fields( $post_type ) {
		$map = array(
			'pll_edificio' => array(
				'claim'        => __( 'Claim', 'palladio' ),
				'vincoli_note' => __( 'Vincoli e note legali', 'palladio' ),
			),
			'pll_unita'    => array(),
			'pll_scenario' => array(),
		);

		$fields = $map[ $post_type ] ?? array();

		/**
		 * Filtra i campi meta traducibili di un CPT.
		 *
		 * @param array  $fields    Mappa chiave => etichetta.
		 * @param string $post_type Slug del CPT.
		 */
		return apply_filters( 'palladio/i18n/meta_fields', $fields, $post_type );
	}

	/**
	 * Restituisce il bucket di traduzione per una lingua.
	 *
	 * @param int    $post_id ID post.
	 * @param string $lang    Lingua.
	 * @return array{title:string,excerpt:string,content:string,meta:array}
	 */
	public static function get_bucket( $post_id, $lang ) {
		$raw    = get_post_meta( $post_id, '_pll_i18n_' . sanitize_key( $lang ), true );
		$bucket = is_array( $raw ) ? $raw : array();

		return wp_parse_args(
			$bucket,
			array(
				'title'   => '',
				'excerpt' => '',
				'content' => '',
				'meta'    => array(),
			)
		);
	}

	/**
	 * Stato della traduzione per una lingua.
	 *
	 * @param int    $post_id ID post.
	 * @param string $lang    Lingua.
	 * @return string
	 */
	public static function get_status( $post_id, $lang ) {
		$status = get_post_meta( $post_id, '_pll_i18n_status_' . sanitize_key( $lang ), true );
		return array_key_exists( $status, self::statuses() ) ? $status : 'assente';
	}

	/**
	 * Salva bucket e stato di traduzione.
	 *
	 * @param int    $post_id ID post.
	 * @param string $lang    Lingua.
	 * @param array  $bucket  Campi tradotti.
	 * @param string $status  Stato.
	 * @return void
	 */
	public static function save( $post_id, $lang, array $bucket, $status ) {
		$lang  = sanitize_key( $lang );
		$clean = array(
			'title'   => sanitize_text_field( $bucket['title'] ?? '' ),
			'excerpt' => sanitize_textarea_field( $bucket['excerpt'] ?? '' ),
			'content' => wp_kses_post( $bucket['content'] ?? '' ),
			'meta'    => array(),
		);

		if ( ! empty( $bucket['meta'] ) && is_array( $bucket['meta'] ) ) {
			foreach ( $bucket['meta'] as $key => $value ) {
				$clean['meta'][ sanitize_key( $key ) ] = sanitize_textarea_field( $value );
			}
		}

		update_post_meta( $post_id, '_pll_i18n_' . $lang, $clean );

		$status = array_key_exists( $status, self::statuses() ) ? $status : 'assente';
		update_post_meta( $post_id, '_pll_i18n_status_' . $lang, $status );
	}

	/**
	 * Indica se per un post/lingua va servita la traduzione.
	 *
	 * @param int    $post_id ID post.
	 * @param string $lang    Lingua.
	 * @return bool
	 */
	public static function is_published( $post_id, $lang ) {
		return 'pubblicata' === self::get_status( $post_id, $lang );
	}

	/**
	 * Risolve un campo testuale nella lingua corrente (o indicata).
	 *
	 * @param int    $post_id  ID post.
	 * @param string $field    'title' | 'excerpt' | 'content'.
	 * @param string $original Valore sorgente.
	 * @param string $lang     Lingua (null = corrente).
	 * @return string
	 */
	public static function translate_field( $post_id, $field, $original, $lang = null ) {
		$lang = $lang ? sanitize_key( $lang ) : Palladio_I18n_Languages::current();

		if ( $lang === Palladio_I18n_Languages::source() || ! self::is_published( $post_id, $lang ) ) {
			return $original;
		}

		$bucket = self::get_bucket( $post_id, $lang );
		$value  = $bucket[ $field ] ?? '';

		return ( '' !== $value ) ? $value : $original;
	}

	/**
	 * Risolve un campo meta nella lingua corrente (o indicata).
	 *
	 * @param int    $post_id  ID post.
	 * @param string $meta_key Chiave meta senza prefisso.
	 * @param string $original Valore sorgente.
	 * @param string $lang     Lingua (null = corrente).
	 * @return mixed
	 */
	public static function translate_meta( $post_id, $meta_key, $original, $lang = null ) {
		$lang = $lang ? sanitize_key( $lang ) : Palladio_I18n_Languages::current();

		if ( $lang === Palladio_I18n_Languages::source() || ! self::is_published( $post_id, $lang ) ) {
			return $original;
		}

		$bucket = self::get_bucket( $post_id, $lang );
		$value  = $bucket['meta'][ $meta_key ] ?? '';

		return ( '' !== $value ) ? $value : $original;
	}
}
