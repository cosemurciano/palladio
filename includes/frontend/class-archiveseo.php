<?php
/**
 * Modulo Presenter — SEO delle pagine elenco (archivi CPT).
 *
 * Gli archivi del plugin (/unita/, /scenario/... e versioni in lingua) sono
 * generati dai template e non hanno una pagina modificabile: questa sezione
 * ("SEO pagine elenco" in Palladio → Impostazioni) permette di definire meta
 * title e meta description PER ARCHIVIO e PER LINGUA attiva.
 *
 * Output: con All in One SEO attivo i valori passano dai suoi filtri
 * (aioseo_title / aioseo_description / tag Facebook e Twitter / canonical),
 * così in pagina esiste UN SOLO tag per tipo; senza AIOSEO, fallback su
 * document_title_parts + emissione diretta in wp_head. Fallback valori:
 * lingua corrente → lingua sorgente → "{Nome archivio} — {Titolo sito}".
 * Canonical per lingua, self-referencing sulle pagine di paginazione
 * (titolo con suffisso "— Pagina N"). Convive con gli hreflang (D7).
 *
 * Rilevazione generica: ogni CPT pll_* con archivio compare da solo.
 * Un'unica option (autoload) — nessuna query aggiuntiva sul frontend.
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Meta SEO per gli archivi del plugin, per lingua.
 */
class Palladio_Frontend_Archiveseo {

	const CAP    = 'manage_options';
	const OPTION = 'palladio_archive_seo'; // [post_type][lang][title|description].

	/**
	 * Registra gli hook.
	 *
	 * @return void
	 */
	public function register() {
		if ( is_admin() ) {
			add_action( 'palladio/settings/after', array( $this, 'settings_section' ) );
			add_action( 'admin_post_palladio_save_archive_seo', array( $this, 'save' ) );
			return;
		}

		// Titolo: AIOSEO se presente, altrimenti il titolo nativo.
		add_filter( 'aioseo_title', array( $this, 'filter_title' ), 20 );
		add_filter( 'document_title_parts', array( $this, 'filter_title_parts' ), 20 );

		// Description e canonical via AIOSEO.
		add_filter( 'aioseo_description', array( $this, 'filter_description' ), 20 );
		add_filter( 'aioseo_canonical_url', array( $this, 'filter_canonical' ), 20 );
		add_filter( 'aioseo_facebook_tags', array( $this, 'filter_social_tags' ), 20 );
		add_filter( 'aioseo_twitter_tags', array( $this, 'filter_social_tags' ), 20 );

		// Fallback senza AIOSEO: emissione diretta (description, og, canonical).
		add_action( 'wp_head', array( $this, 'fallback_head' ), 3 );
	}

	// -------------------------------------------------------------------------
	// Modello.
	// -------------------------------------------------------------------------

	/**
	 * Archivi del plugin (rilevazione generica: CPT pll_* con archivio).
	 *
	 * @return array<string,string> post_type => etichetta.
	 */
	public static function archives() {
		$out = array();
		foreach ( get_post_types( array( 'has_archive' => true ), 'objects' ) as $post_type => $object ) {
			if ( 0 === strpos( $post_type, 'pll_' ) ) {
				$out[ $post_type ] = (string) $object->labels->name;
			}
		}

		return $out;
	}

	/**
	 * Valori salvati.
	 *
	 * @return array
	 */
	private static function values() {
		$values = get_option( self::OPTION, array() );

		return is_array( $values ) ? $values : array();
	}

