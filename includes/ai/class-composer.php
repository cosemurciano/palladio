<?php
/**
 * Modulo AI — Composer: generazione contenuti dai dati strutturati.
 *
 * Genera schede (titolo, abstract, descrizione, meta description, FAQ) a
 * partire dai campi strutturati dell'unità/edificio (§5.3), mai il
 * contrario. L'output è una bozza rivedibile; la traduzione si appoggia al
 * data layer i18n (§5.4) salvando lo stato "generata".
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generazione e traduzione contenuti via OpenAI.
 */
class Palladio_AI_Composer {

	/**
	 * Genera la bozza di scheda per un post e la salva in _pll_ai_draft.
	 *
	 * @param int $post_id ID post (unità o edificio).
	 * @return array|WP_Error Bozza generata.
	 */
	public static function generate_scheda( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'palladio_ai_no_post', __( 'Post non trovato.', 'palladio' ) );
		}

		$facts  = self::collect_facts( $post );
		$source = Palladio_I18n_Languages::source();

		$system = __( 'Sei un copywriter immobiliare. Scrivi in modo accurato, elegante e concreto, senza inventare dati non forniti. Rispondi esclusivamente con un oggetto JSON valido.', 'palladio' );

		$user = sprintf(
			/* translators: 1: lingua, 2: dati strutturati. */
			__( 'Lingua di output: %1$s. Genera i contenuti per questa scheda immobiliare a partire dai dati seguenti (non aggiungere dati non presenti). Restituisci un JSON con le chiavi: title (stringa breve), abstract (1-2 frasi), description (2-4 paragrafi in HTML semplice con <p>), meta_description (max 155 caratteri), faq (array di oggetti {q, a}, 3-5 voci). Dati:%2$s', 'palladio' ),
			$source,
			"\n" . $facts
		);

		$result = Palladio_AI_Openai::chat(
			array(
				array(
					'role'    => 'system',
					'content' => $system,
				),
				array(
					'role'    => 'user',
					'content' => $user,
				),
			),
			array(
				'json'        => true,
				'temperature' => 0.7,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = json_decode( $result['content'], true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'palladio_ai_bad_json', __( 'Risposta AI non valida.', 'palladio' ) );
		}

		$draft = array(
			'title'            => sanitize_text_field( $data['title'] ?? '' ),
			'abstract'         => sanitize_textarea_field( $data['abstract'] ?? '' ),
			'description'      => wp_kses_post( $data['description'] ?? '' ),
			'meta_description' => sanitize_text_field( $data['meta_description'] ?? '' ),
			'faq'              => self::sanitize_faq( $data['faq'] ?? array() ),
			'generated_at'     => current_time( 'mysql' ),
		);

		update_post_meta( $post_id, '_pll_ai_draft', $draft );

		return $draft;
	}

	/**
	 * Applica la bozza generata ai campi del post.
	 *
	 * @param int $post_id ID post.
	 * @return bool|WP_Error
	 */
	public static function apply_draft( $post_id ) {
		$draft = get_post_meta( $post_id, '_pll_ai_draft', true );
		if ( empty( $draft ) || ! is_array( $draft ) ) {
			return new WP_Error( 'palladio_ai_no_draft', __( 'Nessuna bozza da applicare.', 'palladio' ) );
		}

		$update = array( 'ID' => $post_id );
		if ( ! empty( $draft['title'] ) ) {
			$update['post_title'] = $draft['title'];
		}
		if ( ! empty( $draft['description'] ) ) {
			$update['post_content'] = $draft['description'];
		}
		if ( ! empty( $draft['abstract'] ) ) {
			$update['post_excerpt'] = $draft['abstract'];
		}

		$result = wp_update_post( $update, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! empty( $draft['meta_description'] ) ) {
			update_post_meta( $post_id, '_pll_meta_description', sanitize_text_field( $draft['meta_description'] ) );
		}
		if ( ! empty( $draft['faq'] ) ) {
			update_post_meta( $post_id, '_pll_faq', $draft['faq'] );
		}

		return true;
	}

	/**
	 * Traduce titolo/riassunto/contenuto in una lingua e salva nel bucket i18n.
	 *
	 * @param int    $post_id ID post.
	 * @param string $lang    Lingua di destinazione.
	 * @return array|WP_Error Bucket tradotto.
	 */
	public static function translate( $post_id, $lang ) {
		$lang = sanitize_key( $lang );
		$post = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'palladio_ai_no_post', __( 'Post non trovato.', 'palladio' ) );
		}

		if ( ! class_exists( 'Palladio_I18n_Languages' ) || ! Palladio_I18n_Languages::is_active( $lang ) ) {
			return new WP_Error( 'palladio_ai_bad_lang', __( 'Lingua non attiva.', 'palladio' ) );
		}

		$catalog = Palladio_I18n_Languages::catalog();
		$target  = $catalog[ $lang ] ?? $lang;

		$payload = wp_json_encode(
			array(
				'title'   => get_the_title( $post ),
				'excerpt' => $post->post_excerpt,
				'content' => $post->post_content,
			)
		);

		$system = __( 'Sei un traduttore professionista per il settore immobiliare di lusso. Traduci in modo contestuale e naturale, adattando registro e riferimenti culturali, senza tradurre nomi propri. Rispondi esclusivamente con un oggetto JSON valido.', 'palladio' );

		$user = sprintf(
			/* translators: 1: lingua destinazione, 2: json sorgente. */
			__( 'Traduci in %1$s i seguenti campi mantenendo l’HTML del contenuto. Restituisci un JSON con le stesse chiavi (title, excerpt, content). Sorgente: %2$s', 'palladio' ),
			$target,
			$payload
		);

		$result = Palladio_AI_Openai::chat(
			array(
				array(
					'role'    => 'system',
					'content' => $system,
				),
				array(
					'role'    => 'user',
					'content' => $user,
				),
			),
			array(
				'model'       => Palladio_AI_Settings::translate_model(),
				'json'        => true,
				'temperature' => 0.3,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = json_decode( $result['content'], true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'palladio_ai_bad_json', __( 'Risposta AI non valida.', 'palladio' ) );
		}

		// Crea (o riusa) la pagina clone nella lingua di destinazione e la popola.
		$target_id = Palladio_I18n_Translator::clone_post( $post_id, $lang );
		if ( is_wp_error( $target_id ) ) {
			return $target_id;
		}

		wp_update_post(
			array(
				'ID'           => $target_id,
				'post_title'   => sanitize_text_field( $data['title'] ?? get_the_title( $post_id ) ),
				'post_excerpt' => sanitize_textarea_field( $data['excerpt'] ?? '' ),
				'post_content' => wp_kses_post( $data['content'] ?? '' ),
			)
		);

		return array(
			'target_id' => (int) $target_id,
			'edit_link' => get_edit_post_link( $target_id, 'raw' ),
		);
	}

	/**
	 * Raccoglie i dati strutturati rilevanti come testo per il prompt.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private static function collect_facts( $post ) {
		$lines = array( 'Tipo: ' . $post->post_type );
		$title = get_the_title( $post );
		if ( $title ) {
			$lines[] = 'Titolo attuale: ' . $title;
		}

		if ( 'pll_unita' === $post->post_type ) {
			$keys = array(
				'mq_commerciali'    => 'Superficie commerciale (m²)',
				'vani'              => 'Vani',
				'camere'            => 'Camere',
				'bagni'             => 'Bagni',
				'piano'             => 'Piano',
				'esposizione'       => 'Esposizione',
				'classe_energetica' => 'Classe energetica',
				'prezzo'            => 'Prezzo (EUR)',
				'destinazione_uso'  => 'Destinazione d’uso',
			);
		} else {
			$keys = array(
				'claim'            => 'Claim',
				'indirizzo'        => 'Indirizzo',
				'anno_costruzione' => 'Anno di costruzione',
				'mq_totali'        => 'Superficie totale (m²)',
				'num_piani'        => 'Numero di piani',
				'vincoli_note'     => 'Vincoli e note',
			);
		}

		foreach ( $keys as $key => $label ) {
			$value = get_post_meta( $post->ID, '_pll_' . $key, true );
			if ( '' !== $value && null !== $value && '0' !== (string) $value ) {
				$lines[] = $label . ': ' . wp_strip_all_tags( (string) $value );
			}
		}

		// Punti di forza grezzi dal contenuto attuale, se presenti.
		if ( $post->post_content ) {
			$lines[] = 'Note redazionali: ' . wp_trim_words( wp_strip_all_tags( $post->post_content ), 60 );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Sanitizza l'array FAQ.
	 *
	 * @param mixed $faq Dati FAQ grezzi.
	 * @return array<int,array{q:string,a:string}>
	 */
	private static function sanitize_faq( $faq ) {
		if ( ! is_array( $faq ) ) {
			return array();
		}

		$out = array();
		foreach ( $faq as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$q = sanitize_text_field( $item['q'] ?? '' );
			$a = sanitize_textarea_field( $item['a'] ?? '' );
			if ( '' !== $q && '' !== $a ) {
				$out[] = array(
					'q' => $q,
					'a' => $a,
				);
			}
		}

		return $out;
	}
}
