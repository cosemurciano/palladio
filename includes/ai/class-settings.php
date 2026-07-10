<?php
/**
 * Modulo AI — impostazioni e pagina admin.
 *
 * Gestisce chiave API (cifrata), modelli, abilitazione e riepilogo
 * uso/costi. La chiave non viene mai ristampata in chiaro nel form.
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Impostazioni del modulo AI.
 */
class Palladio_AI_Settings {

	/**
	 * Capability richiesta.
	 */
	const CAP = 'manage_palladio';

	/**
	 * Registra menu e handler.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_palladio_save_ai', array( $this, 'save' ) );
		add_action( 'wp_ajax_palladio_ai_diag_storage', array( $this, 'ajax_diag_storage' ) );
	}

	/**
	 * Configurazione con default.
	 *
	 * @return array{enabled:bool,model:string,translate_model:string}
	 */
	public static function config() {
		$defaults = array(
			'enabled'          => false,
			'model'            => 'gpt-4.1-mini',
			'translate_model'  => 'gpt-4.1-mini',
			'vector_store'     => '',
			'tokens_generate'  => 3000,
			'tokens_translate' => 2000,
			'tokens_agent'     => 4000,
			'http_timeout'     => 180,
		);

		$config = get_option( 'palladio_ai', array() );
		$config = wp_parse_args( is_array( $config ) ? $config : array(), $defaults );

		$config['enabled'] = (bool) $config['enabled'];

		return $config;
	}

	/**
	 * Vector store OpenAI (Storage) configurato per il File Search.
	 *
	 * @return string
	 */
	public static function vector_store() {
		return (string) self::config()['vector_store'];
	}

	/**
	 * Limite token di output per contesto d'uso.
	 *
	 * @param string $context 'generate' | 'translate' | 'agent'.
	 * @return int
	 */
	public static function max_tokens( $context ) {
		$config = self::config();
		$map    = array(
			'generate'  => 'tokens_generate',
			'translate' => 'tokens_translate',
			'agent'     => 'tokens_agent',
		);
		$key   = $map[ $context ] ?? 'tokens_agent';
		$value = isset( $config[ $key ] ) ? absint( $config[ $key ] ) : 0;

		// Clamp difensivo: 100–32000.
		return max( 100, min( 32000, $value ? $value : 1500 ) );
	}

	/**
	 * Timeout (secondi) delle richieste HTTP verso OpenAI.
	 *
	 * @return int
	 */
	public static function http_timeout() {
		$value = absint( self::config()['http_timeout'] ?? 0 );
		return max( 30, min( 600, $value ? $value : 180 ) );
	}

	/**
	 * Restituisce la chiave API decifrata.
	 *
	 * @return string
	 */
	public static function api_key() {
		// Priorità a wp-config.php: define( 'PALLADIO_OPENAI_API_KEY', 'sk-...' );
		if ( defined( 'PALLADIO_OPENAI_API_KEY' ) && '' !== (string) PALLADIO_OPENAI_API_KEY ) {
			return (string) PALLADIO_OPENAI_API_KEY;
		}
		return Palladio_AI_Crypto::decrypt( get_option( 'palladio_ai_key', '' ) );
	}

	/**
	 * Indica se la chiave è definita in wp-config.php (non modificabile da UI).
	 *
	 * @return bool
	 */
	public static function key_is_constant() {
		return defined( 'PALLADIO_OPENAI_API_KEY' ) && '' !== (string) PALLADIO_OPENAI_API_KEY;
	}

	/**
	 * Indica se il modulo AI è operativo (abilitato + chiave presente).
	 *
	 * @return bool
	 */
	public static function is_ready() {
		if ( '' === self::api_key() ) {
			return false;
		}
		// La chiave definita in wp-config.php è un opt-in esplicito: attiva l'AI
		// anche senza spuntare "Abilita AI" (altrimenti la chiave non risulta
		// rilevata pur essendo presente nel file di configurazione).
		if ( self::key_is_constant() ) {
			return true;
		}
		return ! empty( self::config()['enabled'] );
	}

