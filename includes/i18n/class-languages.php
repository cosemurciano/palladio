<?php
/**
 * Modulo Lingue — configurazione e risoluzione lingua.
 *
 * Modello a pagine clone (§5.4): ogni lingua è un post CPT dedicato. La
 * lingua corrente deriva dal post visualizzato (o da `?lang=` negli
 * archivi); switcher e hreflang puntano ai post collegati; gli archivi
 * mostrano solo la lingua corrente. Modalità nativa a zero dipendenze.
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Configurazione lingue e integrazione frontend.
 */
class Palladio_I18n_Languages {

	const CAP = 'manage_palladio';

	/**
	 * CPT gestiti dal multilingua.
	 *
	 * @var string[]
	 */
	private $post_types = array( 'pll_edificio', 'pll_unita', 'pll_scenario', 'pll_storia', 'pll_territorio' );

	/**
	 * Registra hook frontend e admin.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_shortcode( 'palladio_lang_switcher', array( $this, 'switcher' ) );

		if ( ! is_admin() ) {
			add_action( 'pre_get_posts', array( $this, 'filter_archive_language' ) );
			add_action( 'wp_head', array( $this, 'hreflang' ) );
		}

		add_action( 'admin_menu', array( $this, 'settings_menu' ) );
		add_action( 'admin_post_palladio_save_languages', array( $this, 'save_settings' ) );
	}

	/**
	 * Catalogo delle lingue supportate.
	 *
	 * @return array<string,string>
	 */
	public static function catalog() {
		return array(
			'it' => __( 'Italiano', 'palladio' ),
			'en' => __( 'English', 'palladio' ),
			'de' => __( 'Deutsch', 'palladio' ),
			'fr' => __( 'Français', 'palladio' ),
		);
	}

	/**
	 * Codici lingua supportati SENZA gettext.
	 *
	 * Usato nei percorsi caldi (config, filtri): catalog() passa da __() e
	 * quindi dal filtro gettext dei testi statici — chiamarlo da lì
	 * causerebbe ricorsione infinita.
	 *
	 * @return string[]
	 */
	public static function catalog_keys() {
		return array( 'it', 'en', 'de', 'fr' );
	}

	/**
	 * Configurazione corrente (sorgente + lingue attive).
	 *
	 * @return array{source:string,active:string[]}
	 */
	public static function config() {
		$defaults = array(
			'source' => 'it',
			'active' => array( 'it', 'en' ),
		);

		$config = get_option( 'palladio_languages', array() );
		$config = wp_parse_args( is_array( $config ) ? $config : array(), $defaults );

		// Validazione sui soli codici (senza gettext: config() è chiamata
		// anche dentro il filtro gettext dei testi statici).
		$keys             = self::catalog_keys();
		$config['source'] = in_array( $config['source'], $keys, true ) ? $config['source'] : 'it';

		$config['active'] = array_values(
			array_filter(
				(array) $config['active'],
				static function ( $lang ) use ( $keys ) {
					return in_array( $lang, $keys, true );
				}
			)
		);

		if ( ! in_array( $config['source'], $config['active'], true ) ) {
			array_unshift( $config['active'], $config['source'] );
		}

		return $config;
	}

	/**
	 * Lingua sorgente.
	 *
	 * @return string
	 */
	public static function source() {
		return self::config()['source'];
	}

	/**
	 * Lingue attive.
	 *
	 * @return string[]
	 */
	public static function active() {
		return self::config()['active'];
	}

	/**
	 * Verifica se una lingua è attiva.
	 *
	 * @param string $lang Lingua.
	 * @return bool
	 */
	public static function is_active( $lang ) {
		return in_array( sanitize_key( $lang ), self::active(), true );
	}

