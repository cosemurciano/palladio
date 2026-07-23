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
	private $post_types = array( 'pll_edificio', 'pll_unita', 'pll_storia' );

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
			} elseif ( 'pll_storia' === $post->post_type ) {
				$this->render_storia_fields( $d );
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
		<?php $this->gallery_layout_field( $d['gallery_layout'] ); ?>
		<?php
		$this->repeater( 'gallery', $d['gallery'], array(
			'image'   => array( 'type' => 'media', 'label' => __( 'Immagine', 'palladio' ) ),
			'caption' => array( 'type' => 'text', 'label' => __( 'Didascalia', 'palladio' ) ),
		), true );
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

		<h4><?php esc_html_e( 'Ambient loop (fascia a piena larghezza, dopo le unità)', 'palladio' ); ?></h4>
		<p class="description"><?php esc_html_e( 'Più immagini vanno in dissolvenza automatica, navigabili con le frecce.', 'palladio' ); ?></p>
		<?php
		$this->repeater( 'ambient_images', $d['ambient_images'], array(
			'image'   => array( 'type' => 'media', 'label' => __( 'Immagine', 'palladio' ) ),
			'caption' => array( 'type' => 'text', 'label' => __( 'Didascalia', 'palladio' ) ),
		), true );
		?>

		<h4><?php esc_html_e( 'Sezione unità', 'palladio' ); ?></h4>
		<div class="palladio-fields-grid">
			<p class="palladio-field-cell"><label><?php esc_html_e( 'Occhiello', 'palladio' ); ?>
				<input type="text" class="widefat" name="palladio_editorial[units_eyebrow]" value="<?php echo esc_attr( $d['units_eyebrow'] ); ?>" placeholder="<?php esc_attr_e( 'Cinque unità · due piani + giardino', 'palladio' ); ?>"></label></p>
			<p class="palladio-field-cell"><label><?php esc_html_e( 'Titolo', 'palladio' ); ?>
				<input type="text" class="widefat" name="palladio_editorial[units_heading]" value="<?php echo esc_attr( $d['units_heading'] ); ?>" placeholder="<?php esc_attr_e( 'Scegli le tue stanze', 'palladio' ); ?>"></label></p>
		</div>
		<p><label><input type="checkbox" name="palladio_editorial[units_filters]" value="1" <?php checked( $d['units_filters'], true ); ?>> <?php esc_html_e( 'Mostra i filtri (Tutte / Piano / Prezzo / Con spazio esterno)', 'palladio' ); ?></label></p>

		<h4><?php esc_html_e( 'Galleria', 'palladio' ); ?></h4>
		<?php $this->gallery_layout_field( $d['gallery_layout'] ); ?>
		<?php
		$this->repeater( 'gallery', $d['gallery'], array(
			'image'   => array( 'type' => 'media', 'label' => __( 'Immagine', 'palladio' ) ),
			'caption' => array( 'type' => 'text', 'label' => __( 'Didascalia', 'palladio' ) ),
		), true );
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
	 * Campi della pagina "La Storia" (tavola d'archivio).
	 *
	 * @param array $d Struttura editoriale.
	 * @return void
	 */
	private function render_storia_fields( $d ) {
		?>
		<h4><?php esc_html_e( 'Testata', 'palladio' ); ?></h4>
		<p class="description"><?php esc_html_e( 'L’immagine in evidenza del post è lo sfondo del hero; il titolo del post è il titolo grande (es. “Venticinque secoli, una pietra.”).', 'palladio' ); ?></p>
		<p><label><?php esc_html_e( 'Occhiello (eyebrow)', 'palladio' ); ?><br>
			<input type="text" class="widefat" name="palladio_editorial[eyebrow]" value="<?php echo esc_attr( $d['eyebrow'] ); ?>" placeholder="<?php esc_attr_e( 'Palazzo Sambiasi · Lecce', 'palladio' ); ?>"></label></p>
		<p><label><?php esc_html_e( 'Frase di apertura (lead)', 'palladio' ); ?><br>
			<textarea class="widefat" rows="2" name="palladio_editorial[lead]" placeholder="<?php esc_attr_e( 'Dalle tombe messapiche di Lupiae al decreto di tutela del 1987…', 'palladio' ); ?>"><?php echo esc_textarea( $d['lead'] ); ?></textarea></label></p>

		<h4><?php esc_html_e( 'Affermazioni introduttive (manifesto)', 'palladio' ); ?></h4>
		<p class="description"><?php esc_html_e( 'Frasi brevi rivelate allo scroll, es. “Sotto questo giardino, l’antica Lupiae.” — l’enfasi è resa in corsivo bordeaux.', 'palladio' ); ?></p>
		<?php
		$this->repeater( 'manifesto', $d['manifesto'], array(
			'text'     => array( 'type' => 'text', 'label' => __( 'Testo', 'palladio' ) ),
			'emphasis' => array( 'type' => 'text', 'label' => __( 'Enfasi (corsivo)', 'palladio' ) ),
		) );
		?>

		<h4><?php esc_html_e( 'Cronologia — tavola d’archivio', 'palladio' ); ?></h4>
		<p class="description"><?php esc_html_e( 'Ogni evento: era, anno grande (es. ’500), sottotitolo (es. XVI SEC.), titolo, racconto, immagine e didascalia. L’asse del tempo alterna gli eventi a destra e sinistra; su mobile si impilano.', 'palladio' ); ?></p>
		<?php
		$this->repeater( 'timeline', $d['timeline'], array(
			'kicker'   => array( 'type' => 'text', 'label' => __( 'Era (es. Il Rinascimento)', 'palladio' ) ),
			'year'     => array( 'type' => 'text', 'label' => __( 'Anno (grande)', 'palladio' ), 'width' => '7rem' ),
			'year_sub' => array( 'type' => 'text', 'label' => __( 'Sottotitolo anno (es. XVI SEC.)', 'palladio' ), 'width' => '10rem' ),
			'heading'  => array( 'type' => 'text', 'label' => __( 'Titolo', 'palladio' ) ),
			'body'     => array( 'type' => 'textarea', 'label' => __( 'Racconto', 'palladio' ) ),
			'image'    => array( 'type' => 'media', 'label' => __( 'Immagine', 'palladio' ) ),
			'caption'  => array( 'type' => 'text', 'label' => __( 'Didascalia immagine', 'palladio' ) ),
		) );
		?>

		<h4><?php esc_html_e( 'L’araldica — blasoni e famiglie', 'palladio' ); ?></h4>
		<?php
		$this->repeater( 'heraldry', $d['heraldry'], array(
			'initial' => array( 'type' => 'text', 'label' => __( 'Iniziale (monogramma)', 'palladio' ), 'width' => '7rem' ),
			'image'   => array( 'type' => 'media', 'label' => __( 'Foto stemma (opz.)', 'palladio' ) ),
			'name'    => array( 'type' => 'text', 'label' => __( 'Casata', 'palladio' ) ),
			'blazon'  => array( 'type' => 'text', 'label' => __( 'Blasone (corsivo)', 'palladio' ) ),
			'note'    => array( 'type' => 'textarea', 'label' => __( 'Nota', 'palladio' ) ),
		) );
		?>
		<div class="palladio-fields-grid">
			<p class="palladio-field-cell"><label><?php esc_html_e( 'Occhiello sezione araldica', 'palladio' ); ?>
				<input type="text" class="widefat" name="palladio_editorial[heraldry_eyebrow]" value="<?php echo esc_attr( $d['heraldry_eyebrow'] ); ?>" placeholder="<?php esc_attr_e( 'L’araldica', 'palladio' ); ?>"></label></p>
			<p class="palladio-field-cell"><label><?php esc_html_e( 'Titolo sezione araldica', 'palladio' ); ?>
				<input type="text" class="widefat" name="palladio_editorial[heraldry_heading]" value="<?php echo esc_attr( $d['heraldry_heading'] ); ?>" placeholder="<?php esc_attr_e( 'Tre blasoni, una dimora', 'palladio' ); ?>"></label></p>
		</div>

		<h4><?php esc_html_e( 'Il lessico della pietra — glossario illustrato', 'palladio' ); ?></h4>
		<?php
		$this->repeater( 'glossary', $d['glossary'], array(
			'image'      => array( 'type' => 'media', 'label' => __( 'Immagine', 'palladio' ) ),
			'caption'    => array( 'type' => 'text', 'label' => __( 'Didascalia immagine', 'palladio' ) ),
			'term'       => array( 'type' => 'text', 'label' => __( 'Termine', 'palladio' ) ),
			'sub'        => array( 'type' => 'text', 'label' => __( 'Sottotitolo (etimologia)', 'palladio' ) ),
			'definition' => array( 'type' => 'textarea', 'label' => __( 'Definizione', 'palladio' ) ),
		), true );
		?>
		<div class="palladio-fields-grid">
			<p class="palladio-field-cell"><label><?php esc_html_e( 'Occhiello sezione glossario', 'palladio' ); ?>
				<input type="text" class="widefat" name="palladio_editorial[glossary_eyebrow]" value="<?php echo esc_attr( $d['glossary_eyebrow'] ); ?>" placeholder="<?php esc_attr_e( 'Il lessico della pietra', 'palladio' ); ?>"></label></p>
			<p class="palladio-field-cell"><label><?php esc_html_e( 'Titolo sezione glossario', 'palladio' ); ?>
				<input type="text" class="widefat" name="palladio_editorial[glossary_heading]" value="<?php echo esc_attr( $d['glossary_heading'] ); ?>" placeholder="<?php esc_attr_e( 'Le parole per capirlo', 'palladio' ); ?>"></label></p>
		</div>
		<p><label><?php esc_html_e( 'Testo introduttivo glossario', 'palladio' ); ?><br>
			<textarea class="widefat" rows="2" name="palladio_editorial[glossary_text]"><?php echo esc_textarea( $d['glossary_text'] ); ?></textarea></label></p>

		<h4><?php esc_html_e( 'Chiusura — il prossimo capitolo', 'palladio' ); ?></h4>
		<div class="palladio-fields-grid">
			<p class="palladio-field-cell"><label><?php esc_html_e( 'Occhiello', 'palladio' ); ?>
				<input type="text" class="widefat" name="palladio_editorial[closing][kicker]" value="<?php echo esc_attr( $d['closing']['kicker'] ); ?>" placeholder="<?php esc_attr_e( 'Il prossimo capitolo', 'palladio' ); ?>"></label></p>
			<p class="palladio-field-cell"><label><?php esc_html_e( 'Titolo (riga 1)', 'palladio' ); ?>
				<input type="text" class="widefat" name="palladio_editorial[closing][heading]" value="<?php echo esc_attr( $d['closing']['heading'] ); ?>" placeholder="<?php esc_attr_e( 'La storia continua', 'palladio' ); ?>"></label></p>
			<p class="palladio-field-cell"><label><?php esc_html_e( 'Titolo — enfasi (riga 2, corsivo)', 'palladio' ); ?>
				<input type="text" class="widefat" name="palladio_editorial[closing][emphasis]" value="<?php echo esc_attr( $d['closing']['emphasis'] ); ?>" placeholder="<?php esc_attr_e( 'con chi la abiterà.', 'palladio' ); ?>"></label></p>
			<p class="palladio-field-cell"><label><?php esc_html_e( 'Pulsante — testo', 'palladio' ); ?>
				<input type="text" class="widefat" name="palladio_editorial[closing][primary_label]" value="<?php echo esc_attr( $d['closing']['primary_label'] ); ?>" placeholder="<?php esc_attr_e( 'Vedi le residenze', 'palladio' ); ?>"></label></p>
			<p class="palladio-field-cell"><label><?php esc_html_e( 'Pulsante — URL', 'palladio' ); ?>
				<input type="text" class="widefat" name="palladio_editorial[closing][primary_url]" value="<?php echo esc_attr( $d['closing']['primary_url'] ); ?>" placeholder="/unita/"></label></p>
		</div>
		<p class="description"><?php esc_html_e( 'Il secondo pulsante è la CTA “Richiedi una visita” configurata in Palladio → Impostazioni.', 'palladio' ); ?></p>
		<?php
	}

	/**
	 * Selettore del layout automatico della galleria.
	 *
	 * @param string $current Layout corrente.
	 * @return void
	 */
	private function gallery_layout_field( $current ) {
		$layouts = array(
			'masonry'   => __( 'Masonry — colonne a incastro (editoriale)', 'palladio' ),
			'grid'      => __( 'Griglia — celle uniformi', 'palladio' ),
			'mosaic'    => __( 'Mosaico — una foto grande ogni cinque', 'palladio' ),
			'filmstrip' => __( 'Filmstrip — pellicola orizzontale scorrevole', 'palladio' ),
			'offset'    => __( 'Sfalsata — due colonne a quote alternate', 'palladio' ),
		);
		echo '<p><label>' . esc_html__( 'Layout della galleria (automatico)', 'palladio' ) . '<br>';
		echo '<select name="palladio_editorial[gallery_layout]" class="widefat" style="max-width:26rem">';
		foreach ( $layouts as $value => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $current, $value, false ), esc_html( $label ) );
		}
		echo '</select></label></p>';
	}

	/**
	 * Renderizza un repeater (righe esistenti + riga template per il JS).
	 *
	 * @param string $section Nome sezione.
	 * @param array  $rows    Righe esistenti.
	 * @param array  $fields  Definizione campi.
	 * @return void
	 */
	private function repeater( $section, $rows, $fields, $multi = false ) {
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

		if ( $multi ) {
			// Selezione multipla dal media picker: una riga per ogni immagine scelta.
			printf(
				' <button type="button" class="button pll-rep__add-multi" data-add="%s">%s</button>',
				esc_attr( $section ),
				esc_html__( '+ Aggiungi più immagini', 'palladio' )
			);
		}
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
			'gallery'         => $this->clean_rows( $raw['gallery'] ?? array(), array( 'image' => 'int', 'caption' => 'text' ) ),
			'gallery_layout'  => in_array( $raw['gallery_layout'] ?? '', array( 'masonry', 'grid', 'mosaic', 'filmstrip', 'offset' ), true ) ? $raw['gallery_layout'] : 'masonry',
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
			'ambient_images'  => $this->clean_rows( $raw['ambient_images'] ?? array(), array( 'image' => 'int', 'caption' => 'text' ) ),
			'manifesto'       => $this->clean_rows( $raw['manifesto'] ?? array(), array( 'text' => 'text', 'emphasis' => 'text' ) ),
			'timeline'        => $this->clean_rows( $raw['timeline'] ?? array(), array( 'kicker' => 'text', 'year' => 'text', 'year_sub' => 'text', 'heading' => 'text', 'body' => 'html', 'image' => 'int', 'caption' => 'text' ) ),
			// Campi della pagina "La Storia".
			'heraldry'         => $this->clean_rows( $raw['heraldry'] ?? array(), array( 'initial' => 'text', 'image' => 'int', 'name' => 'text', 'blazon' => 'text', 'note' => 'text' ) ),
			'heraldry_eyebrow' => sanitize_text_field( $raw['heraldry_eyebrow'] ?? '' ),
			'heraldry_heading' => sanitize_text_field( $raw['heraldry_heading'] ?? '' ),
			'glossary'         => $this->clean_rows( $raw['glossary'] ?? array(), array( 'image' => 'int', 'caption' => 'text', 'term' => 'text', 'sub' => 'text', 'definition' => 'text' ) ),
			'glossary_eyebrow' => sanitize_text_field( $raw['glossary_eyebrow'] ?? '' ),
			'glossary_heading' => sanitize_text_field( $raw['glossary_heading'] ?? '' ),
			'glossary_text'    => sanitize_textarea_field( $raw['glossary_text'] ?? '' ),
			'closing'          => array(
				'kicker'        => sanitize_text_field( $raw['closing']['kicker'] ?? '' ),
				'heading'       => sanitize_text_field( $raw['closing']['heading'] ?? '' ),
				'emphasis'      => sanitize_text_field( $raw['closing']['emphasis'] ?? '' ),
				'primary_label' => sanitize_text_field( $raw['closing']['primary_label'] ?? '' ),
				'primary_url'   => sanitize_text_field( $raw['closing']['primary_url'] ?? '' ),
			),
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
