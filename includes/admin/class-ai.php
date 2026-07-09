<?php
/**
 * Modulo AI — interfaccia admin (metabox + AJAX).
 *
 * Pulsanti "Genera scheda", "Applica bozza" e "Genera traduzione" sui CPT.
 * Tutte le operazioni passano da AJAX server-side con nonce e capability;
 * nessuna chiamata AI dal browser.
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Metabox e handler AJAX del modulo AI.
 */
class Palladio_Admin_AI {

	/**
	 * CPT gestiti.
	 *
	 * @var string[]
	 */
	private $post_types = array( 'pll_edificio', 'pll_unita' );

	/**
	 * Registra hook admin e AJAX.
	 *
	 * @return void
	 */
	public function register() {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'add_meta_boxes', array( $this, 'add_metabox' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );

		add_action( 'wp_ajax_palladio_ai_generate', array( $this, 'ajax_generate' ) );
		add_action( 'wp_ajax_palladio_ai_apply', array( $this, 'ajax_apply' ) );
		add_action( 'wp_ajax_palladio_ai_translate', array( $this, 'ajax_translate' ) );
	}

	/**
	 * Aggiunge il metabox AI.
	 *
	 * @return void
	 */
	public function add_metabox() {
		foreach ( $this->post_types as $pt ) {
			add_meta_box(
				'palladio-ai',
				__( 'Palladio — Assistente AI', 'palladio' ),
				array( $this, 'render' ),
				$pt,
				'side',
				'default'
			);
		}
	}

	/**
	 * Accoda lo script sulle schermate di modifica dei CPT.
	 *
	 * @param string $hook Hook pagina.
	 * @return void
	 */
	public function assets( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, $this->post_types, true ) ) {
			return;
		}

		$rel  = 'assets/js/palladio-ai.js';
		$path = PALLADIO_DIR . $rel;
		$ver  = ( is_readable( $path ) && filemtime( $path ) ) ? (string) filemtime( $path ) : PALLADIO_VERSION;

		wp_enqueue_script( 'palladio-ai', PALLADIO_URI . $rel, array(), $ver, true );
		wp_localize_script(
			'palladio-ai',
			'PalladioAI',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'palladio_ai' ),
				'i18n'    => array(
					'working'   => __( 'Operazione in corso…', 'palladio' ),
					'genOk'     => __( 'Bozza generata. Rivedila e applicala.', 'palladio' ),
					'applyOk'   => __( 'Bozza applicata. Ricarico…', 'palladio' ),
					'transOk'   => __( 'Versione tradotta creata come bozza collegata. Rivedila dal riquadro Lingue.', 'palladio' ),
					'error'     => __( 'Errore', 'palladio' ),
				),
			)
		);
	}

	/**
	 * Renderizza il metabox.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public function render( $post ) {
		if ( ! Palladio_AI_Settings::is_ready() ) {
			printf(
				'<p>%s</p><p><a href="%s">%s</a></p>',
				esc_html__( 'Il modulo AI non è configurato.', 'palladio' ),
				esc_url( admin_url( 'edit.php?post_type=pll_edificio&page=palladio-ai' ) ),
				esc_html__( 'Configura la chiave OpenAI', 'palladio' )
			);
			return;
		}

		$draft   = get_post_meta( $post->ID, '_pll_ai_draft', true );
		$targets = array();
		if ( class_exists( 'Palladio_I18n_Languages' ) ) {
			$catalog = Palladio_I18n_Languages::catalog();
			foreach ( array_diff( Palladio_I18n_Languages::active(), array( Palladio_I18n_Languages::source() ) ) as $lang ) {
				$targets[ $lang ] = $catalog[ $lang ] ?? $lang;
			}
		}
		?>
		<div class="palladio-ai-box" data-post="<?php echo esc_attr( $post->ID ); ?>">
			<p>
				<button type="button" class="button button-primary" data-palladio-ai="generate">
					<?php esc_html_e( 'Genera scheda', 'palladio' ); ?>
				</button>
			</p>

			<div class="palladio-ai-draft" <?php echo empty( $draft ) ? 'style="display:none"' : ''; ?>>
				<p class="description"><?php esc_html_e( 'Bozza disponibile.', 'palladio' ); ?></p>
				<p>
					<button type="button" class="button" data-palladio-ai="apply">
						<?php esc_html_e( 'Applica bozza al contenuto', 'palladio' ); ?>
					</button>
				</p>
			</div>

			<?php if ( $targets ) : ?>
				<hr>
				<p><label for="palladio-ai-lang"><strong><?php esc_html_e( 'Traduci in', 'palladio' ); ?></strong></label></p>
				<p>
					<select id="palladio-ai-lang">
						<?php foreach ( $targets as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="button" class="button" data-palladio-ai="translate">
						<?php esc_html_e( 'Genera traduzione', 'palladio' ); ?>
					</button>
				</p>
			<?php endif; ?>

			<p class="palladio-ai-status" role="status" aria-live="polite"></p>
		</div>
		<?php
	}

	/**
	 * Verifica nonce + capability e restituisce il post id valido.
	 *
	 * @return int
	 */
	private function guard() {
		check_ajax_referer( 'palladio_ai', 'nonce' );

		$post_id = isset( $_POST['post'] ) ? absint( wp_unslash( $_POST['post'] ) ) : 0;

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Permesso negato.', 'palladio' ) ), 403 );
		}

		if ( ! Palladio_AI_Settings::is_ready() ) {
			wp_send_json_error( array( 'message' => __( 'Modulo AI non configurato.', 'palladio' ) ), 400 );
		}

		return $post_id;
	}

	/**
	 * AJAX: genera la bozza di scheda.
	 *
	 * @return void
	 */
	public function ajax_generate() {
		$post_id = $this->guard();

		$result = Palladio_AI_Composer::generate_scheda( $post_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'draft' => $result ) );
	}

	/**
	 * AJAX: applica la bozza al post.
	 *
	 * @return void
	 */
	public function ajax_apply() {
		$post_id = $this->guard();

		$result = Palladio_AI_Composer::apply_draft( $post_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success();
	}

	/**
	 * AJAX: genera una traduzione.
	 *
	 * @return void
	 */
	public function ajax_translate() {
		$post_id = $this->guard();

		$lang = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '';

		$result = Palladio_AI_Composer::translate( $post_id, $lang );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success();
	}
}
