<?php
/**
 * Modulo Lingue — traduzione AI dei contenuti.
 *
 * Traduce una versione in lingua a partire dall'originale collegato:
 * titolo, riassunto, contenuto, meta testuali e tutti i campi editoriali.
 * La qualità richiesta è editoriale (localizzazione, non traduzione
 * letterale) con un vincolo rigido: dati, numeri, prezzi, misure, indirizzi,
 * nomi propri, URL e id non si toccano. Il merge lato PHP garantisce che
 * id, URL e chiavi di controllo restino comunque quelli dell'originale,
 * qualunque cosa risponda il modello.
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Traduzione automatica dall'originale verso la lingua del post.
 */
class Palladio_I18n_Machine {

	/**
	 * Meta testuali traducibili per post type (chiavi senza prefisso _pll_).
	 *
	 * @param string $post_type CPT.
	 * @return string[]
	 */
	private static function translatable_meta( $post_type ) {
		if ( 'pll_edificio' === $post_type ) {
			return array( 'claim', 'sottotitolo', 'vincoli_note' );
		}
		return array( 'esposizione', 'stato_consegna', 'destinazione_uso' );
	}

	/**
	 * Chiavi mai tradotte (id, URL, layout, icone...).
	 *
	 * @param string $key Chiave.
	 * @return bool
	 */
	private static function is_protected_key( $key ) {
		$key = (string) $key;
		if ( in_array( $key, array( 'image', 'file', 'poster', 'layout', 'gallery_layout', 'icon', 'initial', 'src' ), true ) ) {
			return true;
		}
		return (bool) preg_match( '/(_url|_embed|_count)$/', $key ) || 'url' === $key || 'embed' === $key;
	}

	/**
	 * Campi lunghi (multiriga/HTML leggero).
	 *
	 * @param string $key Chiave.
	 * @return bool
	 */
	private static function is_rich_key( $key ) {
		return in_array( (string) $key, array( 'body', 'text', 'definition', 'note', 'notes', 'blazon', 'lead', 'city_text', 'vincoli_note' ), true );
	}

