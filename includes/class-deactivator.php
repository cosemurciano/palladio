<?php
/**
 * Disattivazione del plugin.
 *
 * Non rimuove dati (CPT, meta, termini restano): la disattivazione è
 * reversibile. La rimozione definitiva avviene solo in uninstall.php.
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routine di disattivazione.
 */
class Palladio_Deactivator {

	/**
	 * Eseguita alla disattivazione del plugin.
	 *
	 * @return void
	 */
	public static function deactivate() {
		// I CPT non sono più registrati: ripulisce le rewrite rules.
		flush_rewrite_rules();
	}
}
