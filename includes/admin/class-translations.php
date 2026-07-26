<?php
/**
 * Modulo Lingue — pannello traduzioni (admin).
 *
 * Modello a pagine clone (§5.4): il metabox mostra la lingua del post e, per
 * ogni lingua attiva, un link alla versione esistente o un pulsante che la
 * clona in una nuova pagina dedicata. Al salvataggio i dati strutturati
 * vengono sincronizzati sui cloni collegati.
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pannello lingue e clonazione.
 */
class Palladio_Admin_Translations {

	/**
	 * CPT gestiti.
	 *
	 * @var string[]
	 */
	private $post_types = array( 'pll_edificio', 'pll_unita', 'pll_scenario', 'pll_storia', 'pll_territorio' );

	/**
	 * Registra hook admin.
	 *
	 * @return void
	 */
	public function register() {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'add_meta_boxes', array( $this, 'add_metabox' ) );
		add_action( 'admin_post_palladio_create_translation', array( $this, 'create_translation' ) );
		add_action( 'admin_post_palladio_ai_translate', array( $this, 'ai_translate' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_action( 'save_post', array( $this, 'on_save' ), 30, 2 );
	}

	/**
	 * Aggiunge il metabox lingue.
	 *
	 * @return void
	 */
	public function add_metabox() {
		foreach ( $this->post_types as $pt ) {
			add_meta_box(
				'palladio-i18n',
				__( 'Palladio — Lingue', 'palladio' ),
				array( $this, 'render' ),
				$pt,
				'side',
				'default'
			);
		}
	}

	/**
	 * Renderizza il pannello.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public function render( $post ) {
		$catalog  = Palladio_I18n_Languages::catalog();
		$active   = Palladio_I18n_Languages::active();
		$source   = Palladio_I18n_Languages::source();
		$own_lang = Palladio_I18n_Translator::get_lang( $post->ID );
		$siblings = Palladio_I18n_Translator::siblings( $post->ID, array( 'publish', 'draft', 'pending', 'future', 'private' ) );

		echo '<p><strong>' . esc_html__( 'Lingua di questa pagina:', 'palladio' ) . '</strong> '
			. esc_html( $catalog[ $own_lang ] ?? $own_lang );
		if ( $own_lang === $source ) {
			echo ' <span class="description">(' . esc_html__( 'originale', 'palladio' ) . ')</span>';
		}
		echo '</p>';

		echo '<ul style="margin:0;">';
		foreach ( $active as $lang ) {
			if ( $lang === $own_lang ) {
				continue;
			}
			$label = $catalog[ $lang ] ?? strtoupper( $lang );
			echo '<li style="margin:.35rem 0;display:flex;align-items:center;gap:.5rem;">';
			echo '<span style="min-width:5.5rem;">' . esc_html( $label ) . '</span>';

			if ( ! empty( $siblings[ $lang ] ) ) {
				printf(
					'<a class="button button-small" href="%s">%s</a>',
					esc_url( get_edit_post_link( $siblings[ $lang ] ) ),
					esc_html__( 'Modifica', 'palladio' )
				);
			} else {
				printf(
					'<a class="button button-small button-primary" href="%s">%s</a>',
					esc_url( $this->create_url( $post->ID, $lang ) ),
					esc_html__( 'Crea versione', 'palladio' )
				);
			}
			echo '</li>';
		}
		echo '</ul>';

		echo '<p class="description">' . esc_html__( 'Ogni lingua è una pagina dedicata. Prezzi, stato e misure restano sincronizzati; testi e immagini sono per lingua.', 'palladio' ) . '</p>';

		// Su una versione in lingua: traduzione AI dall'originale.
		if ( $own_lang !== $source && ! empty( $siblings[ $source ] ) ) {
			$label = $catalog[ $own_lang ] ?? strtoupper( $own_lang );
			echo '<hr><p style="margin:.5rem 0;">';
			printf(
				'<a class="button button-primary" style="width:100%%;text-align:center;" href="%s" onclick="this.textContent=%s;this.style.pointerEvents=%s;">%s</a>',
				esc_url(
					wp_nonce_url(
						add_query_arg(
							array(
								'action' => 'palladio_ai_translate',
								'post'   => (int) $post->ID,
							),
							admin_url( 'admin-post.php' )
						),
						'palladio_ai_translate_' . (int) $post->ID
					)
				),
				"'" . esc_js( __( 'Traduzione in corso…', 'palladio' ) ) . "'",
				"'none'",
				/* translators: %s: lingua di destinazione. */
				esc_html( sprintf( __( 'Traduci i contenuti in %s (AI)', 'palladio' ), $label ) )
			);
			echo '</p><p class="description">' . esc_html__( 'Traduce titolo, testi e campi editoriali dall’originale con qualità editoriale (non letterale), senza toccare prezzi, misure, numeri, indirizzi e immagini. Sovrascrive i testi di questa versione.', 'palladio' ) . '</p>';
		}
	}

	/**
	 * Notice esito traduzione AI.
	 *
	 * @return void
	 */
	public function notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['palladio_i18n'] ) ) {
			return;
		}
		$status = sanitize_key( wp_unslash( $_GET['palladio_i18n'] ) );
		// phpcs:enable

		if ( 'translated' === $status ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Traduzione AI completata: rileggi i testi prima di pubblicare.', 'palladio' ) . '</p></div>';
		} elseif ( 'error' === $status ) {
			$msg = get_transient( 'palladio_i18n_error_' . get_current_user_id() );
			delete_transient( 'palladio_i18n_error_' . get_current_user_id() );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $msg ? $msg : __( 'Traduzione non riuscita.', 'palladio' ) ) . '</p></div>';
		}
	}

	/**
	 * Handler: traduce i contenuti del post corrente dall'originale (AI).
	 *
	 * @return void
	 */
	public function ai_translate() {
		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
		check_admin_referer( 'palladio_ai_translate_' . $post_id );

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Permesso negato.', 'palladio' ) );
		}

		$result = Palladio_I18n_Machine::translate_post( $post_id );

		if ( is_wp_error( $result ) ) {
			set_transient( 'palladio_i18n_error_' . get_current_user_id(), $result->get_error_message(), 120 );
			$status = 'error';
		} else {
			$status = 'translated';
		}

		wp_safe_redirect( add_query_arg( 'palladio_i18n', $status, get_edit_post_link( $post_id, 'redirect' ) ) );
		exit;
	}

	/**
	 * URL nonce per creare una traduzione.
	 *
	 * @param int    $post_id ID sorgente.
	 * @param string $lang    Lingua.
	 * @return string
	 */
	private function create_url( $post_id, $lang ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'palladio_create_translation',
					'source' => (int) $post_id,
					'lang'   => sanitize_key( $lang ),
				),
				admin_url( 'admin-post.php' )
			),
			'palladio_create_translation_' . (int) $post_id
		);
	}

	/**
	 * Handler: crea la pagina clone e apre l'editor.
	 *
	 * @return void
	 */
	public function create_translation() {
		$source = isset( $_GET['source'] ) ? absint( wp_unslash( $_GET['source'] ) ) : 0;
		$lang   = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : '';

		check_admin_referer( 'palladio_create_translation_' . $source );

		if ( ! $source || ! current_user_can( 'edit_post', $source ) ) {
			wp_die( esc_html__( 'Permesso negato.', 'palladio' ) );
		}

		$new_id = Palladio_I18n_Translator::clone_post( $source, $lang );
		if ( is_wp_error( $new_id ) ) {
			wp_die( esc_html( $new_id->get_error_message() ) );
		}

		wp_safe_redirect( get_edit_post_link( $new_id, 'redirect' ) );
		exit;
	}

	/**
	 * Al salvataggio: assicura lingua/gruppo e sincronizza i dati strutturati.
	 *
	 * @param int     $post_id ID post.
	 * @param WP_Post $post    Post.
	 * @return void
	 */
	public function on_save( $post_id, $post ) {
		if ( ! in_array( $post->post_type, $this->post_types, true ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Un post senza lingua è l'originale (lingua sorgente).
		if ( '' === (string) get_post_meta( $post_id, Palladio_I18n_Translator::LANG_META, true ) ) {
			Palladio_I18n_Translator::set_lang( $post_id, Palladio_I18n_Languages::source() );
		}

		Palladio_I18n_Translator::sync_shared( $post_id );
	}
}
