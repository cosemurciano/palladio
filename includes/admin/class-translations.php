<?php
/**
 * Modulo Lingue — editor di traduzione (admin).
 *
 * Metabox sui CPT del plugin: per ogni lingua attiva diversa dalla sorgente
 * consente di inserire titolo, riassunto, contenuto e i campi meta
 * traducibili, con uno stato per lingua (§5.4). Il salvataggio passa dal
 * data layer Palladio_I18n_Translator.
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Metabox e salvataggio delle traduzioni.
 */
class Palladio_Admin_Translations {

	/**
	 * CPT gestiti.
	 *
	 * @var string[]
	 */
	private $post_types = array( 'pll_edificio', 'pll_unita', 'pll_scenario' );

	/**
	 * Registra gli hook admin.
	 *
	 * @return void
	 */
	public function register() {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'add_meta_boxes', array( $this, 'add_metabox' ) );
		add_action( 'save_post', array( $this, 'save' ), 10, 2 );
	}

	/**
	 * Aggiunge il metabox lingue ai CPT.
	 *
	 * @return void
	 */
	public function add_metabox() {
		foreach ( $this->post_types as $pt ) {
			add_meta_box(
				'palladio-i18n',
				__( 'Palladio — Traduzioni', 'palladio' ),
				array( $this, 'render' ),
				$pt,
				'normal',
				'default'
			);
		}
	}

	/**
	 * Renderizza il metabox.
	 *
	 * @param WP_Post $post Post corrente.
	 * @return void
	 */
	public function render( $post ) {
		$source  = Palladio_I18n_Languages::source();
		$catalog = Palladio_I18n_Languages::catalog();
		$targets = array_diff( Palladio_I18n_Languages::active(), array( $source ) );

		if ( empty( $targets ) ) {
			printf(
				'<p>%s</p>',
				esc_html__( 'Nessuna lingua di destinazione attiva. Configura le lingue in Palladio → Lingue.', 'palladio' )
			);
			return;
		}

		wp_nonce_field( 'palladio_i18n_save', 'palladio_i18n_nonce' );

		$statuses    = Palladio_I18n_Translator::statuses();
		$meta_fields = Palladio_I18n_Translator::meta_fields( $post->post_type );

		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %s: lingua sorgente. */
				__( 'Contenuti originali in %s. Le traduzioni marcate come “Pubblicata” vengono servite sul sito con hreflang.', 'palladio' ),
				$catalog[ $source ] ?? $source
			)
		) . '</p>';

		foreach ( $targets as $lang ) {
			$bucket = Palladio_I18n_Translator::get_bucket( $post->ID, $lang );
			$status = Palladio_I18n_Translator::get_status( $post->ID, $lang );
			$label  = $catalog[ $lang ] ?? strtoupper( $lang );
			$prefix = 'palladio_i18n[' . esc_attr( $lang ) . ']';
			?>
			<fieldset style="border:1px solid #dcdcde;border-radius:6px;padding:1rem;margin:1rem 0;">
				<legend style="font-weight:600;padding:0 .5rem;"><?php echo esc_html( $label ); ?></legend>

				<p>
					<label><strong><?php esc_html_e( 'Stato', 'palladio' ); ?></strong></label><br>
					<select name="<?php echo $prefix; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[status]">
						<?php foreach ( $statuses as $slug => $st_label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $status, $slug ); ?>><?php echo esc_html( $st_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>

				<p>
					<label><strong><?php esc_html_e( 'Titolo', 'palladio' ); ?></strong></label><br>
					<input type="text" class="widefat" name="<?php echo $prefix; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[title]" value="<?php echo esc_attr( $bucket['title'] ); ?>">
				</p>

				<p>
					<label><strong><?php esc_html_e( 'Riassunto', 'palladio' ); ?></strong></label><br>
					<textarea class="widefat" rows="2" name="<?php echo $prefix; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[excerpt]"><?php echo esc_textarea( $bucket['excerpt'] ); ?></textarea>
				</p>

				<p>
					<label><strong><?php esc_html_e( 'Contenuto', 'palladio' ); ?></strong></label><br>
					<textarea class="widefat" rows="6" name="<?php echo $prefix; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[content]"><?php echo esc_textarea( $bucket['content'] ); ?></textarea>
				</p>

				<?php foreach ( $meta_fields as $key => $meta_label ) : ?>
					<p>
						<label><strong><?php echo esc_html( $meta_label ); ?></strong></label><br>
						<textarea class="widefat" rows="2" name="<?php echo $prefix; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[meta][<?php echo esc_attr( $key ); ?>]"><?php echo esc_textarea( $bucket['meta'][ $key ] ?? '' ); ?></textarea>
					</p>
				<?php endforeach; ?>
			</fieldset>
			<?php
		}
	}

	/**
	 * Salva le traduzioni.
	 *
	 * @param int     $post_id ID post.
	 * @param WP_Post $post    Post.
	 * @return void
	 */
	public function save( $post_id, $post ) {
		if ( ! in_array( $post->post_type, $this->post_types, true ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if (
			! isset( $_POST['palladio_i18n_nonce'] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['palladio_i18n_nonce'] ) ), 'palladio_i18n_save' )
		) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( empty( $_POST['palladio_i18n'] ) || ! is_array( $_POST['palladio_i18n'] ) ) {
			return;
		}

		$source  = Palladio_I18n_Languages::source();
		$targets = array_diff( Palladio_I18n_Languages::active(), array( $source ) );

		// Sanitizzazione profonda demandata a Palladio_I18n_Translator::save().
		$raw = wp_unslash( $_POST['palladio_i18n'] ); // phpcs:ignore WordPress.Security.ValidationSanitization.InputNotSanitized

		foreach ( $targets as $lang ) {
			if ( empty( $raw[ $lang ] ) || ! is_array( $raw[ $lang ] ) ) {
				continue;
			}

			$entry  = $raw[ $lang ];
			$bucket = array(
				'title'   => $entry['title'] ?? '',
				'excerpt' => $entry['excerpt'] ?? '',
				'content' => $entry['content'] ?? '',
				'meta'    => ( isset( $entry['meta'] ) && is_array( $entry['meta'] ) ) ? $entry['meta'] : array(),
			);
			$status = $entry['status'] ?? 'assente';

			Palladio_I18n_Translator::save( $post_id, $lang, $bucket, $status );
		}
	}
}