	/**
	 * Valore per archivio/lingua/campo con fallback: lingua → sorgente →
	 * default "{Nome archivio} — {Titolo sito}" (solo per il title).
	 *
	 * @param string $post_type CPT.
	 * @param string $lang      Lingua.
	 * @param string $field     title|description.
	 * @return string
	 */
	public static function value( $post_type, $lang, $field ) {
		$values = self::values();

		$own = trim( (string) ( $values[ $post_type ][ $lang ][ $field ] ?? '' ) );
		if ( '' !== $own ) {
			return $own;
		}

		$source = class_exists( 'Palladio_I18n_Languages' ) ? Palladio_I18n_Languages::source() : 'it';
		$base   = trim( (string) ( $values[ $post_type ][ $source ][ $field ] ?? '' ) );
		if ( '' !== $base ) {
			return $base;
		}

		if ( 'title' === $field ) {
			$archives = self::archives();
			$label    = isset( $archives[ $post_type ] ) ? $archives[ $post_type ] : $post_type;

			return $label . ' — ' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		}

		return '';
	}

	/**
	 * CPT dell'archivio corrente ('' se non è un archivio del plugin).
	 *
	 * @return string
	 */
	private function current_archive() {
		$archives = self::archives();
		if ( ! $archives || ! is_post_type_archive( array_keys( $archives ) ) ) {
			return '';
		}

		$post_type = get_query_var( 'post_type' );
		if ( is_array( $post_type ) ) {
			$post_type = (string) reset( $post_type );
		}

		return isset( $archives[ (string) $post_type ] ) ? (string) $post_type : '';
	}

	/**
	 * Lingua della pagina corrente.
	 *
	 * @return string
	 */
	private function current_lang() {
		return class_exists( 'Palladio_I18n_Languages' ) ? Palladio_I18n_Languages::current() : 'it';
	}

	/**
	 * Titolo dell'archivio corrente, con suffisso di paginazione.
	 *
	 * @return string
	 */
	private function current_title() {
		$post_type = $this->current_archive();
		if ( '' === $post_type ) {
			return '';
		}

		$title = self::value( $post_type, $this->current_lang(), 'title' );

		$paged = max( 1, (int) get_query_var( 'paged' ) );
		if ( $paged > 1 ) {
			/* translators: %d: numero pagina. */
			$title .= ' — ' . sprintf( __( 'Pagina %d', 'palladio' ), $paged );
		}

		return $title;
	}

	/**
	 * Description dell'archivio corrente.
	 *
	 * @return string
	 */
	private function current_description() {
		$post_type = $this->current_archive();

		return '' === $post_type ? '' : self::value( $post_type, $this->current_lang(), 'description' );
	}

	/**
	 * Canonical dell'archivio nella lingua corrente; self-referencing sulla
	 * paginazione.
	 *
	 * @return string
	 */
	private function current_canonical() {
		$post_type = $this->current_archive();
		if ( '' === $post_type ) {
			return '';
		}

		// get_post_type_archive_link è già localizzato per la lingua corrente
		// dal filtro del modulo URL.
		$url = (string) get_post_type_archive_link( $post_type );

		$paged = max( 1, (int) get_query_var( 'paged' ) );
		if ( $paged > 1 ) {
			$url = user_trailingslashit( trailingslashit( $url ) . 'page/' . $paged );
		}

		return $url;
	}

	/**
	 * AIOSEO è attivo?
	 *
	 * @return bool
	 */
	private function aioseo_active() {
		return defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' );
	}

	// -------------------------------------------------------------------------
	// Output.
	// -------------------------------------------------------------------------

	/**
	 * Filtro aioseo_title.
	 *
	 * @param string $title Titolo corrente.
	 * @return string
	 */
	public function filter_title( $title ) {
		$own = $this->current_title();

		return '' !== $own ? $own : $title;
	}

	/**
	 * Filtro document_title_parts (titolo nativo, senza AIOSEO).
	 *
	 * @param array $parts Parti del titolo.
	 * @return array
	 */
	public function filter_title_parts( $parts ) {
		if ( $this->aioseo_active() ) {
			return $parts;
		}

		$own = $this->current_title();
		if ( '' !== $own ) {
			// Titolo completo configurato: niente tagline/sito appesi da WP.
			return array( 'title' => $own );
		}

		return $parts;
	}

