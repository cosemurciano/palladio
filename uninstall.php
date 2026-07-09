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

$options = array(
	'palladio_version',
	'palladio_db_version',
	'palladio_kb_db_version',
	'palladio_chats_db_version',
	'palladio_languages',
	'palladio_ai',
	'palladio_ai_key',
	'palladio_ai_usage',
	'palladio_agent',
	'palladio_feeds_token',
	'palladio_gdpr_text',
);
foreach ( $options as $option ) {
	delete_option( $option );
}

wp_clear_scheduled_hook( 'palladio_feeds_regen' );

$role = get_role( 'administrator' );
if ( $role ) {
	$role->remove_cap( 'manage_palladio' );
	$role->remove_cap( 'edit_palladio_content' );
}
