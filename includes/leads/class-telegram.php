<?php
/**
 * Modulo Lead — integrazione Telegram.
 *
 * Ogni lead registrato in Pipeline viene inviato, con TUTTI i suoi dati,
 * alle chat Telegram autorizzate. Le credenziali (token del bot e secret
 * del webhook) vivono SOLO in wp-config.php, mai nel database:
 *
 *   define( 'PALLADIO_TELEGRAM_BOT_TOKEN', '123456:ABC...' );
 *   define( 'PALLADIO_TELEGRAM_SECRET', 'una-stringa-casuale-lunga' );
 *
 * Il webhook REST (palladio/v1/telegram) riceve i messaggi del bot: serve a
 * scoprire la propria Chat ID (comando /id), a consultare gli ultimi lead
 * (/ultimi, solo chat autorizzate) e ad annotare le chat viste di recente
 * nella pagina Palladio → Telegram.
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Notifiche lead e comandi via Telegram.
 */
class Palladio_Leads_Telegram {

	const CAP    = 'manage_palladio';
	const OPTION = 'palladio_telegram';

	/**
	 * Registra gli hook.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'palladio/lead_created', array( $this, 'notify_lead' ), 10, 2 );
		add_action( 'rest_api_init', array( $this, 'rest_route' ) );

		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'menu' ), 998 );
			add_action( 'admin_post_palladio_save_telegram', array( $this, 'save' ) );
			add_action( 'admin_post_palladio_telegram_webhook', array( $this, 'handle_webhook_action' ) );
		}
	}

	// -------------------------------------------------------------------------
	// Configurazione.
	// -------------------------------------------------------------------------

	/**
	 * Token del bot (solo da costante).
	 *
	 * @return string
	 */
	private static function token() {
		return defined( 'PALLADIO_TELEGRAM_BOT_TOKEN' ) ? (string) PALLADIO_TELEGRAM_BOT_TOKEN : '';
	}

	/**
	 * Secret del webhook (solo da costante).
	 *
	 * @return string
	 */
	private static function secret() {
		return defined( 'PALLADIO_TELEGRAM_SECRET' ) ? (string) PALLADIO_TELEGRAM_SECRET : '';
	}

	/**
	 * Configurazione con default.
	 *
	 * @return array{enabled:int,notify_leads:int,chat_ids:string,recent:array}
	 */
	public static function config() {
		$defaults = array(
			'enabled'      => 0,
			'notify_leads' => 1,
			'chat_ids'     => '',
			'recent'       => array(), // [chat_id => {name,last}]
		);

		$config = get_option( self::OPTION, array() );
		$config = wp_parse_args( is_array( $config ) ? $config : array(), $defaults );
		if ( ! is_array( $config['recent'] ) ) {
			$config['recent'] = array();
		}

		return $config;
	}

