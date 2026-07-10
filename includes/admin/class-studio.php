<?php
/**
 * Modulo AI — Agente Studio (admin).
 *
 * Agente di regia consapevole dell'intera struttura (edifici, unità, scenari)
 * e dei documenti su OpenAI Storage (File Search). Tramite function calling
 * legge la struttura e i documenti e popola i contenuti (meta + campi
 * editoriali) di edifici, unità e scenari. Tutte le chiamate sono server-side.
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Agente Studio e handler AJAX.
 */
class Palladio_Admin_Studio {

	const CAP = 'manage_palladio';

	/**
	 * Se true, i tool di scrittura (create_*, update_entity) sono consentiti.
	 * In modalità progettazione (false) l'agente non modifica nulla.
	 *
	 * @var bool
	 */
	private $apply = false;

	/**
	 * Tool che modificano dati (bloccati in modalità progettazione).
	 *
	 * @var string[]
	 */
	private $write_tools = array( 'create_edificio', 'create_unit', 'create_scenario', 'update_entity' );

	/**
	 * Registra menu, asset e AJAX.
	 *
	 * @return void
	 */
	public function register() {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'wp_ajax_palladio_studio_chat', array( $this, 'ajax_chat' ) );
	}

	/**
	 * Aggiunge la pagina "Agente AI".
	 *
	 * @return void
	 */
	public function menu() {
		add_submenu_page(
			'edit.php?post_type=pll_edificio',
			__( 'Agente AI', 'palladio' ),
			__( 'Agente AI', 'palladio' ),
			self::CAP,
			'palladio-studio',
			array( $this, 'page' )
		);
	}

	/**
	 * URL della pagina agente (con eventuale focus su un post).
	 *
	 * @param int $focus ID post da mettere a fuoco.
	 * @return string
	 */
	public static function url( $focus = 0 ) {
		$args = array(
			'post_type' => 'pll_edificio',
			'page'      => 'palladio-studio',
		);
		if ( $focus ) {
			$args['focus'] = (int) $focus;
		}
		return add_query_arg( $args, admin_url( 'edit.php' ) );
	}

	/**
	 * Accoda asset sulla pagina agente.
	 *
	 * @param string $hook Hook pagina.
	 * @return void
	 */
	public function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'palladio-studio' ) ) {
			return;
		}

		wp_enqueue_style( 'palladio-studio', PALLADIO_URI . 'assets/css/palladio-studio.css', array(), $this->ver( 'assets/css/palladio-studio.css' ) );
		wp_enqueue_script( 'palladio-studio', PALLADIO_URI . 'assets/js/palladio-studio.js', array(), $this->ver( 'assets/js/palladio-studio.js' ), true );
		wp_localize_script(
			'palladio-studio',
			'PalladioStudio',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'palladio_studio' ),
				'i18n'    => array(
					'working'      => __( 'L’agente sta lavorando…', 'palladio' ),
					'error'        => __( 'Errore', 'palladio' ),
					'send'         => __( 'Invia', 'palladio' ),
					'tooManySteps' => __( 'troppi passi: riprova con una richiesta più semplice.', 'palladio' ),
				),
			)
		);
	}

	/**
	 * Renderizza la pagina agente.
	 *
	 * @return void
	 */
	public function page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permesso negato.', 'palladio' ) );
		}

		$ready = class_exists( 'Palladio_AI_Settings' ) && Palladio_AI_Settings::is_ready();
		$focus = isset( $_GET['focus'] ) ? absint( wp_unslash( $_GET['focus'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap palladio-studio">
			<h1><?php esc_html_e( 'Palladio — Agente AI', 'palladio' ); ?></h1>

			<?php if ( ! $ready ) : ?>
				<div class="notice notice-warning"><p>
					<?php esc_html_e( 'Configura la chiave OpenAI in Palladio → AI per usare l’agente.', 'palladio' ); ?>
				</p></div>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'L’agente conosce l’intera struttura (edifici, unità, scenari) e i documenti su OpenAI Storage. Chiedigli di popolare o aggiornare i contenuti: agisce con i tuoi permessi.', 'palladio' ); ?></p>

				<div class="palladio-studio__box" data-focus="<?php echo esc_attr( $focus ); ?>">
					<div class="palladio-studio__log" data-studio-log aria-live="polite"></div>
					<form class="palladio-studio__form" data-studio-form>
						<textarea class="palladio-studio__input" data-studio-input rows="2" placeholder="<?php esc_attr_e( 'Es. “Popola l’edificio Palazzo Sambiasi e le sue unità usando i documenti su Storage.”', 'palladio' ); ?>"></textarea>
						<span class="palladio-studio__actions"><label class="palladio-studio__apply"><input type="checkbox" data-studio-apply> <?php esc_html_e( 'Applica modifiche (crea/aggiorna)', 'palladio' ); ?></label> <button type="submit" class="button button-primary"><?php esc_html_e( 'Invia', 'palladio' ); ?></button></span>
					</form>
					<p class="palladio-studio__hint description"><?php esc_html_e( 'Modalità progettazione: finché “Applica modifiche” è disattivato l’agente non crea né modifica nulla — puoi discutere e progettare. Attivalo quando vuoi dare il via alla costruzione.', 'palladio' ); ?></p>
					<p class="palladio-studio__status" data-studio-status role="status"></p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handler AJAX: un turno di conversazione con l'agente.
	 *
	 * @return void
	 */
	public function ajax_chat() {
		check_ajax_referer( 'palladio_studio', 'nonce' );

		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permesso negato.', 'palladio' ) ), 403 );
		}
		if ( ! class_exists( 'Palladio_AI_Settings' ) || ! Palladio_AI_Settings::is_ready() ) {
			wp_send_json_error( array( 'message' => __( 'Modulo AI non configurato.', 'palladio' ) ), 400 );
		}

		// Ogni richiesta esegue UN solo passo (una chiamata al modello): così la
		// risposta HTTP resta breve ed evita i timeout del web server/proxy che
		// troncavano la risposta (Content-Length exceeds body).
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$turn = isset( $_POST['turn'] ) ? sanitize_key( wp_unslash( $_POST['turn'] ) ) : '';

		if ( $turn ) {
			// Continua un turno in corso: carica lo stato dal transient.
			$state = get_transient( 'palladio_studio_' . $turn );
			if ( ! is_array( $state ) || (int) ( $state['user'] ?? 0 ) !== get_current_user_id() ) {
				wp_send_json_error( array( 'message' => __( 'Sessione dell’agente scaduta. Reinvia il messaggio.', 'palladio' ) ), 410 );
			}
		} else {
			// Nuovo turno: costruisce lo stato iniziale.
			$message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
			if ( '' === $message ) {
				wp_send_json_error( array( 'message' => __( 'Messaggio vuoto.', 'palladio' ) ), 400 );
			}
			$history = isset( $_POST['history'] ) ? json_decode( wp_unslash( $_POST['history'] ), true ) : array(); // phpcs:ignore WordPress.Security.ValidationSanitization.InputNotSanitized
			$focus   = isset( $_POST['focus'] ) ? absint( wp_unslash( $_POST['focus'] ) ) : 0;

			$state = array(
				'user'     => get_current_user_id(),
				'apply'    => ! empty( $_POST['apply'] ),
				'rounds'   => 0,
				'messages' => $this->build_messages( $message, is_array( $history ) ? $history : array(), $focus ),
			);
			$turn = wp_generate_password( 20, false );
		}

		$this->apply = ! empty( $state['apply'] );

		try {
			$step = $this->step( $state );
		} catch ( Throwable $t ) {
			delete_transient( 'palladio_studio_' . $turn );
			wp_send_json_error( array( 'message' => $t->getMessage() ) );
		}

		if ( is_wp_error( $step ) ) {
			delete_transient( 'palladio_studio_' . $turn );
			$msg = $step->get_error_message();
			wp_send_json_error( array( 'message' => $msg ? $msg : $step->get_error_code() ) );
		}

		if ( ! empty( $step['done'] ) ) {
			delete_transient( 'palladio_studio_' . $turn );
			wp_send_json_success( array( 'done' => true, 'reply' => (string) $step['reply'] ) );
		}

		// Passo intermedio: salva lo stato e chiedi al client di proseguire.
		set_transient( 'palladio_studio_' . $turn, $state, 15 * MINUTE_IN_SECONDS );
		wp_send_json_success( array( 'done' => false, 'turn' => $turn, 'status' => (string) $step['status'] ) );
	}

	/**
	 * Costruisce l'array messaggi iniziale (system + storico + messaggio).
	 *
	 * @param string $message Messaggio utente.
	 * @param array  $history Storico [{role,content}].
	 * @param int    $focus   Post a fuoco.
	 * @return array
	 */
	private function build_messages( $message, $history, $focus ) {
		$messages = array( array( 'role' => 'system', 'content' => $this->system_prompt( $focus ) ) );
		foreach ( array_slice( $history, -12 ) as $m ) {
			if ( isset( $m['role'], $m['content'] ) && in_array( $m['role'], array( 'user', 'assistant' ), true ) ) {
				$messages[] = array( 'role' => $m['role'], 'content' => (string) $m['content'] );
			}
		}
		$messages[] = array( 'role' => 'user', 'content' => $message );
		return $messages;
	}

	/**
	 * Esegue un singolo passo dell'agente: una chiamata al modello, poi
	 * eventuali tool. Aggiorna lo stato per riferimento.
	 *
	 * @param array $state Stato del turno (per riferimento).
	 * @return array{done:bool,reply?:string,status?:string}|WP_Error
	 */
	private function step( &$state ) {
		// Tool in sospeso dal passo precedente: eseguine UNO per richiesta.
		// Così una ricerca sullo Storage (lenta) non si somma mai alla chiamata
		// chat nella stessa richiesta HTTP, restando sotto la finestra del proxy.
		if ( ! empty( $state['pending_tools'] ) ) {
			$call  = array_shift( $state['pending_tools'] );
			$name  = $call['function']['name'] ?? '';
			$targs = json_decode( $call['function']['arguments'] ?? '{}', true );
			$targs = is_array( $targs ) ? $targs : array();

			$output = $this->run_tool( $name, $targs, $state );

			$state['messages'][] = array(
				'role'         => 'tool',
				'tool_call_id' => $call['id'] ?? '',
				'content'      => wp_json_encode( $output ),
			);

			return array(
				'done'   => false,
				/* translators: %s: nome strumento eseguito. */
				'status' => sprintf( __( 'Eseguo… (%s)', 'palladio' ), $name ),
			);
		}

		$state['rounds'] = (int) $state['rounds'] + 1;

		// Cap di sicurezza: forza una risposta finale senza altri tool.
		$use_tools = $state['rounds'] <= 10;

		$args = array(
			'temperature' => 0.3,
			'max_tokens'  => Palladio_AI_Settings::max_tokens( 'agent' ),
			// Ogni passo deve concludersi entro la finestra del proxy front-end
			// (che tronca la risposta al browser con "Content-Length exceeds
			// body"): limita la singola chiamata così PHP restituisce sempre
			// un JSON valido, anche in caso di timeout.
			'timeout'     => $this->step_timeout(),
		);
		if ( $use_tools ) {
			$args['tools'] = $this->tool_definitions();
		}

		$result = Palladio_AI_Openai::chat( $state['messages'], $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$assistant           = $result['message'];
		$state['messages'][] = $assistant;

		if ( empty( $assistant['tool_calls'] ) ) {
			$content = (string) ( $assistant['content'] ?? '' );
			if ( '' === trim( $content ) && 'length' === ( $result['finish_reason'] ?? '' ) ) {
				return new WP_Error(
					'palladio_ai_truncated',
					__( 'Risposta troncata dal limite token prima di produrre testo. Aumenta “Token massimi — agente (chat)” in Palladio → AI (per i modelli reasoning servono 4000+).', 'palladio' )
				);
			}
			return array( 'done' => true, 'reply' => $content );
		}

		// Non eseguire i tool in questa richiesta: accodali e lascia che i
		// prossimi passi li eseguano uno alla volta (vedi sopra).
		$state['pending_tools'] = array_values( $assistant['tool_calls'] );

		$names = array();
		foreach ( $assistant['tool_calls'] as $call ) {
			$names[] = $call['function']['name'] ?? '';
		}

		return array(
			'done'   => false,
			/* translators: %s: elenco strumenti richiesti. */
			'status' => sprintf( __( 'Elaboro… (%s)', 'palladio' ), implode( ', ', array_filter( $names ) ) ),
		);
	}

	/**
	 * Timeout (secondi) per la singola chiamata OpenAI di un passo.
	 *
	 * Deve restare sotto la finestra del proxy front-end (~60s) affinché PHP
	 * possa sempre restituire un JSON valido invece di far troncare la
	 * risposta al browser.
	 *
	 * @return int
	 */
	private function step_timeout() {
		$configured = class_exists( 'Palladio_AI_Settings' ) ? (int) Palladio_AI_Settings::http_timeout() : 45;
		return max( 20, min( 45, $configured ) );
	}

	/**
	 * System prompt dell'agente.
	 *
	 * @param int $focus Post a fuoco.
	 * @return string
	 */
	private function system_prompt( $focus ) {
		$prompt = __( 'Sei l’agente di regia di Palladio, un sistema WordPress per la vendita frazionata di immobili. Hai piena consapevolezza della struttura del progetto (edifici, unità, scenari) e dei documenti caricati su OpenAI Storage. Il tuo compito è popolare e aggiornare i contenuti.

Regole:
- Inizia SEMPRE chiamando get_structure per conoscere edifici, unità e scenari esistenti.
- Per i fatti (prezzi, misure, stanze, vincoli, descrizioni) usa search_project_documents (File Search sui documenti del progetto). NON inventare dati. Interroga lo Storage con PARSIMONIA: poche ricerche mirate, non una raffica di varianti. Se un tool ti risponde con "empty" o dice che non ci sono documenti/risultati, NON riprovare la ricerca in questo turno: procedi con i dati già disponibili (get_structure, get_entity, list_media) o chiedi le informazioni all’utente.
- Per le immagini usa SOLO gli id restituiti da list_media (non inventare id): a parità di pertinenza PREFERISCI le foto più recenti (campo "date", ordinate dalla più recente) e usa anche il NOME DEL FILE (campo "filename"), oltre a titolo/alt/didascalia, per capire il soggetto.
- Scrivi i contenuti con update_entity: puoi impostare title, excerpt, content, i meta (prezzo, mq, camere…) e i campi editoriali (eyebrow, lead, manifesto, timeline, narrative, tech, gallery, floorplan, ambient, position). Gli aggiornamenti sono parziali: invii solo i campi che vuoi cambiare.
- Puoi creare nuovi edifici (create_edificio), unità (create_unit, collegate a un edificio) o scenari (create_scenario) se richiesto.
- Prima di modifiche massicce, riepiloga brevemente cosa stai per fare. Al termine, riassumi cosa hai aggiornato.
- MODALITÀ PROGETTAZIONE: puoi sempre leggere e progettare. Se un tool di scrittura restituisce "planning_mode", NON riprovare a scrivere: proponi invece un piano chiaro (quali edifici/unità/scenari creerai e quali campi popolerai) e invita l’utente ad attivare “Applica modifiche” per dare il via. Procedi con le scritture solo quando l’utente lo conferma.
- Rispondi in italiano, in modo conciso.', 'palladio' );

		if ( $focus ) {
			$prompt .= "\n\n" . sprintf(
				/* translators: 1: id, 2: titolo. */
				__( 'Contesto: l’utente ti ha aperto con focus sull’elemento #%1$d (“%2$s”).', 'palladio' ),
				$focus,
				get_the_title( $focus )
			);
		}

		return $prompt;
	}

	/**
	 * Definizioni dei tool (function calling).
	 *
	 * @return array
	 */
	private function tool_definitions() {
		$fn = static function ( $name, $desc, $props, $required = array() ) {
			$parameters = array(
				'type'       => 'object',
				// Un array PHP vuoto verrebbe serializzato come [] mentre lo
				// schema OpenAI richiede un oggetto {}: forza stdClass.
				'properties' => empty( $props ) ? new stdClass() : $props,
			);

			if ( ! empty( $required ) ) {
				$parameters['required'] = $required;
			}

			return array(
				'type'     => 'function',
				'function' => array(
					'name'        => $name,
					'description' => $desc,
					'parameters'  => $parameters,
				),
			);
		};

		return array(
			$fn( 'get_structure', 'Restituisce l’intera struttura: edifici con le loro unità e scenari, con i dati chiave.', array() ),
			$fn( 'get_entity', 'Restituisce tutti i dati di un elemento (edificio/unità/scenario) dato il suo id.', array( 'id' => array( 'type' => 'integer' ) ), array( 'id' ) ),
			$fn( 'list_media', 'Elenca le immagini della libreria media (id, titolo, filename, alt, didascalia, date, attached), ordinate dalle più recenti e con priorità a quelle allegate al post indicato.', array( 'post_id' => array( 'type' => 'integer' ) ) ),
			$fn( 'search_project_documents', 'Cerca informazioni nei documenti del progetto su OpenAI Storage (File Search).', array( 'query' => array( 'type' => 'string' ) ), array( 'query' ) ),
			$fn( 'update_entity', 'Aggiorna i contenuti di un elemento. Campi opzionali: title, excerpt, content, meta (oggetto chiave→valore, es. prezzo, mq_commerciali, camere), editorial (oggetto con eyebrow, lead, manifesto[], timeline[], narrative[], tech[], gallery[], floorplan, ambient, position, hero_image e altri).', array(
				'id'        => array( 'type' => 'integer' ),
				'title'     => array( 'type' => 'string' ),
				'excerpt'   => array( 'type' => 'string' ),
				'content'   => array( 'type' => 'string' ),
				'meta'      => array( 'type' => 'object' ),
				'editorial' => array( 'type' => 'object' ),
			), array( 'id' ) ),
			$fn( 'create_edificio', 'Crea un nuovo edificio (bozza).', array( 'title' => array( 'type' => 'string' ) ), array( 'title' ) ),
			$fn( 'create_unit', 'Crea una nuova unità (bozza) collegata a un edificio.', array( 'building_id' => array( 'type' => 'integer' ), 'title' => array( 'type' => 'string' ) ), array( 'building_id', 'title' ) ),
			$fn( 'create_scenario', 'Crea un nuovo scenario (bozza).', array( 'title' => array( 'type' => 'string' ) ), array( 'title' ) ),
		);
	}

	/**
	 * Esegue un tool.
	 *
	 * @param string $name  Nome tool.
	 * @param array  $args  Argomenti.
	 * @param array  $state Stato del turno (per riferimento, per la cache).
	 * @return array
	 */
	private function run_tool( $name, $args, &$state = array() ) {
		// Barriera modalità progettazione: nessuna scrittura senza consenso.
		if ( ! $this->apply && in_array( $name, $this->write_tools, true ) ) {
			return array(
				'blocked' => true,
				'reason'  => 'planning_mode',
				'message' => __( 'Modalità progettazione attiva: nessuna modifica applicata. Proponi il piano all’utente; per procedere l’utente deve attivare “Applica modifiche”.', 'palladio' ),
			);
		}

		switch ( $name ) {
			case 'get_structure':
				return $this->tool_get_structure();
			case 'get_entity':
				return $this->tool_get_entity( absint( $args['id'] ?? 0 ) );
			case 'list_media':
				return array( 'media' => Palladio_AI_Composer::media_list( absint( $args['post_id'] ?? 0 ) ) );
			case 'search_project_documents':
				return $this->tool_search( (string) ( $args['query'] ?? '' ), $state );
			case 'update_entity':
				return $this->tool_update( $args );
			case 'create_edificio':
				return $this->tool_create( 'pll_edificio', $args );
			case 'create_unit':
				return $this->tool_create( 'pll_unita', $args );
			case 'create_scenario':
				return $this->tool_create( 'pll_scenario', $args );
			default:
				return array( 'error' => 'unknown_tool' );
		}
	}

	/**
	 * Tool: struttura completa.
	 *
	 * @return array
	 */
	private function tool_get_structure() {
		$edifici = get_posts( array(
			'post_type'      => 'pll_edificio',
			'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
			'posts_per_page' => 50,
			'no_found_rows'  => true,
		) );

		$out = array();
		foreach ( $edifici as $e ) {
			$units = get_posts( array(
				'post_type'      => 'pll_unita',
				'post_parent'    => $e->ID,
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => 50,
				'no_found_rows'  => true,
			) );
			$u = array();
			foreach ( $units as $unit ) {
				$stat = get_the_terms( $unit->ID, 'pll_stato' );
				$u[]  = array(
					'id'     => $unit->ID,
					'title'  => $unit->post_title,
					'status' => $unit->post_status,
					'prezzo' => (float) get_post_meta( $unit->ID, '_pll_prezzo', true ),
					'mq'     => (float) get_post_meta( $unit->ID, '_pll_mq_commerciali', true ),
					'stato'  => ( $stat && ! is_wp_error( $stat ) ) ? $stat[0]->slug : '',
					'lang'   => class_exists( 'Palladio_I18n_Translator' ) ? Palladio_I18n_Translator::get_lang( $unit->ID ) : '',
				);
			}
			$out[] = array(
				'id'         => $e->ID,
				'title'      => $e->post_title,
				'status'     => $e->post_status,
				'indirizzo'  => (string) get_post_meta( $e->ID, '_pll_indirizzo', true ),
				'mq_totali'  => (float) get_post_meta( $e->ID, '_pll_mq_totali', true ),
				'is_home'    => ( (int) get_option( 'palladio_home_building', 0 ) === $e->ID ),
				'units'      => $u,
			);
		}

		$scenari = get_posts( array(
			'post_type'      => 'pll_scenario',
			'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
			'posts_per_page' => 50,
			'no_found_rows'  => true,
		) );
		$sc = array();
		foreach ( $scenari as $s ) {
			$sc[] = array(
				'id'     => $s->ID,
				'title'  => $s->post_title,
				'status' => $s->post_status,
				'tipo'   => (string) get_post_meta( $s->ID, '_pll_scenario_tipo', true ),
				'unita'  => (string) get_post_meta( $s->ID, '_pll_scenario_unita', true ),
			);
		}

		$vs = class_exists( 'Palladio_AI_Settings' ) ? Palladio_AI_Settings::vector_store() : '';

		return array(
			'edifici'          => $out,
			'scenari'          => $sc,
			'storage_attivo'   => '' !== $vs,
		);
	}

	/**
	 * Tool: dati completi di un elemento.
	 *
	 * @param int $id ID.
	 * @return array
	 */
	private function tool_get_entity( $id ) {
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || ! in_array( $post->post_type, array( 'pll_edificio', 'pll_unita', 'pll_scenario' ), true ) ) {
			return array( 'error' => 'not_found' );
		}

		$meta = array();
		foreach ( get_post_meta( $id ) as $key => $vals ) {
			if ( 0 === strpos( $key, '_pll_' ) && '_pll_editorial' !== $key ) {
				$meta[ substr( $key, 5 ) ] = maybe_unserialize( $vals[0] );
			}
		}

		return array(
			'id'        => $id,
			'post_type' => $post->post_type,
			'title'     => $post->post_title,
			'excerpt'   => $post->post_excerpt,
			'content'   => $post->post_content,
			'status'    => $post->post_status,
			'meta'      => $meta,
			'editorial' => function_exists( 'palladio_editorial' ) ? palladio_editorial( $id ) : array(),
		);
	}

	/**
	 * Tool: File Search sui documenti su Storage.
	 *
	 * @param string $query Domanda.
	 * @param array  $state Stato del turno (per riferimento, per la cache).
	 * @return array
	 */
	private function tool_search( $query, &$state = array() ) {
		$vs = Palladio_AI_Settings::vector_store();
		if ( '' === $vs ) {
			return array(
				'note'  => __( 'Nessun documento su Storage: non ci sono documenti da consultare. Non riprovare la ricerca; procedi con i dati già disponibili o chiedi all’utente.', 'palladio' ),
				'empty' => true,
			);
		}

		// Cache per turno: evita di interrogare ripetutamente lo Storage con la
		// stessa domanda (chiamate lente e costose alla Responses API).
		if ( ! isset( $state['searches'] ) || ! is_array( $state['searches'] ) ) {
			$state['searches'] = array();
		}
		$ckey = md5( strtolower( trim( $query ) ) );
		if ( isset( $state['searches'][ $ckey ] ) ) {
			return array( 'result' => $state['searches'][ $ckey ], 'cached' => true );
		}
		// Se una precedente ricerca nel turno non ha trovato nulla, evita di
		// martellare lo Storage con altre varianti.
		if ( ! empty( $state['storage_empty'] ) ) {
			return array(
				'note'  => __( 'Ricerche precedenti in questo turno non hanno prodotto risultati utili dallo Storage. Non insistere: procedi con i dati disponibili o chiedi all’utente.', 'palladio' ),
				'empty' => true,
			);
		}

		$res = Palladio_AI_Openai::responses(
			__( 'Rispondi solo con le informazioni presenti nei documenti. Cita i valori esatti (prezzi, misure, date). Se i documenti non contengono nulla di pertinente rispondi esattamente con "NESSUN_RISULTATO".', 'palladio' ),
			$query,
			array(
				'vector_store_ids' => array( $vs ),
				// Serve il budget pieno: con i modelli reasoning i token di
				// ragionamento consumano il limite prima del testo (un cap
				// basso produceva risposte vuote = "nessun risultato").
				'max_tokens'       => Palladio_AI_Settings::max_tokens( 'agent' ),
				'timeout'          => $this->step_timeout(),
			)
		);

		if ( is_wp_error( $res ) ) {
			return array( 'error' => $res->get_error_message() );
		}

		$text = trim( (string) $res['text'] );
		if ( '' === $text || false !== strpos( $text, 'NESSUN_RISULTATO' ) ) {
			$state['storage_empty'] = true;
			return array(
				'note'  => __( 'Nessun risultato pertinente nei documenti su Storage per questa ricerca. Non riprovare con altre varianti in questo turno.', 'palladio' ),
				'empty' => true,
			);
		}

		$state['searches'][ $ckey ] = $text;
		return array( 'result' => $text );
	}

	/**
	 * Tool: aggiorna i contenuti di un elemento.
	 *
	 * @param array $args Argomenti.
	 * @return array
	 */
	private function tool_update( $args ) {
		$id = absint( $args['id'] ?? 0 );
		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
			return array( 'error' => 'permission_denied' );
		}
		$post = get_post( $id );
		if ( ! $post || ! in_array( $post->post_type, array( 'pll_edificio', 'pll_unita', 'pll_scenario' ), true ) ) {
			return array( 'error' => 'not_found' );
		}

		$data = array();
		foreach ( array( 'title', 'excerpt', 'content' ) as $k ) {
			if ( isset( $args[ $k ] ) ) {
				$data[ $k ] = $args[ $k ];
			}
		}
		if ( isset( $args['meta'] ) && is_array( $args['meta'] ) ) {
			$data['meta'] = $args['meta'];
		}
		if ( isset( $args['editorial'] ) && is_array( $args['editorial'] ) ) {
			$data['editorial'] = $args['editorial'];
		}

		if ( ! $data ) {
			return array( 'error' => 'nothing_to_update' );
		}

		$summary = Palladio_AI_Composer::apply_structured( $id, $data );

		return array( 'updated' => true, 'id' => $id, 'summary' => $summary );
	}

	/**
	 * Tool: crea unità o scenario.
	 *
	 * @param string $post_type CPT.
	 * @param array  $args      Argomenti.
	 * @return array
	 */
	private function tool_create( $post_type, $args ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return array( 'error' => 'permission_denied' );
		}
		$title  = sanitize_text_field( $args['title'] ?? '' );
		if ( '' === $title ) {
			return array( 'error' => 'missing_title' );
		}
		$parent = 0;
		if ( 'pll_unita' === $post_type ) {
			$parent = absint( $args['building_id'] ?? 0 );
			if ( ! $parent || 'pll_edificio' !== get_post_type( $parent ) ) {
				return array( 'error' => 'invalid_building' );
			}
		}

		$new_id = wp_insert_post( array(
			'post_type'   => $post_type,
			'post_status' => 'draft',
			'post_title'  => $title,
			'post_parent' => $parent,
		), true );

		if ( is_wp_error( $new_id ) ) {
			return array( 'error' => $new_id->get_error_message() );
		}

		return array( 'created' => true, 'id' => (int) $new_id, 'edit_link' => get_edit_post_link( $new_id, 'raw' ) );
	}

	/**
	 * Versione asset da filemtime.
	 *
	 * @param string $rel Percorso relativo.
	 * @return string
	 */
	private function ver( $rel ) {
		$path = PALLADIO_DIR . $rel;
		return ( is_readable( $path ) && filemtime( $path ) ) ? (string) filemtime( $path ) : PALLADIO_VERSION;
	}
}
