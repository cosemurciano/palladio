<?php
/**
 * Modulo Agent — runtime REST.
 *
 * Endpoint POST /palladio/v1/agent/chat: retrieval RAG dei chunk più
 * rilevanti, chat completion con function calling e system prompt
 * parametrico (tono progetto + guardrail + trasparenza AI), con rate
 * limiting per il controllo di costi e abusi (§5.5, §6).
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Endpoint e orchestrazione dell'agent.
 */
class Palladio_Agent_Rest {

	/**
	 * Registra la rotta REST.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Configurazione agent con default.
	 *
	 * @return array{enabled:bool,top_k:int,disclaimer:string,system:string,rate_limit:int}
	 */
	public static function config() {
		$defaults = array(
			'enabled'    => false,
			'top_k'      => 5,
			'rate_limit' => 20,
			'disclaimer' => __( 'Stai parlando con un assistente virtuale (AI).', 'palladio' ),
			'system'     => __( 'Sei il concierge di vendita di questo progetto immobiliare. Rispondi solo con le informazioni presenti nel contesto fornito o ottenute dai tool. Non inventare prezzi, stati o promesse. Per prezzi e disponibilità usa sempre i tool (dati freschi). Su temi legali o fiscali fornisci solo indicazioni generali e proponi il contatto umano. Segui la lingua dell’utente. Prima di salvare qualsiasi dato personale chiedi un consenso esplicito.', 'palladio' ),
		);

		$config = get_option( 'palladio_agent', array() );
		$config = wp_parse_args( is_array( $config ) ? $config : array(), $defaults );

		$config['enabled'] = (bool) $config['enabled'];
		$config['top_k']   = max( 1, min( 10, (int) $config['top_k'] ) );

		return $config;
	}

	/**
	 * Indica se l'agent è operativo.
	 *
	 * @return bool
	 */
	public static function is_ready() {
		$config = self::config();
		return $config['enabled']
			&& class_exists( 'Palladio_AI_Settings' )
			&& Palladio_AI_Settings::is_ready();
	}