	/**
	 * Chat ID autorizzate (interi non nulli).
	 *
	 * @return string[]
	 */
	public static function chat_ids() {
		$raw = self::config()['chat_ids'];
		$ids = array();
		foreach ( preg_split( '/[\s,;]+/', (string) $raw ) as $part ) {
			$part = trim( $part );
			if ( '' !== $part && preg_match( '/^-?\d+$/', $part ) ) {
				$ids[] = $part;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * L'integrazione è operativa?
	 *
	 * @return bool
	 */
	private static function active() {
		$config = self::config();
		return ! empty( $config['enabled'] ) && '' !== self::token();
	}

	// -------------------------------------------------------------------------
	// API Telegram.
	// -------------------------------------------------------------------------

	/**
	 * Chiama l'API Telegram.
	 *
	 * @param string $method Metodo API (es. sendMessage).
	 * @param array  $body   Parametri.
	 * @return array|WP_Error Risposta decodificata.
	 */
	private static function api( $method, array $body = array() ) {
		$token = self::token();
		if ( '' === $token ) {
			return new WP_Error( 'palladio_tg_no_token', __( 'PALLADIO_TELEGRAM_BOT_TOKEN non definita in wp-config.php.', 'palladio' ) );
		}

		$response = wp_remote_post(
			'https://api.telegram.org/bot' . rawurlencode( $token ) . '/' . $method,
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'palladio_tg_bad_response', __( 'Risposta Telegram non valida.', 'palladio' ) );
		}
		if ( empty( $decoded['ok'] ) ) {
			return new WP_Error( 'palladio_tg_api_error', (string) ( $decoded['description'] ?? 'API error' ) );
		}

		return $decoded;
	}

	/**
	 * Invia un messaggio a una chat.
	 *
	 * @param string $chat_id Chat ID.
	 * @param string $text    Testo (HTML).
	 * @return array|WP_Error
	 */
	private static function send( $chat_id, $text ) {
		return self::api( 'sendMessage', array(
			'chat_id'                  => $chat_id,
			'text'                     => $text,
			'parse_mode'               => 'HTML',
			'disable_web_page_preview' => true,
		) );
	}

	// -------------------------------------------------------------------------
	// Notifica lead.
	// -------------------------------------------------------------------------

	/**
	 * Invia il nuovo lead (con tutti i dati) alle chat autorizzate.
	 *
	 * @param int   $lead_id ID lead.
	 * @param array $data    Dati del lead.
	 * @return void
	 */
	public function notify_lead( $lead_id, $data ) {
		$config = self::config();
		if ( ! self::active() || empty( $config['notify_leads'] ) ) {
			return;
		}

		$chats = self::chat_ids();
		if ( ! $chats ) {
			return;
		}

		$text = self::format_lead( $lead_id, (array) $data );
		foreach ( $chats as $chat_id ) {
			self::send( $chat_id, $text );
		}
	}

	/**
	 * Formatta un lead con TUTTI i dati memorizzati (HTML Telegram).
	 *
	 * @param int   $lead_id ID lead.
	 * @param array $data    Dati lead.
	 * @return string
	 */
	private static function format_lead( $lead_id, array $data ) {
		$e = static function ( $value ) {
			return esc_html( (string) $value );
		};

		$lines   = array();
		$lines[] = '🆕 <b>' . $e( sprintf( __( 'Nuovo lead #%d — Palladio', 'palladio' ), $lead_id ) ) . '</b>';
		$lines[] = '';
		$lines[] = '👤 <b>' . $e( __( 'Nome', 'palladio' ) ) . ':</b> ' . $e( $data['nome'] ?? '' );
		$lines[] = '✉️ <b>Email:</b> ' . $e( $data['email'] ?? '' );

		if ( ! empty( $data['telefono'] ) ) {
			$lines[] = '📞 <b>' . $e( __( 'Telefono', 'palladio' ) ) . ':</b> ' . $e( $data['telefono'] );
		}
		if ( ! empty( $data['motivo'] ) ) {
			$lines[] = '📋 <b>' . $e( __( 'Vorrei', 'palladio' ) ) . ':</b> ' . $e( $data['motivo'] );
		}
		if ( ! empty( $data['note'] ) ) {
			$lines[] = '💬 <b>' . $e( __( 'Messaggio', 'palladio' ) ) . ':</b> ' . $e( $data['note'] );
		}
		if ( ! empty( $data['unita_ids'] ) ) {
			$titles = array_map( 'get_the_title', (array) $data['unita_ids'] );
			$lines[] = '🏠 <b>' . $e( __( 'Unità', 'palladio' ) ) . ':</b> ' . $e( implode( ', ', array_filter( $titles ) ) );
		}
		if ( ! empty( $data['pagina'] ) ) {
			$lines[] = '🔗 <b>' . $e( __( 'Pagina', 'palladio' ) ) . ':</b> ' . $e( $data['pagina'] );
		}
		if ( ! empty( $data['lang'] ) ) {
			$lines[] = '🌐 <b>' . $e( __( 'Lingua', 'palladio' ) ) . ':</b> ' . $e( strtoupper( (string) $data['lang'] ) );
		}
		if ( ! empty( $data['source'] ) ) {
			$lines[] = '📡 <b>' . $e( __( 'Fonte', 'palladio' ) ) . ':</b> ' . $e( $data['source'] );
		}

		$utm = array_filter( array(
			'utm_source'   => $data['utm_source'] ?? '',
			'utm_medium'   => $data['utm_medium'] ?? '',
			'utm_campaign' => $data['utm_campaign'] ?? '',
		) );
		if ( $utm ) {
			$pairs = array();
			foreach ( $utm as $key => $value ) {
				$pairs[] = $key . '=' . $value;
			}
			$lines[] = '🎯 <b>UTM:</b> ' . $e( implode( ' · ', $pairs ) );
		}

		$lines[] = '✅ <b>GDPR:</b> ' . $e( empty( $data['consenso_gdpr'] ) ? __( 'no', 'palladio' ) : __( 'sì', 'palladio' ) );
		$lines[] = '🕒 ' . $e( date_i18n( get_option( 'date_format' ) . ' H:i', current_time( 'timestamp' ) ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		$lines[] = '';
		$lines[] = '📊 ' . $e( __( 'Pipeline', 'palladio' ) ) . ': ' . esc_url( admin_url( 'edit.php?post_type=pll_edificio&page=palladio-leads' ) );

		return implode( "\n", $lines );
	}

	// -------------------------------------------------------------------------
	// Webhook (comandi dal bot).
	// -------------------------------------------------------------------------

	/**
	 * Registra la route REST del webhook.
	 *
	 * @return void
	 */
	public function rest_route() {
		register_rest_route( 'palladio/v1', '/telegram', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_update' ),
			'permission_callback' => '__return_true', // Autenticazione via secret header.
		) );
	}

	/**
	 * URL del webhook.
	 *
	 * @return string
	 */
	public static function webhook_url() {
		return rest_url( 'palladio/v1/telegram' );
	}

	/**
	 * Gestisce un update Telegram (autenticato dal secret header).
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response
	 */
	public function handle_update( $request ) {
		$secret = self::secret();
		$header = (string) $request->get_header( 'x-telegram-bot-api-secret-token' );
		if ( '' === $secret || ! hash_equals( $secret, $header ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 403 );
		}
		if ( ! self::active() ) {
			return new WP_REST_Response( array( 'ok' => true ) );
		}

		$update  = $request->get_json_params();
		$message = $update['message'] ?? ( $update['edited_message'] ?? null );
		if ( ! is_array( $message ) || empty( $message['chat']['id'] ) ) {
			return new WP_REST_Response( array( 'ok' => true ) );
		}

		$chat_id = (string) $message['chat']['id'];
		$name    = trim( ( $message['from']['first_name'] ?? '' ) . ' ' . ( $message['from']['last_name'] ?? '' ) );
		$text    = trim( (string) ( $message['text'] ?? '' ) );

		$this->remember_chat( $chat_id, $name ? $name : (string) ( $message['chat']['title'] ?? '' ) );

		$authorized = in_array( $chat_id, self::chat_ids(), true );
		$command    = strtolower( strtok( $text, ' @' ) );

		switch ( $command ) {
			case '/id':
				self::send( $chat_id, sprintf( __( 'La Chat ID di questa conversazione è: %s', 'palladio' ), '<code>' . esc_html( $chat_id ) . '</code>' ) );
				break;

			case '/start':
			case '/aiuto':
			case '/help':
				self::send( $chat_id, self::instructions_text( $authorized ) );
				break;

			case '/ultimi':
				if ( ! $authorized ) {
					self::send( $chat_id, __( 'Chat non autorizzata: aggiungi questa Chat ID in Palladio → Telegram.', 'palladio' ) );
					break;
				}
				self::send( $chat_id, self::latest_leads_text() );
				break;
		}

		return new WP_REST_Response( array( 'ok' => true ) );
	}

	/**
	 * Annota la chat tra le "viste di recente" (max 20).
	 *
	 * @param string $chat_id Chat ID.
	 * @param string $name    Nome visualizzato.
	 * @return void
	 */
	private function remember_chat( $chat_id, $name ) {
		$config = self::config();

		$config['recent'][ $chat_id ] = array(
			'name' => sanitize_text_field( $name ),
			'last' => time(),
		);

		if ( count( $config['recent'] ) > 20 ) {
			uasort( $config['recent'], static function ( $a, $b ) {
				return ( $b['last'] ?? 0 ) <=> ( $a['last'] ?? 0 );
			} );
			$config['recent'] = array_slice( $config['recent'], 0, 20, true );
		}

		update_option( self::OPTION, $config, false );
	}

	/**
	 * Testo guida inviato dal bot (/start, /aiuto).
	 *
	 * @param bool $authorized Chat autorizzata.
	 * @return string
	 */
	private static function instructions_text( $authorized ) {
		$lines   = array();
		$lines[] = '🏛 <b>Palladio — ' . esc_html( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ) . '</b>';
		$lines[] = '';
		$lines[] = esc_html__( 'Questo bot invia in chat ogni nuova richiesta ricevuta dal sito (Pipeline lead), con tutti i dati: nome, contatti, messaggio, unità, pagina e lingua di provenienza.', 'palladio' );
		$lines[] = '';
		$lines[] = '<b>' . esc_html__( 'Comandi', 'palladio' ) . '</b>';
		$lines[] = '/id — ' . esc_html__( 'mostra la Chat ID di questa conversazione (da inserire in Palladio → Telegram)', 'palladio' );
		$lines[] = '/ultimi — ' . esc_html__( 'gli ultimi 5 lead ricevuti (solo chat autorizzate)', 'palladio' );
		$lines[] = '/aiuto — ' . esc_html__( 'questa guida', 'palladio' );
		if ( ! $authorized ) {
			$lines[] = '';
			$lines[] = '⚠️ ' . esc_html__( 'Questa chat non è ancora autorizzata: invia /id e aggiungi la Chat ID in Palladio → Telegram.', 'palladio' );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Ultimi 5 lead in formato breve (per /ultimi).
	 *
	 * @return string
	 */
	private static function latest_leads_text() {
		$result = Palladio_Leads_Store::query( array( 'per_page' => 5, 'paged' => 1 ) );
		$rows   = is_array( $result ) && isset( $result['items'] ) ? $result['items'] : ( is_array( $result ) ? $result : array() );

		if ( ! $rows ) {
			return esc_html__( 'Nessun lead in archivio.', 'palladio' );
		}

		$lines   = array();
		$lines[] = '📊 <b>' . esc_html__( 'Ultimi lead', 'palladio' ) . '</b>';
		foreach ( $rows as $row ) {
			$row     = (array) $row;
			$lines[] = '';
			$lines[] = '• <b>' . esc_html( $row['nome'] ?? '' ) . '</b> — ' . esc_html( $row['email'] ?? '' )
				. ( ! empty( $row['telefono'] ) ? ' — ' . esc_html( $row['telefono'] ) : '' );
			$meta = array_filter( array( $row['motivo'] ?? '', $row['stato'] ?? '', $row['created_at'] ?? '' ) );
			if ( $meta ) {
				$lines[] = '  ' . esc_html( implode( ' · ', $meta ) );
			}
		}

		return implode( "\n", $lines );
	}

	// -------------------------------------------------------------------------
	// Admin.
	// -------------------------------------------------------------------------

	/**
	 * Voce di menu "Telegram".
	 *
	 * @return void
	 */
	public function menu() {
		add_submenu_page(
			'edit.php?post_type=pll_edificio',
			__( 'Telegram', 'palladio' ),
			__( 'Telegram', 'palladio' ),
			self::CAP,
			'palladio-telegram',
			array( $this, 'page' )
		);
	}

	/**
	 * Pagina impostazioni Telegram.
	 *
	 * @return void
	 */
	public function page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permesso negato.', 'palladio' ) );
		}

		$config = self::config();
		$msg    = isset( $_GET['palladio_msg'] ) ? sanitize_key( wp_unslash( $_GET['palladio_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$detail = get_transient( 'palladio_tg_msg_' . get_current_user_id() );
		delete_transient( 'palladio_tg_msg_' . get_current_user_id() );

		uasort( $config['recent'], static function ( $a, $b ) {
			return ( $b['last'] ?? 0 ) <=> ( $a['last'] ?? 0 );
		} );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Palladio — Telegram', 'palladio' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Ogni lead della Pipeline viene inviato, con tutti i suoi dati, alle chat Telegram autorizzate.', 'palladio' ); ?></p>

			<?php if ( 'saved' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Impostazioni Telegram salvate.', 'palladio' ); ?></p></div>
			<?php elseif ( 'ok' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $detail ? $detail : __( 'Operazione completata.', 'palladio' ) ); ?></p></div>
			<?php elseif ( 'error' === $msg ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $detail ? $detail : __( 'Operazione non riuscita.', 'palladio' ) ); ?></p></div>
			<?php endif; ?>

			<div class="card" style="max-width:900px;padding:1.5em 2em;">
				<h2><?php esc_html_e( 'Come configurare e usare l’integrazione Telegram', 'palladio' ); ?></h2>
				<p><strong><?php esc_html_e( 'Configurazione (una sola volta):', 'palladio' ); ?></strong></p>
				<ol>
					<li><?php echo wp_kses_post( __( 'Crea un bot con <a href="https://t.me/BotFather" target="_blank" rel="noopener">@BotFather</a> su Telegram e copia il token.', 'palladio' ) ); ?></li>
					<li><?php esc_html_e( 'In wp-config.php aggiungi:', 'palladio' ); ?><br>
						<code>define( 'PALLADIO_TELEGRAM_BOT_TOKEN', '123456:ABC...' );</code><br>
						<code>define( 'PALLADIO_TELEGRAM_SECRET', 'una-stringa-casuale-lunga' );</code></li>
					<li><?php echo wp_kses_post( __( 'Spunta “Abilita Telegram”, salva, poi clicca <strong>Registra webhook</strong> e <strong>Verifica stato webhook</strong> per conferma.', 'palladio' ) ); ?></li>
					<li><?php echo wp_kses_post( __( 'Scopri la tua Chat ID: apri il bot su Telegram e invia <code>/id</code> (il bot risponde con l’ID). L’ID comparirà anche qui sotto in “Chat ID viste di recente”, con un pulsante <strong>Aggiungi</strong>.', 'palladio' ) ); ?></li>
					<li><?php esc_html_e( 'Aggiungi le Chat ID autorizzate e salva di nuovo le impostazioni.', 'palladio' ); ?></li>
				</ol>
				<p><strong><?php esc_html_e( 'Cosa fa il bot:', 'palladio' ); ?></strong></p>
				<ul style="list-style:disc;margin-left:1.5em;">
					<li><?php echo wp_kses_post( __( '<strong>Lead in tempo reale</strong>: ogni richiesta dal form contatti arriva in chat con tutti i dati (nome, email, telefono, “Vorrei”, messaggio, unità, pagina e lingua di provenienza, fonte, UTM, consenso GDPR) e il link alla Pipeline.', 'palladio' ) ); ?></li>
					<li><?php echo wp_kses_post( __( '<strong>Comandi</strong>: <code>/id</code> mostra la Chat ID; <code>/ultimi</code> gli ultimi 5 lead (solo chat autorizzate); <code>/aiuto</code> la guida.', 'palladio' ) ); ?></li>
				</ul>
				<p class="description"><?php esc_html_e( 'Le credenziali vivono solo in wp-config.php, mai nel database.', 'palladio' ); ?></p>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'palladio_telegram' ); ?>
				<input type="hidden" name="action" value="palladio_save_telegram">

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Stato costanti', 'palladio' ); ?></th>
						<td>
							<p>PALLADIO_TELEGRAM_BOT_TOKEN:
								<?php if ( '' !== self::token() ) : ?>
									<span style="color:#1a6b41;font-weight:600;"><?php esc_html_e( 'definita', 'palladio' ); ?></span>
								<?php else : ?>
									<span style="color:#b32d2e;font-weight:600;"><?php esc_html_e( 'mancante', 'palladio' ); ?></span>
								<?php endif; ?>
							</p>
							<p>PALLADIO_TELEGRAM_SECRET:
								<?php if ( '' !== self::secret() ) : ?>
									<span style="color:#1a6b41;font-weight:600;"><?php esc_html_e( 'definita', 'palladio' ); ?></span>
								<?php else : ?>
									<span style="color:#b32d2e;font-weight:600;"><?php esc_html_e( 'mancante', 'palladio' ); ?></span>
								<?php endif; ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'URL webhook', 'palladio' ); ?></th>
						<td><code><?php echo esc_html( self::webhook_url() ); ?></code></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Abilita Telegram', 'palladio' ); ?></th>
						<td><label><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $config['enabled'] ) ); ?>> <?php esc_html_e( 'Attiva webhook, comandi e notifiche', 'palladio' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Notifica nuovi lead', 'palladio' ); ?></th>
						<td><label><input type="checkbox" name="notify_leads" value="1" <?php checked( ! empty( $config['notify_leads'] ) ); ?>> <?php esc_html_e( 'Invia in chat ogni nuovo lead della Pipeline con tutti i dati', 'palladio' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="pll-tg-chats"><?php esc_html_e( 'Chat ID autorizzate', 'palladio' ); ?></label></th>
						<td>
							<input type="text" id="pll-tg-chats" name="chat_ids" class="regular-text" value="<?php echo esc_attr( $config['chat_ids'] ); ?>" placeholder="123456789, -100987654321">
							<p class="description"><?php esc_html_e( 'Separate da virgola. Solo queste chat possono usare i comandi e ricevere le notifiche.', 'palladio' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Salva impostazioni Telegram', 'palladio' ) ); ?>
			</form>

			<p>
				<a class="button" href="<?php echo esc_url( $this->action_url( 'register' ) ); ?>"><?php esc_html_e( 'Registra webhook', 'palladio' ); ?></a>
				<a class="button" href="<?php echo esc_url( $this->action_url( 'check' ) ); ?>"><?php esc_html_e( 'Verifica stato webhook', 'palladio' ); ?></a>
				<a class="button" href="<?php echo esc_url( $this->action_url( 'instructions' ) ); ?>"><?php esc_html_e( 'Invia istruzioni su Telegram', 'palladio' ); ?></a>
			</p>
			<p class="description"><?php esc_html_e( 'I pulsanti usano il token e il secret configurati nelle costanti, senza terminale. Registra il webhook dopo aver salvato le impostazioni e definito le costanti.', 'palladio' ); ?></p>

			<?php if ( $config['recent'] ) : ?>
				<h2><?php esc_html_e( 'Chat ID viste di recente', 'palladio' ); ?></h2>
				<table class="widefat striped" style="max-width:700px;">
					<thead><tr>
						<th><?php esc_html_e( 'Chat ID', 'palladio' ); ?></th>
						<th><?php esc_html_e( 'Nome', 'palladio' ); ?></th>
						<th><?php esc_html_e( 'Ultimo contatto', 'palladio' ); ?></th>
						<th></th>
					</tr></thead>
					<tbody>
						<?php $authorized_ids = self::chat_ids(); ?>
						<?php foreach ( $config['recent'] as $chat_id => $entry ) : ?>
							<tr>
								<td><code><?php echo esc_html( (string) $chat_id ); ?></code></td>
								<td><?php echo esc_html( $entry['name'] ?? '' ); ?></td>
								<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' H:i', (int) ( $entry['last'] ?? 0 ) ) ); ?></td>
								<td>
									<?php if ( in_array( (string) $chat_id, $authorized_ids, true ) ) : ?>
										<span style="color:#1a6b41;font-weight:600;"><?php esc_html_e( 'autorizzata', 'palladio' ); ?></span>
									<?php else : ?>
										<button type="button" class="button button-small pll-tg-add" data-chat="<?php echo esc_attr( (string) $chat_id ); ?>"><?php esc_html_e( 'Aggiungi', 'palladio' ); ?></button>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<script>
				document.addEventListener('click', function (e) {
					if (!e.target.classList.contains('pll-tg-add')) { return; }
					var field = document.getElementById('pll-tg-chats');
					var id = e.target.getAttribute('data-chat');
					var ids = field.value.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
					if (ids.indexOf(id) === -1) { ids.push(id); }
					field.value = ids.join(', ');
					e.target.textContent = '<?php echo esc_js( __( 'Aggiunta — salva le impostazioni', 'palladio' ) ); ?>';
					e.target.disabled = true;
					field.scrollIntoView({ behavior: 'smooth', block: 'center' });
				});
				</script>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * URL nonce per le azioni webhook.
	 *
	 * @param string $do Azione (register|check|instructions).
	 * @return string
	 */
	private function action_url( $do ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'palladio_telegram_webhook',
					'do'     => $do,
				),
				admin_url( 'admin-post.php' )
			),
			'palladio_telegram_webhook'
		);
	}

	/**
	 * Salvataggio impostazioni.
	 *
	 * @return void
	 */
	public function save() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permesso negato.', 'palladio' ) );
		}
		check_admin_referer( 'palladio_telegram' );

