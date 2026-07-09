<?php
/**
 * Modulo Feeds — adapter base.
 *
 * Ogni formato di export estende questa classe mappando i campi Palladio nei
 * campi del portale (§5.8). Il manager interroga una volta le unità e le
 * passa a render().
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adapter di feed astratto.
 */
abstract class Palladio_Feeds_Adapter {

	/**
	 * Slug dell'adapter (usato nell'URL del feed).
	 *
	 * @return string
	 */
	abstract public function key();

	/**
	 * Nome leggibile.
	 *
	 * @return string
	 */
	abstract public function label();

	/**
	 * Content-Type dell'output.
	 *
	 * @return string
	 */
	abstract public function content_type();

	/**
	 * Genera il feed dall'elenco di unità.
	 *
	 * @param WP_Post[] $units Unità.
	 * @return string
	 */
	abstract public function render( array $units );

	/**
	 * Interroga le unità esportabili (in vendita).
	 *
	 * @return WP_Post[]
	 */
	public function query_units() {
		/**
		 * Stati inclusi nell'export.
		 *
		 * @param string[] $states Slug stato.
		 * @param string   $key    Adapter.
		 */
		$states = apply_filters(
			'palladio/feeds/states',
			array( 'disponibile', 'riservata', 'in_trattativa' ),
			$this->key()
		);

		$query = new WP_Query(
			array(
				'post_type'      => 'pll_unita',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'tax_query'      => array(
					array(
						'taxonomy' => 'pll_stato',
						'field'    => 'slug',
						'terms'    => $states,
					),
				),
				'no_found_rows'  => true,
			)
		);

		/**
		 * Filtra le unità esportate.
		 *
		 * @param WP_Post[] $posts Unità.
		 * @param string    $key   Adapter.
		 */
		return apply_filters( 'palladio/feeds/units', $query->posts, $this->key() );
	}

	/**
	 * Legge un meta dell'unità.
	 *
	 * @param int    $post_id ID.
	 * @param string $key     Chiave senza prefisso.
	 * @return mixed
	 */
	protected function meta( $post_id, $key ) {
		return get_post_meta( $post_id, '_pll_' . $key, true );
	}

	/**
	 * Primo termine (slug o nome) di una tassonomia.
	 *
	 * @param int    $post_id  ID.
	 * @param string $taxonomy Tassonomia.
	 * @param string $field    'name' | 'slug'.
	 * @return string
	 */
	protected function term( $post_id, $taxonomy, $field = 'name' ) {
		$terms = get_the_terms( $post_id, $taxonomy );
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return '';
		}
		$term = array_shift( $terms );
		return 'slug' === $field ? $term->slug : $term->name;
	}

	/**
	 * Dato edificio genitore, restituisce i suoi meta utili.
	 *
	 * @param int $unit_id ID unità.
	 * @return array{indirizzo:string,lat:string,lng:string,titolo:string}
	 */
	protected function building( $unit_id ) {
		$bid = wp_get_post_parent_id( $unit_id );
		if ( ! $bid ) {
			return array( 'indirizzo' => '', 'lat' => '', 'lng' => '', 'titolo' => '' );
		}
		return array(
			'indirizzo' => (string) get_post_meta( $bid, '_pll_indirizzo', true ),
			'lat'       => (string) get_post_meta( $bid, '_pll_geo_lat', true ),
			'lng'       => (string) get_post_meta( $bid, '_pll_geo_lng', true ),
			'titolo'    => get_the_title( $bid ),
		);
	}

	/**
	 * URL delle immagini dell'unità (featured + gallery allegata).
	 *
	 * @param int $unit_id ID.
	 * @param int $limit   Massimo immagini.
	 * @return string[]
	 */
	protected function images( $unit_id, $limit = 20 ) {
		$urls = array();

		$thumb = get_the_post_thumbnail_url( $unit_id, 'large' );
		if ( $thumb ) {
			$urls[] = $thumb;
		}

		$attachments = get_attached_media( 'image', $unit_id );
		foreach ( $attachments as $att ) {
			$url = wp_get_attachment_image_url( $att->ID, 'large' );
			if ( $url && ! in_array( $url, $urls, true ) ) {
				$urls[] = $url;
			}
		}

		return array_slice( $urls, 0, $limit );
	}
}
