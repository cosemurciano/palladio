<?php
/**
 * Modulo Core — sitemap.xml per Google.
 *
 * Serve /sitemap.xml con tutte le pagine pubblicate del sito: home (anche
 * per lingua), edifici, unità, scenari, storia, territorio, pagine e
 * articoli WordPress. Per i contenuti Palladio ogni <url> include gli
 * alternate hreflang (xhtml:link) verso le versioni in lingua e l'immagine
 * in evidenza (image:image). Il riferimento alla sitemap è aggiunto anche
 * a robots.txt.
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generatore sitemap.xml.
 */
class Palladio_Core_Sitemap {

	/**
	 * CPT del plugin inclusi con hreflang.
	 *
	 * @var string[]
	 */
	private $palladio_types = array( 'pll_edificio', 'pll_unita', 'pll_scenario', 'pll_storia', 'pll_territorio' );

	/**
	 * Registra gli hook.
	 *
	 * @return void
	 */
	public function register() {
		if ( ! apply_filters( 'palladio/sitemap/enabled', true ) ) {
			return;
		}

		add_action( 'init', array( $this, 'add_rewrite_rule' ), 20 );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render' ), 0 );
		add_filter( 'robots_txt', array( $this, 'robots_txt' ), 10, 1 );
	}

	/**
	 * Rewrite: /palladio-sitemap.xml (nome dedicato: convive con la sitemap
	 * del plugin SEO su /sitemap.xml).
	 *
	 * @return void
	 */
	public function add_rewrite_rule() {
		add_rewrite_rule( '^palladio-sitemap\.xml$', 'index.php?palladio_sitemap=1', 'top' );
	}

	/**
	 * Query var della sitemap.
	 *
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public function query_vars( $vars ) {
		$vars[] = 'palladio_sitemap';
		return $vars;
	}

	/**
	 * Riga Sitemap in robots.txt.
	 *
	 * @param string $output Contenuto robots.txt.
	 * @return string
	 */
	public function robots_txt( $output ) {
		return rtrim( (string) $output ) . "\n\nSitemap: " . home_url( '/palladio-sitemap.xml' ) . "\n";
	}

	/**
	 * Se la richiesta è /sitemap.xml, emette l'XML e termina.
	 *
	 * @return void
	 */
	public function maybe_render() {
		if ( ! get_query_var( 'palladio_sitemap' ) ) {
			return;
		}

		status_header( 200 );
		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex' ); // La sitemap stessa non va indicizzata.
		nocache_headers();

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- XML costruito con esc_url/esc_attr.
		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
			. ' xmlns:xhtml="http://www.w3.org/1999/xhtml"'
			. ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";

		foreach ( $this->entries() as $entry ) {
			echo "\t<url>\n";
			echo "\t\t<loc>" . esc_url( $entry['loc'] ) . "</loc>\n";
			if ( ! empty( $entry['lastmod'] ) ) {
				echo "\t\t<lastmod>" . esc_html( $entry['lastmod'] ) . "</lastmod>\n";
			}
			foreach ( ( $entry['alternates'] ?? array() ) as $hreflang => $href ) {
				echo "\t\t" . '<xhtml:link rel="alternate" hreflang="' . esc_attr( $hreflang ) . '" href="' . esc_url( $href ) . '"/>' . "\n";
			}
			if ( ! empty( $entry['image'] ) ) {
				echo "\t\t<image:image><image:loc>" . esc_url( $entry['image'] ) . "</image:loc></image:image>\n";
			}
			echo "\t</url>\n";
		}

		echo '</urlset>' . "\n";
		// phpcs:enable
		exit;
	}