	/**
	 * Filtro aioseo_description.
	 *
	 * @param string $description Description corrente.
	 * @return string
	 */
	public function filter_description( $description ) {
		$own = $this->current_description();

		return '' !== $own ? $own : $description;
	}

	/**
	 * Filtro aioseo_canonical_url.
	 *
	 * @param string $url Canonical corrente.
	 * @return string
	 */
	public function filter_canonical( $url ) {
		$own = $this->current_canonical();

		return '' !== $own ? $own : $url;
	}

	/**
	 * Filtri aioseo_facebook_tags / aioseo_twitter_tags: og/twitter coerenti.
	 *
	 * @param array $tags Tag correnti.
	 * @return array
	 */
	public function filter_social_tags( $tags ) {
		if ( ! is_array( $tags ) || '' === $this->current_archive() ) {
			return $tags;
		}

		$title       = $this->current_title();
		$description = $this->current_description();

		foreach ( array( 'og:title', 'twitter:title' ) as $key ) {
			if ( isset( $tags[ $key ] ) && '' !== $title ) {
				$tags[ $key ] = $title;
			}
		}
		foreach ( array( 'og:description', 'twitter:description' ) as $key ) {
			if ( isset( $tags[ $key ] ) && '' !== $description ) {
				$tags[ $key ] = $description;
			}
		}

		return $tags;
	}

	/**
	 * Fallback senza AIOSEO: description, og e canonical emessi direttamente.
	 * (Il tag <title> è coperto da document_title_parts; l'og:title del
	 * modulo Schema usa lo stesso titolo nativo: nessun doppione.)
	 *
	 * @return void
	 */
	public function fallback_head() {
		if ( $this->aioseo_active() || '' === $this->current_archive() ) {
			return;
		}

		$description = $this->current_description();
		$canonical   = $this->current_canonical();

		if ( '' !== $description ) {
			echo '<meta name="description" content="' . esc_attr( $description ) . '">' . "\n";
			echo '<meta property="og:description" content="' . esc_attr( $description ) . '">' . "\n";
		}
		if ( '' !== $canonical ) {
			echo '<link rel="canonical" href="' . esc_url( $canonical ) . '" />' . "\n";
		}
	}

	// -------------------------------------------------------------------------
	// Admin.
	// -------------------------------------------------------------------------

	/**
	 * Sezione "SEO pagine elenco" nella pagina Impostazioni.
	 *
	 * @return void
	 */
	public function settings_section() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$archives = self::archives();
		if ( ! $archives ) {
			return;
		}

