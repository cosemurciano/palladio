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
	}

	/**
	 * Configurazione con default.
	 *
	 * @return array{enabled:bool,model:string,translate_model:string}
	 */
	public static function config() {
		$defaults = array(
			'enabled'         => false,
			'model'           => 'gpt-4.1-mini',
			'translate_model' => 'gpt-4.1-mini',
		);

		$config = get_option( 'palladio_ai', array() );
		$config = wp_parse_args( is_array( $config ) ? $config : array(), $defaults );

		$config['enabled'] = (bool) $config['enabled'];

		return $config;
	}

	/**
	 * Restituisce la chiave API decifrata.
	 *
	 * @return string
	 */
	public static function api_key() {
		return Palladio_AI_Crypto::decrypt( get_option( 'palladio_ai_key', '' ) );
	}

	/**
	 * Indica se il modulo AI è operativo (abilitato + chiave presente).
	 *
	 * @return bool
	 */
	public static function is_ready() {
		$config = self::config();
		return $config['enabled'] && '' !== self::api_key();
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
								<input type="checkbox" name="enabled" value="1" <?php checked( $config['enabled'] ); ?>>
								<?php esc_html_e( 'Attiva la generazione contenuti e le traduzioni via OpenAI.', 'palladio' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pll-ai-key"><?php esc_html_e( 'Chiave API OpenAI', 'palladio' ); ?></label></th>
						<td>
							<input type="password" id="pll-ai-key" name="api_key" class="regular-text" autocomplete="off"
								placeholder="<?php echo $has_key ? esc_attr__( '•••••••• (impostata — lascia vuoto per non cambiarla)', 'palladio' ) : 'sk-…'; ?>">
							<p class="description"><?php esc_html_e( 'Salvata cifrata nel database e usata solo lato server.', 'palladio' ); ?></p>
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
		<?php
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

		$config = array(
			'enabled'         => ! empty( $_POST['enabled'] ),
			'model'           => isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : 'gpt-4.1-mini',
			'translate_model' => isset( $_POST['translate_model'] ) ? sanitize_text_field( wp_unslash( $_POST['translate_model'] ) ) : 'gpt-4.1-mini',
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

		// Aggiorna la chiave solo se fornita (non ristampiamo mai quella salvata).
		if ( isset( $_POST['api_key'] ) && '' !== trim( (string) wp_unslash( $_POST['api_key'] ) ) ) {
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
