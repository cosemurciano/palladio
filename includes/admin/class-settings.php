<?php
/**
 * Modulo Admin — Impostazioni generali del plugin.
 *
 * Ultima voce del menu Palladio: CTA dossier configurabile (testo e URL o
 * àncora), destinatari delle richieste dal form contatti dell'edificio,
 * testi del form e consenso GDPR. Le richieste inviate sono archiviate nei
 * Lead (Palladio → Lead).
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pagina Impostazioni e accessor della configurazione.
 */
class Palladio_Admin_Settings {

	const CAP    = 'manage_palladio';
	const OPTION = 'palladio_settings';

	/**
	 * Registra menu e salvataggio.
	 *
	 * @return void
	 */
	public function register() {
		if ( ! is_admin() ) {
			return;
		}
		// Priorità alta: la voce compare per ultima nel menu Palladio.
		add_action( 'admin_menu', array( $this, 'menu' ), 999 );
		add_action( 'admin_post_palladio_save_settings', array( $this, 'save' ) );
	}

	/**
	 * Configurazione con default.
	 *
	 * @return array
	 */
	public static function config() {
		$defaults = array(
			'dossier_label'   => __( 'Richiedi una visita', 'palladio' ),
			'dossier_url'     => '', // Vuoto = àncora al form contatti (#palladio-contact).
			'notify_emails'   => '',
			'contact_heading' => __( 'Richiedi una visita o informazioni', 'palladio' ),
			'contact_text'    => __( 'Lascia i tuoi recapiti: ti ricontattiamo per una visita in loco, il dossier completo o qualsiasi domanda.', 'palladio' ),
		);

		$config = get_option( self::OPTION, array() );
		$config = wp_parse_args( is_array( $config ) ? $config : array(), $defaults );

		// Migrazione: il vecchio testo di default diventa "Richiedi una visita".
		if ( 'Richiedi il dossier' === $config['dossier_label'] ) {
			$config['dossier_label'] = $defaults['dossier_label'];
		}

		return $config;
	}

	/**
	 * Legge una singola impostazione.
	 *
	 * @param string $key Chiave.
	 * @return string
	 */
	public static function get( $key ) {
		$config = self::config();
		return isset( $config[ $key ] ) ? (string) $config[ $key ] : '';
	}

	/**
	 * Destinatari delle notifiche richieste (array di email valide).
	 *
	 * @return string[]
	 */
	public static function notify_emails() {
		$raw    = self::get( 'notify_emails' );
		$parts  = preg_split( '/[\s,;]+/', $raw );
		$emails = array();
		foreach ( (array) $parts as $part ) {
			$email = sanitize_email( trim( (string) $part ) );
			if ( is_email( $email ) ) {
				$emails[] = $email;
			}
		}
		return array_values( array_unique( $emails ) );
	}

	/**
	 * Aggiunge la voce "Impostazioni" (ultima del menu Palladio).
	 *
	 * @return void
	 */
	public function menu() {
		add_submenu_page(
			'edit.php?post_type=pll_edificio',
			__( 'Impostazioni', 'palladio' ),
			__( 'Impostazioni', 'palladio' ),
			self::CAP,
			'palladio-settings',
			array( $this, 'page' )
		);
	}