	/**
	 * Traduce il post dalla versione originale collegata.
	 *
	 * @param int $post_id ID della versione in lingua.
	 * @return true|WP_Error
	 */
	public static function translate_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'palladio_i18n_no_post', __( 'Pagina non trovata.', 'palladio' ) );
		}

		$source_lang = Palladio_I18n_Languages::source();
		$target_lang = Palladio_I18n_Translator::get_lang( $post_id );
		if ( $target_lang === $source_lang ) {
			return new WP_Error( 'palladio_i18n_is_source', __( 'Questa è la pagina originale: la traduzione si lancia dalle versioni in lingua.', 'palladio' ) );
		}

		$src_id = Palladio_I18n_Translator::sibling_in( $post_id, $source_lang, array( 'publish', 'draft', 'pending', 'future', 'private' ) );
		if ( ! $src_id ) {
			return new WP_Error( 'palladio_i18n_no_source', __( 'Versione originale non trovata nel gruppo di traduzione.', 'palladio' ) );
		}

		$catalog = Palladio_I18n_Languages::catalog();

		// ------------------------------------------------------------ payload
		$editorial = function_exists( 'palladio_editorial' ) ? palladio_editorial( $src_id ) : array();

		$meta = array();
		foreach ( self::translatable_meta( $post->post_type ) as $key ) {
			$value = (string) palladio_meta( $src_id, $key );
			if ( '' !== $value ) {
				$meta[ $key ] = $value;
			}
		}

		$payload = array(
			'title'     => get_the_title( $src_id ),
			'excerpt'   => (string) get_post_field( 'post_excerpt', $src_id ),
			'content'   => (string) get_post_field( 'post_content', $src_id ),
			'meta'      => $meta,
			'editorial' => $editorial,
		);

		$instructions = sprintf(
			'Sei un traduttore editoriale madrelingua %1$s, specializzato in immobili di pregio e palazzi storici. ' .
			'Traduci i contenuti JSON dal %2$s al %1$s con la massima qualità: scrittura naturale e idiomatica, tono elegante da luxury real estate, MAI una traduzione letterale parola per parola. ' .
			'VINCOLI ASSOLUTI: non alterare significato e concetti; non modificare numeri, prezzi, misure, percentuali, date, indirizzi, coordinate, nomi propri, toponimi e riferimenti normativi; ' .
			'i termini architettonici italiani intraducibili (es. "palazzo", "piano nobile", "volta a stella") restano in italiano, eventualmente con naturalezza nel contesto. ' .
			'STRUTTURA: restituisci SOLO il JSON con la STESSA identica struttura (stesse chiavi, stessi tipi, stessi elementi negli array); traduci esclusivamente i valori stringa testuali; ' .
			'NON toccare: id numerici, URL, embed, chiavi "layout", "gallery_layout", "icon", "src", valori vuoti. Il campo "content" può contenere HTML: conserva i tag e traduci solo il testo.',
			$catalog[ $target_lang ] ?? $target_lang,
			$catalog[ $source_lang ] ?? $source_lang
		);

		$result = Palladio_AI_Openai::responses(
			$instructions,
			wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
			array(
				'json'       => true,
				'max_tokens' => 16000,
				'timeout'    => 240,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$translated = json_decode( (string) $result['text'], true );
		if ( ! is_array( $translated ) ) {
			return new WP_Error( 'palladio_i18n_bad_json', __( 'La risposta del modello non è un JSON valido: riprova.', 'palladio' ) );
		}

		// ------------------------------------------------------------- apply
		$update = array( 'ID' => $post_id );

		if ( ! empty( $translated['title'] ) && is_string( $translated['title'] ) ) {
			$update['post_title'] = sanitize_text_field( $translated['title'] );
			// Le bozze prendono lo slug dalla lingua di destinazione.
			if ( in_array( $post->post_status, array( 'draft', 'pending', 'auto-draft' ), true ) ) {
				$update['post_name'] = wp_unique_post_slug(
					sanitize_title( $update['post_title'] ),
					$post_id,
					$post->post_status,
					$post->post_type,
					$post->post_parent
				);
			}
		}
		if ( isset( $translated['excerpt'] ) && is_string( $translated['excerpt'] ) ) {
			$update['post_excerpt'] = sanitize_textarea_field( $translated['excerpt'] );
		}
		if ( isset( $translated['content'] ) && is_string( $translated['content'] ) ) {
			$update['post_content'] = wp_kses_post( $translated['content'] );
		}

		$updated = wp_update_post( wp_slash( $update ), true );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		foreach ( self::translatable_meta( $post->post_type ) as $key ) {
			if ( isset( $meta[ $key ], $translated['meta'][ $key ] ) && is_string( $translated['meta'][ $key ] ) && '' !== trim( $translated['meta'][ $key ] ) ) {
				$clean = self::is_rich_key( $key ) ? sanitize_textarea_field( $translated['meta'][ $key ] ) : sanitize_text_field( $translated['meta'][ $key ] );
				update_post_meta( $post_id, '_pll_' . $key, $clean );
			}
		}

		if ( isset( $translated['editorial'] ) && is_array( $translated['editorial'] ) ) {
			$merged = self::merge_editorial( $editorial, $translated['editorial'] );
			update_post_meta( $post_id, '_pll_editorial', $merged );
		}

		return true;
	}

	/**
	 * Fonde la traduzione sull'originale: solo i valori stringa testuali
	 * vengono sostituiti; id, URL, chiavi protette e struttura restano
	 * quelli dell'originale.
	 *
	 * @param array $orig  Struttura originale.
	 * @param array $trans Struttura tradotta (non fidata).
	 * @return array
	 */
	private static function merge_editorial( $orig, $trans ) {
		$out = array();

		foreach ( $orig as $key => $value ) {
			if ( is_array( $value ) ) {
				$out[ $key ] = ( isset( $trans[ $key ] ) && is_array( $trans[ $key ] ) )
					? self::merge_editorial( $value, $trans[ $key ] )
					: $value;
				continue;
			}

			if (
				is_string( $value ) && '' !== $value
				&& ! self::is_protected_key( $key )
				&& ! preg_match( '#^(https?://|/|\#)#i', $value )
				&& isset( $trans[ $key ] ) && is_string( $trans[ $key ] ) && '' !== trim( $trans[ $key ] )
			) {
				$out[ $key ] = self::is_rich_key( $key )
					? wp_kses_post( $trans[ $key ] )
					: sanitize_text_field( $trans[ $key ] );
				continue;
			}

			$out[ $key ] = $value;
		}

		return $out;
	}
}
