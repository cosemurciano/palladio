<?php
/**
 * Modulo Regia — form di cattura lead (classico, §5.6 / Fase 0).
 *
 * Renderizza il form (shortcode + iniezione nel pannello contatti unità),
 * gestisce l'invio con nonce, honeypot e consenso GDPR, salva il lead e
 * notifica via email la regia/agenzia.
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Form lead e handler di invio.
 */
class Palladio_Leads_Form {

	/**
	 * Registra shortcode, hook di rendering e handler.
	 *
	 * @return void
	 */
	public function register() {
		add_shortcode( 'palladio_lead_form', array( $this, 'shortcode' ) );

		// MODULO UNICO: la sezione contatti è iniettata in fondo a TUTTE le
		// pagine del sito, prima del footer del tema (hook get_footer).
		add_action( 'get_footer', array( $this, 'render_section' ) );

		// Handler invio (utenti loggati e non).
		add_action( 'admin_post_nopriv_palladio_submit_lead', array( $this, 'handle' ) );
		add_action( 'admin_post_palladio_submit_lead', array( $this, 'handle' ) );

		// Tracciamento click sui contatti agenzia (mail/telefono/WhatsApp).
		add_action( 'wp_ajax_palladio_track_contact', array( $this, 'ajax_track' ) );
		add_action( 'wp_ajax_nopriv_palladio_track_contact', array( $this, 'ajax_track' ) );
	}

	/**
	 * Icona SVG inline per i canali di contatto.
	 *
	 * @param string $name email|phone|whatsapp.
	 * @return string SVG.
	 */
	private static function icon( $name ) {
		$icons = array(
			'email'    => '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="5" width="19" height="14" rx="2"/><path d="m3.5 6.5 8.5 6.5 8.5-6.5"/></svg>',
			'phone'    => '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M5 3.5h3.2l1.6 4-2 1.6a13.5 13.5 0 0 0 6.1 6.1l1.6-2 4 1.6V18a2.5 2.5 0 0 1-2.7 2.5A17 17 0 0 1 3.5 5.2 2.5 2.5 0 0 1 5 3.5Z"/></svg>',
			'whatsapp' => '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M12 2.2a9.7 9.7 0 0 0-8.4 14.6L2.2 21.8l5.2-1.4A9.7 9.7 0 1 0 12 2.2Zm0 17.6a7.8 7.8 0 0 1-4-1.1l-.3-.2-3 .8.8-2.9-.2-.3A7.9 7.9 0 1 1 12 19.8Zm4.4-5.9c-.2-.1-1.4-.7-1.6-.8-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1a6.5 6.5 0 0 1-3.2-2.8c-.2-.4 0-.5.1-.7l.5-.6c.2-.2.2-.4.1-.6l-.8-1.9c-.2-.5-.4-.4-.6-.4h-.5c-.2 0-.5.1-.7.3-.9.9-1.2 2.1-.4 3.6a11.6 11.6 0 0 0 4.5 4.4c1.7.9 2.6 1 3.5.8.6-.1 1.4-.6 1.6-1.2.2-.6.2-1.1.1-1.2 0 0-.2-.1-.4-.2Z"/></svg>',
		);
		return $icons[ $name ] ?? '';
	}

	/**
	 * Handler AJAX: registra un click su un canale di contatto.
	 *
	 * Aggrega i totali per canale e conserva gli ultimi 50 click (canale,
	 * destinatario, pagina, orario) per il report nella Pipeline lead.
	 *
	 * @return void
	 */
	public function ajax_track() {
		check_ajax_referer( 'palladio_track', 'nonce' );

		$valid   = array( 'email', 'telefono', 'whatsapp' );
		$channel = isset( $_POST['channel'] ) ? sanitize_key( wp_unslash( $_POST['channel'] ) ) : '';
		if ( ! in_array( $channel, $valid, true ) ) {
			wp_send_json_error( null, 400 );
		}

		$target = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : '';
		$page   = isset( $_POST['page'] ) ? esc_url_raw( wp_unslash( $_POST['page'] ) ) : '';

		$store = get_option( 'palladio_contact_clicks', array() );
		$store = wp_parse_args( is_array( $store ) ? $store : array(), array( 'totals' => array(), 'recent' => array() ) );

		$store['totals'][ $channel ] = (int) ( $store['totals'][ $channel ] ?? 0 ) + 1;

		$store['recent'][] = array(
			'channel' => $channel,
			'target'  => substr( $target, 0, 100 ),
			'page'    => substr( $page, 0, 200 ),
			'time'    => time(),
		);
		if ( count( $store['recent'] ) > 50 ) {
			$store['recent'] = array_slice( $store['recent'], -50 );
		}

		update_option( 'palladio_contact_clicks', $store, false );

		wp_send_json_success();
	}

