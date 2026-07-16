<?php
/**
 * Modulo Presenter — campi strutturati (admin).
 *
 * Metabox che popola i campi del template editoriale "Sambiasi" (§5.2): non
 * un testo libero, ma campi dedicati e repeater (narrazione asimmetrica,
 * scheda tecnica, capitoli del walkthrough, galleria, planimetria, posizione)
 * salvati in `_pll_editorial` e letti dai template.
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Editor dei contenuti strutturati.
 */
class Palladio_Admin_Content {

	/**
	 * CPT gestiti.
	 *
	 * @var string[]
	 */
	private $post_types = array( 'pll_edificio', 'pll_unita' );

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
		add_action( 'save_post', array( $this, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	/**
	 * Aggiunge il metabox.
	 *
	 * @return void
	 */
	public function add_metabox() {
		foreach ( $this->post_types as $pt ) {
			add_meta_box(
				'palladio-content',
				__( 'Palladio — Contenuti della scheda', 'palladio' ),
				array( $this, 'render' ),
				$pt,
				'normal',
				'high'
			);
		}
	}

	/**
	 * Accoda media picker, JS e CSS sulle schermate di modifica.
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

		wp_enqueue_media();

		wp_enqueue_style(
			'palladio-content-admin',
			PALLADIO_URI . 'assets/css/palladio-content-admin.css',
			array(),
			$this->ver( 'assets/css/palladio-content-admin.css' )
		);
		wp_enqueue_script(
			'palladio-content-admin',
			PALLADIO_URI . 'assets/js/palladio-content-admin.js',
			array(),
			$this->ver( 'assets/js/palladio-content-admin.js' ),
			true
		);
		wp_localize_script(
			'palladio-content-admin',
			'PalladioContent',
			array(
				'choose' => __( 'Scegli immagine', 'palladio' ),
				'use'    => __( 'Usa questa immagine', 'palladio' ),
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
		$d = palladio_editorial( $post->ID );
		wp_nonce_field( 'palladio_content_save', 'palladio_content_nonce' );
		?>
		<div class="palladio-content-box">
			<p class="description"><?php esc_html_e( 'Questi campi popolano il template editoriale della scheda. Il contenuto principale (editor) resta usato come narrazione introduttiva se i blocchi qui sotto sono vuoti.', 'palladio' ); ?></p>
			<?php
			if ( 'pll_edificio' === $post->post_type ) {
				$this->render_edificio_fields( $d );
			} else {
				$this->render_unita_fields( $d );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Campi della scheda Unità.
	 *
	 * @param array $d Struttura editoriale.
	 * @return void
	 */
	private function render_unita_fields( $d ) {
		?>
		<h4><?php esc_html_e( 'Testata', 'palladio' ); ?></h4>
		<p><label><?php esc_html_e( 'Occhiello (eyebrow)', 'palladio' ); ?><br>
			<input type="text" class="widefat" name="palladio_editorial[eyebrow]" value="<?php echo esc_attr( $d['eyebrow'] ); ?>" placeholder="<?php esc_attr_e( 'Primo piano · Appartamento 7 · Palazzo Sambiasi', 'palladio' ); ?>"></label></p>
		<p><label><?php esc_html_e( 'Frase di apertura (lead)', 'palladio' ); ?><br>
			<textarea class="widefat" rows="2" name="palladio_editorial[lead]"><?php echo esc_textarea( $d['lead'] ); ?></textarea></label></p>
		<p><label><?php esc_html_e( 'URL walkthrough / virtual tour', 'palladio' ); ?><br>
			<input type="url" class="widefat" name="palladio_editorial[walkthrough_url]" value="<?php echo esc_attr( $d['walkthrough_url'] ); ?>"></label></p>

		<h4><?php esc_html_e( 'Capitoli del walkthrough', 'palladio' ); ?></h4>
		<?php
		$this->repeater( 'chapters', $d['chapters'], array(
			'time'  => array( 'type' => 'text', 'label' => __( 'Minutaggio', 'palladio' ), 'width' => '8rem' ),
			'label' => array( 'type' => 'text', 'label' => __( 'Titolo capitolo', 'palladio' ) ),
		) );
		?>

		<h4><?php esc_html_e( 'Narrazione (blocchi asimmetrici)', 'palladio' ); ?></h4>
		<?php
		$this->repeater( 'narrative', $d['narrative'], array(
			'kicker'  => array( 'type' => 'text', 'label' => __( 'Occhiello', 'palladio' ) ),
			'heading' => array( 'type' => 'text', 'label' => __( 'Titolo', 'palladio' ) ),
			'body'    => array( 'type' => 'textarea', 'label' => __( 'Testo', 'palladio' ) ),
			'image'   => array( 'type' => 'media', 'label' => __( 'Immagine', 'palladio' ) ),
			'caption' => array( 'type' => 'text', 'label' => __( 'Didascalia', 'palladio' ) ),
			'layout'  => array( 'type' => 'select', 'label' => __( 'Immagine a', 'palladio' ), 'options' => array( 'right' => __( 'Destra', 'palladio' ), 'left' => __( 'Sinistra', 'palladio' ) ) ),
		) );
		?>

		<h4><?php esc_html_e( 'Scheda tecnica', 'palladio' ); ?></h4>
		<?php
		$this->repeater( 'tech', $d['tech'], array(
			'label' => array( 'type' => 'text', 'label' => __( 'Voce', 'palladio' ) ),
			'value' => array( 'type' => 'text', 'label' => __( 'Valore', 'palladio' ) ),
		) );
		?>

		<h4><?php esc_html_e( 'Planimetria', 'palladio' ); ?></h4>
		<?php $this->media_field( 'palladio_editorial[floorplan][image]', (int) $d['floorplan']['image'] ); ?>
		<p><label><?php esc_html_e( 'Didascalia planimetria', 'palladio' ); ?><br>
			<input type="text" class="widefat" name="palladio_editorial[floorplan][caption]" value="<?php echo esc_attr( $d['floorplan']['caption'] ); ?>"></label></p>
		<p><label><?php esc_html_e( 'Note / misure', 'palladio' ); ?><br>
			<textarea class="widefat" rows="2" name="palladio_editorial[floorplan][notes]"><?php echo esc_textarea( $d['floorplan']['notes'] ); ?></textarea></label></p>

		<h4><?php esc_html_e( 'Galleria', 'palladio' ); ?></h4>
		<?php
		$this->repeater( 'gallery', $d['gallery'], array(
			'image'   => array( 'type' => 'media', 'label' => __( 'Immagine', 'palladio' ) ),
			'caption' => array( 'type' => 'text', 'label' => __( 'Didascalia', 'palladio' ) ),
			'ratio'   => array( 'type' => 'select', 'label' => __( 'Proporzione', 'palladio' ), 'options' => array( '3:2' => '3:2', '4:3' => '4:3', '4:5' => '4:5', '1:1' => '1:1' ) ),
		) );
		?>

		<h4><?php esc_html_e( 'Posizione nell’edificio', 'palladio' ); ?></h4>
		<p><label><?php esc_html_e( 'Titolo', 'palladio' ); ?><br>
			<input type="text" class="widefat" name="palladio_editorial[position][heading]" value="<?php echo esc_attr( $d['position']['heading'] ); ?>"></label></p>
		<p><label><?php esc_html_e( 'Testo', 'palladio' ); ?><br>
			<textarea class="widefat" rows="2" name="palladio_editorial[position][text]"><?php echo esc_textarea( $d['position']['text'] ); ?></textarea></label></p>
		<?php
	}

	/**
	 * Campi della landing Edificio (§ immagini di riferimento).
	 *
	 * @param array $d Struttura editoriale.
	 * @return void
	 */
	private function render_edificio_fields( $d ) {
		?>
		<h4><?php esc_html_e( 'Testata', 'palladio' ); ?></h4>
		<p><label><?php esc_html_e( 'Occhiello (eyebrow)', 'palladio' ); ?><br>
			<input type="text" class="widefat" name="palladio_editorial[eyebrow]" value="<?php echo esc_attr( $d['eyebrow'] ); ?>" placeholder="<?php esc_attr_e( 'Lecce · Via Marco Basseo 31 · Dimora del XVI secolo', 'palladio' ); ?>"></label></p>
		<p><label><?php esc_html_e( 'Frase di apertura (lead)', 'palladio' ); ?><br>
			<textarea class="widefat" rows="3" name="palladio_editorial[lead]"><?php echo esc_textarea( $d['lead'] ); ?></textarea></label></p>

		<h4><?php esc_html_e( 'Manifesto (affermazioni)', 'palladio' ); ?></h4>
		<p class="description"><?php esc_html_e( 'Frasi brevi rivelate allo scroll. La parte “enfasi” viene resa in corsivo bordeaux.', 'palladio' ); ?></p>
		<?php
		$this->repeater( 'manifesto', $d['manifesto'], array(
			'text'     => array( 'type' => 'text', 'label' => __( 'Testo', 'palladio' ) ),
			'emphasis' => array( 'type' => 'text', 'label' => __( 'Enfasi (corsivo)', 'palladio' ) ),
		) );
		?>

		<h4><?php esc_html_e( 'Timeline / scroll-telling', 'palladio' ); ?></h4>
		<p class="description"><?php esc_html_e( 'Capitoli storici: occhiello, anno, titolo, testo e immagine.', 'palladio' ); ?></p>
		<?php
		$this->repeater( 'timeline', $d['timeline'], array(
			'kicker'  => array( 'type' => 'text', 'label' => __( 'Occhiello (es. Capitolo II · Il secolo barocco)', 'palladio' ) ),
			'year'    => array( 'type' => 'text', 'label' => __( 'Anno', 'palladio' ), 'width' => '7rem' ),
			'heading' => array( 'type' => 'text', 'label' => __( 'Titolo', 'palladio' ) ),
			'body'    => array( 'type' => 'textarea', 'label' => __( 'Testo', 'palladio' ) ),
			'image'   => array( 'type' => 'media', 'label' => __( 'Immagine', 'palladio' ) ),
		) );
		?>

		<h4><?php esc_html_e( 'Ambient loop (fascia a piena larghezza)', 'palladio' ); ?></h4>
		<?php $this->media_field( 'palladio_editorial[ambient][image]', (int) $d['ambient']['image'] ); ?>
		<p><label><?php esc_html_e( 'Didascalia', 'palladio' ); ?><br>
			<input type="text" class="widefat" name="palladio_editorial[ambient][caption]" value="<?php echo esc_attr( $d['ambient']['caption'] ); ?>" placeholder="<?php esc_attr_e( 'Ambient loop · il glicine della loggetta, 6s, senza audio', 'palladio' ); ?>"></label></p>

		<h4><?php esc_html_e( 'Sezione unità', 'palladio' ); ?></h4>
		<div class="palladio-fields-grid">
			<p class="palladio-field-cell"><label><?php esc_html_e( 'Occhiello', 'palladio' ); ?>
				<input type="text" class="widefat" name="palladio_editorial[units_eyebrow]" value="<?php echo esc_attr( $d['units_eyebrow'] ); ?>" placeholder="<?php esc_attr_e( 'Cinque unità · due piani + giardino', 'palladio' ); ?>"></label></p>
			<p class="palladio-field-cell"><label><?php esc_html_e( 'Titolo', 'palladio' ); ?>
				<input type="text" class="widefat" name="palladio_editorial[units_heading]" value="<?php echo esc_attr( $d['units_heading'] ); ?>" placeholder="<?php esc_attr_e( 'Scegli le tue stanze', 'palladio' ); ?>"></label></p>
		</div>
		<p><label><input type="checkbox" name="palladio_editorial[units_filters]" value="1" <?php checked( $d['units_filters'], true ); ?>> <?php esc_html_e( 'Mostra i filtri (Tutte / Piano / Prezzo / Con spazio esterno)', 'palladio' ); ?></label></p>

		<h4><?php esc_html_e( 'Galleria', 'palladio' ); ?></h4>
		<?php
		$this->repeater( 'gallery', $d['gallery'], array(
			'image'   => array( 'type' => 'media', 'label' => __( 'Immagine', 'palladio' ) ),
			'caption' => array( 'type' => 'text', 'label' => __( 'Didascalia', 'palladio' ) ),
			'ratio'   => array( 'type' => 'select', 'label' => __( 'Proporzione', 'palladio' ), 'options' => array( '3:2' => '3:2', '4:3' => '4:3', '4:5' => '4:5', '1:1' => '1:1', '16:9' => '16:9' ) ),
		) );
		?>
		<div class="palladio-fields-grid">
			<p class="palladio-field-cell"><label><?php esc_html_e( 'Link “Tutta la galleria”', 'palladio' ); ?>
				<input type="url" class="widefat" name="palladio_editorial[gallery_url]" value="<?php echo esc_attr( $d['gallery_url'] ); ?>"></label></p>
			<p class="palladio-field-cell"><label><?php esc_html_e( 'Numero di fotografie', 'palladio' ); ?>
				<input type="text" class="widefat" name="palladio_editorial[gallery_count]" value="<?php echo esc_attr( $d['gallery_count'] ); ?>" placeholder="42"></label></p>
		</div>
		<?php
	}

	/**
	 * Renderizza un repeater (righe esistenti + riga template per il JS).
	 *
	 * @param string $section Nome sezione.
	 * @param array  $rows    Righe esistenti.
	 * @param array  $fields  Definizione campi.
	 * @return void
	 */
	private function repeater( $section, $rows, $fields ) {
		echo '<div class="pll-rep" data-section="' . esc_attr( $section ) . '">';
		echo '<div class="pll-rep__rows">';

		$rows = is_array( $rows ) ? array_values( $rows ) : array();
		foreach ( $rows as $i => $row ) {
			$this->repeater_row( $section, (int) $i, $fields, is_array( $row ) ? $row : array() );
		}

		echo '</div>';

		// Riga template (indice segnaposto __i__), nascosta.
		echo '<script type="text/html" class="pll-rep__tpl">';
		$this->repeater_row( $section, '__i__', $fields, array() );
		echo '</script>';

		printf(
			'<button type="button" class="button pll-rep__add" data-add="%s">%s</button>',
			esc_attr( $section ),
			esc_html__( '+ Aggiungi', 'palladio' )
		);
		echo '</div>';
	}

	/**
	 * Renderizza una riga di repeater.
	 *
	 * @param string     $section Sezione.
	 * @param int|string $index   Indice (o __i__).
	 * @param array      $fields  Campi.
	 * @param array      $values  Valori riga.
	 * @return void
	 */
	private function repeater_row( $section, $index, $fields, $values ) {
		echo '<div class="pll-rep__row" draggable="true">';
		// Maniglia di riordino: trascinabile, oppure frecce su/giù (anche con
		// i tasti freccia della tastiera quando la maniglia ha il focus).
		echo '<span class="pll-rep__order">';
		echo '<button type="button" class="pll-rep__handle" title="' . esc_attr__( 'Trascina per riordinare (o usa le frecce)', 'palladio' ) . '" aria-label="' . esc_attr__( 'Riordina elemento', 'palladio' ) . '">⋮⋮</button>';
		echo '<button type="button" class="pll-rep__move pll-rep__move--up" aria-label="' . esc_attr__( 'Sposta su', 'palladio' ) . '">↑</button>';
		echo '<button type="button" class="pll-rep__move pll-rep__move--down" aria-label="' . esc_attr__( 'Sposta giù', 'palladio' ) . '">↓</button>';
		echo '</span>';
		echo '<div class="pll-rep__fields">';
		foreach ( $fields as $key => $conf ) {
			$name  = 'palladio_editorial[' . $section . '][' . $index . '][' . $key . ']';
			$value = isset( $values[ $key ] ) ? $values[ $key ] : '';
			$style = ! empty( $conf['width'] ) ? ' style="max-width:' . esc_attr( $conf['width'] ) . '"' : '';

			echo '<label class="pll-rep__field"' . $style . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<span>' . esc_html( $conf['label'] ) . '</span>';

			switch ( $conf['type'] ) {
				case 'textarea':
					printf( '<textarea rows="2" name="%s">%s</textarea>', esc_attr( $name ), esc_textarea( $value ) );
					break;
				case 'select':
					echo '<select name="' . esc_attr( $name ) . '">';
					foreach ( $conf['options'] as $ov => $ol ) {
						printf( '<option value="%s"%s>%s</option>', esc_attr( $ov ), selected( $value, $ov, false ), esc_html( $ol ) );
					}
					echo '</select>';
					break;
				case 'media':
					$this->media_field( $name, (int) $value );
					break;
				default:
					printf( '<input type="text" name="%s" value="%s">', esc_attr( $name ), esc_attr( $value ) );
			}
			echo '</label>';
		}
		echo '</div>';
		echo '<button type="button" class="button-link pll-rep__remove" aria-label="' . esc_attr__( 'Rimuovi', 'palladio' ) . '">&times;</button>';
		echo '</div>';
	}

	/**
	 * Campo immagine (media picker): hidden id + anteprima + pulsanti.
	 *
	 * @param string $name Nome campo.
	 * @param int    $id   Attachment id.
	 * @return void
	 */
	private function media_field( $name, $id ) {
		$url = $id ? wp_get_attachment_image_url( $id, 'thumbnail' ) : '';
		echo '<span class="pll-media" data-pll-media>';
		printf( '<input type="hidden" class="pll-media__id" name="%s" value="%s">', esc_attr( $name ), esc_attr( (int) $id ) );
		printf( '<span class="pll-media__preview">%s</span>', $url ? '<img src="' . esc_url( $url ) . '" alt="">' : '' );
		echo '<button type="button" class="button pll-media__choose">' . esc_html__( 'Immagine', 'palladio' ) . '</button>';
		echo '<button type="button" class="button-link pll-media__clear">' . esc_html__( 'Rimuovi', 'palladio' ) . '</button>';
		echo '</span>';
	}

	/**
	 * Salva i contenuti strutturati.
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
			! isset( $_POST['palladio_content_nonce'] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['palladio_content_nonce'] ) ), 'palladio_content_save' )
		) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$raw = isset( $_POST['palladio_editorial'] ) && is_array( $_POST['palladio_editorial'] )
			? wp_unslash( $_POST['palladio_editorial'] ) // phpcs:ignore WordPress.Security.ValidationSanitization.InputNotSanitized
			: array();

		$clean = array(
			'eyebrow'         => sanitize_text_field( $raw['eyebrow'] ?? '' ),
			'lead'            => sanitize_textarea_field( $raw['lead'] ?? '' ),
			'walkthrough_url' => esc_url_raw( $raw['walkthrough_url'] ?? '' ),
			'chapters'        => $this->clean_rows( $raw['chapters'] ?? array(), array( 'time' => 'text', 'label' => 'text' ) ),
			'narrative'       => $this->clean_rows( $raw['narrative'] ?? array(), array( 'kicker' => 'text', 'heading' => 'text', 'body' => 'html', 'image' => 'int', 'caption' => 'text', 'layout' => 'key' ) ),
			'tech'            => $this->clean_rows( $raw['tech'] ?? array(), array( 'label' => 'text', 'value' => 'text' ) ),
			'gallery'         => $this->clean_rows( $raw['gallery'] ?? array(), array( 'image' => 'int', 'caption' => 'text', 'ratio' => 'text' ) ),
			'floorplan'       => array(
				'image'   => absint( $raw['floorplan']['image'] ?? 0 ),
				'caption' => sanitize_text_field( $raw['floorplan']['caption'] ?? '' ),
				'notes'   => sanitize_textarea_field( $raw['floorplan']['notes'] ?? '' ),
			),
			'position'        => array(
				'heading' => sanitize_text_field( $raw['position']['heading'] ?? '' ),
				'text'    => sanitize_textarea_field( $raw['position']['text'] ?? '' ),
			),
			// Campi della landing Edificio.
			'ambient'         => array(
				'image'   => absint( $raw['ambient']['image'] ?? 0 ),
				'caption' => sanitize_text_field( $raw['ambient']['caption'] ?? '' ),
			),
			'manifesto'       => $this->clean_rows( $raw['manifesto'] ?? array(), array( 'text' => 'text', 'emphasis' => 'text' ) ),
			'timeline'        => $this->clean_rows( $raw['timeline'] ?? array(), array( 'kicker' => 'text', 'year' => 'text', 'heading' => 'text', 'body' => 'html', 'image' => 'int' ) ),
			'gallery_url'     => esc_url_raw( $raw['gallery_url'] ?? '' ),
			'gallery_count'   => sanitize_text_field( $raw['gallery_count'] ?? '' ),
			'units_eyebrow'   => sanitize_text_field( $raw['units_eyebrow'] ?? '' ),
			'units_heading'   => sanitize_text_field( $raw['units_heading'] ?? '' ),
			'units_filters'   => ! empty( $raw['units_filters'] ),
		);

		update_post_meta( $post_id, '_pll_editorial', $clean );
	}

	/**
	 * Sanitizza le righe di un repeater, scartando quelle vuote.
	 *
	 * @param mixed $rows  Righe grezze.
	 * @param array $types Mappa campo => tipo (text|html|int|key).
	 * @return array
	 */
	private function clean_rows( $rows, $types ) {
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$clean = array();
			$empty = true;

			foreach ( $types as $field => $type ) {
				$value = $row[ $field ] ?? '';
				switch ( $type ) {
					case 'html':
						$clean[ $field ] = wp_kses_post( $value );
						break;
					case 'int':
						$clean[ $field ] = absint( $value );
						break;
					case 'key':
						$clean[ $field ] = sanitize_key( $value );
						break;
					default:
						$clean[ $field ] = sanitize_text_field( $value );
				}
				if ( '' !== (string) $clean[ $field ] && '0' !== (string) $clean[ $field ] ) {
					$empty = false;
				}
			}

			if ( ! $empty ) {
				$out[] = $clean;
			}
		}

		return $out;
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