	/**
	 * Elenco delle voci della sitemap.
	 *
	 * @return array<int,array{loc:string,lastmod:string,alternates?:array<string,string>,image?:string}>
	 */
	private function entries() {
		$entries = array();

		// ------------------------------------------------------------- home
		$entries[] = array(
			'loc'     => home_url( '/' ),
			'lastmod' => $this->latest_modified(),
		);

		// Home per lingua (/{lang}/) quando esiste la versione dell'edificio.
		if ( class_exists( 'Palladio_I18n_Languages' ) && function_exists( 'palladio_home_building_id' ) ) {
			$home_building = palladio_home_building_id();
			$source        = Palladio_I18n_Languages::source();
			if ( $home_building ) {
				foreach ( Palladio_I18n_Languages::active() as $lang ) {
					if ( $lang === $source ) {
						continue;
					}
					$sibling = Palladio_I18n_Translator::sibling_in( $home_building, $lang, array( 'publish' ) );
					if ( $sibling ) {
						$entries[] = array(
							'loc'     => home_url( '/' . $lang . '/' ),
							'lastmod' => get_the_modified_date( 'c', $sibling ),
						);
					}
				}
			}
		}

		// -------------------------------------------- contenuti Palladio
		$palladio_posts = get_posts( array(
			'post_type'      => $this->palladio_types,
			'post_status'    => 'publish',
			'posts_per_page' => 2000,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		) );

		$home_building = function_exists( 'palladio_home_building_id' ) ? palladio_home_building_id() : 0;

		foreach ( $palladio_posts as $post ) {
			// L'edificio homepage è già rappresentato dalla radice.
			if ( (int) $post->ID === $home_building ) {
				continue;
			}

			$entry = array(
				'loc'     => get_permalink( $post ),
				'lastmod' => get_the_modified_date( 'c', $post ),
			);

			$image = get_the_post_thumbnail_url( $post->ID, 'full' );
			if ( $image ) {
				$entry['image'] = $image;
			}

			// Alternate hreflang verso le versioni in lingua pubblicate.
			if ( class_exists( 'Palladio_I18n_Translator' ) ) {
				$siblings = Palladio_I18n_Translator::siblings( $post->ID, array( 'publish' ) );
				if ( count( $siblings ) > 1 ) {
					$alternates = array();
					foreach ( $siblings as $lang => $sid ) {
						$alternates[ $lang ] = get_permalink( $sid );
					}
					$source = Palladio_I18n_Languages::source();
					if ( isset( $alternates[ $source ] ) ) {
						$alternates['x-default'] = $alternates[ $source ];
					}
					$entry['alternates'] = $alternates;
				}
			}

			$entries[] = $entry;
		}

		// --------------------------------------- pagine e articoli standard
		$standard = get_posts( array(
			'post_type'      => array( 'page', 'post' ),
			'post_status'    => 'publish',
			'posts_per_page' => 2000,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		) );

		$front_page = (int) get_option( 'page_on_front', 0 );
		foreach ( $standard as $post ) {
			if ( (int) $post->ID === $front_page ) {
				continue; // Già rappresentata dalla radice.
			}
			$entry = array(
				'loc'     => get_permalink( $post ),
				'lastmod' => get_the_modified_date( 'c', $post ),
			);
			$image = get_the_post_thumbnail_url( $post->ID, 'full' );
			if ( $image ) {
				$entry['image'] = $image;
			}
			$entries[] = $entry;
		}

		// ------------------------------------------------ archivi Palladio
		foreach ( array( 'pll_unita', 'pll_scenario' ) as $post_type ) {
			$link = get_post_type_archive_link( $post_type );
			if ( $link ) {
				$entries[] = array(
					'loc'     => $link,
					'lastmod' => $this->latest_modified( $post_type ),
				);
			}
		}

		return $entries;
	}

	/**
	 * Data di ultima modifica più recente (per home e archivi).
	 *
	 * @param string $post_type CPT specifico o vuoto per tutti.
	 * @return string
	 */
	private function latest_modified( $post_type = '' ) {
		$posts = get_posts( array(
			'post_type'      => $post_type ? $post_type : array_merge( $this->palladio_types, array( 'page', 'post' ) ),
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		) );

		return $posts ? get_the_modified_date( 'c', $posts[0] ) : '';
	}
}