		$catalog = class_exists( 'Palladio_I18n_Languages' ) ? Palladio_I18n_Languages::catalog() : array( 'it' => 'Italiano' );
		$active  = class_exists( 'Palladio_I18n_Languages' ) ? Palladio_I18n_Languages::active() : array( 'it' );
		$values  = self::values();
		$saved   = isset( $_GET['palladio_seo'] ) && 'saved' === sanitize_key( wp_unslash( $_GET['palladio_seo'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<hr style="margin:2em 0;">
		<h2><?php esc_html_e( 'SEO pagine elenco', 'palladio' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Meta title e meta description delle pagine archivio generate dal plugin (elenco unità, scenari...), per lingua. Campo vuoto: usa il valore della lingua sorgente; tutto vuoto: "{Nome archivio} — {Titolo sito}". I valori vengono emessi tramite All in One SEO se attivo (un solo tag per tipo).', 'palladio' ); ?></p>

		<?php if ( $saved ) : ?>
			<div class="notice notice-success inline"><p><?php esc_html_e( 'SEO pagine elenco salvata.', 'palladio' ); ?></p></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'palladio_archive_seo' ); ?>
			<input type="hidden" name="action" value="palladio_save_archive_seo">

			<?php foreach ( $archives as $post_type => $label ) : ?>
				<h3 style="margin-top:1.5em;"><?php echo esc_html( $label ); ?> <code style="font-weight:400;"><?php echo esc_html( '/' . ( get_post_type_object( $post_type )->rewrite['slug'] ?? $post_type ) . '/' ); ?></code></h3>
				<table class="form-table" role="presentation" style="max-width:1000px;margin-top:0;">
					<?php foreach ( $active as $lang ) : ?>
						<tr>
							<th scope="row" style="width:9em;">
								<?php echo esc_html( ( class_exists( 'Palladio_Admin_Translations' ) ? Palladio_Admin_Translations::flag( $lang ) . ' ' : '' ) . ( $catalog[ $lang ] ?? strtoupper( $lang ) ) ); ?>
							</th>
							<td>
								<p style="margin:0 0 .5em;">
									<input type="text" class="large-text pll-seo-count" data-limit="60"
										name="seo[<?php echo esc_attr( $post_type ); ?>][<?php echo esc_attr( $lang ); ?>][title]"
										value="<?php echo esc_attr( $values[ $post_type ][ $lang ]['title'] ?? '' ); ?>"
										placeholder="<?php esc_attr_e( 'Meta title (consigliati ≤ 60 caratteri)', 'palladio' ); ?>">
									<span class="pll-seo-counter description"></span>
								</p>
								<p style="margin:0;">
									<textarea class="large-text pll-seo-count" data-limit="160" rows="2"
										name="seo[<?php echo esc_attr( $post_type ); ?>][<?php echo esc_attr( $lang ); ?>][description]"
										placeholder="<?php esc_attr_e( 'Meta description (consigliati ≤ 160 caratteri)', 'palladio' ); ?>"><?php echo esc_textarea( $values[ $post_type ][ $lang ]['description'] ?? '' ); ?></textarea>
									<span class="pll-seo-counter description"></span>
								</p>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
			<?php endforeach; ?>

			<?php submit_button( __( 'Salva SEO pagine elenco', 'palladio' ) ); ?>
		</form>

		<script>
		(function () {
			function refresh( field ) {
				var limit   = parseInt( field.getAttribute( 'data-limit' ), 10 );
				var counter = field.parentNode.querySelector( '.pll-seo-counter' );
				if ( ! counter ) { return; }
				var len = field.value.length;
				counter.textContent = len + ' / ' + limit;
				counter.style.color = len > limit ? '#b32d2e' : '#6c7781';
			}
			document.querySelectorAll( '.pll-seo-count' ).forEach( function ( field ) {
				refresh( field );
				field.addEventListener( 'input', function () { refresh( field ); } );
			} );
		}());
		</script>
		<?php
	}

	/**
	 * Salvataggio (capability manage_options, nonce, sanitizzazione per campo).
	 *
	 * @return void
	 */
	public function save() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permesso negato.', 'palladio' ) );
		}
		check_admin_referer( 'palladio_archive_seo' );

		$archives = self::archives();
		$active   = class_exists( 'Palladio_I18n_Languages' ) ? Palladio_I18n_Languages::active() : array( 'it' );
		$posted   = isset( $_POST['seo'] ) && is_array( $_POST['seo'] ) ? wp_unslash( $_POST['seo'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		$clean = array();
		foreach ( $archives as $post_type => $label ) {
			foreach ( $active as $lang ) {
				$title       = sanitize_text_field( (string) ( $posted[ $post_type ][ $lang ]['title'] ?? '' ) );
				$description = sanitize_textarea_field( (string) ( $posted[ $post_type ][ $lang ]['description'] ?? '' ) );
				if ( '' !== $title || '' !== $description ) {
					$clean[ $post_type ][ $lang ] = array(
						'title'       => $title,
						'description' => $description,
					);
				}
			}
		}

		// Autoload attivo: unica option letta sul frontend, nessuna query extra.
		update_option( self::OPTION, $clean );

		wp_safe_redirect( add_query_arg( 'palladio_seo', 'saved', admin_url( 'edit.php?post_type=pll_edificio&page=palladio-settings' ) ) );
		exit;
	}
}
