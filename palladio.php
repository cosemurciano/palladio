<?php
/**
 * Plugin Name:       Palladio
 * Plugin URI:        https://github.com/cosemurciano/palladio
 * Description:       Sistema di regia per la vendita frazionata di immobili: un edificio, molte unità, una campagna. Core, Presenter, Regia, i18n, AI, Agent, Feeds — vedi PALLADIO-Progetto.md.
 * Version:           0.15.1
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Cosè Murciano
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       palladio
 * Domain Path:       /languages
 *
 * @package Palladio
 */

// Impedisce l'accesso diretto.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// -----------------------------------------------------------------------------
// Costanti del plugin.
// -----------------------------------------------------------------------------
define( 'PALLADIO_VERSION', '0.15.1' );
define( 'PALLADIO_FILE', __FILE__ );
define( 'PALLADIO_DIR', plugin_dir_path( __FILE__ ) );
define( 'PALLADIO_URI', plugin_dir_url( __FILE__ ) );
define( 'PALLADIO_BASENAME', plugin_basename( __FILE__ ) );

// -----------------------------------------------------------------------------
// Autoloader minimale per le classi del plugin (Palladio_*).
//
// Mappa il nome classe al percorso file secondo la convenzione WordPress:
// Palladio_Core_CPT -> includes/core/class-cpt.php
// -----------------------------------------------------------------------------
require_once PALLADIO_DIR . 'includes/class-autoloader.php';
Palladio_Autoloader::register();

// -----------------------------------------------------------------------------
// Hook di attivazione / disattivazione.
// -----------------------------------------------------------------------------
register_activation_hook( __FILE__, array( 'Palladio_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Palladio_Deactivator', 'deactivate' ) );

// -----------------------------------------------------------------------------
// Bootstrap: avvia l'orchestratore quando WordPress è pronto.
// -----------------------------------------------------------------------------
/**
 * Restituisce l'istanza principale del plugin.
 *
 * @return Palladio
 */
function palladio() {
	return Palladio::instance();
}

palladio()->run();
