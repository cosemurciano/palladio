<?php
/**
 * Modulo Lingue — testi statici dei template per lingua.
 *
 * Le etichette fisse del frontend (campi del form, titoli di sezione come
 * "Galleria" o "Planimetria", voci del tema) sono in gettext con dominio
 * 'palladio' o 'poetheme'. Qui vengono tradotte PER LINGUA DELLA PAGINA,
 * non per locale del sito: un filtro gettext sostituisce la stringa quando
 * la pagina visitata è in una lingua diversa dalla sorgente.
 *
 * Scelta progettuale: dizionario in option (seed già tradotto per EN/DE/FR)
 * invece di file .po/.mo. Motivi: i .mo sono legati al locale di WordPress
 * (unico per richiesta, non per pagina), non sono modificabili dall'admin e
 * richiedono compilazione; il dizionario è editabile dalla pagina
 * Palladio → Testi statici, traducibile con l'AI in un colpo solo e vale
 * per plugin e tema insieme. Le stringhe incontrate sul frontend e non
 * ancora tradotte vengono annotate automaticamente nel catalogo.
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dizionario dei testi statici per lingua.
 */
class Palladio_I18n_Strings {

	const CAP            = 'manage_palladio';
	const OPTION         = 'palladio_strings';        // [lang][originale] => traduzione.
	const CATALOG_OPTION = 'palladio_strings_catalog'; // [originale] => 1.
	const CATALOG_MAX    = 500;

	/**
	 * Domini gettext coperti (plugin + tema).
	 *
	 * @var string[]
	 */
	private $domains = array( 'palladio', 'poetheme' );

	/**
	 * Stringhe raccolte durante la richiesta (per il catalogo).
	 *
	 * @var array<string,bool>
	 */
	private $seen = array();

