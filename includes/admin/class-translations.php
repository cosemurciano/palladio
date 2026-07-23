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
	private $post_types = array( 'pll_edificio', 'pll_unita', 'pll_scenario', 'pll_storia' );

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