	/**
	 * Registro click sui contatti (per il report nella Pipeline lead).
	 *
	 * @return array{totals:array<string,int>,recent:array}
	 */
	public static function contact_clicks() {
		$store = get_option( 'palladio_contact_clicks', array() );
		return wp_parse_args( is_array( $store ) ? $store : array(), array( 'totals' => array(), 'recent' => array() ) );
	}

	/**
	 * Sezione contatti globale: form a sinistra, contatti agenzia a destra.
	 *
	 * Renderizzata una sola volta per pagina, in fondo, su tutto il sito.
	 * Disattivabile via filtro `palladio/contact/enabled`.
	 *
	 * @return void
	 */
	public function render_section() {
		static $done = false;

		if ( $done || is_admin() || is_feed() || is_embed() || is_404() ) {
			return;
		}
		/**
		 * Consente di nascondere la sezione contatti globale.
		 *
		 * @param bool $enabled Attiva.
		 */
		if ( ! apply_filters( 'palladio/contact/enabled', true ) ) {
			return;
		}
		$done = true;

		$unit_id     = is_singular( 'pll_unita' ) ? get_the_ID() : 0;
		$building_id = is_singular( 'pll_edificio' ) ? get_the_ID() : 0;
		if ( ! $building_id && $unit_id ) {
			$building_id = (int) wp_get_post_parent_id( $unit_id );
		}
		if ( ! $building_id && is_front_page() && function_exists( 'palladio_home_building_id' ) ) {
			$building_id = palladio_home_building_id();
		}

		$heading = class_exists( 'Palladio_Admin_Settings' ) ? Palladio_Admin_Settings::get( 'contact_heading' ) : __( 'Richiedi una visita o informazioni', 'palladio' );
		$intro   = class_exists( 'Palladio_Admin_Settings' ) ? Palladio_Admin_Settings::get( 'contact_text' ) : '';

		$emails   = class_exists( 'Palladio_Admin_Settings' ) ? Palladio_Admin_Settings::agency_emails() : array();
		$phone    = class_exists( 'Palladio_Admin_Settings' ) ? Palladio_Admin_Settings::get( 'agency_phone' ) : '';
		$whatsapp = class_exists( 'Palladio_Admin_Settings' ) ? Palladio_Admin_Settings::get( 'agency_whatsapp' ) : '';
		$has_side = $emails || $phone || $whatsapp;
		?>
		<section class="palladio-contact" id="palladio-contact">
			<div class="palladio-contact__wrap">
				<p class="palladio-contact__kicker" id="palladio-contact-eyebrow"><?php esc_html_e( 'Contatti', 'palladio' ); ?></p>
				<h2 class="palladio-contact__heading" id="palladio-contact-title"><?php echo esc_html( $heading ); ?></h2>
				<?php if ( $intro ) : ?>
					<p class="palladio-contact__intro" id="palladio-contact-text"><?php echo esc_html( $intro ); ?></p>
				<?php endif; ?>

				<div class="palladio-contact__grid<?php echo $has_side ? '' : ' palladio-contact__grid--single'; ?>">
					<div class="palladio-contact__form">
						<?php $this->render( $unit_id, $building_id ); ?>
					</div>

					<?php if ( $has_side ) : ?>
						<aside class="palladio-contact__aside" id="palladio-contact-agenzia">
							<h3 class="palladio-contact__aside-title"><?php esc_html_e( 'Parla con noi', 'palladio' ); ?></h3>
							<?php foreach ( $emails as $i => $email ) : ?>
								<a class="palladio-contact__channel" id="palladio-contact-mail-<?php echo esc_attr( $i + 1 ); ?>"
									href="mailto:<?php echo esc_attr( $email ); ?>"
									data-pll-track="email" data-pll-target="<?php echo esc_attr( $email ); ?>">
									<span class="palladio-contact__channel-icon" aria-hidden="true"><?php echo self::icon( 'email' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
									<span class="palladio-contact__channel-body"><b><?php esc_html_e( 'Scrivi una mail', 'palladio' ); ?></b><span><?php echo esc_html( $email ); ?></span></span>
								</a>
							<?php endforeach; ?>
							<?php if ( $phone ) : ?>
								<a class="palladio-contact__channel" id="palladio-contact-cell"
									href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $phone ) ); ?>"
									data-pll-track="telefono" data-pll-target="<?php echo esc_attr( $phone ); ?>">
									<span class="palladio-contact__channel-icon" aria-hidden="true"><?php echo self::icon( 'phone' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
									<span class="palladio-contact__channel-body"><b><?php esc_html_e( 'Chiama', 'palladio' ); ?></b><span><?php echo esc_html( $phone ); ?></span></span>
								</a>
							<?php endif; ?>
							<?php if ( $whatsapp ) : ?>
								<a class="palladio-contact__channel" id="palladio-contact-whatsapp"
									href="https://wa.me/<?php echo esc_attr( preg_replace( '/[^0-9]/', '', $whatsapp ) ); ?>" target="_blank" rel="noopener"
									data-pll-track="whatsapp" data-pll-target="<?php echo esc_attr( $whatsapp ); ?>">
									<span class="palladio-contact__channel-icon" aria-hidden="true"><?php echo self::icon( 'whatsapp' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
									<span class="palladio-contact__channel-body"><b><?php esc_html_e( 'WhatsApp', 'palladio' ); ?></b><span><?php echo esc_html( $whatsapp ); ?></span></span>
								</a>
							<?php endif; ?>
							<p class="palladio-contact__aside-note"><?php esc_html_e( 'Visite private su appuntamento.', 'palladio' ); ?></p>
						</aside>
					<?php endif; ?>
				</div>
			</div>
		</section>
		<?php
	}

	/**
	 * Shortcode [palladio_lead_form unita="123"].
	 *
	 * @param array $atts Attributi.
	 * @return string
	 */
	public function shortcode( $atts ) {
		$atts    = shortcode_atts( array( 'unita' => 0 ), $atts, 'palladio_lead_form' );
		$unit_id = absint( $atts['unita'] );

		if ( ! $unit_id && is_singular( 'pll_unita' ) ) {
			$unit_id = get_the_ID();
		}

		ob_start();
		$this->render( $unit_id );
		return (string) ob_get_clean();
	}

	/**
	 * Stampa il form nel contesto della landing edificio.
	 *
	 * @param int $building_id ID edificio.
	 * @return void
	 */
	public function render_building( $building_id ) {
		$this->render( 0, absint( $building_id ) );
	}

	/**
	 * Motivi selezionabili della richiesta.
	 *
	 * @return array<string,string>
	 */
	public static function motivi() {
		return array(
			'visita'       => __( 'Richiedere una visita in loco', 'palladio' ),
			'informazioni' => __( 'Informazioni generali', 'palladio' ),
			'altro'        => __( 'Altro', 'palladio' ),
		);
	}

	/**
	 * Stampa il form.
	 *
	 * @param int $unit_id     ID unità collegata (0 se generico).
	 * @param int $building_id ID edificio collegato (0 se generico).
	 * @return void
	 */
	public function render( $unit_id = 0, $building_id = 0 ) {
		$unit_id     = absint( $unit_id );
		$building_id = absint( $building_id );
		$notice      = isset( $_GET['palladio_lead'] ) ? sanitize_key( wp_unslash( $_GET['palladio_lead'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$gdpr        = $this->gdpr_text();
		?>
		<form class="palladio-lead-form" id="palladio-lead-form" method="post"
			action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">

			<?php if ( 'ok' === $notice ) : ?>
				<p class="palladio-notice palladio-notice--ok" role="status"><?php esc_html_e( 'Grazie! La tua richiesta è stata inviata.', 'palladio' ); ?></p>
			<?php elseif ( 'error' === $notice ) : ?>
				<p class="palladio-notice palladio-notice--error" role="alert"><?php esc_html_e( 'Controlla i campi obbligatori e il consenso, poi riprova.', 'palladio' ); ?></p>
			<?php endif; ?>

			<input type="hidden" name="action" value="palladio_submit_lead">
			<input type="hidden" name="unita_id" value="<?php echo esc_attr( $unit_id ); ?>">
			<input type="hidden" name="edificio_id" value="<?php echo esc_attr( $building_id ); ?>">
			<input type="hidden" name="redirect" value="<?php echo esc_url( $this->current_url() ); ?>">
			<input type="hidden" name="source" value="<?php echo esc_attr( $this->detect_source() ); ?>">
			<input type="hidden" name="utm_source" value="<?php echo esc_attr( $this->utm( 'utm_source' ) ); ?>">
			<input type="hidden" name="utm_medium" value="<?php echo esc_attr( $this->utm( 'utm_medium' ) ); ?>">
			<input type="hidden" name="utm_campaign" value="<?php echo esc_attr( $this->utm( 'utm_campaign' ) ); ?>">
			<input type="hidden" name="lang" value="<?php echo esc_attr( class_exists( 'Palladio_I18n_Languages' ) ? Palladio_I18n_Languages::current() : '' ); ?>">
			<?php wp_nonce_field( 'palladio_lead', 'palladio_lead_nonce' ); ?>

			<?php // Honeypot anti-spam: deve restare vuoto. ?>
			<div class="palladio-hp" aria-hidden="true">
				<label>Lascia vuoto questo campo
					<input type="text" name="palladio_hp" tabindex="-1" autocomplete="off" value="">
				</label>
			</div>

			<div class="palladio-lead-form__grid">
				<p class="palladio-field">
					<label for="pll-nome"><?php esc_html_e( 'Nome e cognome', 'palladio' ); ?> <span aria-hidden="true">*</span></label>
					<input type="text" id="pll-nome" name="nome" required>
				</p>
				<p class="palladio-field">
					<label for="pll-email"><?php esc_html_e( 'Email', 'palladio' ); ?> <span aria-hidden="true">*</span></label>
					<input type="email" id="pll-email" name="email" required>
				</p>
				<p class="palladio-field">
					<label for="pll-tel"><?php esc_html_e( 'Telefono', 'palladio' ); ?></label>
					<input type="tel" id="pll-tel" name="telefono">
				</p>
			</div>

			<fieldset class="palladio-field palladio-field--motivi">
				<legend><?php esc_html_e( 'Vorrei', 'palladio' ); ?></legend>
				<?php foreach ( self::motivi() as $slug => $label ) : ?>
					<label class="palladio-motivo">
						<input type="checkbox" name="motivo[]" value="<?php echo esc_attr( $slug ); ?>">
						<span><?php echo esc_html( $label ); ?></span>
					</label>
				<?php endforeach; ?>
			</fieldset>

			<p class="palladio-field">
				<label for="pll-note"><?php esc_html_e( 'Messaggio', 'palladio' ); ?></label>
				<textarea id="pll-note" name="note" rows="4"></textarea>
			</p>

			<p class="palladio-field palladio-field--consent">
				<label>
					<input type="checkbox" name="consenso_gdpr" value="1" required>
					<span><?php echo wp_kses_post( $gdpr ); ?></span>
				</label>
			</p>

			<p class="palladio-field">
				<button type="submit" class="palladio-cta"><?php esc_html_e( 'Invia richiesta', 'palladio' ); ?></button>
			</p>
		</form>
		<?php
	}

	/**
	 * Gestisce l'invio del form.
	 *
	 * @return void
	 */
	public function handle() {
		$redirect = isset( $_POST['redirect'] ) ? esc_url_raw( wp_unslash( $_POST['redirect'] ) ) : home_url( '/' );

		// Nonce.
		if (
			! isset( $_POST['palladio_lead_nonce'] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['palladio_lead_nonce'] ) ), 'palladio_lead' )
		) {
			$this->redirect_back( $redirect, 'error' );
		}

		// Honeypot: se compilato, scarta silenziosamente (finge successo).
		if ( ! empty( $_POST['palladio_hp'] ) ) {
			$this->redirect_back( $redirect, 'ok' );
		}

		$nome     = isset( $_POST['nome'] ) ? sanitize_text_field( wp_unslash( $_POST['nome'] ) ) : '';
		$email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$consenso = ! empty( $_POST['consenso_gdpr'] );

		// Validazione minima.
		if ( '' === $nome || ! is_email( $email ) || ! $consenso ) {
			$this->redirect_back( $redirect, 'error' );
		}

		$unit_id     = isset( $_POST['unita_id'] ) ? absint( wp_unslash( $_POST['unita_id'] ) ) : 0;
		$building_id = isset( $_POST['edificio_id'] ) ? absint( wp_unslash( $_POST['edificio_id'] ) ) : 0;

		// "Vorrei": checkbox multiple, facoltative.
		$motivi   = self::motivi();
		$selected = isset( $_POST['motivo'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['motivo'] ) ) : array();
		$selected = array_values( array_intersect( $selected, array_keys( $motivi ) ) );

		// Motivi e pagina di provenienza sono colonne strutturate del lead;
		// nelle note resta solo l'eventuale contesto edificio + il messaggio.
		$labels = array_map(
			static function ( $slug ) use ( $motivi ) {
				return $motivi[ $slug ];
			},
			$selected
		);

		$note = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		if ( $building_id ) {
			/* translators: %s: titolo edificio. */
			$note = sprintf( __( 'Edificio: %s', 'palladio' ), get_the_title( $building_id ) ) . ( $note ? "\n\n" . $note : '' );
		}

		$data = array(
			'source'         => isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( $_POST['source'] ) ) : 'form',
			'utm_source'     => isset( $_POST['utm_source'] ) ? sanitize_text_field( wp_unslash( $_POST['utm_source'] ) ) : '',
			'utm_medium'     => isset( $_POST['utm_medium'] ) ? sanitize_text_field( wp_unslash( $_POST['utm_medium'] ) ) : '',
			'utm_campaign'   => isset( $_POST['utm_campaign'] ) ? sanitize_text_field( wp_unslash( $_POST['utm_campaign'] ) ) : '',
			// Lingua della PAGINA da cui arriva la richiesta (it/en/de/fr).
			'lang'           => isset( $_POST['lang'] ) && '' !== $_POST['lang'] ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : substr( get_locale(), 0, 2 ),
			'nome'           => $nome,
			'email'          => $email,
			'telefono'       => isset( $_POST['telefono'] ) ? sanitize_text_field( wp_unslash( $_POST['telefono'] ) ) : '',
			'uso_previsto'   => '',
			'motivo'         => implode( ', ', $labels ),
			'pagina'         => $redirect,
			'note'           => $note,
			'unita_ids'      => $unit_id ? array( $unit_id ) : array(),
			'consenso_gdpr'  => true,
			'consenso_testo' => wp_strip_all_tags( $this->gdpr_text() ),
		);

		$lead_id = Palladio_Leads_Store::insert( $data );

		if ( ! $lead_id ) {
			$this->redirect_back( $redirect, 'error' );
		}

		/**
		 * Nuovo lead creato.
		 *
		 * @param int   $lead_id ID lead.
		 * @param array $data    Dati del lead.
		 */
		do_action( 'palladio/lead_created', $lead_id, $data );

		$this->notify( $lead_id, $data, $unit_id, $building_id );

		$this->redirect_back( $redirect, 'ok' );
	}

	/**
	 * Invia la notifica email del nuovo lead alla regia/agenzia.
	 *
	 * Destinatari, in ordine di priorità: elenco configurato in
	 * Palladio → Impostazioni; email di contatto dell'edificio; email admin.
	 *
	 * @param int   $lead_id     ID lead.
	 * @param array $data        Dati lead.
	 * @param int   $unit_id     ID unità collegata.
	 * @param int   $building_id ID edificio collegato.
	 * @return void
	 */
	private function notify( $lead_id, $data, $unit_id, $building_id = 0 ) {
		$recipient = array();

		if ( class_exists( 'Palladio_Admin_Settings' ) ) {
			$recipient = Palladio_Admin_Settings::notify_emails();
		}

		if ( ! $recipient ) {
			if ( ! $building_id && $unit_id ) {
				$building_id = wp_get_post_parent_id( $unit_id );
			}
			$building_email = $building_id ? get_post_meta( $building_id, '_pll_contatto_email', true ) : '';
			$recipient      = is_email( $building_email ) ? array( $building_email ) : array( get_option( 'admin_email' ) );
		}

		/**
		 * Filtra i destinatari della notifica lead.
		 *
		 * @param string|string[] $recipient Email destinatari.
		 * @param int             $lead_id   ID lead.
		 */
		$recipient = apply_filters( 'palladio/lead/notify_recipient', $recipient, $lead_id );

		if ( $unit_id ) {
			$subject_label = get_the_title( $unit_id );
		} elseif ( $building_id ) {
			$subject_label = get_the_title( $building_id );
		} else {
			$subject_label = __( '(richiesta generica)', 'palladio' );
		}
		$unit_label = $unit_id ? get_the_title( $unit_id ) : __( '(nessuna unità)', 'palladio' );

		/* translators: %s: titolo dell'unità o edificio. */
		$subject = sprintf( __( 'Nuovo lead Palladio: %s', 'palladio' ), $subject_label );

		$lines = array(
			sprintf( __( 'Nome: %s', 'palladio' ), $data['nome'] ),
			sprintf( __( 'Email: %s', 'palladio' ), $data['email'] ),
			sprintf( __( 'Telefono: %s', 'palladio' ), $data['telefono'] ),
			sprintf( __( 'Unità: %s', 'palladio' ), $unit_label ),
			'',
			__( 'Messaggio:', 'palladio' ),
			$data['note'],
			'',
			sprintf( __( 'Gestisci il lead: %s', 'palladio' ), admin_url( 'edit.php?post_type=pll_edificio&page=palladio-leads' ) ),
		);

		$headers = $this->from_headers();
		if ( is_email( $data['email'] ) ) {
			$headers[] = 'Reply-To: ' . $data['nome'] . ' <' . $data['email'] . '>';
		}

		wp_mail( $recipient, $subject, implode( "\n", $lines ), $headers );

		// Risposta automatica al cliente, nella lingua della pagina.
		$this->send_auto_reply( $data, $unit_label );
	}

	/**
	 * Header From configurato in Impostazioni (es. vendita@dominio.it).
	 *
	 * @return string[]
	 */
	private function from_headers() {
		$sender = class_exists( 'Palladio_Admin_Settings' ) ? Palladio_Admin_Settings::get( 'sender_email' ) : '';
		if ( ! is_email( $sender ) ) {
			return array();
		}

		return array( 'From: ' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . ' <' . $sender . '>' );
	}

	/**
	 * Traduce un testo nella lingua del lead (identità se sorgente).
	 *
	 * @param string $text Testo sorgente.
	 * @param string $lang Lingua del lead.
	 * @return string
	 */
	private function t( $text, $lang ) {
		return class_exists( 'Palladio_I18n_Strings' ) ? Palladio_I18n_Strings::translate_text_in( $text, $lang ) : $text;
	}

	/**
	 * Email automatica di cortesia al cliente: riepilogo dei dati inseriti,
	 * contatti dell'agenzia e promessa di ricontatto — nella lingua della
	 * pagina da cui è partita la richiesta.
	 *
	 * @param array  $data       Dati del lead.
	 * @param string $unit_label Etichetta unità (per il riepilogo).
	 * @return void
	 */
	private function send_auto_reply( $data, $unit_label ) {
		if ( ! is_email( $data['email'] ) ) {
			return;
		}

		$lang = (string) ( $data['lang'] ?? '' );
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		$subject = $this->t( 'Abbiamo ricevuto la tua richiesta', $lang ) . ' — ' . $site;

		$lines   = array();
		$lines[] = sprintf( $this->t( 'Gentile %s,', $lang ), $data['nome'] );
		$lines[] = $this->t( 'grazie per averci contattato: la tua richiesta è stata ricevuta correttamente e verrai ricontattato nel minor tempo possibile.', $lang );
		$lines[] = '';
		$lines[] = $this->t( 'Riepilogo della tua richiesta', $lang ) . ':';
		$lines[] = '· ' . $this->t( 'Nome e cognome', $lang ) . ': ' . $data['nome'];
		$lines[] = '· ' . $this->t( 'Email', $lang ) . ': ' . $data['email'];
		if ( '' !== $data['telefono'] ) {
			$lines[] = '· ' . $this->t( 'Telefono', $lang ) . ': ' . $data['telefono'];
		}
		if ( ! empty( $data['unita_ids'] ) ) {
			$lines[] = '· ' . $this->t( 'Unità', $lang ) . ': ' . $unit_label;
		}
		if ( '' !== $data['motivo'] ) {
			// Ogni motivo tradotto singolarmente nella lingua del cliente.
			$motivi  = array_map( function ( $m ) use ( $lang ) {
				return $this->t( trim( $m ), $lang );
			}, explode( ',', $data['motivo'] ) );
			$lines[] = '· ' . $this->t( 'Vorrei', $lang ) . ': ' . implode( ', ', $motivi );
		}
		if ( '' !== trim( (string) $data['note'] ) ) {
			$lines[] = '· ' . $this->t( 'Messaggio', $lang ) . ': ' . $data['note'];
		}

		// Contatti dell'agenzia.
		$emails   = class_exists( 'Palladio_Admin_Settings' ) ? Palladio_Admin_Settings::agency_emails() : array();
		$phone    = class_exists( 'Palladio_Admin_Settings' ) ? Palladio_Admin_Settings::get( 'agency_phone' ) : '';
		$whatsapp = class_exists( 'Palladio_Admin_Settings' ) ? Palladio_Admin_Settings::get( 'agency_whatsapp' ) : '';
		$sender   = class_exists( 'Palladio_Admin_Settings' ) ? Palladio_Admin_Settings::get( 'sender_email' ) : '';

		if ( $emails || $phone || $whatsapp ) {
			$lines[] = '';
			$lines[] = $this->t( 'I nostri contatti', $lang ) . ':';
			foreach ( $emails as $agency_email ) {
				$lines[] = '· ' . $this->t( 'Email', $lang ) . ': ' . $agency_email;
			}
			if ( $phone ) {
				$lines[] = '· ' . $this->t( 'Telefono', $lang ) . ': ' . $phone;
			}
			if ( $whatsapp ) {
				$lines[] = '· WhatsApp: ' . $whatsapp;
			}
			$lines[] = $this->t( 'Visite private su appuntamento.', $lang );
		}

		$lines[] = '';
		$lines[] = $this->t( 'A presto,', $lang );
		$lines[] = $site;

		$headers = $this->from_headers();
		if ( is_email( $sender ) ) {
			$headers[] = 'Reply-To: ' . $site . ' <' . $sender . '>';
		}

		wp_mail( $data['email'], $subject, implode( "\n", $lines ), $headers );
	}

	/**
	 * Testo del consenso GDPR (opzione configurabile).
	 *
	 * @return string
	 */
	private function gdpr_text() {
		$default = sprintf(
			/* translators: %s: URL della pagina privacy. */
			__( 'Ho letto e accetto l’<a href="%s" target="_blank" rel="noopener">informativa sulla privacy</a> e autorizzo il trattamento dei miei dati per rispondere alla richiesta.', 'palladio' ),
			esc_url( home_url( '/privacy' ) )
		);
		$text = get_option( 'palladio_gdpr_text', '' );
		return $text ? $text : $default;
	}

	/**
	 * Rileva la fonte del lead (default 'form').
	 *
	 * @return string
	 */
	private function detect_source() {
		$utm = $this->utm( 'utm_source' );
		return $utm ? $utm : 'form';
	}

	/**
	 * Legge un parametro UTM dalla query string.
	 *
	 * @param string $key Nome parametro.
	 * @return string
	 */
	private function utm( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
	}

	/**
	 * URL corrente (per il PRG dopo l'invio).
	 *
	 * @return string
	 */
	private function current_url() {
		if ( is_singular() ) {
			$permalink = get_permalink();
			if ( $permalink ) {
				return $permalink;
			}
		}
		return home_url( add_query_arg( array() ) );
	}

	/**
	 * Redirect Post/Redirect/Get con esito e chiude l'esecuzione.
	 *
	 * @param string $url    URL base.
	 * @param string $status 'ok' | 'error'.
	 * @return void
	 */
	private function redirect_back( $url, $status ) {
		// Àncora alla sezione contatti: l'utente atterra sul titolo con il
		// messaggio di esito ben visibile PRIMA del form.
		$url = add_query_arg( 'palladio_lead', $status, $url ) . '#palladio-contact';
		wp_safe_redirect( $url );
		exit;
	}
}