	/**
	 * Registra le rotte.
	 *
	 * @return void
	 */
	public function routes() {
		register_rest_route(
			'palladio/v1',
			'/agent/chat',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'handle_chat' ),
				'args'                => array(
					'message'    => array( 'required' => true, 'type' => 'string' ),
					'session_id' => array( 'required' => false, 'type' => 'string' ),
					'nonce'      => array( 'required' => false, 'type' => 'string' ),
				),
			)
		);
	}

	/**
	 * Gestisce un turno di conversazione.
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response
	 */
	public function handle_chat( WP_REST_Request $request ) {
		// Nonce (fornito dal widget localizzato).
		$nonce = (string) $request->get_param( 'nonce' );
		if ( ! wp_verify_nonce( $nonce, 'palladio_agent' ) ) {
			return new WP_REST_Response( array( 'error' => __( 'Sessione scaduta, ricarica la pagina.', 'palladio' ) ), 403 );
		}

		if ( ! self::is_ready() ) {
			return new WP_REST_Response( array( 'error' => __( 'Assistente non disponibile.', 'palladio' ) ), 503 );
		}

		// Rate limiting per IP.
		if ( ! $this->check_rate_limit() ) {
			return new WP_REST_Response( array( 'error' => __( 'Troppe richieste. Riprova tra qualche minuto.', 'palladio' ) ), 429 );
		}

		$message = trim( (string) $request->get_param( 'message' ) );
		if ( '' === $message ) {
			return new WP_REST_Response( array( 'error' => __( 'Messaggio vuoto.', 'palladio' ) ), 400 );
		}
		$message = sanitize_textarea_field( $message );

		$session_id = sanitize_key( (string) $request->get_param( 'session_id' ) );
		if ( '' === $session_id ) {
			$session_id = wp_generate_password( 24, false );
		}

		$lang = class_exists( 'Palladio_I18n_Languages' ) ? Palladio_I18n_Languages::current() : get_locale();

		Palladio_Agent_Chats::append( $session_id, 'user', $message, $lang );

		$reply = $this->run_agent( $session_id, $message, $lang );
		if ( is_wp_error( $reply ) ) {
			return new WP_REST_Response( array( 'error' => $reply->get_error_message() ), 500 );
		}

		Palladio_Agent_Chats::append( $session_id, 'assistant', $reply, $lang );

		return new WP_REST_Response(
			array(
				'session_id' => $session_id,
				'reply'      => $reply,
			),
			200
		);
	}

	/**
	 * Orchestrazione RAG + function calling.
	 *
	 * @param string $session_id ID sessione.
	 * @param string $message    Messaggio utente.
	 * @param string $lang       Lingua.
	 * @return string|WP_Error
	 */
	private function run_agent( $session_id, $message, $lang ) {
		$config = self::config();

		// Retrieval.
		$context_chunks = Palladio_Agent_KB::search( $message, $config['top_k'] );
		$context_text   = '';
		foreach ( $context_chunks as $chunk ) {
			$context_text .= '- ' . $chunk['content'] . "\n";
		}
		if ( '' === $context_text ) {
			$context_text = __( '(nessun contenuto rilevante indicizzato)', 'palladio' );
		}

		$system = $config['system'] . "\n\n" . __( 'Contesto disponibile:', 'palladio' ) . "\n" . $context_text;

		// Storico recente (ultimi 10 messaggi user/assistant).
		$history  = Palladio_Agent_Chats::messages( $session_id );
		$history  = array_slice( $history, -10 );
		$messages = array( array( 'role' => 'system', 'content' => $system ) );
		foreach ( $history as $m ) {
			if ( in_array( $m['role'], array( 'user', 'assistant' ), true ) ) {
				$messages[] = array( 'role' => $m['role'], 'content' => (string) $m['content'] );
			}
		}

		$tools   = Palladio_Agent_Tools::definitions();
		$tctx    = array( 'session_id' => $session_id, 'lang' => $lang );
		$rounds  = 0;

		while ( $rounds < 3 ) {
			$rounds++;

			$result = Palladio_AI_Openai::chat(
				$messages,
				array(
					'tools'       => $tools,
					'temperature' => 0.4,
					'max_tokens'  => Palladio_AI_Settings::max_tokens( 'agent' ),
				)
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$assistant = $result['message'];
			$messages[] = $assistant;

			// Nessun tool richiesto: risposta finale.
			if ( empty( $assistant['tool_calls'] ) ) {
				$content = (string) ( $assistant['content'] ?? '' );

				if ( '' === trim( $content ) && 'length' === ( $result['finish_reason'] ?? '' ) ) {
					return new WP_Error(
						'palladio_ai_truncated',
						__( 'Risposta non disponibile: limite token raggiunto. Aumenta “Token massimi — agente (chat)” in Palladio → AI.', 'palladio' )
					);
				}

				return $content;
			}

			// Esegue i tool richiesti e accoda i risultati.
			foreach ( $assistant['tool_calls'] as $call ) {
				$name = $call['function']['name'] ?? '';
				$args = json_decode( $call['function']['arguments'] ?? '{}', true );
				$args = is_array( $args ) ? $args : array();

				$output = Palladio_Agent_Tools::run( $name, $args, $tctx );

				$messages[] = array(
					'role'         => 'tool',
					'tool_call_id' => $call['id'] ?? '',
					'content'      => wp_json_encode( $output ),
				);
			}
		}

		// Fallback se supera i round: chiede una risposta senza tool.
		$final = Palladio_AI_Openai::chat( $messages, array( 'temperature' => 0.4, 'max_tokens' => Palladio_AI_Settings::max_tokens( 'agent' ) ) );
		if ( is_wp_error( $final ) ) {
			return $final;
		}

		return (string) $final['content'];
	}

	/**
	 * Rate limiting per IP (finestra di 10 minuti).
	 *
	 * @return bool True se sotto il limite.
	 */
	private function check_rate_limit() {
		$config = self::config();
		$limit  = (int) $config['rate_limit'];

		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key = 'palladio_agent_rl_' . md5( $ip );

		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return false;
		}

		set_transient( $key, $count + 1, 10 * MINUTE_IN_SECONDS );
		return true;
	}
}