	/**
	 * Modello per le generazioni.
	 *
	 * @return string
	 */
	public static function model() {
		return self::config()['model'];
	}

	/**
	 * Modello per le traduzioni.
	 *
	 * @return string
	 */
	public static function translate_model() {
		return self::config()['translate_model'];
	}

	/**
	 * Aggiunge la pagina "AI" al menu Palladio.
	 *
	 * @return void
	 */
	public function menu() {
		add_submenu_page(
			'edit.php?post_type=pll_edificio',
			__( 'AI', 'palladio' ),
			__( 'AI', 'palladio' ),
			self::CAP,
			'palladio-ai',
			array( $this, 'page' )
		);
	}

	/**
	 * Renderizza la pagina impostazioni AI.
	 *
	 * @return void
	 */
	public function page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permesso negato.', 'palladio' ) );
		}

		$config   = self::config();
		$has_key  = '' !== self::api_key();
		$usage    = get_option( 'palladio_ai_usage', array() );
		$saved    = isset( $_GET['palladio_msg'] ) ? sanitize_key( wp_unslash( $_GET['palladio_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tokens   = isset( $usage['total_tokens'] ) ? (int) $usage['total_tokens'] : 0;
		$cost     = isset( $usage['estimated_cost'] ) ? (float) $usage['estimated_cost'] : 0.0;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Palladio — AI', 'palladio' ); ?></h1>

			<?php if ( 'saved' === $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Impostazioni AI salvate.', 'palladio' ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! Palladio_AI_Crypto::has_sodium() ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'libsodium non disponibile: la chiave verrà salvata solo offuscata. Aggiorna PHP per la cifratura reale.', 'palladio' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="palladio_save_ai">
				<?php wp_nonce_field( 'palladio_ai_settings' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Abilita AI', 'palladio' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="enabled" value="1" <?php checked( $config['enabled'] || self::key_is_constant() ); ?> <?php disabled( self::key_is_constant() ); ?>>
								<?php esc_html_e( 'Attiva la generazione contenuti e le traduzioni via OpenAI.', 'palladio' ); ?>
							</label>
							<?php if ( self::key_is_constant() ) : ?>
								<p class="description"><?php esc_html_e( 'Chiave presente in wp-config.php: l’AI è attiva automaticamente.', 'palladio' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pll-ai-key"><?php esc_html_e( 'Chiave API OpenAI', 'palladio' ); ?></label></th>
						<td>
							<input type="password" id="pll-ai-key" name="api_key" class="regular-text" autocomplete="off"
								placeholder="<?php echo $has_key ? esc_attr__( '•••••••• (impostata — lascia vuoto per non cambiarla)', 'palladio' ) : 'sk-…'; ?>">
							<?php if ( self::key_is_constant() ) : ?>
								<p class="description"><strong><?php esc_html_e( 'Definita in wp-config.php', 'palladio' ); ?></strong> (<code>PALLADIO_OPENAI_API_KEY</code>) — <?php esc_html_e( 'questo campo viene ignorato.', 'palladio' ); ?></p>
							<?php else : ?>
								<p class="description">
									<?php esc_html_e( 'Salvata cifrata nel database e usata solo lato server. Per maggiore sicurezza puoi definirla in wp-config.php:', 'palladio' ); ?>
									<code>define( 'PALLADIO_OPENAI_API_KEY', 'sk-...' );</code>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pll-ai-model"><?php esc_html_e( 'Modello (generazione)', 'palladio' ); ?></label></th>
						<td><input type="text" id="pll-ai-model" name="model" class="regular-text" value="<?php echo esc_attr( $config['model'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="pll-ai-tmodel"><?php esc_html_e( 'Modello (traduzione)', 'palladio' ); ?></label></th>
						<td><input type="text" id="pll-ai-tmodel" name="translate_model" class="regular-text" value="<?php echo esc_attr( $config['translate_model'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="pll-ai-vs"><?php esc_html_e( 'Vector Store (OpenAI Storage)', 'palladio' ); ?></label></th>
						<td>
							<input type="text" id="pll-ai-vs" name="vector_store" class="regular-text" value="<?php echo esc_attr( $config['vector_store'] ); ?>" placeholder="vs_...">
							<p class="description"><?php esc_html_e( 'ID del vector store su platform.openai.com/storage usato dal File Search per popolare le pagine con i documenti del progetto (dossier, planimetrie, atti).', 'palladio' ); ?></p>
							<p>
								<button type="button" class="button" id="pll-vs-test"><?php esc_html_e( 'Verifica Storage', 'palladio' ); ?></button>
								<span class="description"><?php esc_html_e( 'Controlla che l’ID sia valido, quanti documenti sono indicizzati e prova una ricerca. Salva prima l’ID se lo hai appena inserito.', 'palladio' ); ?></span>
							</p>
							<div id="pll-vs-result" style="display:none;margin-top:8px;padding:10px 12px;border:1px solid #c3c4c7;border-radius:4px;background:#fff;"></div>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pll-ai-tok-gen"><?php esc_html_e( 'Token massimi — generazione contenuti', 'palladio' ); ?></label></th>
						<td>
							<input type="number" id="pll-ai-tok-gen" name="tokens_generate" min="100" max="32000" step="100" value="<?php echo esc_attr( (int) $config['tokens_generate'] ); ?>">
							<p class="description"><?php esc_html_e( 'Lunghezza massima della risposta per la generazione schede e la costruzione da Storage + Media.', 'palladio' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pll-ai-tok-tr"><?php esc_html_e( 'Token massimi — traduzione', 'palladio' ); ?></label></th>
						<td><input type="number" id="pll-ai-tok-tr" name="tokens_translate" min="100" max="32000" step="100" value="<?php echo esc_attr( (int) $config['tokens_translate'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="pll-ai-tok-ag"><?php esc_html_e( 'Token massimi — agente (chat)', 'palladio' ); ?></label></th>
						<td>
							<input type="number" id="pll-ai-tok-ag" name="tokens_agent" min="100" max="32000" step="100" value="<?php echo esc_attr( (int) $config['tokens_agent'] ); ?>">
							<p class="description"><?php esc_html_e( 'Vale per l’Agente AI in amministrazione e per il widget di chat sul sito.', 'palladio' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pll-ai-timeout"><?php esc_html_e( 'Timeout richiesta API (secondi)', 'palladio' ); ?></label></th>
						<td>
							<input type="number" id="pll-ai-timeout" name="http_timeout" min="30" max="600" step="10" value="<?php echo esc_attr( (int) $config['http_timeout'] ); ?>">
							<p class="description"><?php esc_html_e( 'Tempo massimo di attesa per ogni chiamata a OpenAI. I modelli reasoning con molti token possono richiedere 120–300 secondi. Verifica che anche max_execution_time del PHP lo consenta.', 'palladio' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Agent conversazionale', 'palladio' ); ?></h2>
				<?php $agent = class_exists( 'Palladio_Agent_Rest' ) ? Palladio_Agent_Rest::config() : array( 'enabled' => false, 'top_k' => 5, 'rate_limit' => 20, 'disclaimer' => '', 'system' => '' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Abilita agent', 'palladio' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="agent_enabled" value="1" <?php checked( ! empty( $agent['enabled'] ) ); ?>>
								<?php esc_html_e( 'Mostra il widget di chat sulle schede del progetto.', 'palladio' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pll-agent-topk"><?php esc_html_e( 'Chunk di contesto (top-k)', 'palladio' ); ?></label></th>
						<td><input type="number" id="pll-agent-topk" name="agent_top_k" min="1" max="10" value="<?php echo esc_attr( (int) $agent['top_k'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="pll-agent-rl"><?php esc_html_e( 'Limite messaggi / 10 min (per IP)', 'palladio' ); ?></label></th>
						<td><input type="number" id="pll-agent-rl" name="agent_rate_limit" min="1" max="200" value="<?php echo esc_attr( (int) $agent['rate_limit'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="pll-agent-disc"><?php esc_html_e( 'Avviso AI (trasparenza)', 'palladio' ); ?></label></th>
						<td><input type="text" id="pll-agent-disc" name="agent_disclaimer" class="large-text" value="<?php echo esc_attr( $agent['disclaimer'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="pll-agent-sys"><?php esc_html_e( 'Istruzioni (system prompt)', 'palladio' ); ?></label></th>
						<td><textarea id="pll-agent-sys" name="agent_system" class="large-text" rows="5"><?php echo esc_textarea( $agent['system'] ); ?></textarea></td>
					</tr>
				</table>

				<?php submit_button( __( 'Salva impostazioni AI', 'palladio' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Uso stimato', 'palladio' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: 1: numero token, 2: costo stimato. */
					esc_html__( 'Token totali: %1$s — Costo stimato: € %2$s', 'palladio' ),
					esc_html( number_format_i18n( $tokens ) ),
					esc_html( number_format_i18n( $cost, 4 ) )
				);
				?>
			</p>
		</div>
		<script>
		( function () {
			var btn = document.getElementById( 'pll-vs-test' );
			var box = document.getElementById( 'pll-vs-result' );
			if ( ! btn || ! box ) { return; }
			var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var nonce   = <?php echo wp_json_encode( wp_create_nonce( 'palladio_ai_diag' ) ); ?>;
			var esc = function ( s ) {
				return String( s == null ? '' : s ).replace( /[&<>]/g, function ( c ) {
					return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[ c ];
				} );
			};
			btn.addEventListener( 'click', function () {
				btn.disabled = true;
				box.style.display = 'block';
				box.innerHTML = '<?php echo esc_js( __( 'Verifica in corso… (può richiedere qualche secondo)', 'palladio' ) ); ?>';
				var body = new URLSearchParams();
				body.append( 'action', 'palladio_ai_diag_storage' );
				body.append( 'nonce', nonce );
				fetch( ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: body.toString()
				} ).then( function ( r ) {
					return r.text().then( function ( raw ) {
						var d = null;
						try { d = JSON.parse( raw ); } catch ( e ) {}
						return { status: r.status, d: d, raw: raw };
					} );
				} ).then( function ( res ) {
					btn.disabled = false;
					var d = res.d;
					if ( ! d ) {
						box.innerHTML = '<strong style="color:#b32d2e"><?php echo esc_js( __( 'Risposta non valida dal server', 'palladio' ) ); ?>:</strong> ' + esc( ( res.raw || '' ).slice( 0, 200 ) );
						return;
					}
					if ( ! d.success ) {
						box.innerHTML = '<strong style="color:#b32d2e">&#10007;</strong> ' + esc( d.data && d.data.message ? d.data.message : 'Errore' );
						return;
					}
					var x = d.data;
					var h = '';
					h += '<p style="margin:0 0 6px"><strong><?php echo esc_js( __( 'Vector store', 'palladio' ) ); ?>:</strong> <code>' + esc( x.vector_store ) + '</code>' + ( x.name ? ' &mdash; ' + esc( x.name ) : '' ) + ' <em>(' + esc( x.status ) + ')</em></p>';
					h += '<p style="margin:0 0 6px"><strong><?php echo esc_js( __( 'Documenti', 'palladio' ) ); ?>:</strong> ' + x.files_ready + ' / ' + x.files_total + ' <?php echo esc_js( __( 'indicizzati', 'palladio' ) ); ?>';
					if ( x.files_progress ) { h += ' &middot; ' + x.files_progress + ' <?php echo esc_js( __( 'in elaborazione', 'palladio' ) ); ?>'; }
					if ( x.files_failed ) { h += ' &middot; <span style="color:#b32d2e">' + x.files_failed + ' <?php echo esc_js( __( 'falliti', 'palladio' ) ); ?></span>'; }
					h += '</p>';
					if ( x.search_ok ) {
						h += '<p style="margin:8px 0 4px"><strong style="color:#008a20">&#10003; <?php echo esc_js( __( 'File Search operativo — risposta di prova', 'palladio' ) ); ?>:</strong></p>';
						h += '<div style="white-space:pre-wrap;max-height:220px;overflow:auto;font-size:12px;background:#f6f7f7;padding:8px;border-radius:3px">' + esc( x.search_reply ) + '</div>';
					} else {
						h += '<p style="margin:8px 0 0"><strong style="color:#b32d2e">&#10007; <?php echo esc_js( __( 'La ricerca non ha prodotto testo', 'palladio' ) ); ?>.</strong> ' + esc( x.search_error || '<?php echo esc_js( __( 'Se i documenti risultano 0/0 o “in elaborazione”, attendi il completamento dell’indicizzazione su OpenAI.', 'palladio' ) ); ?>' ) + '</p>';
					}
					box.innerHTML = h;
				} ).catch( function ( err ) {
					btn.disabled = false;
					box.innerHTML = '<strong style="color:#b32d2e">&#10007;</strong> ' + esc( err && err.message ? err.message : 'rete' );
				} );
			} );
		}() );
		</script>
		<?php
	}

	/**
	 * Diagnostica Storage (AJAX): verifica raggiungibilità del vector store,
	 * conteggio documenti indicizzati e una ricerca di prova con File Search.
	 *
	 * @return void
	 */
	public function ajax_diag_storage() {
		check_ajax_referer( 'palladio_ai_diag', 'nonce' );

		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permesso negato.', 'palladio' ) ), 403 );
		}
		if ( ! self::is_ready() ) {
			wp_send_json_error( array( 'message' => __( 'Configura la chiave OpenAI prima di verificare lo Storage.', 'palladio' ) ), 400 );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 90 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$vs = self::vector_store();
		if ( '' === $vs ) {
			wp_send_json_error( array( 'message' => __( 'Nessun Vector Store configurato: incolla l’ID (vs_…) nel campo qui sopra e salva.', 'palladio' ) ), 400 );
		}

		$out = array( 'vector_store' => $vs );

		// 1) Stato del vector store (esiste? quanti file indicizzati?).
		$info = Palladio_AI_Openai::vector_store_info( $vs );
		if ( is_wp_error( $info ) ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: 1: id vector store, 2: messaggio errore. */
						__( 'Vector store “%1$s” non raggiungibile: %2$s — controlla che l’ID sia corretto e che la chiave API appartenga allo stesso account/organizzazione OpenAI dello Storage.', 'palladio' ),
						$vs,
						$info->get_error_message()
					),
				)
			);
		}

		$counts = isset( $info['file_counts'] ) && is_array( $info['file_counts'] ) ? $info['file_counts'] : array();
		$out['name']        = (string) ( $info['name'] ?? '' );
		$out['status']      = (string) ( $info['status'] ?? '' );
		$out['files_total'] = (int) ( $counts['total'] ?? 0 );
		$out['files_ready'] = (int) ( $counts['completed'] ?? 0 );
		$out['files_progress'] = (int) ( $counts['in_progress'] ?? 0 );
		$out['files_failed']   = (int) ( $counts['failed'] ?? 0 );

		// 2) Ricerca di prova con File Search.
		$query = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';
		if ( '' === $query ) {
			$query = __( 'Elenca i documenti disponibili e riassumi i dati principali dell’immobile (indirizzo, superfici, prezzi, unità).', 'palladio' );
		}

		$res = Palladio_AI_Openai::responses(
			__( 'Usa esclusivamente i documenti forniti tramite File Search. Elenca da quali file provengono le informazioni e cita valori esatti.', 'palladio' ),
			$query,
			array(
				'vector_store_ids' => array( $vs ),
				'max_tokens'       => min( 1500, self::max_tokens( 'agent' ) ),
				'timeout'          => 45,
			)
		);

		if ( is_wp_error( $res ) ) {
			$out['search_ok']    = false;
			$out['search_error'] = $res->get_error_message();
		} else {
			$text                = trim( (string) $res['text'] );
			$out['search_ok']    = ( '' !== $text );
			$out['search_reply'] = $text;
		}

		wp_send_json_success( $out );
	}

	/**
	 * Salva le impostazioni AI.
	 *
	 * @return void
	 */
	public function save() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permesso negato.', 'palladio' ) );
		}

		check_admin_referer( 'palladio_ai_settings' );

		// Con la chiave da wp-config il checkbox è disabilitato (non inviato):
		// preserva il valore salvato invece di azzerarlo.
		$enabled = self::key_is_constant() ? ! empty( self::config()['enabled'] ) : ! empty( $_POST['enabled'] );

		$tok = static function ( $key, $default ) {
			$v = isset( $_POST[ $key ] ) ? absint( wp_unslash( $_POST[ $key ] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return max( 100, min( 32000, $v ? $v : $default ) );
		};

		$config = array(
			'enabled'          => $enabled,
			'model'            => isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : 'gpt-4.1-mini',
			'translate_model'  => isset( $_POST['translate_model'] ) ? sanitize_text_field( wp_unslash( $_POST['translate_model'] ) ) : 'gpt-4.1-mini',
			'vector_store'     => isset( $_POST['vector_store'] ) ? sanitize_text_field( wp_unslash( $_POST['vector_store'] ) ) : '',
			'tokens_generate'  => $tok( 'tokens_generate', 3000 ),
			'tokens_translate' => $tok( 'tokens_translate', 2000 ),
			'tokens_agent'     => $tok( 'tokens_agent', 4000 ),
			'http_timeout'     => isset( $_POST['http_timeout'] ) ? max( 30, min( 600, absint( wp_unslash( $_POST['http_timeout'] ) ) ) ) : 180,
		);

		update_option( 'palladio_ai', $config );

		// Impostazioni agent.
		$agent = array(
			'enabled'    => ! empty( $_POST['agent_enabled'] ),
			'top_k'      => isset( $_POST['agent_top_k'] ) ? max( 1, min( 10, absint( wp_unslash( $_POST['agent_top_k'] ) ) ) ) : 5,
			'rate_limit' => isset( $_POST['agent_rate_limit'] ) ? max( 1, min( 200, absint( wp_unslash( $_POST['agent_rate_limit'] ) ) ) ) : 20,
			'disclaimer' => isset( $_POST['agent_disclaimer'] ) ? sanitize_text_field( wp_unslash( $_POST['agent_disclaimer'] ) ) : '',
			'system'     => isset( $_POST['agent_system'] ) ? sanitize_textarea_field( wp_unslash( $_POST['agent_system'] ) ) : '',
		);
		update_option( 'palladio_agent', $agent );

		// Aggiorna la chiave solo se fornita e se non è gestita da wp-config.php.
		if ( ! self::key_is_constant() && isset( $_POST['api_key'] ) && '' !== trim( (string) wp_unslash( $_POST['api_key'] ) ) ) {
			$key = sanitize_text_field( wp_unslash( $_POST['api_key'] ) );
			update_option( 'palladio_ai_key', Palladio_AI_Crypto::encrypt( $key ), false );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type'    => 'pll_edificio',
					'page'         => 'palladio-ai',
					'palladio_msg' => 'saved',
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}
}
