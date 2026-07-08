<?php
/**
 * Autoloader minimale per le classi del plugin.
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Carica le classi Palladio_* dai file includes/ seguendo la convenzione WP.
 */
class Palladio_Autoloader {

	/**
	 * Registra l'autoloader nello stack SPL.
	 *
	 * @return void
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	/**
	 * Risolve un nome classe in un percorso file e lo include se esiste.
	 *
	 * Esempi:
	 *   Palladio              -> includes/class-palladio.php
	 *   Palladio_Activator    -> includes/class-activator.php
	 *   Palladio_Core_CPT     -> includes/core/class-cpt.php
	 *   Palladio_Core_Meta    -> includes/core/class-meta.php
	 *
	 * @param string $class Nome completo della classe richiesta.
	 * @return void
	 */
	public static function autoload( $class ) {
		if ( 'Palladio' !== $class && 0 !== strpos( $class, 'Palladio_' ) ) {
			return;
		}

		// Caso speciale: la classe principale.
		if ( 'Palladio' === $class ) {
			$path = PALLADIO_DIR . 'includes/class-palladio.php';
			if ( is_readable( $path ) ) {
				require_once $path;
			}
			return;
		}

		// Rimuove il prefisso "Palladio_" e spezza in segmenti.
		$relative = substr( $class, strlen( 'Palladio_' ) );
		$parts    = explode( '_', $relative );

		// L'ultimo segmento è il nome del file (class-<nome>.php),
		// gli eventuali segmenti precedenti sono la sotto-cartella.
		$file_slug = strtolower( array_pop( $parts ) );
		$sub_dir   = '';
		if ( ! empty( $parts ) ) {
			$sub_dir = strtolower( implode( '/', $parts ) ) . '/';
		}

		$path = PALLADIO_DIR . 'includes/' . $sub_dir . 'class-' . $file_slug . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}
