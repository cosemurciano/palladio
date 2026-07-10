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
				'max_tokens'  => Palladio_AI_Settings::max_tokens( 'generate' ),
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
				'max_tokens'  => Palladio_AI_Settings::max_tokens( 'translate' ),
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

	/**
	 * Costruisce la pagina dai contenuti su OpenAI Storage (File Search) e dai
	 * media del sito, popolando i campi strutturati (_pll_editorial + meta).
	 *
	 * @param int $post_id ID post (edificio o unità).
	 * @return array|WP_Error Riepilogo campi popolati.
	 */
	public static function build_from_sources( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'palladio_ai_no_post', __( 'Post non trovato.', 'palladio' ) );
		}

		$vector_store = Palladio_AI_Settings::vector_store();
		$media        = self::media_list( $post_id );

		$is_unit = 'pll_unita' === $post->post_type;

		$schema = wp_json_encode(
			array(
				'title'     => 'string',
				'excerpt'   => 'string',
				'meta'      => $is_unit
					? array( 'codice' => 'string', 'prezzo' => 'number', 'mq_commerciali' => 'number', 'mq_coperti' => 'number', 'camere' => 'int', 'bagni' => 'int', 'vani' => 'number', 'esposizione' => 'string', 'classe_energetica' => 'string', 'millesimi' => 'number', 'spese_condominiali' => 'number', 'terrazza_mq' => 'number', 'giardino_mq' => 'number', 'stato_consegna' => 'string', 'destinazione_uso' => 'string' )
					: array( 'anno_costruzione' => 'int', 'mq_totali' => 'number', 'num_piani' => 'int', 'num_unita_vendita' => 'int', 'indirizzo' => 'string', 'sottotitolo' => 'string', 'claim' => 'string' ),
				'editorial' => array(
					'eyebrow'         => 'string',
					'lead'            => 'string',
					'walkthrough_url' => 'string',
					'hero_image'      => 'attachment_id (dalla lista media)',
					'chapters'        => array( array( 'time' => 'string', 'label' => 'string' ) ),
					'narrative'       => array( array( 'kicker' => 'string', 'heading' => 'string', 'body' => 'html', 'image' => 'attachment_id', 'caption' => 'string', 'layout' => 'left|right' ) ),
					'tech'            => array( array( 'label' => 'string', 'value' => 'string' ) ),
					'gallery'         => array( array( 'image' => 'attachment_id', 'caption' => 'string', 'ratio' => '3:2|4:3|4:5|1:1' ) ),
					'floorplan'       => array( 'image' => 'attachment_id', 'caption' => 'string', 'notes' => 'string' ),
					'position'        => array( 'heading' => 'string', 'text' => 'string' ),
				),
			)
		);

		$instructions = __( 'Sei l’editor immobiliare del progetto. Costruisci la scheda usando SOLO fatti verificati: cerca prezzi, misure, stanze, vincoli e descrizioni nei documenti del progetto tramite file search. Non inventare dati. Per le immagini scegli ESCLUSIVAMENTE gli id presenti nella lista media fornita (non inventare id): a parità di pertinenza PREFERISCI le foto più recenti (campo "date", ordine discendente) e usa il NOME DEL FILE (campo "filename") oltre a titolo/alt/didascalia per capire il soggetto. Rispondi con un unico oggetto JSON valido conforme allo schema.', 'palladio' );

		$input = sprintf(
			/* translators: 1: tipo, 2: titolo, 3: schema json, 4: lista media json. */
			__( "Tipo: %1\$s. Titolo attuale: %2\$s.\n\nSchema JSON richiesto:\n%3\$s\n\nMedia disponibili (ordinati per data, più recenti prima; usa questi id, considera 'filename' e 'date'):\n%4\$s", 'palladio' ),
			$post->post_type,
			get_the_title( $post ),
			$schema,
			wp_json_encode( $media )
		);

		$result = Palladio_AI_Openai::responses(
			$instructions,
			$input,
			array(
				'vector_store_ids' => $vector_store ? array( $vector_store ) : array(),
				'json'             => true,
				'max_tokens'       => Palladio_AI_Settings::max_tokens( 'generate' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = json_decode( $result['text'], true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'palladio_ai_bad_json', __( 'Risposta AI non valida.', 'palladio' ) );
		}

		return self::apply_structured( $post_id, $data, $media );
	}

	/**
	 * Applica al post dati strutturati (meta, immagini, editoriale).
	 *
	 * Riutilizzabile: usato sia da build_from_sources() sia dall'agente Studio.
	 *
	 * @param int   $post_id ID post.
	 * @param array $data    Dati (title, excerpt, content, meta{}, editorial{}).
	 * @param array $media   Lista media valida (per validare gli id immagine).
	 * @return array Riepilogo.
	 */
	public static function apply_structured( $post_id, $data, $media = null ) {
		if ( null === $media ) {
			$media = self::media_list( $post_id );
		}
		$valid_ids = wp_list_pluck( $media, 'id' );

		$vid = static function ( $id ) use ( $valid_ids ) {
			$id = absint( $id );
			return in_array( $id, $valid_ids, true ) ? $id : 0;
		};

		// Testi principali.
		$update = array( 'ID' => $post_id );
		if ( ! empty( $data['title'] ) ) {
			$update['post_title'] = sanitize_text_field( $data['title'] );
		}
		if ( ! empty( $data['excerpt'] ) ) {
			$update['post_excerpt'] = sanitize_textarea_field( $data['excerpt'] );
		}
		if ( ! empty( $data['content'] ) ) {
			$update['post_content'] = wp_kses_post( $data['content'] );
		}
		if ( count( $update ) > 1 ) {
			wp_update_post( $update );
		}

		// Meta strutturati (numeri/stringhe).
		if ( ! empty( $data['meta'] ) && is_array( $data['meta'] ) ) {
			foreach ( $data['meta'] as $key => $value ) {
				$key = sanitize_key( $key );
				if ( is_numeric( $value ) ) {
					update_post_meta( $post_id, '_pll_' . $key, (float) $value );
				} else {
					$value = (string) $value;
					// I campi multiriga (es. vincoli_note) conservano gli a-capo.
					$value = ( false !== strpos( $value, "\n" ) ) ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
					update_post_meta( $post_id, '_pll_' . $key, $value );
				}
			}
		}

		$ed = isset( $data['editorial'] ) && is_array( $data['editorial'] ) ? $data['editorial'] : array();

		// Parte dai valori esistenti così gli aggiornamenti parziali non azzerano il resto.
		$editorial = function_exists( 'palladio_editorial' ) ? palladio_editorial( $post_id ) : array();

		// Immagine in evidenza (hero).
		$hero = $vid( $ed['hero_image'] ?? 0 );
		if ( $hero ) {
			set_post_thumbnail( $post_id, $hero );
		}

		// Campi scalari (sovrascritti solo se forniti).
		if ( isset( $ed['eyebrow'] ) ) {
			$editorial['eyebrow'] = sanitize_text_field( $ed['eyebrow'] );
		}
		if ( isset( $ed['lead'] ) ) {
			$editorial['lead'] = sanitize_textarea_field( $ed['lead'] );
		}
		if ( isset( $ed['walkthrough_url'] ) ) {
			$editorial['walkthrough_url'] = esc_url_raw( $ed['walkthrough_url'] );
		}
		if ( isset( $ed['gallery_url'] ) ) {
			$editorial['gallery_url'] = esc_url_raw( $ed['gallery_url'] );
		}
		if ( isset( $ed['gallery_count'] ) ) {
			$editorial['gallery_count'] = sanitize_text_field( $ed['gallery_count'] );
		}
		if ( isset( $ed['units_eyebrow'] ) ) {
			$editorial['units_eyebrow'] = sanitize_text_field( $ed['units_eyebrow'] );
		}
		if ( isset( $ed['units_heading'] ) ) {
			$editorial['units_heading'] = sanitize_text_field( $ed['units_heading'] );
		}
		if ( isset( $ed['units_filters'] ) ) {
			$editorial['units_filters'] = ! empty( $ed['units_filters'] );
		}

		// Gruppi.
		if ( isset( $ed['floorplan'] ) && is_array( $ed['floorplan'] ) ) {
			$editorial['floorplan'] = array(
				'image'   => $vid( $ed['floorplan']['image'] ?? 0 ),
				'caption' => sanitize_text_field( $ed['floorplan']['caption'] ?? '' ),
				'notes'   => sanitize_textarea_field( $ed['floorplan']['notes'] ?? '' ),
			);
		}
		if ( isset( $ed['position'] ) && is_array( $ed['position'] ) ) {
			$editorial['position'] = array(
				'heading' => sanitize_text_field( $ed['position']['heading'] ?? '' ),
				'text'    => sanitize_textarea_field( $ed['position']['text'] ?? '' ),
			);
		}
		if ( isset( $ed['ambient'] ) && is_array( $ed['ambient'] ) ) {
			$editorial['ambient'] = array(
				'image'   => $vid( $ed['ambient']['image'] ?? 0 ),
				'caption' => sanitize_text_field( $ed['ambient']['caption'] ?? '' ),
			);
		}

		// Repeater (sostituiti solo se forniti).
		if ( isset( $ed['chapters'] ) ) {
			$editorial['chapters'] = array();
			foreach ( (array) $ed['chapters'] as $c ) {
				if ( is_array( $c ) ) {
					$editorial['chapters'][] = array(
						'time'  => sanitize_text_field( $c['time'] ?? '' ),
						'label' => sanitize_text_field( $c['label'] ?? '' ),
					);
				}
			}
		}
		if ( isset( $ed['narrative'] ) ) {
			$editorial['narrative'] = array();
			foreach ( (array) $ed['narrative'] as $n ) {
				if ( is_array( $n ) ) {
					$editorial['narrative'][] = array(
						'kicker'  => sanitize_text_field( $n['kicker'] ?? '' ),
						'heading' => sanitize_text_field( $n['heading'] ?? '' ),
						'body'    => wp_kses_post( $n['body'] ?? '' ),
						'image'   => $vid( $n['image'] ?? 0 ),
						'caption' => sanitize_text_field( $n['caption'] ?? '' ),
						'layout'  => ( 'left' === ( $n['layout'] ?? '' ) ) ? 'left' : 'right',
					);
				}
			}
		}
		if ( isset( $ed['tech'] ) ) {
			$editorial['tech'] = array();
			foreach ( (array) $ed['tech'] as $t ) {
				if ( is_array( $t ) ) {
					$editorial['tech'][] = array(
						'label' => sanitize_text_field( $t['label'] ?? '' ),
						'value' => sanitize_text_field( $t['value'] ?? '' ),
					);
				}
			}
		}
		if ( isset( $ed['gallery'] ) ) {
			$editorial['gallery'] = array();
			foreach ( (array) $ed['gallery'] as $g ) {
				$img = is_array( $g ) ? $vid( $g['image'] ?? 0 ) : 0;
				if ( $img ) {
					$editorial['gallery'][] = array(
						'image'   => $img,
						'caption' => sanitize_text_field( $g['caption'] ?? '' ),
						'ratio'   => in_array( $g['ratio'] ?? '', array( '3:2', '4:3', '4:5', '1:1', '16:9' ), true ) ? $g['ratio'] : '4:3',
					);
				}
			}
		}
		if ( isset( $ed['manifesto'] ) ) {
			$editorial['manifesto'] = array();
			foreach ( (array) $ed['manifesto'] as $m ) {
				if ( is_array( $m ) ) {
					$editorial['manifesto'][] = array(
						'text'     => sanitize_text_field( $m['text'] ?? '' ),
						'emphasis' => sanitize_text_field( $m['emphasis'] ?? '' ),
					);
				}
			}
		}
		if ( isset( $ed['timeline'] ) ) {
			$editorial['timeline'] = array();
			foreach ( (array) $ed['timeline'] as $t ) {
				if ( is_array( $t ) ) {
					$editorial['timeline'][] = array(
						'kicker'  => sanitize_text_field( $t['kicker'] ?? '' ),
						'year'    => sanitize_text_field( $t['year'] ?? '' ),
						'heading' => sanitize_text_field( $t['heading'] ?? '' ),
						'body'    => wp_kses_post( $t['body'] ?? '' ),
						'image'   => $vid( $t['image'] ?? 0 ),
					);
				}
			}
		}

		update_post_meta( $post_id, '_pll_editorial', $editorial );

		return array(
			'hero'      => (bool) $hero,
			'narrative' => count( $editorial['narrative'] ?? array() ),
			'gallery'   => count( $editorial['gallery'] ?? array() ),
			'manifesto' => count( $editorial['manifesto'] ?? array() ),
			'timeline'  => count( $editorial['timeline'] ?? array() ),
		);
	}

	/**
	 * Raccoglie il contesto dei media del sito (immagini) per la scelta AI.
	 *
	 * Preferenza alle foto più recenti (ordinate per data, discendente) e ai
	 * media allegati al post; include il nome del file e la data così l'agente
	 * può scegliere in base a recency e nome del file.
	 *
	 * @param int $post_id ID post.
	 * @param int $limit   Numero massimo di media.
	 * @return array<int,array{id:int,title:string,filename:string,alt:string,caption:string,date:string,attached:bool}>
	 */
	public static function media_list( $post_id, $limit = 40 ) {
		$common = array(
			'post_type'      => 'attachment',
			'post_mime_type' => 'image',
			'post_status'    => 'inherit',
			'fields'         => 'ids',
			'orderby'        => 'date',
			'order'          => 'DESC', // Più recenti prima.
			'no_found_rows'  => true,
		);

		// Prima gli allegati al post (più pertinenti), già ordinati per data.
		$attached = get_posts( array_merge( $common, array( 'post_parent' => (int) $post_id, 'posts_per_page' => $limit ) ) );

		// Poi i più recenti dell'archivio, sempre per data discendente.
		$ids = $attached;
		if ( count( $ids ) < $limit ) {
			$recent = get_posts( array_merge( $common, array(
				'posts_per_page' => $limit - count( $ids ),
				'post__not_in'   => $ids ? $ids : array( 0 ),
			) ) );
			$ids = array_merge( $ids, $recent );
		}

		$attached_lookup = array_flip( $attached );

		$media = array();
		foreach ( $ids as $id ) {
			$file = get_attached_file( $id );
			$media[] = array(
				'id'       => (int) $id,
				'title'    => get_the_title( $id ),
				'filename' => $file ? wp_basename( $file ) : '',
				'alt'      => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
				'caption'  => wp_get_attachment_caption( $id ) ? wp_get_attachment_caption( $id ) : '',
				'date'     => get_the_date( 'Y-m-d', $id ),
				'attached' => isset( $attached_lookup[ $id ] ),
			);
		}

		return $media;
	}

	/**
	 * Carica i documenti allegati al post (PDF/doc) su OpenAI Storage e li
	 * aggiunge al vector store configurato (creandolo se assente).
	 *
	 * @param int $post_id ID post.
	 * @return array|WP_Error Riepilogo (vector store, file caricati).
	 */
	public static function upload_documents( $post_id ) {
		$config = Palladio_AI_Settings::config();
		$vs     = $config['vector_store'];

		if ( '' === $vs ) {
			$created = Palladio_AI_Openai::create_vector_store( 'Palladio — ' . get_bloginfo( 'name' ) );
			if ( is_wp_error( $created ) ) {
				return $created;
			}
			$vs               = $created;
			$config['vector_store'] = $vs;
			update_option( 'palladio_ai', $config );
		}

		$docs = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => array( 'application/pdf', 'text/plain' ),
				'post_status'    => 'inherit',
				'post_parent'    => (int) $post_id,
				'posts_per_page' => 20,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		$uploaded = 0;
		foreach ( $docs as $id ) {
			$path = get_attached_file( $id );
			if ( ! $path ) {
				continue;
			}
			$file_id = Palladio_AI_Openai::upload_file( $path );
			if ( is_wp_error( $file_id ) ) {
				continue;
			}
			$added = Palladio_AI_Openai::vector_store_add_file( $vs, $file_id );
			if ( ! is_wp_error( $added ) ) {
				$uploaded++;
			}
		}

		return array(
			'vector_store' => $vs,
			'uploaded'     => $uploaded,
			'total'        => count( $docs ),
		);
	}
}
