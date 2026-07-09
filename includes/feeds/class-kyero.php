<?php
/**
 * Modulo Feeds — adapter Kyero (XML v3).
 *
 * Formato aperto e documentato, accettato da Gate-away.com, Kyero e
 * Idealista international. Costruito con DOMDocument (escaping sicuro).
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Feed in formato Kyero 3.
 */
class Palladio_Feeds_Kyero extends Palladio_Feeds_Adapter {

	/**
	 * {@inheritDoc}
	 */
	public function key() {
		return 'kyero';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'Kyero / Gate-away (XML)', 'palladio' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function content_type() {
		return 'application/xml; charset=UTF-8';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param WP_Post[] $units Unità.
	 * @return string
	 */
	public function render( array $units ) {
		$dom               = new DOMDocument( '1.0', 'UTF-8' );
		$dom->formatOutput = true;

		$root = $dom->createElement( 'root' );
		$dom->appendChild( $root );

		$kyero = $dom->createElement( 'kyero' );
		$this->child( $dom, $kyero, 'feed_version', '3' );
		$root->appendChild( $kyero );

		$lang = class_exists( 'Palladio_I18n_Languages' ) ? Palladio_I18n_Languages::source() : 'it';

		foreach ( $units as $unit ) {
			$id       = $unit->ID;
			$building = $this->building( $id );

			$property = $dom->createElement( 'property' );

			$this->child( $dom, $property, 'id', (string) $id );
			$this->child( $dom, $property, 'date', get_post_modified_time( 'c', true, $unit ) );
			$this->child( $dom, $property, 'ref', (string) $id );
			$this->child( $dom, $property, 'price', (string) (int) $this->meta( $id, 'prezzo' ) );
			$this->child( $dom, $property, 'currency', 'EUR' );
			$this->child( $dom, $property, 'price_freq', 'sale' );
			$this->child( $dom, $property, 'type', $this->term( $id, 'pll_tipologia' ) );
			$this->child( $dom, $property, 'town', $building['indirizzo'] );
			$this->child( $dom, $property, 'province', $building['titolo'] );
			$this->child( $dom, $property, 'country', 'Italy' );

			if ( '' !== $building['lat'] && '' !== $building['lng'] ) {
				$location = $dom->createElement( 'location' );
				$this->child( $dom, $location, 'latitude', $building['lat'] );
				$this->child( $dom, $location, 'longitude', $building['lng'] );
				$property->appendChild( $location );
			}

			$this->child( $dom, $property, 'beds', (string) (int) $this->meta( $id, 'camere' ) );
			$this->child( $dom, $property, 'baths', (string) (int) $this->meta( $id, 'bagni' ) );

			$energy = $this->meta( $id, 'classe_energetica' );
			if ( $energy ) {
				$rating = $dom->createElement( 'energy_rating' );
				$this->child( $dom, $rating, 'consumption', (string) $energy );
				$property->appendChild( $rating );
			}

			$surface = $dom->createElement( 'surface_area' );
			$this->child( $dom, $surface, 'built', (string) (int) $this->meta( $id, 'mq_commerciali' ) );
			$property->appendChild( $surface );

			// Descrizione per lingua.
			$desc = $dom->createElement( 'desc' );
			$node = $dom->createElement( $lang );
			$node->appendChild( $dom->createCDATASection( wp_strip_all_tags( (string) $unit->post_content ) ) );
			$desc->appendChild( $node );
			$property->appendChild( $desc );

			// URL.
			$url_node = $dom->createElement( 'url' );
			$en       = $dom->createElement( $lang );
			$en->appendChild( $dom->createCDATASection( (string) get_permalink( $unit ) ) );
			$url_node->appendChild( $en );
			$property->appendChild( $url_node );

			// Immagini.
			$images = $this->images( $id );
			if ( $images ) {
				$images_node = $dom->createElement( 'images' );
				$n           = 1;
				foreach ( $images as $img ) {
					$image = $dom->createElement( 'image' );
					$image->setAttribute( 'id', (string) $n );
					$url = $dom->createElement( 'url' );
					$url->appendChild( $dom->createCDATASection( $img ) );
					$image->appendChild( $url );
					$images_node->appendChild( $image );
					$n++;
				}
				$property->appendChild( $images_node );
			}

			$root->appendChild( $property );
		}

		return (string) $dom->saveXML();
	}

	/**
	 * Crea e appende un nodo figlio testuale.
	 *
	 * @param DOMDocument $dom    Documento.
	 * @param DOMElement  $parent Genitore.
	 * @param string      $name   Nome nodo.
	 * @param string      $value  Valore.
	 * @return void
	 */
	private function child( $dom, $parent, $name, $value ) {
		$node = $dom->createElement( $name );
		$node->appendChild( $dom->createTextNode( (string) $value ) );
		$parent->appendChild( $node );
	}
}