	/**
	 * Registra gli hook.
	 *
	 * @return void
	 */
	public function register() {
		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'menu' ), 997 );
			add_action( 'admin_post_palladio_save_strings', array( $this, 'save' ) );
			add_action( 'admin_post_palladio_ai_strings', array( $this, 'ai_translate' ) );
			return;
		}

		add_filter( 'gettext', array( $this, 'translate' ), 20, 3 );
		add_action( 'shutdown', array( $this, 'flush_catalog' ) );
		add_action( 'wp', array( $this, 'maybe_switch_locale' ) );
	}

	/**
	 * Locale coerente con la lingua della pagina (per stringhe core: date...).
	 *
	 * @return void
	 */
	public function maybe_switch_locale() {
		$current = Palladio_I18n_Languages::current();
		if ( $current === Palladio_I18n_Languages::source() ) {
			return;
		}

		$map = array( 'it' => 'it_IT', 'en' => 'en_US', 'de' => 'de_DE', 'fr' => 'fr_FR' );
		if ( isset( $map[ $current ] ) && get_locale() !== $map[ $current ] ) {
			switch_to_locale( $map[ $current ] );
		}
	}

	/**
	 * Dizionario completo (con seed di fabbrica).
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function dictionary() {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();

		$dict = self::seed();
		foreach ( $saved as $lang => $pairs ) {
			foreach ( (array) $pairs as $original => $translation ) {
				if ( '' !== (string) $translation ) {
					$dict[ $lang ][ $original ] = (string) $translation;
				}
			}
		}

		return $dict;
	}

	/**
	 * Filtro gettext: sulla pagina in lingua sostituisce i testi statici.
	 *
	 * @param string $translation Traduzione corrente.
	 * @param string $text        Stringa originale.
	 * @param string $domain      Text domain.
	 * @return string
	 */
	public function translate( $translation, $text, $domain ) {
		if ( ! in_array( $domain, $this->domains, true ) ) {
			return $translation;
		}

		$current = Palladio_I18n_Languages::current();
		if ( $current === Palladio_I18n_Languages::source() ) {
			return $translation;
		}

		$dict = self::dictionary();
		if ( isset( $dict[ $current ][ $text ] ) ) {
			return $dict[ $current ][ $text ];
		}

		// Non tradotta: annotala nel catalogo per l'elenco in admin.
		$this->seen[ $text ] = true;

		return $translation;
	}

	/**
	 * Salva a fine richiesta le stringhe nuove incontrate.
	 *
	 * @return void
	 */
	public function flush_catalog() {
		if ( ! $this->seen ) {
			return;
		}

		$catalog = get_option( self::CATALOG_OPTION, array() );
		$catalog = is_array( $catalog ) ? $catalog : array();
		$dirty   = false;

		foreach ( array_keys( $this->seen ) as $text ) {
			if ( ! isset( $catalog[ $text ] ) && count( $catalog ) < self::CATALOG_MAX ) {
				$catalog[ $text ] = 1;
				$dirty            = true;
			}
		}

		if ( $dirty ) {
			update_option( self::CATALOG_OPTION, $catalog, false );
		}
	}

	/**
	 * Catalogo delle stringhe da tradurre: seed + incontrate sul frontend.
	 *
	 * @return string[]
	 */
	public static function catalog() {
		$catalog = array_keys( self::seed()['en'] );

		$recorded = get_option( self::CATALOG_OPTION, array() );
		if ( is_array( $recorded ) ) {
			$catalog = array_merge( $catalog, array_keys( $recorded ) );
		}

		$catalog = array_values( array_unique( $catalog ) );
		sort( $catalog, SORT_NATURAL | SORT_FLAG_CASE );

		return $catalog;
	}

	// -------------------------------------------------------------------------
	// Admin.
	// -------------------------------------------------------------------------

	/**
	 * Voce di menu "Testi statici".
	 *
	 * @return void
	 */
	public function menu() {
		add_submenu_page(
			'edit.php?post_type=pll_edificio',
			__( 'Testi statici', 'palladio' ),
			__( 'Testi statici', 'palladio' ),
			self::CAP,
			'palladio-strings',
			array( $this, 'page' )
		);
	}

	/**
	 * Pagina admin: elenco per lingua con traduzione manuale o AI.
	 *
	 * @return void
	 */
	public function page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permesso negato.', 'palladio' ) );
		}

		$catalog_langs = Palladio_I18n_Languages::catalog();
		$active        = Palladio_I18n_Languages::active();
		$source        = Palladio_I18n_Languages::source();
		$langs         = array_values( array_diff( $active, array( $source ) ) );

		$lang = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : ( $langs ? $langs[0] : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $lang, $langs, true ) ) {
			$lang = $langs ? $langs[0] : '';
		}

		$dict    = self::dictionary();
		$strings = self::catalog();
		$msg     = isset( $_GET['palladio_msg'] ) ? sanitize_key( wp_unslash( $_GET['palladio_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Palladio — Testi statici', 'palladio' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Etichette fisse dei template (form contatti, titoli di sezione, voci del tema) tradotte per lingua della pagina. Le stringhe non ancora tradotte incontrate sul sito vengono aggiunte all’elenco automaticamente.', 'palladio' ); ?></p>

			<?php if ( 'saved' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Testi salvati.', 'palladio' ); ?></p></div>
			<?php elseif ( 'translated' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Traduzione AI completata: controlla e salva eventuali ritocchi.', 'palladio' ); ?></p></div>
			<?php elseif ( 'error' === $msg ) : ?>
				<?php $err = get_transient( 'palladio_strings_error_' . get_current_user_id() ); delete_transient( 'palladio_strings_error_' . get_current_user_id() ); ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $err ? $err : __( 'Operazione non riuscita.', 'palladio' ) ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! $langs ) : ?>
				<p><?php esc_html_e( 'Nessuna lingua aggiuntiva attiva: attivale in Palladio → Lingue.', 'palladio' ); ?></p></div>
				<?php
				return;
			endif;
			?>

			<h2 class="nav-tab-wrapper">
				<?php foreach ( $langs as $l ) : ?>
					<a class="nav-tab <?php echo $l === $lang ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'edit.php?post_type=pll_edificio&page=palladio-strings&lang=' . $l ) ); ?>">
						<?php echo esc_html( Palladio_Admin_Translations::flag( $l ) . ' ' . ( $catalog_langs[ $l ] ?? strtoupper( $l ) ) ); ?>
					</a>
				<?php endforeach; ?>
			</h2>

			<p style="margin-top:1em;">
				<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'palladio_ai_strings', 'lang' => $lang ), admin_url( 'admin-post.php' ) ), 'palladio_ai_strings_' . $lang ) ); ?>"
					onclick="this.textContent='<?php echo esc_js( __( 'Traduzione in corso…', 'palladio' ) ); ?>';this.style.pointerEvents='none';">
					<?php
					/* translators: %s: lingua. */
					printf( esc_html__( 'Traduci tutte le voci mancanti in %s (AI)', 'palladio' ), esc_html( $catalog_langs[ $lang ] ?? strtoupper( $lang ) ) );
					?>
				</a>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'palladio_save_strings' ); ?>
				<input type="hidden" name="action" value="palladio_save_strings">
				<input type="hidden" name="lang" value="<?php echo esc_attr( $lang ); ?>">

				<table class="widefat striped" style="max-width:1100px;">
					<thead><tr>
						<th style="width:50%;"><?php echo esc_html( $catalog_langs[ $source ] ?? strtoupper( $source ) ); ?> (<?php esc_html_e( 'originale', 'palladio' ); ?>)</th>
						<th><?php echo esc_html( $catalog_langs[ $lang ] ?? strtoupper( $lang ) ); ?></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $strings as $i => $original ) : ?>
							<tr>
								<td><?php echo esc_html( $original ); ?><input type="hidden" name="original[<?php echo (int) $i; ?>]" value="<?php echo esc_attr( $original ); ?>"></td>
								<td><input type="text" class="widefat" name="translation[<?php echo (int) $i; ?>]" value="<?php echo esc_attr( $dict[ $lang ][ $original ] ?? '' ); ?>"></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php submit_button( __( 'Salva testi', 'palladio' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Salvataggio manuale.
	 *
	 * @return void
	 */
	public function save() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permesso negato.', 'palladio' ) );
		}
		check_admin_referer( 'palladio_save_strings' );

		$lang = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '';

		$originals    = isset( $_POST['original'] ) ? (array) wp_unslash( $_POST['original'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$translations = isset( $_POST['translation'] ) ? (array) wp_unslash( $_POST['translation'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();

		foreach ( $originals as $i => $original ) {
			$original    = (string) $original;
			$translation = isset( $translations[ $i ] ) ? wp_kses_post( (string) $translations[ $i ] ) : '';
			if ( '' === trim( $translation ) ) {
				unset( $saved[ $lang ][ $original ] );
			} else {
				$saved[ $lang ][ $original ] = $translation;
			}
		}

		update_option( self::OPTION, $saved, false );

		wp_safe_redirect( admin_url( 'edit.php?post_type=pll_edificio&page=palladio-strings&lang=' . $lang . '&palladio_msg=saved' ) );
		exit;
	}

	/**
	 * Traduzione AI di tutte le voci mancanti nella lingua.
	 *
	 * @return void
	 */
	public function ai_translate() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permesso negato.', 'palladio' ) );
		}
		$lang = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : '';
		check_admin_referer( 'palladio_ai_strings_' . $lang );

		$dict    = self::dictionary();
		$missing = array();
		foreach ( self::catalog() as $original ) {
			if ( ! isset( $dict[ $lang ][ $original ] ) ) {
				$missing[] = $original;
			}
		}

		if ( ! $missing ) {
			wp_safe_redirect( admin_url( 'edit.php?post_type=pll_edificio&page=palladio-strings&lang=' . $lang . '&palladio_msg=translated' ) );
			exit;
		}

		$catalog_langs = Palladio_I18n_Languages::catalog();
		$instructions  = sprintf(
			'Sei un traduttore madrelingua %1$s specializzato in interfacce web del settore immobiliare di pregio. ' .
			'Traduci le etichette dal %2$s al %1$s: brevi, naturali, coerenti col tono luxury real estate. ' .
			'Conserva INALTERATI i segnaposto come %%s, %%1$s e i tag HTML. ' .
			'Rispondi SOLO con un oggetto JSON {"originale": "traduzione", ...} con TUTTE le voci ricevute.',
			$catalog_langs[ $lang ] ?? $lang,
			$catalog_langs[ Palladio_I18n_Languages::source() ] ?? Palladio_I18n_Languages::source()
		);

		$result = Palladio_AI_Openai::responses(
			$instructions,
			"Traduci il seguente JSON di etichette e restituisci SOLO il JSON tradotto:\n" . wp_json_encode( array_values( $missing ), JSON_UNESCAPED_UNICODE ),
			array( 'json' => true, 'max_tokens' => 8000, 'timeout' => 180 )
		);

		if ( is_wp_error( $result ) ) {
			set_transient( 'palladio_strings_error_' . get_current_user_id(), $result->get_error_message(), 120 );
			wp_safe_redirect( admin_url( 'edit.php?post_type=pll_edificio&page=palladio-strings&lang=' . $lang . '&palladio_msg=error' ) );
			exit;
		}

		$translated = json_decode( (string) $result['text'], true );
		if ( is_array( $translated ) ) {
			$saved = get_option( self::OPTION, array() );
			$saved = is_array( $saved ) ? $saved : array();
			foreach ( $translated as $original => $translation ) {
				if ( is_string( $original ) && is_string( $translation ) && '' !== trim( $translation ) && in_array( $original, $missing, true ) ) {
					$saved[ $lang ][ $original ] = wp_kses_post( $translation );
				}
			}
			update_option( self::OPTION, $saved, false );
		}

		wp_safe_redirect( admin_url( 'edit.php?post_type=pll_edificio&page=palladio-strings&lang=' . $lang . '&palladio_msg=translated' ) );
		exit;
	}

	/**
	 * Seed di fabbrica: le etichette frontend principali già tradotte.
	 *
	 * @return array<string,array<string,string>>
	 */
	private static function seed() {
		return array(
			'en' => array(
				'Richiedi una visita'                  => 'Book a visit',
				'Richiedi una visita o informazioni'   => 'Book a visit or request information',
				'Nome e cognome'                       => 'Full name',
				'Email'                                => 'Email',
				'Telefono'                             => 'Phone',
				'Messaggio'                            => 'Message',
				'Vorrei'                               => 'I would like',
				'Richiedere una visita in loco'        => 'To book an on-site visit',
				'Informazioni generali'                => 'General information',
				'Altro'                                => 'Other',
				'Invia richiesta'                      => 'Send request',
				'Grazie! La tua richiesta è stata inviata.' => 'Thank you! Your request has been sent.',
				'Controlla i campi obbligatori e il consenso, poi riprova.' => 'Please check the required fields and the consent, then try again.',
				'Parla con noi'                        => 'Talk to us',
				'Contatti'                             => 'Contacts',
				'Scrivi una mail'                      => 'Write an email',
				'Chiama'                               => 'Call',
				'WhatsApp'                             => 'WhatsApp',
				'Visite private su appuntamento.'      => 'Private visits by appointment.',
				'Galleria'                             => 'Gallery',
				'In luce'                              => 'In the light',
				'Il palazzo in luce'                   => 'The palazzo in the light',
				'Planimetria'                          => 'Floor plan',
				'La pianta, quotata'                   => 'The plan, with measurements',
				'Posizione nell’edificio'              => 'Position in the building',
				'Esplora l’edificio'                   => 'Explore the building',
				'Video'                                => 'Video',
				'Il racconto in movimento'             => 'The story in motion',
				'Le residenze'                         => 'The residences',
				'Scegli le tue stanze'                 => 'Choose your rooms',
				'Unità immobiliari in vendita'         => 'Property units for sale',
				'Tutte'                                => 'All',
				'Piano'                                => 'Floor',
				'Prezzo'                               => 'Price',
				'Superficie'                           => 'Surface area',
				'Camere'                               => 'Bedrooms',
				'Bagni'                                => 'Bathrooms',
				'Stato'                                => 'Status',
				'Scheda tecnica'                       => 'Technical details',
				'La composizione'                      => 'The composition',
				'Le unità dello scenario'              => 'The units in this scenario',
				'Lo scenario in numeri'                => 'The scenario in numbers',
				'I dati, aggregati'                    => 'The data, combined',
				'Unità comprese'                       => 'Units included',
				'Superficie complessiva'               => 'Total surface area',
				'Camere totali'                        => 'Total bedrooms',
				'Bagni totali'                         => 'Total bathrooms',
				'Somma prezzi unità'                   => 'Sum of unit prices',
				'Prezzo dello scenario'                => 'Scenario price',
				'Il tuo vantaggio'                     => 'Your advantage',
				'Vedi le residenze'                    => 'View the residences',
				'Ingrandisci immagine'                 => 'Enlarge image',
				'Ingrandisci la planimetria'           => 'Enlarge the floor plan',
				'Immagine precedente'                  => 'Previous image',
				'Immagine successiva'                  => 'Next image',
				'Guarda il walkthrough'                => 'Watch the walkthrough',
				'Avvia il virtual tour'                => 'Start the virtual tour',
				'Lingua'                               => 'Language',
			),
			'de' => array(
				'Richiedi una visita'                  => 'Besichtigung anfragen',
				'Richiedi una visita o informazioni'   => 'Besichtigung oder Informationen anfragen',
				'Nome e cognome'                       => 'Vor- und Nachname',
				'Email'                                => 'E-Mail',
				'Telefono'                             => 'Telefon',
				'Messaggio'                            => 'Nachricht',
				'Vorrei'                               => 'Ich möchte',
				'Richiedere una visita in loco'        => 'Eine Besichtigung vor Ort',
				'Informazioni generali'                => 'Allgemeine Informationen',
				'Altro'                                => 'Sonstiges',
				'Invia richiesta'                      => 'Anfrage senden',
				'Grazie! La tua richiesta è stata inviata.' => 'Vielen Dank! Ihre Anfrage wurde gesendet.',
				'Controlla i campi obbligatori e il consenso, poi riprova.' => 'Bitte prüfen Sie die Pflichtfelder und die Einwilligung und versuchen Sie es erneut.',
				'Parla con noi'                        => 'Sprechen Sie mit uns',
				'Contatti'                             => 'Kontakt',
				'Scrivi una mail'                      => 'E-Mail schreiben',
				'Chiama'                               => 'Anrufen',
				'WhatsApp'                             => 'WhatsApp',
				'Visite private su appuntamento.'      => 'Private Besichtigungen nach Vereinbarung.',
				'Galleria'                             => 'Galerie',
				'In luce'                              => 'Im Licht',
				'Il palazzo in luce'                   => 'Der Palazzo im Licht',
				'Planimetria'                          => 'Grundriss',
				'La pianta, quotata'                   => 'Der Grundriss, bemaßt',
				'Posizione nell’edificio'              => 'Lage im Gebäude',
				'Esplora l’edificio'                   => 'Das Gebäude entdecken',
				'Video'                                => 'Video',
				'Il racconto in movimento'             => 'Die Geschichte in Bewegung',
				'Le residenze'                         => 'Die Residenzen',
				'Scegli le tue stanze'                 => 'Wählen Sie Ihre Räume',
				'Unità immobiliari in vendita'         => 'Wohneinheiten zum Verkauf',
				'Tutte'                                => 'Alle',
				'Piano'                                => 'Etage',
				'Prezzo'                               => 'Preis',
				'Superficie'                           => 'Fläche',
				'Camere'                               => 'Schlafzimmer',
				'Bagni'                                => 'Badezimmer',
				'Stato'                                => 'Status',
				'Scheda tecnica'                       => 'Technische Daten',
				'La composizione'                      => 'Die Zusammensetzung',
				'Le unità dello scenario'              => 'Die Einheiten dieses Szenarios',
				'Lo scenario in numeri'                => 'Das Szenario in Zahlen',
				'I dati, aggregati'                    => 'Die Daten, zusammengefasst',
				'Unità comprese'                       => 'Enthaltene Einheiten',
				'Superficie complessiva'               => 'Gesamtfläche',
				'Camere totali'                        => 'Schlafzimmer gesamt',
				'Bagni totali'                         => 'Badezimmer gesamt',
				'Somma prezzi unità'                   => 'Summe der Einzelpreise',
				'Prezzo dello scenario'                => 'Szenario-Preis',
				'Il tuo vantaggio'                     => 'Ihr Vorteil',
				'Vedi le residenze'                    => 'Residenzen ansehen',
				'Ingrandisci immagine'                 => 'Bild vergrößern',
				'Ingrandisci la planimetria'           => 'Grundriss vergrößern',
				'Immagine precedente'                  => 'Vorheriges Bild',
				'Immagine successiva'                  => 'Nächstes Bild',
				'Guarda il walkthrough'                => 'Walkthrough ansehen',
				'Avvia il virtual tour'                => 'Virtuellen Rundgang starten',
				'Lingua'                               => 'Sprache',
			),
			'fr' => array(
				'Richiedi una visita'                  => 'Demander une visite',
				'Richiedi una visita o informazioni'   => 'Demander une visite ou des informations',
				'Nome e cognome'                       => 'Nom et prénom',
				'Email'                                => 'E-mail',
				'Telefono'                             => 'Téléphone',
				'Messaggio'                            => 'Message',
				'Vorrei'                               => 'Je souhaite',
				'Richiedere una visita in loco'        => 'Demander une visite sur place',
				'Informazioni generali'                => 'Informations générales',
				'Altro'                                => 'Autre',
				'Invia richiesta'                      => 'Envoyer la demande',
				'Grazie! La tua richiesta è stata inviata.' => 'Merci ! Votre demande a bien été envoyée.',
				'Controlla i campi obbligatori e il consenso, poi riprova.' => 'Vérifiez les champs obligatoires et le consentement, puis réessayez.',
				'Parla con noi'                        => 'Parlez avec nous',
				'Contatti'                             => 'Contacts',
				'Scrivi una mail'                      => 'Écrire un e-mail',
				'Chiama'                               => 'Appeler',
				'WhatsApp'                             => 'WhatsApp',
				'Visite private su appuntamento.'      => 'Visites privées sur rendez-vous.',
				'Galleria'                             => 'Galerie',
				'In luce'                              => 'En lumière',
				'Il palazzo in luce'                   => 'Le palazzo en lumière',
				'Planimetria'                          => 'Plan',
				'La pianta, quotata'                   => 'Le plan, coté',
				'Posizione nell’edificio'              => 'Position dans le bâtiment',
				'Esplora l’edificio'                   => 'Explorer le bâtiment',
				'Video'                                => 'Vidéo',
				'Il racconto in movimento'             => 'Le récit en mouvement',
				'Le residenze'                         => 'Les résidences',
				'Scegli le tue stanze'                 => 'Choisissez vos pièces',
				'Unità immobiliari in vendita'         => 'Unités immobilières à vendre',
				'Tutte'                                => 'Toutes',
				'Piano'                                => 'Étage',
				'Prezzo'                               => 'Prix',
				'Superficie'                           => 'Surface',
				'Camere'                               => 'Chambres',
				'Bagni'                                => 'Salles de bain',
				'Stato'                                => 'Statut',
				'Scheda tecnica'                       => 'Fiche technique',
				'La composizione'                      => 'La composition',
				'Le unità dello scenario'              => 'Les unités de ce scénario',
				'Lo scenario in numeri'                => 'Le scénario en chiffres',
				'I dati, aggregati'                    => 'Les données, agrégées',
				'Unità comprese'                       => 'Unités comprises',
				'Superficie complessiva'               => 'Surface totale',
				'Camere totali'                        => 'Chambres au total',
				'Bagni totali'                         => 'Salles de bain au total',
				'Somma prezzi unità'                   => 'Somme des prix des unités',
				'Prezzo dello scenario'                => 'Prix du scénario',
				'Il tuo vantaggio'                     => 'Votre avantage',
				'Vedi le residenze'                    => 'Voir les résidences',
				'Ingrandisci immagine'                 => 'Agrandir l’image',
				'Ingrandisci la planimetria'           => 'Agrandir le plan',
				'Immagine precedente'                  => 'Image précédente',
				'Immagine successiva'                  => 'Image suivante',
				'Guarda il walkthrough'                => 'Voir la visite guidée',
				'Avvia il virtual tour'                => 'Lancer la visite virtuelle',
				'Lingua'                               => 'Langue',
			),
		);
	}
}
