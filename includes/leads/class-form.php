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

		// Iniezione automatica nel pannello contatti dell'unità (Presenter).
		add_action( 'palladio/unita/after_contact', array( $this, 'render' ) );

		// Form contatti in chiusura della landing dell'edificio.
		add_action( 'palladio/edificio/contact_form', array( $this, 'render_building' ) );

		// Handler invio (utenti loggati e non).
		add_action( 'admin_post_nopriv_palladio_submit_lead', array( $this, 'handle' ) );
		add_action( 'admin_post_palladio_submit_lead', array( $this, 'handle' ) );
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
			'dossier'      => __( 'Ricevere il dossier completo', 'palladio' ),
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
		$usi         = Palladio_Leads_Store::usi();
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
				<p class="palladio-field">
					<label for="pll-uso"><?php esc_html_e( 'Uso previsto', 'palladio' ); ?></label>
					<select id="pll-uso" name="uso_previsto">
						<option value=""><?php esc_html_e( '—', 'palladio' ); ?></option>
						<?php foreach ( $usi as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p class="palladio-field">
					<label for="pll-motivo"><?php esc_html_e( 'Vorrei', 'palladio' ); ?></label>
					<select id="pll-motivo" name="motivo">
						<?php foreach ( self::motivi() as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
			</div>

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

		$motivi = self::motivi();
		$motivo = isset( $_POST['motivo'] ) ? sanitize_key( wp_unslash( $_POST['motivo'] ) ) : '';
		$motivo = isset( $motivi[ $motivo ] ) ? $motivo : '';

		// Contesto della richiesta in testa alle note, così resta leggibile
		// nell'archivio lead (Palladio → Lead).
		$note    = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		$context = array();
		if ( $motivo ) {
			/* translators: %s: motivo della richiesta. */
			$context[] = sprintf( __( 'Richiesta: %s', 'palladio' ), $motivi[ $motivo ] );
		}
		if ( $building_id ) {
			/* translators: %s: titolo edificio. */
			$context[] = sprintf( __( 'Edificio: %s', 'palladio' ), get_the_title( $building_id ) );
		}
		if ( $context ) {
			$note = implode( "\n", $context ) . ( $note ? "\n\n" . $note : '' );
		}

		$data = array(
			'source'         => isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( $_POST['source'] ) ) : 'form',
			'utm_source'     => isset( $_POST['utm_source'] ) ? sanitize_text_field( wp_unslash( $_POST['utm_source'] ) ) : '',
			'utm_medium'     => isset( $_POST['utm_medium'] ) ? sanitize_text_field( wp_unslash( $_POST['utm_medium'] ) ) : '',
			'utm_campaign'   => isset( $_POST['utm_campaign'] ) ? sanitize_text_field( wp_unslash( $_POST['utm_campaign'] ) ) : '',
			'lang'           => get_locale(),
			'nome'           => $nome,
			'email'          => $email,
			'telefono'       => isset( $_POST['telefono'] ) ? sanitize_text_field( wp_unslash( $_POST['telefono'] ) ) : '',
			'uso_previsto'   => isset( $_POST['uso_previsto'] ) ? sanitize_key( wp_unslash( $_POST['uso_previsto'] ) ) : '',
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
			sprintf( __( 'Uso previsto: %s', 'palladio' ), $data['uso_previsto'] ),
			sprintf( __( 'Unità: %s', 'palladio' ), $unit_label ),
			'',
			__( 'Messaggio:', 'palladio' ),
			$data['note'],
			'',
			sprintf( __( 'Gestisci il lead: %s', 'palladio' ), admin_url( 'edit.php?post_type=pll_edificio&page=palladio-leads' ) ),
		);

		$headers = array();
		if ( is_email( $data['email'] ) ) {
			$headers[] = 'Reply-To: ' . $data['nome'] . ' <' . $data['email'] . '>';
		}

		wp_mail( $recipient, $subject, implode( "\n", $lines ), $headers );
	}

	/**
	 * Testo del consenso GDPR (opzione configurabile).
	 *
	 * @return string
	 */
	private function gdpr_text() {
		$default = __( 'Ho letto e accetto l’informativa sulla privacy e autorizzo il trattamento dei miei dati per rispondere alla richiesta.', 'palladio' );
		$text    = get_option( 'palladio_gdpr_text', '' );
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
		$url = add_query_arg( 'palladio_lead', $status, $url ) . '#palladio-lead-form';
		wp_safe_redirect( $url );
		exit;
	}
}
