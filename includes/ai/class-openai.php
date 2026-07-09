<?php
/**
 * Modulo AI — client HTTP OpenAI.
 *
 * Chiamate server-side a Chat Completions ed Embeddings, con retry su
 * errori transitori e log di token/costo stimato (§5.3). La chiave non
 * lascia mai il server.
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Client OpenAI.
 */
class Palladio_AI_Openai {

	/**
	 * Base URL API (filtrabile per proxy/gateway).
	 *
	 * @return string
	 */
	private static function base_url() {
		return (string) apply_filters( 'palladio/ai/base_url', 'https://api.openai.com/v1' );
	}

	/**
	 * Esegue una chat completion.
	 *
	 * @param array $messages Messaggi ruolo/contenuto.
	 * @param array $args     model, temperature, json (bool), max_tokens.
	 * @return array{content:string,usage:array}|WP_Error
	 */
	public static function chat( array $messages, array $args = array() ) {
		$key = Palladio_AI_Settings::api_key();
		if ( '' === $key ) {
			return new WP_Error( 'palladio_ai_no_key', __( 'Chiave API OpenAI non configurata.', 'palladio' ) );
		}

		$args = wp_parse_args(
			$args,
			array(
				'model'       => Palladio_AI_Settings::model(),
				'temperature' => 0.7,
				'json'        => false,
				'max_tokens'  => Palladio_AI_Settings::max_tokens( 'agent' ),
				'tools'       => array(),
				'tool_choice' => 'auto',
			)
		);

		$body = array(
			'model'                 => $args['model'],
			'messages'              => $messages,
			'temperature'           => (float) $args['temperature'],
			// I modelli recenti richiedono max_completion_tokens (max_tokens è
			// deprecato); il fallback sotto gestisce i modelli più vecchi.
			'max_completion_tokens' => (int) $args['max_tokens'],
		);

		if ( $args['json'] ) {
			$body['response_format'] = array( 'type' => 'json_object' );
		}

		if ( ! empty( $args['tools'] ) ) {
			$body['tools']       = $args['tools'];
			$body['tool_choice'] = $args['tool_choice'];
		}

		// Compatibilità tra generazioni di modelli: se l'API rifiuta un
		// parametro (400), adattalo e riprova (max 3 correzioni).
		$response = null;
		for ( $fix = 0; $fix < 3; $fix++ ) {
			$response = self::request( '/chat/completions', $body, $key );

			if ( ! is_wp_error( $response ) ) {
				break;
			}

			$msg = $response->get_error_message();

			if ( isset( $body['max_completion_tokens'] ) && false !== strpos( $msg, 'max_completion_tokens' ) ) {
				// Modello più vecchio: usa il parametro storico.
				$body['max_tokens'] = $body['max_completion_tokens'];
				unset( $body['max_completion_tokens'] );
				continue;
			}
			if ( isset( $body['max_tokens'] ) && false !== strpos( $msg, "'max_tokens'" ) ) {
				$body['max_completion_tokens'] = $body['max_tokens'];
				unset( $body['max_tokens'] );
				continue;
			}
			if ( isset( $body['temperature'] ) && false !== stripos( $msg, 'temperature' ) ) {
				// I modelli reasoning accettano solo la temperatura di default.
				unset( $body['temperature'] );
				continue;
			}

			break;
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$message = $response['choices'][0]['message'] ?? array();
		$usage   = $response['usage'] ?? array();

		self::record_usage( $args['model'], $usage );

		return array(
			'content'       => (string) ( $message['content'] ?? '' ),
			'message'       => $message,
			'usage'         => $usage,
			'finish_reason' => (string) ( $response['choices'][0]['finish_reason'] ?? '' ),
		);
	}

	/**
	 * Calcola gli embedding di uno o più testi.
	 *
	 * @param string|array $input Testo o array di testi.
	 * @param string       $model Modello embeddings.
	 * @return array<int,array<float>>|WP_Error
	 */
	public static function embeddings( $input, $model = 'text-embedding-3-small' ) {
		$key = Palladio_AI_Settings::api_key();
		if ( '' === $key ) {
			return new WP_Error( 'palladio_ai_no_key', __( 'Chiave API OpenAI non configurata.', 'palladio' ) );
		}

		$response = self::request(
			'/embeddings',
			array(
				'model' => $model,
				'input' => $input,
			),
			$key
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		self::record_usage( $model, $response['usage'] ?? array() );

		$vectors = array();
		foreach ( ( $response['data'] ?? array() ) as $item ) {
			$vectors[] = $item['embedding'] ?? array();
		}

		return $vectors;
	}

	/**
	 * Responses API con supporto a File Search (OpenAI Storage).
	 *
	 * @param string $instructions System/istruzioni.
	 * @param string $input        Input utente.
	 * @param array  $args         model, vector_store_ids (array), json (bool), max_tokens.
	 * @return array{text:string}|WP_Error
	 */
	public static function responses( $instructions, $input, array $args = array() ) {
		$key = Palladio_AI_Settings::api_key();
		if ( '' === $key ) {
			return new WP_Error( 'palladio_ai_no_key', __( 'Chiave API OpenAI non configurata.', 'palladio' ) );
		}

		$args = wp_parse_args(
			$args,
			array(
				'model'            => Palladio_AI_Settings::model(),
				'vector_store_ids' => array(),
				'json'             => false,
				'max_tokens'       => 2500,
			)
		);

		$body = array(
			'model'             => $args['model'],
			'instructions'      => $instructions,
			'input'             => $input,
			'max_output_tokens' => (int) $args['max_tokens'],
		);

		if ( ! empty( $args['vector_store_ids'] ) ) {
			$body['tools'] = array(
				array(
					'type'             => 'file_search',
					'vector_store_ids' => array_values( array_filter( (array) $args['vector_store_ids'] ) ),
				),
			);
		}

		if ( $args['json'] ) {
			$body['text'] = array( 'format' => array( 'type' => 'json_object' ) );
		}

		$response = self::request( '/responses', $body, $key );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		self::record_usage( $args['model'], $response['usage'] ?? array() );

		return array( 'text' => self::extract_output_text( $response ) );
	}

	/**
	 * Estrae il testo di output dalla risposta della Responses API.
	 *
	 * @param array $response Risposta decodificata.
	 * @return string
	 */
	private static function extract_output_text( $response ) {
		if ( isset( $response['output_text'] ) && is_string( $response['output_text'] ) ) {
			return $response['output_text'];
		}

		$text = '';
		foreach ( ( $response['output'] ?? array() ) as $item ) {
			if ( 'message' !== ( $item['type'] ?? '' ) ) {
				continue;
			}
			foreach ( ( $item['content'] ?? array() ) as $chunk ) {
				if ( isset( $chunk['text'] ) && is_string( $chunk['text'] ) ) {
					$text .= $chunk['text'];
				}
			}
		}

		return $text;
	}

	/**
	 * Carica un file su OpenAI (Files API), es. per il File Search.
	 *
	 * @param string $path    Percorso file locale.
	 * @param string $purpose Scopo (default 'assistants').
	 * @return string|WP_Error File id.
	 */
	public static function upload_file( $path, $purpose = 'assistants' ) {
		$key = Palladio_AI_Settings::api_key();
		if ( '' === $key ) {
			return new WP_Error( 'palladio_ai_no_key', __( 'Chiave API OpenAI non configurata.', 'palladio' ) );
		}
		if ( ! is_readable( $path ) ) {
			return new WP_Error( 'palladio_ai_no_file', __( 'File non leggibile.', 'palladio' ) );
		}

		$boundary = 'pll' . md5( $path . $purpose . (string) filesize( $path ) );
		$eol      = "\r\n";
		$name     = basename( $path );
		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$payload  = '--' . $boundary . $eol;
		$payload .= 'Content-Disposition: form-data; name="purpose"' . $eol . $eol . $purpose . $eol;
		$payload .= '--' . $boundary . $eol;
		$payload .= 'Content-Disposition: form-data; name="file"; filename="' . $name . '"' . $eol;
		$payload .= 'Content-Type: application/octet-stream' . $eol . $eol;
		$payload .= $contents . $eol;
		$payload .= '--' . $boundary . '--' . $eol;

		$response = wp_remote_post(
			self::base_url() . '/files',
			array(
				'timeout' => 60,
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
				),
				'body'    => $payload,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['id'] ) ) {
			return new WP_Error( 'palladio_ai_upload', $data['error']['message'] ?? __( 'Upload non riuscito.', 'palladio' ) );
		}

		return (string) $data['id'];
	}

	/**
	 * Crea un vector store.
	 *
	 * @param string $name Nome.
	 * @return string|WP_Error Vector store id.
	 */
	public static function create_vector_store( $name ) {
		$key = Palladio_AI_Settings::api_key();
		if ( '' === $key ) {
			return new WP_Error( 'palladio_ai_no_key', __( 'Chiave API OpenAI non configurata.', 'palladio' ) );
		}

		$response = self::request( '/vector_stores', array( 'name' => $name ), $key );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( empty( $response['id'] ) ) {
			return new WP_Error( 'palladio_ai_vs', __( 'Creazione vector store non riuscita.', 'palladio' ) );
		}

		return (string) $response['id'];
	}

	/**
	 * Aggiunge un file a un vector store.
	 *
	 * @param string $vector_store_id Vector store.
	 * @param string $file_id         File id.
	 * @return bool|WP_Error
	 */
	public static function vector_store_add_file( $vector_store_id, $file_id ) {
		$key = Palladio_AI_Settings::api_key();
		if ( '' === $key ) {
			return new WP_Error( 'palladio_ai_no_key', __( 'Chiave API OpenAI non configurata.', 'palladio' ) );
		}

		$response = self::request(
			'/vector_stores/' . rawurlencode( $vector_store_id ) . '/files',
			array( 'file_id' => $file_id ),
			$key
		);

		return is_wp_error( $response ) ? $response : true;
	}

	/**
	 * Esegue la richiesta HTTP con retry su errori transitori.
	 *
	 * @param string $path Endpoint relativo.
	 * @param array  $body Corpo JSON.
	 * @param string $key  Chiave API.
	 * @return array|WP_Error Risposta decodificata.
	 */
	private static function request( $path, array $body, $key ) {
		$url      = self::base_url() . $path;
		$attempts = 3;

		for ( $i = 0; $i < $attempts; $i++ ) {
			$response = wp_remote_post(
				$url,
				array(
					'timeout' => 60,
					'headers' => array(
						'Authorization' => 'Bearer ' . $key,
						'Content-Type'  => 'application/json',
					),
					'body'    => wp_json_encode( $body ),
				)
			);

			if ( is_wp_error( $response ) ) {
				// Errore di rete: riprova con backoff.
				if ( $i < $attempts - 1 ) {
					sleep( (int) pow( 2, $i ) );
					continue;
				}
				return $response;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$raw  = wp_remote_retrieve_body( $response );

			if ( 429 === $code || $code >= 500 ) {
				// Rate limit / errore server: riprova.
				if ( $i < $attempts - 1 ) {
					sleep( (int) pow( 2, $i ) );
					continue;
				}
			}

			$data = json_decode( $raw, true );

			if ( $code >= 200 && $code < 300 && is_array( $data ) ) {
				return $data;
			}

			$message = '';
			if ( is_array( $data ) && isset( $data['error']['message'] ) ) {
				$message = $data['error']['message'];
			}

			return new WP_Error(
				'palladio_ai_http_' . $code,
				$message ? $message : sprintf(
					/* translators: %d: codice HTTP. */
					__( 'Errore OpenAI (HTTP %d).', 'palladio' ),
					$code
				)
			);
		}

		return new WP_Error( 'palladio_ai_failed', __( 'Richiesta AI non riuscita.', 'palladio' ) );
	}

	/**
	 * Registra token e costo stimato dell'operazione.
	 *
	 * @param string $model Modello usato.
	 * @param array  $usage Blocco usage della risposta.
	 * @return void
	 */
	private static function record_usage( $model, array $usage ) {
		$prompt     = (int) ( $usage['prompt_tokens'] ?? 0 );
		$completion = (int) ( $usage['completion_tokens'] ?? 0 );
		$total      = (int) ( $usage['total_tokens'] ?? ( $prompt + $completion ) );

		if ( $total <= 0 ) {
			return;
		}

		/**
		 * Prezzi per 1M token [input, output], per stima del costo.
		 *
		 * @param array  $prices Mappa modello => [input, output].
		 * @param string $model  Modello corrente.
		 */
		$prices = apply_filters(
			'palladio/ai/prices',
			array(
				'default' => array( 0.40, 1.60 ),
			),
			$model
		);

		$price = $prices[ $model ] ?? $prices['default'];
		$cost  = ( $prompt / 1000000 * $price[0] ) + ( $completion / 1000000 * $price[1] );

		$store = get_option( 'palladio_ai_usage', array() );
		$store = wp_parse_args(
			is_array( $store ) ? $store : array(),
			array(
				'total_tokens'   => 0,
				'estimated_cost' => 0.0,
				'calls'          => 0,
			)
		);

		$store['total_tokens']   += $total;
		$store['estimated_cost'] += $cost;
		$store['calls']++;

		update_option( 'palladio_ai_usage', $store, false );
	}
}