	/**
	 * Renderizza la pagina Impostazioni.
	 *
	 * @return void
	 */
	public function page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permesso negato.', 'palladio' ) );
		}

		$config = self::config();
		$gdpr   = get_option( 'palladio_gdpr_text', '' );
		$saved  = isset( $_GET['palladio_msg'] ) ? sanitize_key( wp_unslash( $_GET['palladio_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Palladio — Impostazioni', 'palladio' ); ?></h1>

			<?php if ( 'saved' === $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Impostazioni salvate.', 'palladio' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="palladio_save_settings">
				<?php wp_nonce_field( 'palladio_settings' ); ?>

				<h2><?php esc_html_e( 'Pulsante “Richiedi una visita”', 'palladio' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pll-set-dlabel"><?php esc_html_e( 'Testo del pulsante', 'palladio' ); ?></label></th>
						<td><input type="text" id="pll-set-dlabel" name="dossier_label" class="regular-text" value="<?php echo esc_attr( $config['dossier_label'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="pll-set-durl"><?php esc_html_e( 'URL o àncora', 'palladio' ); ?></label></th>
						<td>
							<input type="text" id="pll-set-durl" name="dossier_url" class="regular-text" value="<?php echo esc_attr( $config['dossier_url'] ); ?>" placeholder="#palladio-contact">
							<p class="description"><?php esc_html_e( 'Un URL completo (es. il PDF del dossier o una pagina dedicata) oppure un’àncora (es. #palladio-contact). Vuoto = porta al form contatti in fondo alla pagina.', 'palladio' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Form contatti (pagina Edificio)', 'palladio' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pll-set-cheading"><?php esc_html_e( 'Titolo della sezione', 'palladio' ); ?></label></th>
						<td><input type="text" id="pll-set-cheading" name="contact_heading" class="large-text" value="<?php echo esc_attr( $config['contact_heading'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="pll-set-ctext"><?php esc_html_e( 'Testo introduttivo', 'palladio' ); ?></label></th>
						<td><textarea id="pll-set-ctext" name="contact_text" class="large-text" rows="3"><?php echo esc_textarea( $config['contact_text'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="pll-set-emails"><?php esc_html_e( 'Email destinatarie delle richieste', 'palladio' ); ?></label></th>
						<td>
							<textarea id="pll-set-emails" name="notify_emails" class="large-text" rows="3" placeholder="agenzia@example.com, regia@example.com"><?php echo esc_textarea( $config['notify_emails'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Una o più email, separate da virgola o a capo. Se vuoto: email di contatto dell’edificio, poi email amministratore. Tutte le richieste restano comunque archiviate in Palladio → Lead.', 'palladio' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pll-set-gdpr"><?php esc_html_e( 'Testo consenso GDPR', 'palladio' ); ?></label></th>
						<td><textarea id="pll-set-gdpr" name="gdpr_text" class="large-text" rows="2" placeholder="<?php esc_attr_e( 'Ho letto e accetto l’informativa sulla privacy…', 'palladio' ); ?>"><?php echo esc_textarea( $gdpr ); ?></textarea></td>
					</tr>
				</table>

				<?php submit_button( __( 'Salva impostazioni', 'palladio' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Salva le impostazioni.
	 *
	 * @return void
	 */
	public function save() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permesso negato.', 'palladio' ) );
		}

		check_admin_referer( 'palladio_settings' );

		$url = isset( $_POST['dossier_url'] ) ? trim( (string) wp_unslash( $_POST['dossier_url'] ) ) : '';
		// Consenti sia URL completi sia àncore (#...).
		$url = ( '' !== $url && '#' === $url[0] ) ? sanitize_text_field( $url ) : esc_url_raw( $url );

		$config = array(
			'dossier_label'   => isset( $_POST['dossier_label'] ) ? sanitize_text_field( wp_unslash( $_POST['dossier_label'] ) ) : '',
			'dossier_url'     => $url,
			'notify_emails'   => isset( $_POST['notify_emails'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notify_emails'] ) ) : '',
			'contact_heading' => isset( $_POST['contact_heading'] ) ? sanitize_text_field( wp_unslash( $_POST['contact_heading'] ) ) : '',
			'contact_text'    => isset( $_POST['contact_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['contact_text'] ) ) : '',
		);

		update_option( self::OPTION, $config );

		if ( isset( $_POST['gdpr_text'] ) ) {
			update_option( 'palladio_gdpr_text', sanitize_textarea_field( wp_unslash( $_POST['gdpr_text'] ) ) );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type'    => 'pll_edificio',
					'page'         => 'palladio-settings',
					'palladio_msg' => 'saved',
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}
}
