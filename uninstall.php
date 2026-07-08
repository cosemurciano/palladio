<?php
/**
 * Disinstallazione del plugin Palladio.
 *
 * Rimuove le opzioni e le capability create dal plugin. I contenuti
 * (edifici, unità, scenari) NON vengono cancellati automaticamente per
 * evitare perdite di dati: la rimozione dei CPT è lasciata all'utente.
 *
 * @package Palladio
 */

// Eseguito solo da WordPress in fase di disinstallazione.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'palladio_version' );

$role = get_role( 'administrator' );
if ( $role ) {
	$role->remove_cap( 'manage_palladio' );
	$role->remove_cap( 'edit_palladio_content' );
}
