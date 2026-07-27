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
	private static $seen = array();

	/**
	 * Registra gli hook.
	 *
	 * @return void
	 */
	public function register() {
		// Reset una tantum del catalogo registrato: le prime versioni vi
		// avevano annotato anche etichette admin (registrazioni CPT ecc.)
		// perché il filtro girava prima della risoluzione della query.
		if ( '2' !== get_option( 'palladio_strings_catalog_v' ) ) {
			delete_option( self::CATALOG_OPTION );
			update_option( 'palladio_strings_catalog_v', '2', false );
		}

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

		// La lingua è nota solo a query principale risolta.
		if ( ! did_action( 'wp' ) ) {
			return $translation;
		}

		// Guardia anti-rientro: la risoluzione della lingua può passare a sua
		// volta da stringhe gettext degli stessi domini.
		static $running = false;
		if ( $running ) {
			return $translation;
		}
		$running = true;

		$current = Palladio_I18n_Languages::current();
		$source  = Palladio_I18n_Languages::source();

		$running = false;

		if ( $current === $source ) {
			return $translation;
		}

		$dict = self::dictionary();
		if ( isset( $dict[ $current ][ $text ] ) ) {
			return $dict[ $current ][ $text ];
		}

		// Non tradotta: annotala nel catalogo per l'elenco in admin.
		self::$seen[ $text ] = true;

		return $translation;
	}

	/**
	 * Traduce un testo NON gettext (es. valori delle Impostazioni: etichetta
	 * CTA, titolo e testo del form) nella lingua della pagina corrente.
	 *
	 * @param string $text Testo nella lingua sorgente.
	 * @return string
	 */
	public static function translate_text( $text ) {
		$text = (string) $text;
		if ( '' === $text || is_admin() || ! did_action( 'wp' ) ) {
			return $text;
		}

		$current = Palladio_I18n_Languages::current();
		if ( $current === Palladio_I18n_Languages::source() ) {
			return $text;
		}

		$dict = self::dictionary();
		if ( isset( $dict[ $current ][ $text ] ) ) {
			return $dict[ $current ][ $text ];
		}

		self::$seen[ $text ] = true;

		return $text;
	}

	/**
	 * Salva a fine richiesta le stringhe nuove incontrate.
	 *
	 * @return void
	 */
	public function flush_catalog() {
		if ( ! self::$seen ) {
			return;
		}

		$catalog = get_option( self::CATALOG_OPTION, array() );
		$catalog = is_array( $catalog ) ? $catalog : array();
		$dirty   = false;

		foreach ( array_keys( self::$seen ) as $text ) {
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
	 * Stringhe da NON tradurre: pannello di amministrazione, email interne
	 * all'agenzia, etichette dei selettori dell'editor. Restano in italiano
	 * e non compaiono nell'elenco dei Testi statici.
	 *
	 * @return string[]
	 */
	private static function denylist() {
		return array(
			// Email interne all'agenzia (lead).
			'Nuovo lead Palladio: %s', 'Gestisci il lead: %s', 'Nome: %s', 'Email: %s',
			'Telefono: %s', 'Messaggio:', 'Edificio: %s', 'Unità: %s',
			'(nessuna unità)', '(richiesta generica)',
			// Etichette del selettore icone (editor Territorio).
			'Dimora / Residenziale', 'Mondo / Turismo internazionale', 'Ospitalità / Hôtellerie',
			'Lavoro da remoto', 'Cultura / Museo', 'Crescita / Mercato', 'Sole / Clima',
			'Mare / Costa', 'Aereo / Collegamenti', 'Chiave / Investimento',
			// Registrazioni admin del tema (menu locations, aree widget).
			'Navigazione principale', 'Navigazione principale mobile', 'Menu informazioni',
			'Menu laterale del sito', 'Menu mobile', 'Menu fascia App Sidebar',
			'Area widget', 'Widget pagina', 'Footer widgets', 'Introduzione App Sidebar',
			'Aggiungi i tuoi widget nella sezione "Page Widgets" per visualizzarli qui.',
			'Use the Widgets area in the WordPress admin to customize this sidebar.',
		);
	}

	/**
	 * Catalogo delle stringhe da tradurre: seed + incontrate sul frontend,
	 * senza le voci del pannello di amministrazione.
	 *
	 * @return string[]
	 */
	public static function catalog() {
		$catalog = array_keys( self::seed()['en'] );

		$recorded = get_option( self::CATALOG_OPTION, array() );
		if ( is_array( $recorded ) ) {
			$catalog = array_merge( $catalog, array_keys( $recorded ) );
		}

		$catalog = array_values( array_unique( array_diff( $catalog, self::denylist() ) ) );
		sort( $catalog, SORT_NATURAL | SORT_FLAG_CASE );

		return $catalog;
	}

	/**
	 * Rimuove dal catalogo registrato le voci admin finite lì per errore.
	 *
	 * @return void
	 */
	private static function purge_catalog() {
		$recorded = get_option( self::CATALOG_OPTION, array() );
		if ( ! is_array( $recorded ) ) {
			return;
		}

		$clean = array_diff_key( $recorded, array_flip( self::denylist() ) );
		if ( count( $clean ) !== count( $recorded ) ) {
			update_option( self::CATALOG_OPTION, $clean, false );
		}
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

		self::purge_catalog();

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
				'Il film dell’unità'                   => 'The film of the unit',
				'Cammina nell’unità'                   => 'Walk through the unit',
				'Capitolo %s'                          => 'Chapter %s',
				'Capitoli della timeline'              => 'Timeline chapters',
				'I dati, con precisione'               => 'The data, precisely',
				'Questa unità fa parte di'             => 'This unit is part of',
				'Nella stessa storia'                  => 'In the same story',
				'Unità sorelle'                        => 'Sister units',
				'L’edificio'                           => 'The building',
				'Il dossier'                           => 'The dossier',
				'Tutto, per iscritto'                  => 'Everything, in writing',
				'Planimetrie quotate, prezzi, millesimi e note sul vincolo. Lascia i tuoi contatti: nessuna telefonata se non la chiedi tu.' => 'Dimensioned floor plans, prices, ownership shares and notes on the heritage listing. Leave your contact details: no phone calls unless you ask for one.',
				'Prezzo su richiesta'                  => 'Price on request',
				'Non disponibile'                      => 'Not available',
				'%s m²'                                => '%s m²',
				'%s camere'                            => '%s bedrooms',
				'Risparmi %1$s (−%2$s%%)'              => 'You save %1$s (−%2$s%%)',
				'Classe energetica'                    => 'Energy class',
				'Consegna'                             => 'Delivery',
				'Esposizione'                          => 'Exposure',
				'Millesimi'                            => 'Ownership shares',
				'Spese condominiali'                   => 'Service charges',
				'Superficie commerciale'               => 'Commercial area',
				'Superficie coperta'                   => 'Covered area',
				'Superficie totale'                    => 'Total area',
				'Terrazze'                             => 'Terraces',
				'Giardino'                             => 'Garden',
				'Stanze'                               => 'Rooms',
				'Anno'                                 => 'Year',
				'Uso attuale'                          => 'Current use',
				'Piani'                                => 'Floors',
				'Unità in vendita'                     => 'Units for sale',
				'Unità'                                => 'Units',
				'Scopri le residenze'                  => 'Discover the residences',
				'Gli scenari'                          => 'The scenarios',
				'Soluzioni e opportunità'              => 'Solutions and opportunities',
				'Scenario'                             => 'Scenario',
				'Più unità, un unico progetto abitativo o di business: i dati restano quelli delle unità, cambia solo il prezzo del pacchetto.' => 'Several units, one living or business project: the data stays that of the units — only the package price changes.',
				'Tutta la galleria — %s fotografie →'  => 'The full gallery — %s photographs →',
				'Mappa della posizione'                => 'Location map',
				'Il lessico della pietra'              => 'The lexicon of stone',
				'Le parole per capirlo'                => 'The words to understand it',
				'L’araldica'                           => 'Heraldry',
				'Tre blasoni, una dimora'              => 'Three coats of arms, one residence',
				'Il prossimo capitolo'                 => 'The next chapter',
				'La storia continua'                   => 'The story continues',
				'con chi la abiterà.'                  => 'with those who will live it.',
				'La posizione'                         => 'The location',
				'Nel cuore del centro storico'         => 'In the heart of the historic centre',
				'La città'                             => 'The city',
				'Lecce, la Firenze del Sud'            => 'Lecce, the Florence of the South',
				'Un territorio in crescita'            => 'A growing territory',
				'Il Salento, destinazione in piena espansione' => 'Salento, a destination in full expansion',
				'Un territorio, molti mercati'         => 'One territory, many markets',
				'Cinque ragioni per investire qui'     => 'Five reasons to invest here',
				'Investire o vivere, qui'              => 'Invest or live, here',
				'Un valore che cresce,'                => 'A value that grows,',
				'una vita che rallenta.'               => 'a life that slows down.',
				'Nessun edificio pubblicato.'          => 'No buildings published.',
				'Nessuna unità pubblicata al momento.' => 'No units published at the moment.',
				'Nessuno scenario pubblicato al momento.' => 'No scenarios published at the moment.',
				'Ho letto e accetto l’<a href="%s" target="_blank" rel="noopener">informativa sulla privacy</a> e autorizzo il trattamento dei miei dati per rispondere alla richiesta.' => 'I have read and accept the <a href="%s" target="_blank" rel="noopener">privacy policy</a> and authorise the processing of my data to answer this request.',
				'Questo contenuto è stato rimosso definitivamente.' => 'This content has been permanently removed.',
				'Cerca'                                => 'Search',
				'Torna su'                             => 'Back to top',
				'Condividi'                            => 'Share',
				'Vai ai commenti'                      => 'Go to comments',
				'Apri il menù principale'              => 'Open the main menu',
				'Chiudi il menù principale'            => 'Close the main menu',
				'Apri menu mobile'                     => 'Open mobile menu',
				'Chiudi menu mobile'                   => 'Close mobile menu',
				'Risultati della ricerca per "%s"'     => 'Search results for "%s"',
				'Lascia i tuoi recapiti: ti ricontattiamo per una visita in loco o per qualsiasi domanda.' => 'Leave your contact details: we will get back to you for an on-site visit or any question.',
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
				'Il film dell’unità'                   => 'Der Film der Einheit',
				'Cammina nell’unità'                   => 'Durch die Einheit gehen',
				'Capitolo %s'                          => 'Kapitel %s',
				'Capitoli della timeline'              => 'Kapitel der Timeline',
				'I dati, con precisione'               => 'Die Daten, präzise',
				'Questa unità fa parte di'             => 'Diese Einheit gehört zu',
				'Nella stessa storia'                  => 'In derselben Geschichte',
				'Unità sorelle'                        => 'Schwester-Einheiten',
				'L’edificio'                           => 'Das Gebäude',
				'Il dossier'                           => 'Das Dossier',
				'Tutto, per iscritto'                  => 'Alles, schriftlich',
				'Planimetrie quotate, prezzi, millesimi e note sul vincolo. Lascia i tuoi contatti: nessuna telefonata se non la chiedi tu.' => 'Bemaßte Grundrisse, Preise, Miteigentumsanteile und Hinweise zum Denkmalschutz. Hinterlassen Sie Ihre Kontaktdaten: kein Anruf, wenn Sie ihn nicht wünschen.',
				'Prezzo su richiesta'                  => 'Preis auf Anfrage',
				'Non disponibile'                      => 'Nicht verfügbar',
				'%s m²'                                => '%s m²',
				'%s camere'                            => '%s Schlafzimmer',
				'Risparmi %1$s (−%2$s%%)'              => 'Sie sparen %1$s (−%2$s%%)',
				'Classe energetica'                    => 'Energieklasse',
				'Consegna'                             => 'Übergabe',
				'Esposizione'                          => 'Ausrichtung',
				'Millesimi'                            => 'Miteigentumsanteile',
				'Spese condominiali'                   => 'Nebenkosten',
				'Superficie commerciale'               => 'Kommerzielle Fläche',
				'Superficie coperta'                   => 'Überdachte Fläche',
				'Superficie totale'                    => 'Gesamtfläche',
				'Terrazze'                             => 'Terrassen',
				'Giardino'                             => 'Garten',
				'Stanze'                               => 'Zimmer',
				'Anno'                                 => 'Jahr',
				'Uso attuale'                          => 'Aktuelle Nutzung',
				'Piani'                                => 'Etagen',
				'Unità in vendita'                     => 'Einheiten zum Verkauf',
				'Unità'                                => 'Einheiten',
				'Scopri le residenze'                  => 'Die Residenzen entdecken',
				'Gli scenari'                          => 'Die Szenarien',
				'Soluzioni e opportunità'              => 'Lösungen und Chancen',
				'Scenario'                             => 'Szenario',
				'Più unità, un unico progetto abitativo o di business: i dati restano quelli delle unità, cambia solo il prezzo del pacchetto.' => 'Mehrere Einheiten, ein Wohn- oder Geschäftsprojekt: Die Daten bleiben die der Einheiten — nur der Paketpreis ändert sich.',
				'Tutta la galleria — %s fotografie →'  => 'Die ganze Galerie — %s Fotografien →',
				'Mappa della posizione'                => 'Lagekarte',
				'Il lessico della pietra'              => 'Das Lexikon des Steins',
				'Le parole per capirlo'                => 'Die Worte, um ihn zu verstehen',
				'L’araldica'                           => 'Die Heraldik',
				'Tre blasoni, una dimora'              => 'Drei Wappen, ein Haus',
				'Il prossimo capitolo'                 => 'Das nächste Kapitel',
				'La storia continua'                   => 'Die Geschichte geht weiter',
				'con chi la abiterà.'                  => 'mit denen, die hier wohnen werden.',
				'La posizione'                         => 'Die Lage',
				'Nel cuore del centro storico'         => 'Im Herzen der Altstadt',
				'La città'                             => 'Die Stadt',
				'Lecce, la Firenze del Sud'            => 'Lecce, das Florenz des Südens',
				'Un territorio in crescita'            => 'Eine wachsende Region',
				'Il Salento, destinazione in piena espansione' => 'Der Salento, eine Destination im Aufschwung',
				'Un territorio, molti mercati'         => 'Eine Region, viele Märkte',
				'Cinque ragioni per investire qui'     => 'Fünf Gründe, hier zu investieren',
				'Investire o vivere, qui'              => 'Investieren oder wohnen, hier',
				'Un valore che cresce,'                => 'Ein Wert, der wächst,',
				'una vita che rallenta.'               => 'ein Leben, das langsamer wird.',
				'Nessun edificio pubblicato.'          => 'Keine Gebäude veröffentlicht.',
				'Nessuna unità pubblicata al momento.' => 'Derzeit keine Einheiten veröffentlicht.',
				'Nessuno scenario pubblicato al momento.' => 'Derzeit keine Szenarien veröffentlicht.',
				'Ho letto e accetto l’<a href="%s" target="_blank" rel="noopener">informativa sulla privacy</a> e autorizzo il trattamento dei miei dati per rispondere alla richiesta.' => 'Ich habe die <a href="%s" target="_blank" rel="noopener">Datenschutzerklärung</a> gelesen und akzeptiert und willige in die Verarbeitung meiner Daten zur Beantwortung der Anfrage ein.',
				'Questo contenuto è stato rimosso definitivamente.' => 'Dieser Inhalt wurde dauerhaft entfernt.',
				'Cerca'                                => 'Suchen',
				'Torna su'                             => 'Nach oben',
				'Condividi'                            => 'Teilen',
				'Vai ai commenti'                      => 'Zu den Kommentaren',
				'Apri il menù principale'              => 'Hauptmenü öffnen',
				'Chiudi il menù principale'            => 'Hauptmenü schließen',
				'Apri menu mobile'                     => 'Mobiles Menü öffnen',
				'Chiudi menu mobile'                   => 'Mobiles Menü schließen',
				'Risultati della ricerca per "%s"'     => 'Suchergebnisse für "%s"',
				'Lascia i tuoi recapiti: ti ricontattiamo per una visita in loco o per qualsiasi domanda.' => 'Hinterlassen Sie Ihre Kontaktdaten: Wir melden uns für eine Besichtigung vor Ort oder bei Fragen.',
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
				'Il film dell’unità'                   => 'Le film de l’unité',
				'Cammina nell’unità'                   => 'Parcourez l’unité',
				'Capitolo %s'                          => 'Chapitre %s',
				'Capitoli della timeline'              => 'Chapitres de la timeline',
				'I dati, con precisione'               => 'Les données, avec précision',
				'Questa unità fa parte di'             => 'Cette unité fait partie de',
				'Nella stessa storia'                  => 'Dans la même histoire',
				'Unità sorelle'                        => 'Unités sœurs',
				'L’edificio'                           => 'Le bâtiment',
				'Il dossier'                           => 'Le dossier',
				'Tutto, per iscritto'                  => 'Tout, par écrit',
				'Planimetrie quotate, prezzi, millesimi e note sul vincolo. Lascia i tuoi contatti: nessuna telefonata se non la chiedi tu.' => 'Plans cotés, prix, millièmes et notes sur le classement. Laissez vos coordonnées : aucun appel si vous ne le demandez pas.',
				'Prezzo su richiesta'                  => 'Prix sur demande',
				'Non disponibile'                      => 'Non disponible',
				'%s m²'                                => '%s m²',
				'%s camere'                            => '%s chambres',
				'Risparmi %1$s (−%2$s%%)'              => 'Vous économisez %1$s (−%2$s%%)',
				'Classe energetica'                    => 'Classe énergétique',
				'Consegna'                             => 'Livraison',
				'Esposizione'                          => 'Exposition',
				'Millesimi'                            => 'Millièmes',
				'Spese condominiali'                   => 'Charges de copropriété',
				'Superficie commerciale'               => 'Surface commerciale',
				'Superficie coperta'                   => 'Surface couverte',
				'Superficie totale'                    => 'Surface totale',
				'Terrazze'                             => 'Terrasses',
				'Giardino'                             => 'Jardin',
				'Stanze'                               => 'Pièces',
				'Anno'                                 => 'Année',
				'Uso attuale'                          => 'Usage actuel',
				'Piani'                                => 'Étages',
				'Unità in vendita'                     => 'Unités à vendre',
				'Unità'                                => 'Unités',
				'Scopri le residenze'                  => 'Découvrez les résidences',
				'Gli scenari'                          => 'Les scénarios',
				'Soluzioni e opportunità'              => 'Solutions et opportunités',
				'Scenario'                             => 'Scénario',
				'Più unità, un unico progetto abitativo o di business: i dati restano quelli delle unità, cambia solo il prezzo del pacchetto.' => 'Plusieurs unités, un seul projet d’habitation ou d’activité : les données restent celles des unités, seul le prix du lot change.',
				'Tutta la galleria — %s fotografie →'  => 'Toute la galerie — %s photographies →',
				'Mappa della posizione'                => 'Carte de localisation',
				'Il lessico della pietra'              => 'Le lexique de la pierre',
				'Le parole per capirlo'                => 'Les mots pour le comprendre',
				'L’araldica'                           => 'L’héraldique',
				'Tre blasoni, una dimora'              => 'Trois blasons, une demeure',
				'Il prossimo capitolo'                 => 'Le prochain chapitre',
				'La storia continua'                   => 'L’histoire continue',
				'con chi la abiterà.'                  => 'avec ceux qui l’habiteront.',
				'La posizione'                         => 'L’emplacement',
				'Nel cuore del centro storico'         => 'Au cœur du centre historique',
				'La città'                             => 'La ville',
				'Lecce, la Firenze del Sud'            => 'Lecce, la Florence du Sud',
				'Un territorio in crescita'            => 'Un territoire en croissance',
				'Il Salento, destinazione in piena espansione' => 'Le Salento, une destination en pleine expansion',
				'Un territorio, molti mercati'         => 'Un territoire, de nombreux marchés',
				'Cinque ragioni per investire qui'     => 'Cinq raisons d’investir ici',
				'Investire o vivere, qui'              => 'Investir ou vivre, ici',
				'Un valore che cresce,'                => 'Une valeur qui grandit,',
				'una vita che rallenta.'               => 'une vie qui ralentit.',
				'Nessun edificio pubblicato.'          => 'Aucun bâtiment publié.',
				'Nessuna unità pubblicata al momento.' => 'Aucune unité publiée pour le moment.',
				'Nessuno scenario pubblicato al momento.' => 'Aucun scénario publié pour le moment.',
				'Ho letto e accetto l’<a href="%s" target="_blank" rel="noopener">informativa sulla privacy</a> e autorizzo il trattamento dei miei dati per rispondere alla richiesta.' => 'J’ai lu et j’accepte la <a href="%s" target="_blank" rel="noopener">politique de confidentialité</a> et j’autorise le traitement de mes données pour répondre à ma demande.',
				'Questo contenuto è stato rimosso definitivamente.' => 'Ce contenu a été définitivement supprimé.',
				'Cerca'                                => 'Rechercher',
				'Torna su'                             => 'Retour en haut',
				'Condividi'                            => 'Partager',
				'Vai ai commenti'                      => 'Aller aux commentaires',
				'Apri il menù principale'              => 'Ouvrir le menu principal',
				'Chiudi il menù principale'            => 'Fermer le menu principal',
				'Apri menu mobile'                     => 'Ouvrir le menu mobile',
				'Chiudi menu mobile'                   => 'Fermer le menu mobile',
				'Risultati della ricerca per "%s"'     => 'Résultats de recherche pour « %s »',
				'Lascia i tuoi recapiti: ti ricontattiamo per una visita in loco o per qualsiasi domanda.' => 'Laissez vos coordonnées : nous vous recontactons pour une visite sur place ou pour toute question.',
			),
		);
	}
}