		$config = self::config();

		$config['enabled']      = empty( $_POST['enabled'] ) ? 0 : 1;
		$config['notify_leads'] = empty( $_POST['notify_leads'] ) ? 0 : 1;
		$config['chat_ids']     = isset( $_POST['chat_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['chat_ids'] ) ) : '';

		update_option( self::OPTION, $config, false );

		wp_safe_redirect( admin_url( 'edit.php?post_type=pll_edificio&page=palladio-telegram&palladio_msg=saved' ) );
		exit;
	}

	/**
	 * Azioni webhook: registra, verifica, invia istruzioni.
	 *
	 * @return void
	 */
	public function handle_webhook_action() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permesso negato.', 'palladio' ) );
		}
		check_admin_referer( 'palladio_telegram_webhook' );

		$do       = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
		$redirect = admin_url( 'edit.php?post_type=pll_edificio&page=palladio-telegram' );

		switch ( $do ) {
			case 'register':
				$result = self::api( 'setWebhook', array(
					'url'             => self::webhook_url(),
					'secret_token'    => self::secret(),
					'allowed_updates' => array( 'message' ),
				) );
				$done = __( 'Webhook registrato su Telegram.', 'palladio' );
				break;

			case 'check':
				$result = self::api( 'getWebhookInfo' );
				if ( ! is_wp_error( $result ) ) {
					$info = $result['result'] ?? array();
					$done = sprintf(
						/* translators: 1: URL, 2: aggiornamenti in attesa, 3: ultimo errore. */
						__( 'Webhook attivo su %1$s — in attesa: %2$d — ultimo errore: %3$s', 'palladio' ),
						(string) ( $info['url'] ?? '—' ),
						(int) ( $info['pending_update_count'] ?? 0 ),
						(string) ( $info['last_error_message'] ?? '—' )
					);
				}
				break;

			case 'instructions':
				$chats = self::chat_ids();
				if ( ! $chats ) {
					$result = new WP_Error( 'palladio_tg_no_chats', __( 'Nessuna Chat ID autorizzata: aggiungile e salva prima di inviare le istruzioni.', 'palladio' ) );
				} else {
					$result = null;
					foreach ( $chats as $chat_id ) {
						$result = self::send( $chat_id, self::instructions_text( true ) );
					}
				}
				$done = __( 'Istruzioni inviate alle chat autorizzate.', 'palladio' );
				break;

			default:
				$result = new WP_Error( 'palladio_tg_bad_action', __( 'Azione non valida.', 'palladio' ) );
		}

		if ( is_wp_error( $result ) ) {
			set_transient( 'palladio_tg_msg_' . get_current_user_id(), $result->get_error_message(), 120 );
			wp_safe_redirect( add_query_arg( 'palladio_msg', 'error', $redirect ) );
			exit;
		}

		set_transient( 'palladio_tg_msg_' . get_current_user_id(), isset( $done ) ? $done : '', 120 );
		wp_safe_redirect( add_query_arg( 'palladio_msg', 'ok', $redirect ) );
		exit;
	}
}
