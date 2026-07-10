<?php
/**
 * Modulo Presenter — dati principali (admin).
 *
 * Metabox con i campi commerciali e fisici del modello dati (§3): finora i
 * meta core erano registrati (register_post_meta) ma privi di UI dedicata.
 * Qui si popolano con campi tipizzati, non testo libero. Include il flag
 * "usa come homepage" per l'edificio.
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Editor dei dati principali.
 */
class Palladio_Admin_Fields {

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
		add_action( 'save_post_pll_edificio', array( $this, 'save' ), 10, 2 );
		add_action( 'save_post_pll_unita', array( $this, 'save' ), 10, 2 );
	}

	/**
	 * Definizione campi per tipo di post.
	 *
	 * Pubblica e statica: è la fonte unica dello schema campi, riusata
	 * dall'agente Studio per conoscere tutti i campi disponibili.
	 *
	 * @param string $post_type CPT.
	 * @return array<string,array{label:string,type:string,help?:string}>
	 */
	public static function fields( $post_type ) {
		if ( 'pll_edificio' === $post_type ) {
			return array(
				'claim'             => array( 'label' => __( 'Claim (titolo grande)', 'palladio' ), 'type' => 'text' ),
				'sottotitolo'       => array( 'label' => __( 'Sottotitolo / secolo', 'palladio' ), 'type' => 'text', 'help' => __( 'Es. “Dimora nobiliare del XVI secolo”.', 'palladio' ) ),
				'indirizzo'         => array( 'label' => __( 'Indirizzo', 'palladio' ), 'type' => 'text' ),
				'anno_costruzione'  => array( 'label' => __( 'Anno di costruzione', 'palladio' ), 'type' => 'int' ),
				'mq_totali'         => array( 'label' => __( 'Superficie totale (m²)', 'palladio' ), 'type' => 'number' ),
				'num_piani'         => array( 'label' => __( 'Numero di piani', 'palladio' ), 'type' => 'int' ),
				'num_unita_vendita' => array( 'label' => __( 'Unità in vendita', 'palladio' ), 'type' => 'int' ),
				'vincoli_note'      => array( 'label' => __( 'Vincoli e note legali', 'palladio' ), 'type' => 'textarea' ),
				'contatto_agenzia'  => array( 'label' => __( 'Agenzia / contatto', 'palladio' ), 'type' => 'text' ),
				'contatto_email'    => array( 'label' => __( 'Email di contatto', 'palladio' ), 'type' => 'email' ),
				'contatto_tel'      => array( 'label' => __( 'Telefono', 'palladio' ), 'type' => 'text' ),
				'geo_lat'           => array( 'label' => __( 'Latitudine', 'palladio' ), 'type' => 'number' ),
				'geo_lng'           => array( 'label' => __( 'Longitudine', 'palladio' ), 'type' => 'number' ),
			);
		}

		return array(
			'codice'             => array( 'label' => __( 'Codice / etichetta', 'palladio' ), 'type' => 'text', 'help' => __( 'Es. “app. 1 + 2 + 3” oppure “unità 6 + 14”.', 'palladio' ) ),
			'prezzo'             => array( 'label' => __( 'Prezzo (EUR)', 'palladio' ), 'type' => 'number' ),
			'prezzo_trattabile'  => array( 'label' => __( 'Prezzo trattabile', 'palladio' ), 'type' => 'checkbox' ),
			'mq_commerciali'     => array( 'label' => __( 'Superficie commerciale (m²)', 'palladio' ), 'type' => 'number' ),
			'mq_coperti'         => array( 'label' => __( 'Superficie coperta (m²)', 'palladio' ), 'type' => 'number' ),
			'vani'               => array( 'label' => __( 'Vani / stanze', 'palladio' ), 'type' => 'number' ),
			'camere'             => array( 'label' => __( 'Camere', 'palladio' ), 'type' => 'int' ),
			'bagni'              => array( 'label' => __( 'Bagni', 'palladio' ), 'type' => 'int' ),
			'esposizione'        => array( 'label' => __( 'Esposizione', 'palladio' ), 'type' => 'text' ),
			'classe_energetica'  => array( 'label' => __( 'Classe energetica', 'palladio' ), 'type' => 'text' ),
			'millesimi'          => array( 'label' => __( 'Millesimi', 'palladio' ), 'type' => 'number' ),
			'spese_condominiali' => array( 'label' => __( 'Spese condominiali (EUR)', 'palladio' ), 'type' => 'number' ),
			'terrazza_mq'        => array( 'label' => __( 'Terrazza (m²)', 'palladio' ), 'type' => 'number' ),
			'giardino_mq'        => array( 'label' => __( 'Giardino (m²)', 'palladio' ), 'type' => 'number' ),
			'stato_consegna'     => array( 'label' => __( 'Stato di consegna', 'palladio' ), 'type' => 'text' ),
			'destinazione_uso'   => array( 'label' => __( 'Uso attuale / destinazione', 'palladio' ), 'type' => 'text' ),
			'virtual_tour_url'   => array( 'label' => __( 'URL virtual tour', 'palladio' ), 'type' => 'url' ),
			'video_url'          => array( 'label' => __( 'URL video', 'palladio' ), 'type' => 'url' ),
		);
	}

	/**
	 * Aggiunge il metabox.
	 *
	 * @return void
	 */
	public function add_metabox() {
		foreach ( array( 'pll_edificio', 'pll_unita' ) as $pt ) {
			add_meta_box(
				'palladio-fields',
				__( 'Palladio — Dati principali', 'palladio' ),
				array( $this, 'render' ),
				$pt,
				'normal',
				'high'
			);
		}
	}

	/**
	 * Renderizza il metabox.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public function render( $post ) {
		wp_nonce_field( 'palladio_fields_save', 'palladio_fields_nonce' );

		if ( 'pll_edificio' === $post->post_type ) {
			$is_home = ( (int) get_option( 'palladio_home_building', 0 ) === $post->ID );
			echo '<p class="palladio-home-flag"><label><input type="checkbox" name="palladio_is_home" value="1" ' . checked( $is_home, true, false ) . '> <strong>' . esc_html__( 'Usa questo edificio come homepage del sito', 'palladio' ) . '</strong></label><br><span class="description">' . esc_html__( 'La landing dell’edificio verrà mostrata alla radice del sito, distinta dalle schede delle singole unità.', 'palladio' ) . '</span></p><hr>';
		}

		echo '<div class="palladio-fields-grid">';
		foreach ( self::fields( $post->post_type ) as $key => $conf ) {
			$value = get_post_meta( $post->ID, '_pll_' . $key, true );
			$name  = 'palladio_fields[' . $key . ']';
			echo '<p class="palladio-field-cell">';

			if ( 'checkbox' === $conf['type'] ) {
				echo '<label><input type="checkbox" name="' . esc_attr( $name ) . '" value="1" ' . checked( (bool) $value, true, false ) . '> ' . esc_html( $conf['label'] ) . '</label>';
			} else {
				echo '<label>' . esc_html( $conf['label'] );
				switch ( $conf['type'] ) {
					case 'textarea':
						echo '<textarea class="widefat" rows="2" name="' . esc_attr( $name ) . '">' . esc_textarea( (string) $value ) . '</textarea>';
						break;
					case 'number':
						echo '<input type="number" step="any" class="widefat" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';
						break;
					case 'int':
						echo '<input type="number" step="1" class="widefat" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';
						break;
					case 'url':
						echo '<input type="url" class="widefat" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';
						break;
					case 'email':
						echo '<input type="email" class="widefat" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';
						break;
					default:
						echo '<input type="text" class="widefat" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';
				}
				echo '</label>';
			}

			if ( ! empty( $conf['help'] ) ) {
				echo '<span class="description">' . esc_html( $conf['help'] ) . '</span>';
			}
			echo '</p>';
		}
		echo '</div>';
	}

	/**
	 * Salva i dati principali.
	 *
	 * @param int     $post_id ID post.
	 * @param WP_Post $post    Post.
	 * @return void
	 */
	public function save( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if (
			! isset( $_POST['palladio_fields_nonce'] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['palladio_fields_nonce'] ) ), 'palladio_fields_save' )
		) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$raw = isset( $_POST['palladio_fields'] ) && is_array( $_POST['palladio_fields'] )
			? wp_unslash( $_POST['palladio_fields'] ) // phpcs:ignore WordPress.Security.ValidationSanitization.InputNotSanitized
			: array();

		foreach ( self::fields( $post->post_type ) as $key => $conf ) {
			$meta = '_pll_' . $key;

			if ( 'checkbox' === $conf['type'] ) {
				update_post_meta( $post_id, $meta, empty( $raw[ $key ] ) ? 0 : 1 );
				continue;
			}

			$value = $raw[ $key ] ?? '';
			switch ( $conf['type'] ) {
				case 'textarea':
					$value = sanitize_textarea_field( $value );
					break;
				case 'number':
					$value = ( '' === $value ) ? '' : (float) $value;
					break;
				case 'int':
					$value = ( '' === $value ) ? '' : absint( $value );
					break;
				case 'url':
					$value = esc_url_raw( $value );
					break;
				case 'email':
					$value = sanitize_email( $value );
					break;
				default:
					$value = sanitize_text_field( $value );
			}
			update_post_meta( $post_id, $meta, $value );
		}

		// Flag homepage (solo edificio).
		if ( 'pll_edificio' === $post->post_type ) {
			$current = (int) get_option( 'palladio_home_building', 0 );
			if ( ! empty( $_POST['palladio_is_home'] ) ) {
				update_option( 'palladio_home_building', (int) $post_id );
			} elseif ( $current === (int) $post_id ) {
				delete_option( 'palladio_home_building' );
			}
		}
	}
}