	/**
	 * Lingua corrente della richiesta.
	 *
	 * Su un CPT singolo è la lingua del post; altrimenti da `?lang=` o sorgente.
	 * In admin è sempre la sorgente.
	 *
	 * @return string
	 */
	public static function current() {
		static $current = null;
		if ( null !== $current ) {
			return $current;
		}

		$source = self::source();

		if ( is_admin() ) {
			$current = $source;
			return $current;
		}

		// Prima che la query principale sia risolta la lingua non è
		// determinabile: rispondi con la sorgente SENZA memorizzarla
		// (altrimenti una chiamata precoce congelerebbe il valore sbagliato).
		if ( ! did_action( 'wp' ) || empty( $GLOBALS['wp_query'] ) ) {
			return $source;
		}

		if ( is_singular( array( 'pll_edificio', 'pll_unita', 'pll_scenario', 'pll_storia', 'pll_territorio' ) ) ) {
			$current = Palladio_I18n_Translator::get_lang( get_queried_object_id() );
			return $current;
		}

		$lang = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['lang'] ) ) {
			$lang = sanitize_key( wp_unslash( $_GET['lang'] ) );
		} else {
			$qv = get_query_var( 'lang' );
			if ( $qv ) {
				$lang = sanitize_key( $qv );
			}
		}

		$current = ( $lang && self::is_active( $lang ) ) ? $lang : $source;
		return $current;
	}

	/**
	 * Registra la query var `lang`.
	 *
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public function query_vars( $vars ) {
		$vars[] = 'lang';
		return $vars;
	}

	/**
	 * Limita gli archivi dei CPT alla lingua corrente.
	 *
	 * @param WP_Query $query Query.
	 * @return void
	 */
	public function filter_archive_language( $query ) {
		if ( ! $query->is_main_query() ) {
			return;
		}
		if ( ! $query->is_post_type_archive( 'pll_edificio' ) && ! $query->is_tax( array( 'pll_tipologia', 'pll_piano', 'pll_stato' ) ) ) {
			return;
		}

		$current = self::current();
		$source  = self::source();

		if ( $current === $source ) {
			// Sorgente: mostra i post in lingua sorgente o senza lingua impostata.
			$meta_query = array(
				'relation' => 'OR',
				array( 'key' => Palladio_I18n_Translator::LANG_META, 'value' => $source ),
				array( 'key' => Palladio_I18n_Translator::LANG_META, 'compare' => 'NOT EXISTS' ),
			);
		} else {
			$meta_query = array(
				array( 'key' => Palladio_I18n_Translator::LANG_META, 'value' => $current ),
			);
		}

		$existing = $query->get( 'meta_query' );
		if ( ! empty( $existing ) ) {
			$meta_query = array( 'relation' => 'AND', $existing, $meta_query );
		}
		$query->set( 'meta_query', $meta_query );
	}

	/**
	 * Homepage nella lingua indicata (sempre valida: mai link morti).
	 *
	 * @param string $lang Lingua.
	 * @return string
	 */
	public static function language_home_url( $lang ) {
		// Se un edificio è la homepage, punta alla sua versione in lingua.
		$home_building = function_exists( 'palladio_home_building_id' ) ? palladio_home_building_id() : 0;
		if ( $home_building && class_exists( 'Palladio_I18n_Translator' ) ) {
			if ( $lang === Palladio_I18n_Translator::get_lang( $home_building ) ) {
				return (string) home_url( '/' );
			}
			$sibling = Palladio_I18n_Translator::sibling_in( $home_building, $lang, array( 'publish' ) );
			if ( $sibling ) {
				return (string) get_permalink( $sibling );
			}
		}

		return $lang === self::source() ? home_url( '/' ) : add_query_arg( 'lang', $lang, home_url( '/' ) );
	}

	/**
	 * URL del post nella lingua indicata ('' se non esiste).
	 *
	 * @param string $lang    Lingua.
	 * @param int    $post_id ID post (default: corrente).
	 * @return string
	 */
	public static function post_url( $lang, $post_id = 0 ) {
		$post_id = $post_id ? $post_id : get_queried_object_id();
		if ( ! $post_id ) {
			return '';
		}

		if ( $lang === Palladio_I18n_Translator::get_lang( $post_id ) ) {
			return (string) get_permalink( $post_id );
		}

		$sibling = Palladio_I18n_Translator::sibling_in( $post_id, $lang, array( 'publish' ) );
		return $sibling ? (string) get_permalink( $sibling ) : '';
	}

	/**
	 * Stampa gli hreflang verso i post collegati.
	 *
	 * @return void
	 */
	public function hreflang() {
		if ( ! is_singular( $this->post_types ) ) {
			return;
		}

		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			return;
		}

		$source = self::source();

		foreach ( self::active() as $lang ) {
			$url = self::post_url( $lang, $post_id );
			if ( '' === $url ) {
				continue;
			}
			printf(
				'<link rel="alternate" hreflang="%1$s" href="%2$s" />' . "\n",
				esc_attr( $lang ),
				esc_url( $url )
			);
		}

		$default = self::post_url( $source, $post_id );
		if ( '' !== $default ) {
			printf( '<link rel="alternate" hreflang="x-default" href="%s" />' . "\n", esc_url( $default ) );
		}
	}

	/**
	 * Shortcode switcher lingua (link ai post collegati).
	 *
	 * @param array $atts Attributi.
	 * @return string
	 */
	public function switcher( $atts ) {
		$active = self::active();
		if ( count( $active ) < 2 ) {
			return '';
		}

		$catalog = self::catalog();
		$current = self::current();

		$items     = array();
		$available = 0; // Lingue diverse dalla corrente effettivamente raggiungibili.

		foreach ( $active as $lang ) {
			// Codice breve (IT/EN/DE/FR) per lo switcher; nome esteso come title.
			$label = strtoupper( $lang );
			$title = $catalog[ $lang ] ?? $label;

			if ( $lang === $current ) {
				$items[] = sprintf( '<span class="palladio-lang is-current" aria-current="true" title="%2$s">%1$s</span>', esc_html( $label ), esc_attr( $title ) );
				continue;
			}

			// Preferisce la pagina tradotta corrispondente; senza traduzione,
			// il link porta alla home della lingua (mai un link morto): così
			// lo switcher è presente su tutte le pagine, non solo in home.
			$url = self::post_url( $lang );
			if ( '' === $url ) {
				$url = self::language_home_url( $lang );
			}

			$items[]  = sprintf( '<a class="palladio-lang" href="%1$s" title="%3$s">%2$s</a>', esc_url( $url ), esc_html( $label ), esc_attr( $title ) );
			$available++;
		}

		// Nessuna alternativa raggiungibile: niente switcher.
		if ( $available < 1 ) {
			return '';
		}

		return '<nav class="palladio-lang-switcher" aria-label="' . esc_attr__( 'Lingua', 'palladio' ) . '">' . implode( '', $items ) . '</nav>';
	}

	/**
	 * Aggiunge la pagina impostazioni "Lingue".
	 *
	 * @return void
	 */
	public function settings_menu() {
		add_submenu_page(
			'edit.php?post_type=pll_edificio',
			__( 'Lingue', 'palladio' ),
			__( 'Lingue', 'palladio' ),
			self::CAP,
			'palladio-lingue',
			array( $this, 'settings_page' )
		);
	}

	/**
	 * Renderizza la pagina impostazioni lingue.
	 *
	 * @return void
	 */
	public function settings_page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permesso negato.', 'palladio' ) );
		}

		$config  = self::config();
		$catalog = self::catalog();
		$saved   = isset( $_GET['palladio_msg'] ) ? sanitize_key( wp_unslash( $_GET['palladio_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Palladio — Lingue', 'palladio' ); ?></h1>

			<?php if ( 'saved' === $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Impostazioni lingue salvate.', 'palladio' ); ?></p></div>
			<?php endif; ?>

			<p class="description"><?php esc_html_e( 'Ogni lingua è una pagina dedicata collegata all’originale. Crea le versioni dal riquadro “Lingue” nella pagina dell’edificio o dell’unità.', 'palladio' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="palladio_save_languages">
				<?php wp_nonce_field( 'palladio_languages' ); ?>

				<h2><?php esc_html_e( 'Lingua sorgente', 'palladio' ); ?></h2>
				<select name="source">
					<?php foreach ( $catalog as $slug => $label ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $config['source'], $slug ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>

				<h2><?php esc_html_e( 'Lingue attive', 'palladio' ); ?></h2>
				<fieldset>
					<?php foreach ( $catalog as $slug => $label ) : ?>
						<label style="display:block;margin:.3rem 0;">
							<input type="checkbox" name="active[]" value="<?php echo esc_attr( $slug ); ?>"
								<?php checked( in_array( $slug, $config['active'], true ) ); ?>
								<?php disabled( $slug, $config['source'] ); ?>>
							<?php echo esc_html( $label ); ?>
						</label>
					<?php endforeach; ?>
				</fieldset>

				<?php submit_button( __( 'Salva lingue', 'palladio' ) ); ?>
			</form>

			<?php
			/**
			 * Sezioni aggiuntive della pagina Lingue (es. menu per lingua).
			 */
			do_action( 'palladio/languages/after_settings' );
			?>
		</div>
		<?php
	}

	/**
	 * Salva le impostazioni lingue.
	 *
	 * @return void
	 */
	public function save_settings() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permesso negato.', 'palladio' ) );
		}

		check_admin_referer( 'palladio_languages' );

		$catalog = self::catalog();

		$source = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : 'it';
		if ( ! array_key_exists( $source, $catalog ) ) {
			$source = 'it';
		}

		$active = array();
		if ( isset( $_POST['active'] ) && is_array( $_POST['active'] ) ) {
			foreach ( wp_unslash( $_POST['active'] ) as $lang ) {
				$lang = sanitize_key( $lang );
				if ( array_key_exists( $lang, $catalog ) ) {
					$active[] = $lang;
				}
			}
		}

		if ( ! in_array( $source, $active, true ) ) {
			array_unshift( $active, $source );
		}

		update_option(
			'palladio_languages',
			array(
				'source' => $source,
				'active' => array_values( array_unique( $active ) ),
			)
		);

		// Le regole /{lang}/... dipendono dalle lingue attive: forza la
		// rigenerazione delle rewrite al prossimo init.
		delete_option( 'palladio_rewrite_version' );

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type'    => 'pll_edificio',
					'page'         => 'palladio-lingue',
					'palladio_msg' => 'saved',
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}
}
