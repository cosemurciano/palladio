<?php
/**
 * Modulo Lingue — URL con prefisso lingua e basi tradotte.
 *
 * Le versioni in lingua vivono sotto /{lang}/ con la voce di base dell'URL
 * tradotta: /en/building/{slug}, /fr/batiment/{slug}, /de/gebaeude/{slug}.
 * La lingua sorgente resta senza prefisso (/edificio/{slug}) — standard SEO.
 *
 * I permalink dei post tradotti vengono riscritti di conseguenza, così
 * switcher, hreflang, schema.org e sitemap usano automaticamente gli URL
 * localizzati; il vecchio URL non prefissato risponde con un 301 canonico.
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rewrite e permalink localizzati.
 */
class Palladio_I18n_Urls {

	/**
	 * Basi URL per CPT e lingua (filtrabili con palladio/i18n/url_bases).
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function bases() {
		return apply_filters(
			'palladio/i18n/url_bases',
			array(
				'pll_edificio'   => array( 'it' => 'edificio', 'en' => 'building', 'de' => 'gebaeude', 'fr' => 'batiment' ),
				'pll_unita'      => array( 'it' => 'unita', 'en' => 'apartment', 'de' => 'wohnung', 'fr' => 'appartement' ),
				'pll_scenario'   => array( 'it' => 'scenario', 'en' => 'scenario', 'de' => 'szenario', 'fr' => 'scenario' ),
				'pll_storia'     => array( 'it' => 'storia', 'en' => 'history', 'de' => 'geschichte', 'fr' => 'histoire' ),
				'pll_territorio' => array( 'it' => 'territorio', 'en' => 'territory', 'de' => 'umgebung', 'fr' => 'territoire' ),
			)
		);
	}

	/**
	 * Base URL per un CPT in una lingua (fallback: slug della sorgente).
	 *
	 * @param string $post_type CPT.
	 * @param string $lang      Lingua.
	 * @return string
	 */
	public static function base_for( $post_type, $lang ) {
		$bases = self::bases();
		if ( isset( $bases[ $post_type ][ $lang ] ) ) {
			return $bases[ $post_type ][ $lang ];
		}
		return isset( $bases[ $post_type ]['it'] ) ? $bases[ $post_type ]['it'] : $post_type;
	}

	/**
	 * Registra gli hook.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( $this, 'add_rewrite_rules' ), 20 );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_filter( 'request', array( $this, 'resolve_lang_home' ) );
		add_filter( 'post_type_link', array( $this, 'localize_permalink' ), 10, 2 );
		add_filter( 'post_type_archive_link', array( $this, 'localize_archive_link' ), 10, 2 );
	}

	/**
	 * Query var per la home di lingua (/{lang}/).
	 *
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public function query_vars( $vars ) {
		$vars[] = 'palladio_lang_home';
		return $vars;
	}

	/**
	 * Regole di rewrite per ogni lingua attiva diversa dalla sorgente.
	 *
	 * @return void
	 */
	public function add_rewrite_rules() {
		$source   = Palladio_I18n_Languages::source();
		$archives = array( 'pll_edificio', 'pll_unita', 'pll_scenario' );

		foreach ( Palladio_I18n_Languages::active() as $lang ) {
			if ( $lang === $source ) {
				continue;
			}

			// Home di lingua: /{lang}/ → landing dell'edificio homepage in lingua.
			add_rewrite_rule(
				'^' . $lang . '/?$',
				'index.php?lang=' . $lang . '&palladio_lang_home=1',
				'top'
			);

			foreach ( array_keys( self::bases() ) as $post_type ) {
				$base = self::base_for( $post_type, $lang );

				// Scheda singola: /{lang}/{base}/{slug}/.
				add_rewrite_rule(
					'^' . $lang . '/' . $base . '/([^/]+)/?$',
					'index.php?post_type=' . $post_type . '&name=$matches[1]&lang=' . $lang,
					'top'
				);

				// Archivio: /{lang}/{base}/.
				if ( in_array( $post_type, $archives, true ) ) {
					add_rewrite_rule(
						'^' . $lang . '/' . $base . '/?$',
						'index.php?post_type=' . $post_type . '&lang=' . $lang,
						'top'
					);
				}
			}
		}
	}

	/**
	 * /{lang}/ serve la landing dell'edificio homepage nella lingua richiesta.
	 *
	 * @param array $vars Query vars della richiesta.
	 * @return array
	 */
	public function resolve_lang_home( $vars ) {
		if ( empty( $vars['palladio_lang_home'] ) || empty( $vars['lang'] ) ) {
			return $vars;
		}

		unset( $vars['palladio_lang_home'] );

		$home = function_exists( 'palladio_home_building_id' ) ? palladio_home_building_id() : 0;
		if ( $home && class_exists( 'Palladio_I18n_Translator' ) ) {
			$sibling = Palladio_I18n_Translator::sibling_in( $home, sanitize_key( $vars['lang'] ), array( 'publish' ) );
			if ( $sibling ) {
				return array(
					'post_type' => 'pll_edificio',
					'p'         => $sibling,
					'lang'      => sanitize_key( $vars['lang'] ),
				);
			}
		}

		return $vars;
	}

	/**
	 * Permalink localizzato per i post tradotti: /{lang}/{base}/{slug}/.
	 *
	 * @param string  $permalink Permalink corrente.
	 * @param WP_Post $post      Post.
	 * @return string
	 */
	public function localize_permalink( $permalink, $post ) {
		if ( ! $post instanceof WP_Post || ! array_key_exists( $post->post_type, self::bases() ) ) {
			return $permalink;
		}
		if ( 'publish' !== $post->post_status || '' === $post->post_name ) {
			return $permalink;
		}
		if ( ! class_exists( 'Palladio_I18n_Translator' ) ) {
			return $permalink;
		}

		$lang = Palladio_I18n_Translator::get_lang( $post->ID );
		if ( $lang === Palladio_I18n_Languages::source() ) {
			return $permalink;
		}

		return home_url( user_trailingslashit( '/' . $lang . '/' . self::base_for( $post->post_type, $lang ) . '/' . $post->post_name ) );
	}

	/**
	 * Link archivio localizzato quando la lingua corrente non è la sorgente.
	 *
	 * @param string $link      Link archivio.
	 * @param string $post_type CPT.
	 * @return string
	 */
	public function localize_archive_link( $link, $post_type ) {
		if ( is_admin() || ! array_key_exists( $post_type, self::bases() ) ) {
			return $link;
		}

		$current = Palladio_I18n_Languages::current();
		if ( $current === Palladio_I18n_Languages::source() ) {
			return $link;
		}

		return home_url( user_trailingslashit( '/' . $current . '/' . self::base_for( $post_type, $current ) ) );
	}
}
