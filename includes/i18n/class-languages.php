<?php
/**
 * Modulo Lingue — configurazione e risoluzione lingua.
 *
 * Gestisce le lingue attive, la lingua sorgente, il rilevamento della
 * lingua corrente dalla query (`?lang=xx`), l'applicazione delle traduzioni
 * sul frontend (titolo/contenuto/riassunto), gli hreflang, lo switcher e la
 * pagina impostazioni. Modalità nativa a zero dipendenze (§5.4.A).
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

	/**
	 * Capability per la pagina impostazioni.
	 */
	const CAP = 'manage_palladio';

	/**
	 * CPT su cui applicare le traduzioni.
	 *
	 * @var string[]
	 */
	private $post_types = array( 'pll_edificio', 'pll_unita', 'pll_scenario' );

	/**
	 * Registra hook frontend e admin.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'query_vars', array( $this, 'query_vars' ) );

		add_shortcode( 'palladio_lang_switcher', array( $this, 'switcher' ) );

		if ( ! is_admin() ) {
			add_filter( 'the_title', array( $this, 'filter_title' ), 10, 2 );
			add_filter( 'the_content', array( $this, 'filter_content' ), 9 );
			add_filter( 'get_the_excerpt', array( $this, 'filter_excerpt' ), 10, 2 );
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

		$catalog         = self::catalog();
		$config['source'] = array_key_exists( $config['source'], $catalog ) ? $config['source'] : 'it';

		$config['active'] = array_values(
			array_filter(
				(array) $config['active'],
				static function ( $lang ) use ( $catalog ) {
					return array_key_exists( $lang, $catalog );
				}
			)
		);

		// La sorgente è sempre attiva.
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
		$config = self::config();
		return $config['source'];
	}

	/**
	 * Lingue attive.
	 *
	 * @return string[]
	 */
	public static function active() {
		$config = self::config();
		return $config['active'];
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
	 * Lingua corrente della richiesta (in admin sempre la sorgente).
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
	 * Restituisce l'URL di una lingua per un permalink.
	 *
	 * @param string $lang Lingua.
	 * @param string $url  URL base (default permalink corrente).
	 * @return string
	 */
	public static function url( $lang, $url = '' ) {
		if ( '' === $url ) {
			$url = get_permalink();
			if ( ! $url ) {
				$url = home_url( '/' );
			}
		}

		if ( $lang === self::source() ) {
			return remove_query_arg( 'lang', $url );
		}

		return add_query_arg( 'lang', $lang, $url );
	}

	/**
	 * Verifica se il post corrente è traducibile nella lingua corrente.
	 *
	 * @param int $post_id ID post.
	 * @return bool
	 */
	private function should_translate( $post_id ) {
		if ( self::current() === self::source() ) {
			return false;
		}
		return in_array( get_post_type( $post_id ), $this->post_types, true );
	}

	/**
	 * Filtra il titolo.
	 *
	 * @param string $title   Titolo.
	 * @param int    $post_id ID post.
	 * @return string
	 */
	public function filter_title( $title, $post_id = 0 ) {
		if ( ! $post_id || ! $this->should_translate( $post_id ) ) {
			return $title;
		}
		return Palladio_I18n_Translator::translate_field( $post_id, 'title', $title );
	}

	/**
	 * Filtra il contenuto.
	 *
	 * @param string $content Contenuto.
	 * @return string
	 */
	public function filter_content( $content ) {
		$post_id = get_the_ID();
		if ( ! $post_id || ! $this->should_translate( $post_id ) ) {
			return $content;
		}
		return Palladio_I18n_Translator::translate_field( $post_id, 'content', $content );
	}

	/**
	 * Filtra il riassunto.
	 *
	 * @param string  $excerpt Riassunto.
	 * @param WP_Post $post    Post.
	 * @return string
	 */
	public function filter_excerpt( $excerpt, $post = null ) {
		$post_id = $post instanceof WP_Post ? $post->ID : get_the_ID();
		if ( ! $post_id || ! $this->should_translate( $post_id ) ) {
			return $excerpt;
		}
		return Palladio_I18n_Translator::translate_field( $post_id, 'excerpt', $excerpt );
	}

	/**
	 * Stampa i tag hreflang sulle schede dei CPT.
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
			// Serve l'alternativa solo se sorgente o traduzione pubblicata.
			if ( $lang !== $source && ! Palladio_I18n_Translator::is_published( $post_id, $lang ) ) {
				continue;
			}
			printf(
				'<link rel="alternate" hreflang="%1$s" href="%2$s" />' . "\n",
				esc_attr( $lang ),
				esc_url( self::url( $lang, get_permalink( $post_id ) ) )
			);
		}

		printf(
			'<link rel="alternate" hreflang="x-default" href="%s" />' . "\n",
			esc_url( self::url( $source, get_permalink( $post_id ) ) )
		);
	}

	/**
	 * Shortcode switcher lingua.
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

		$items = array();
		foreach ( $active as $lang ) {
			$label = $catalog[ $lang ] ?? strtoupper( $lang );
			if ( $lang === $current ) {
				$items[] = sprintf( '<span class="palladio-lang is-current" aria-current="true">%s</span>', esc_html( $label ) );
			} else {
				$items[] = sprintf(
					'<a class="palladio-lang" href="%s">%s</a>',
					esc_url( self::url( $lang ) ),
					esc_html( $label )
				);
			}
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

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="palladio_save_languages">
				<?php wp_nonce_field( 'palladio_languages' ); ?>

				<h2><?php esc_html_e( 'Lingua sorgente', 'palladio' ); ?></h2>
				<p class="description"><?php esc_html_e( 'La lingua in cui inserisci i contenuti originali.', 'palladio' ); ?></p>
				<select name="source">
					<?php foreach ( $catalog as $slug => $label ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $config['source'], $slug ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>

				<h2><?php esc_html_e( 'Lingue attive', 'palladio' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Le lingue in cui il sito può servire i contenuti. La sorgente è sempre attiva.', 'palladio' ); ?></p>
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
