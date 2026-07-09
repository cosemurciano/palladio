<?php
/**
 * Modulo Feeds — adapter CSV generico.
 *
 * Export portabile (una riga per unità) utile per import manuali o portali
 * che accettano CSV. Le colonne sono filtrabili.
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Feed in formato CSV.
 */
class Palladio_Feeds_Csv extends Palladio_Feeds_Adapter {

	/**
	 * {@inheritDoc}
	 */
	public function key() {
		return 'csv';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'CSV generico', 'palladio' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function content_type() {
		return 'text/csv; charset=UTF-8';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param WP_Post[] $units Unità.
	 * @return string
	 */
	public function render( array $units ) {
		$columns = array(
			'ref',
			'titolo',
			'prezzo',
			'valuta',
			'tipologia',
			'stato',
			'camere',
			'bagni',
			'mq',
			'classe_energetica',
			'edificio',
			'url',
		);

		$handle = fopen( 'php://temp', 'r+' );
		fputcsv( $handle, $columns );

		foreach ( $units as $unit ) {
			$id       = $unit->ID;
			$building = $this->building( $id );

			fputcsv(
				$handle,
				array(
					$id,
					get_the_title( $unit ),
					(int) $this->meta( $id, 'prezzo' ),
					'EUR',
					$this->term( $id, 'pll_tipologia' ),
					$this->term( $id, 'pll_stato' ),
					(int) $this->meta( $id, 'camere' ),
					(int) $this->meta( $id, 'bagni' ),
					(int) $this->meta( $id, 'mq_commerciali' ),
					(string) $this->meta( $id, 'classe_energetica' ),
					$building['titolo'],
					get_permalink( $unit ),
				)
			);
		}

		rewind( $handle );
		$csv = stream_get_contents( $handle );
		fclose( $handle );

		return (string) $csv;
	}
}
