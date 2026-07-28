<?php
/**
 * Modulo Core — redirect dei contenuti eliminati (mai 404).
 *
 * Intercetta ogni richiesta che WordPress risolverebbe in 404 — pagine,
 * schede del plugin, allegati e file media mancanti — e la reindirizza:
 *
 *  1. REGOLE SINGOLE: exact (con supporto prefisso `*`) oppure REGEX, con
 *     codice 301/302/410 e priorità (order). Le exact vengono sempre
 *     valutate prima; tra le regex vince l'order più basso. Match
 *     case-insensitive, trailing slash indifferente, query string esclusa
 *     dal match ma PRESERVATA e accodata alla destinazione.
 *  2. REGOLA GENERALE: unico redirect per tutto il resto.
 *
 * Import massivo da CSV (source,target,status,match_type,order) con
 * anteprima dry-run, normalizzazione, risoluzione delle catene, report ed
 * export delle regole correnti nello stesso formato.
 *
 * Le regole vivono in un'unica option (autoload disattivo, cache object
 * di WordPress): il lookup avviene a inizio template_redirect (priorità 0)
 * e SOLO sui 404, quindi le pagine reali non sono mai toccate. Escluse
 * admin, login, REST e AJAX.
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Redirect 301/302/410 per contenuti che non esistono più.
 */
class Palladio_Core_Redirects {

	const CAP        = 'manage_palladio';
	const CAP_IMPORT = 'manage_options';
	const OPTION     = 'palladio_redirects';
	const LOG_OPTION = 'palladio_redirects_log';
	const LOG_MAX    = 50;
	const CSV_MAX    = 1048576; // 1 MB.

	/**
	 * Registra gli hook.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 0 );

		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'menu' ), 998 );
			add_action( 'admin_post_palladio_save_redirects', array( $this, 'save' ) );
			add_action( 'admin_post_palladio_redirects_export', array( $this, 'export_csv' ) );
			add_action( 'admin_post_palladio_redirects_preview', array( $this, 'handle_preview' ) );
			add_action( 'admin_post_palladio_redirects_import', array( $this, 'handle_import' ) );
			add_action( 'admin_post_palladio_redirects_errors_csv', array( $this, 'download_errors_csv' ) );
		}
	}

	/**
	 * Configurazione con default; le regole storiche ricevono match/order.
	 *
	 * @return array{general:array{enabled:int,target:string,code:int},rules:array}
	 */
	public static function config() {
		$defaults = array(
			'general' => array(
				'enabled' => 0,
				'target'  => '',
				'code'    => 301,
			),
			'rules'   => array(), // [ {source,target,code,match,order,hits,last} ]
		);

		$config = get_option( self::OPTION, array() );
		$config = wp_parse_args( is_array( $config ) ? $config : array(), $defaults );

		$config['general'] = wp_parse_args( is_array( $config['general'] ) ? $config['general'] : array(), $defaults['general'] );
		if ( ! is_array( $config['rules'] ) ) {
			$config['rules'] = array();
		}

		foreach ( $config['rules'] as $i => $rule ) {
			$config['rules'][ $i ]['match'] = in_array( $rule['match'] ?? '', array( 'exact', 'regex' ), true ) ? $rule['match'] : 'exact';
			$config['rules'][ $i ]['order'] = isset( $rule['order'] ) ? (int) $rule['order'] : 10;
		}

		return $config;
	}

	/**
	 * Normalizza un percorso per il confronto: solo path, decodificato,
	 * minuscolo, con slash iniziale e senza slash finale (root esclusa).
	 *
	 * @param string $url URL o percorso.
	 * @return string
	 */
	private static function normalize_path( $url ) {
		$path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
		if ( '' === $path ) {
			$path = '/';
		}
		$path = strtolower( urldecode( $path ) );
		if ( '/' !== $path[0] ) {
			$path = '/' . $path;
		}
		if ( strlen( $path ) > 1 ) {
			$path = untrailingslashit( $path );
		}

		return $path;
	}

	/**
	 * Verifica che una regex sia compilabile (in modo sicuro).
	 *
	 * @param string $pattern Pattern senza delimitatori.
	 * @return bool
	 */
	private static function regex_valid( $pattern ) {
		if ( '' === $pattern ) {
			return false;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors -- validazione pattern non fidato.
		return false !== @preg_match( self::regex_delimited( $pattern ), '' );
	}

	/**
	 * Pattern con delimitatori e flag case-insensitive.
	 *
	 * @param string $pattern Pattern grezzo.
	 * @return string
	 */
	private static function regex_delimited( $pattern ) {
		return '#' . str_replace( '#', '\#', $pattern ) . '#i';
	}

	// -------------------------------------------------------------------------
	// Frontend: intercettazione dei 404.
	// -------------------------------------------------------------------------

	/**
	 * Sostituisce il 404 con il redirect configurato.
	 *
	 * Exact (e prefissi *) valutate per prime; regex solo dopo, in ordine di
	 * priorità. Query string esclusa dal match ma preservata.
	 *
	 * @return void
	 */
	public function maybe_redirect() {
		if ( ! is_404() ) {
			return;
		}
		// Mai su admin, login, REST o AJAX.
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		$request = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path    = self::normalize_path( $request );

		if ( 0 === strpos( $path, '/wp-admin' ) || 0 === strpos( $path, '/wp-json' ) || '/wp-login.php' === $path ) {
			return;
		}

		$query  = (string) wp_parse_url( $request, PHP_URL_QUERY );
		$config = self::config();

		// 1) Regole exact (incluse le * a prefisso), nell'ordine di elenco.
		foreach ( $config['rules'] as $i => $rule ) {
			if ( 'regex' === $rule['match'] ) {
				continue;
			}
			if ( $this->exact_matches( (string) $rule['source'], $path ) ) {
				$this->track_rule_hit( $i );
				$this->send( (string) $rule['target'], (int) $rule['code'], $path, $query );
				return;
			}
		}

		// 2) Regole regex, per order crescente (a parità: ordine di elenco).
		$regex_rules = array();
		foreach ( $config['rules'] as $i => $rule ) {
			if ( 'regex' === $rule['match'] ) {
				$regex_rules[] = array( 'i' => $i, 'rule' => $rule );
			}
		}
		usort( $regex_rules, static function ( $a, $b ) {
			$cmp = (int) $a['rule']['order'] <=> (int) $b['rule']['order'];
			return 0 !== $cmp ? $cmp : $a['i'] <=> $b['i'];
		} );

		foreach ( $regex_rules as $entry ) {
			$pattern = (string) $entry['rule']['source'];
			// phpcs:ignore WordPress.PHP.NoSilencedErrors -- pattern validato in salvataggio, difesa in profondità.
			if ( self::regex_valid( $pattern ) && @preg_match( self::regex_delimited( $pattern ), $path ) ) {
				$this->track_rule_hit( $entry['i'] );
				$this->send( (string) $entry['rule']['target'], (int) $entry['rule']['code'], $path, $query );
				return;
			}
		}

		// 3) Regola generale: unico redirect per tutto il resto.
		if ( ! empty( $config['general']['enabled'] ) ) {
			$this->track_general_hit( $path );
			$target = (string) $config['general']['target'];
			$this->send( $target ? $target : home_url( '/' ), (int) $config['general']['code'], $path, $query );
		}
	}

	/**
	 * Match exact (con supporto prefisso "*" finale).
	 *
	 * @param string $source Sorgente della regola.
	 * @param string $path   Percorso normalizzato della richiesta.
	 * @return bool
	 */
	private function exact_matches( $source, $path ) {
		if ( '' === $source ) {
			return false;
		}

		$wildcard = ( '*' === substr( $source, -1 ) );
		$base     = $wildcard ? self::normalize_path( substr( $source, 0, -1 ) . '/x' ) : self::normalize_path( $source );
		$base     = $wildcard ? substr( $base, 0, -2 ) : $base; // Rimuove il segnaposto "/x".

		return $wildcard ? ( 0 === strpos( $path . '/', untrailingslashit( $base ) . '/' ) ) : ( $path === $base );
	}

	/**
	 * Esegue il redirect (o il 410) e termina; la query string della
	 * richiesta viene accodata alla destinazione.
	 *
	 * @param string $target URL di destinazione (ignorato con 410).
	 * @param int    $code   301|302|410.
	 * @param string $path   Percorso corrente normalizzato (anti-loop).
	 * @param string $query  Query string originale (senza "?").
	 * @return void
	 */
	private function send( $target, $code, $path, $query = '' ) {
		if ( 410 === $code ) {
			status_header( 410 );
			nocache_headers();
			header( 'Content-Type: text/html; charset=utf-8' );
			echo '<!doctype html><html><head><meta name="robots" content="noindex"><title>410</title></head><body><p>' . esc_html__( 'Questo contenuto è stato rimosso definitivamente.', 'palladio' ) . '</p></body></html>';
			exit;
		}

		if ( ! in_array( $code, array( 301, 302 ), true ) ) {
			$code = 301;
		}

		$target = $target ? $target : home_url( '/' );

		// Query string preservata (fusa con quella eventualmente già presente).
		if ( '' !== $query ) {
			$target .= ( false === strpos( $target, '?' ) ? '?' : '&' ) . $query;
		}

		// Anti-loop: mai reindirizzare un percorso su sé stesso.
		if ( self::normalize_path( $target ) === $path ) {
			return;
		}

		// Niente cache sui redirect: un 301 senza header di cache verrebbe
		// memorizzato per sempre dal browser anche se il contenuto tornasse.
		nocache_headers();

		// wp_redirect (non "safe"): le destinazioni sono configurate dall'admin
		// e possono puntare anche a un altro dominio.
		wp_redirect( $target, $code, 'Palladio' ); // phpcs:ignore WordPress.Security.SafeRedirect
		exit;
	}

	/**
	 * Conteggio hit di una regola singola.
	 *
	 * @param int $index Indice regola.
	 * @return void
	 */
	private function track_rule_hit( $index ) {
		$config = self::config();
		if ( ! isset( $config['rules'][ $index ] ) ) {
			return;
		}
		$config['rules'][ $index ]['hits'] = (int) ( $config['rules'][ $index ]['hits'] ?? 0 ) + 1;
		$config['rules'][ $index ]['last'] = time();
		update_option( self::OPTION, $config, false );
	}

	/**
	 * Annota un URL intercettato dalla regola generale (ultimi 50, con conteggio).
	 *
	 * @param string $path Percorso normalizzato.
	 * @return void
	 */
	private function track_general_hit( $path ) {
		$log = get_option( self::LOG_OPTION, array() );
		$log = is_array( $log ) ? $log : array();

		if ( isset( $log[ $path ] ) ) {
			$log[ $path ]['hits'] = (int) $log[ $path ]['hits'] + 1;
			$log[ $path ]['last'] = time();
		} else {
			$log[ $path ] = array( 'hits' => 1, 'last' => time() );
		}

		if ( count( $log ) > self::LOG_MAX ) {
			uasort( $log, static function ( $a, $b ) {
				return $b['last'] <=> $a['last'];
			} );
			$log = array_slice( $log, 0, self::LOG_MAX, true );
		}

		update_option( self::LOG_OPTION, $log, false );
	}

	// -------------------------------------------------------------------------
	// Normalizzazione e validazione (condivise da editor manuale e import).
	// -------------------------------------------------------------------------

	/**
	 * Normalizza una sorgente exact: URL assoluti ridotti al percorso,
	 * minuscolo, trailing slash rimosso; preserva il prefisso "*".
	 *
	 * @param string $source Sorgente grezza.
	 * @return string
	 */
	private static function normalize_source( $source ) {
		$source   = trim( $source );
		$wildcard = ( '*' === substr( $source, -1 ) );
		if ( $wildcard ) {
			$source = substr( $source, 0, -1 );
		}

		// URL assoluto: rimuove schema e dominio (qualunque dominio).
		if ( preg_match( '#^https?://[^/]+(?<path>/.*)?$#i', $source, $m ) ) {
			$source = isset( $m['path'] ) && '' !== $m['path'] ? $m['path'] : '/';
		}

		return self::normalize_path( $source ) . ( $wildcard ? '*' : '' );
	}

	/**
	 * Normalizza una destinazione: URL assoluti sul dominio del sito
	 * diventano relativi (percorso + query); il resto viene ripulito.
	 *
	 * @param string $target Destinazione grezza.
	 * @return string
	 */
	private static function normalize_target( $target ) {
		$target = trim( $target );
		if ( '' === $target ) {
			return '';
		}

		$site_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$host      = strtolower( (string) wp_parse_url( $target, PHP_URL_HOST ) );

		if ( '' !== $host && ( $host === $site_host || 'www.' . $host === $site_host || $host === 'www.' . $site_host ) ) {
			$path  = (string) wp_parse_url( $target, PHP_URL_PATH );
			$query = (string) wp_parse_url( $target, PHP_URL_QUERY );
			$target = ( '' !== $path ? $path : '/' ) . ( '' !== $query ? '?' . $query : '' );
		}

		return esc_url_raw( $target );
	}

	/**
	 * Valida e normalizza una riga regola. Non scrive nulla.
	 *
	 * @param array $row {source,target,status,match_type,order} grezzi.
	 * @return array{ok:bool,rule?:array,error?:string}
	 */
	private static function validate_row( array $row ) {
		$source = trim( (string) ( $row['source'] ?? '' ) );
		$target = trim( (string) ( $row['target'] ?? '' ) );
		$status = (int) ( $row['status'] ?? 0 );
		$match  = strtolower( trim( (string) ( $row['match_type'] ?? 'exact' ) ) );
		$order  = (int) ( $row['order'] ?? 10 );

		if ( '' === $source ) {
			return array( 'ok' => false, 'error' => __( 'source vuoto', 'palladio' ) );
		}
		if ( ! in_array( $match, array( 'exact', 'regex' ), true ) ) {
			return array( 'ok' => false, 'error' => __( 'match_type non ammesso (exact|regex)', 'palladio' ) );
		}
		if ( ! in_array( $status, array( 301, 302, 410 ), true ) ) {
			return array( 'ok' => false, 'error' => __( 'status non ammesso (301|302|410)', 'palladio' ) );
		}

		if ( 'regex' === $match ) {
			if ( ! self::regex_valid( $source ) ) {
				return array( 'ok' => false, 'error' => __( 'regex non compilabile', 'palladio' ) );
			}
		} else {
			$source = self::normalize_source( $source );
		}

		$target = self::normalize_target( $target );
		if ( 410 !== $status && '' === $target ) {
			return array( 'ok' => false, 'error' => __( 'target vuoto (ammesso solo con 410)', 'palladio' ) );
		}

		// Self-redirect (solo exact: confronto sul percorso normalizzato).
		if ( 'exact' === $match && 410 !== $status && self::normalize_path( $target ) === self::normalize_path( $source ) ) {
			return array( 'ok' => false, 'error' => __( 'self-redirect: source e target coincidono', 'palladio' ) );
		}

		return array(
			'ok'   => true,
			'rule' => array(
				'source' => $source,
				'target' => $target,
				'code'   => $status,
				'match'  => $match,
				'order'  => $order,
				'hits'   => 0,
				'last'   => 0,
			),
		);
	}

	// -------------------------------------------------------------------------
	// Import CSV.
	// -------------------------------------------------------------------------

	/**
	 * Chiave del transient di anteprima per l'utente corrente.
	 *
	 * @param string $what preview|report|errors.
	 * @return string
	 */
	private static function transient_key( $what ) {
		return 'palladio_rdr_' . $what . '_' . get_current_user_id();
	}

	/**
	 * Handler: upload + anteprima (dry-run, nessuna scrittura).
	 *
	 * @return void
	 */
	public function handle_preview() {
		if ( ! current_user_can( self::CAP_IMPORT ) ) {
			wp_die( esc_html__( 'Permesso negato.', 'palladio' ) );
		}
		check_admin_referer( 'palladio_redirects_import' );

		$redirect = admin_url( 'edit.php?post_type=pll_edificio&page=palladio-redirects&tab=importa' );

		$file = $_FILES['csv'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! $file || ! empty( $file['error'] ) || empty( $file['tmp_name'] ) ) {
			$this->fail( __( 'Nessun file ricevuto o upload non riuscito.', 'palladio' ), $redirect );
		}
		if ( (int) $file['size'] > self::CSV_MAX ) {
			$this->fail( __( 'File troppo grande: massimo 1 MB.', 'palladio' ), $redirect );
		}
		if ( 'csv' !== strtolower( pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) ) ) {
			$this->fail( __( 'Formato non ammesso: carica un file .csv.', 'palladio' ), $redirect );
		}

		$content = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $content ) {
			$this->fail( __( 'Impossibile leggere il file.', 'palladio' ), $redirect );
		}

		// BOM UTF-8 e normalizzazione fine riga: il contenuto è SOLO dati.
		$content = preg_replace( '/^\xEF\xBB\xBF/', '', $content );
		$lines   = preg_split( '/\r\n|\r|\n/', (string) $content );

		$header = str_getcsv( (string) array_shift( $lines ) );
		$header = array_map( static function ( $h ) {
			return strtolower( trim( (string) $h ) );
		}, $header );
		if ( array( 'source', 'target', 'status', 'match_type', 'order' ) !== array_slice( $header, 0, 5 ) ) {
			$this->fail( __( 'Intestazione non valida: attesa "source,target,status,match_type,order".', 'palladio' ), $redirect );
		}

		$update_existing = ! empty( $_POST['update_existing'] );
		$existing        = self::config()['rules'];
		$preview         = array();

		foreach ( $lines as $n => $line ) {
			if ( '' === trim( (string) $line ) ) {
				continue;
			}
			$cells = str_getcsv( (string) $line );
			$row   = array(
				'source'     => $cells[0] ?? '',
				'target'     => $cells[1] ?? '',
				'status'     => $cells[2] ?? '',
				'match_type' => $cells[3] ?? '',
				'order'      => $cells[4] ?? 10,
			);

			$checked = self::validate_row( $row );
			$entry   = array(
				'line' => $n + 2, // 1-based + intestazione.
				'raw'  => $row,
			);

			if ( ! $checked['ok'] ) {
				$entry['esito']  = 'errore';
				$entry['motivo'] = $checked['error'];
				$preview[]       = $entry;
				continue;
			}

			$entry['rule'] = $checked['rule'];
			$preview[]     = $entry;
		}

		// Duplicati DENTRO il file: vince l'ultima riga con la stessa chiave.
		$seen = array();
		foreach ( $preview as $i => $entry ) {
			if ( empty( $entry['rule'] ) ) {
				continue;
			}
			$key = $entry['rule']['match'] . '|' . $entry['rule']['source'];
			if ( isset( $seen[ $key ] ) ) {
				$preview[ $seen[ $key ] ]['esito']  = 'salta';
				$preview[ $seen[ $key ] ]['motivo'] = __( 'duplicato nel file: vale la riga successiva', 'palladio' );
				unset( $preview[ $seen[ $key ] ]['rule'] );
			}
			$seen[ $key ] = $i;
		}

		// Risoluzione catene: target che coincide con il source exact di
		// un'altra regola (nel file o già salvata) → target finale.
		$map = array();
		foreach ( $preview as $entry ) {
			if ( ! empty( $entry['rule'] ) && 'exact' === $entry['rule']['match'] && 410 !== $entry['rule']['code'] ) {
				$map[ self::normalize_path( $entry['rule']['source'] ) ] = $entry['rule']['target'];
			}
		}
		foreach ( $existing as $rule ) {
			if ( 'exact' === ( $rule['match'] ?? 'exact' ) && 410 !== (int) $rule['code'] && '*' !== substr( (string) $rule['source'], -1 ) ) {
				$key = self::normalize_path( (string) $rule['source'] );
				if ( ! isset( $map[ $key ] ) ) {
					$map[ $key ] = (string) $rule['target'];
				}
			}
		}

		foreach ( $preview as $i => $entry ) {
			if ( empty( $entry['rule'] ) || 410 === $entry['rule']['code'] ) {
				continue;
			}
			$target  = $entry['rule']['target'];
			$resolved = false;
			$steps    = 0;
			while ( $steps < 5 ) {
				$key = self::normalize_path( $target );
				if ( ! isset( $map[ $key ] ) || self::normalize_path( $map[ $key ] ) === $key ) {
					break;
				}
				// Loop tra regole: fermati e segnala.
				if ( self::normalize_path( $map[ $key ] ) === self::normalize_path( $entry['rule']['source'] ) ) {
					$preview[ $i ]['esito']  = 'errore';
					$preview[ $i ]['motivo'] = __( 'loop di redirect tra le regole', 'palladio' );
					unset( $preview[ $i ]['rule'] );
					continue 2;
				}
				$target   = $map[ $key ];
				$resolved = true;
				$steps++;
			}
			if ( $resolved ) {
				$preview[ $i ]['rule']['target'] = $target;
				/* translators: %s: destinazione finale. */
				$preview[ $i ]['nota'] = sprintf( __( 'catena risolta → %s', 'palladio' ), $target );
			}
		}

		// Esito nuova/aggiorna/salta rispetto alle regole già salvate.
		$existing_keys = array();
		foreach ( $existing as $rule ) {
			$key = ( $rule['match'] ?? 'exact' ) . '|' . ( 'regex' === ( $rule['match'] ?? 'exact' ) ? (string) $rule['source'] : self::normalize_path( (string) $rule['source'] ) );
			$existing_keys[ $key ] = true;
		}
		foreach ( $preview as $i => $entry ) {
			if ( empty( $entry['rule'] ) ) {
				continue;
			}
			$rule = $entry['rule'];
			$key  = $rule['match'] . '|' . ( 'regex' === $rule['match'] ? $rule['source'] : self::normalize_path( $rule['source'] ) );
			if ( isset( $existing_keys[ $key ] ) ) {
				$preview[ $i ]['esito'] = $update_existing ? 'aggiorna' : 'salta';
				if ( ! $update_existing ) {
					$preview[ $i ]['motivo'] = __( 'regola già esistente (opzione: salta)', 'palladio' );
					unset( $preview[ $i ]['rule'] );
				}
			} else {
				$preview[ $i ]['esito'] = 'nuova';
			}
		}

		set_transient( self::transient_key( 'preview' ), array(
			'rows'            => $preview,
			'update_existing' => $update_existing,
		), 30 * MINUTE_IN_SECONDS );

		wp_safe_redirect( add_query_arg( 'preview', '1', $redirect ) );
		exit;
	}

	/**
	 * Handler: conferma importazione (scrive le regole dall'anteprima).
	 *
	 * @return void
	 */
	public function handle_import() {
		if ( ! current_user_can( self::CAP_IMPORT ) ) {
			wp_die( esc_html__( 'Permesso negato.', 'palladio' ) );
		}
		check_admin_referer( 'palladio_redirects_confirm' );

		$redirect = admin_url( 'edit.php?post_type=pll_edificio&page=palladio-redirects&tab=importa' );
		$stash    = get_transient( self::transient_key( 'preview' ) );

		if ( ! is_array( $stash ) || empty( $stash['rows'] ) ) {
			$this->fail( __( 'Anteprima scaduta: ricarica il file CSV.', 'palladio' ), $redirect );
		}

		$config = self::config();
		$report = array( 'nuove' => 0, 'aggiornate' => 0, 'saltate' => 0, 'errori' => 0 );
		$errors = array();

		// Indice delle regole esistenti per chiave.
		$index = array();
		foreach ( $config['rules'] as $i => $rule ) {
			$key = ( $rule['match'] ?? 'exact' ) . '|' . ( 'regex' === ( $rule['match'] ?? 'exact' ) ? (string) $rule['source'] : self::normalize_path( (string) $rule['source'] ) );
			$index[ $key ] = $i;
		}

		foreach ( $stash['rows'] as $entry ) {
			if ( 'errore' === ( $entry['esito'] ?? '' ) ) {
				$report['errori']++;
				$errors[] = array_merge( (array) $entry['raw'], array( 'motivo' => (string) ( $entry['motivo'] ?? '' ) ) );
				continue;
			}
			if ( 'salta' === ( $entry['esito'] ?? '' ) || empty( $entry['rule'] ) ) {
				$report['saltate']++;
				continue;
			}

			$rule = $entry['rule'];
			$key  = $rule['match'] . '|' . ( 'regex' === $rule['match'] ? $rule['source'] : self::normalize_path( $rule['source'] ) );

			if ( isset( $index[ $key ] ) ) {
				$i = $index[ $key ];
				// Aggiorna destinazione/codice/ordine; conserva hit e ultimo.
				$config['rules'][ $i ]['target'] = $rule['target'];
				$config['rules'][ $i ]['code']   = $rule['code'];
				$config['rules'][ $i ]['order']  = $rule['order'];
				$report['aggiornate']++;
			} else {
				$config['rules'][] = $rule;
				$index[ $key ]     = count( $config['rules'] ) - 1;
				$report['nuove']++;
			}
		}

		update_option( self::OPTION, $config, false );
		delete_transient( self::transient_key( 'preview' ) );
		set_transient( self::transient_key( 'report' ), $report, 30 * MINUTE_IN_SECONDS );
		set_transient( self::transient_key( 'errors' ), $errors, 30 * MINUTE_IN_SECONDS );

		wp_safe_redirect( add_query_arg( 'imported', '1', $redirect ) );
		exit;
	}

	/**
	 * Handler: esporta le regole correnti in CSV.
	 *
	 * @return void
	 */
	public function export_csv() {
		if ( ! current_user_can( self::CAP_IMPORT ) ) {
			wp_die( esc_html__( 'Permesso negato.', 'palladio' ) );
		}
		check_admin_referer( 'palladio_redirects_export' );

		$rules = self::config()['rules'];

		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="palladio-redirects.csv"' );
		nocache_headers();

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $out, array( 'source', 'target', 'status', 'match_type', 'order' ) );
		foreach ( $rules as $rule ) {
			fputcsv( $out, array(
				(string) $rule['source'],
				(string) $rule['target'],
				(int) $rule['code'],
				(string) ( $rule['match'] ?? 'exact' ),
				(int) ( $rule['order'] ?? 10 ),
			) );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * Handler: scarica il CSV delle sole righe in errore dell'ultimo import.
	 *
	 * @return void
	 */
	public function download_errors_csv() {
		if ( ! current_user_can( self::CAP_IMPORT ) ) {
			wp_die( esc_html__( 'Permesso negato.', 'palladio' ) );
		}
		check_admin_referer( 'palladio_redirects_export' );

		$errors = get_transient( self::transient_key( 'errors' ) );
		$errors = is_array( $errors ) ? $errors : array();

		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="palladio-redirects-errori.csv"' );
		nocache_headers();

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $out, array( 'source', 'target', 'status', 'match_type', 'order', 'motivo' ) );
		foreach ( $errors as $row ) {
			fputcsv( $out, array(
				(string) ( $row['source'] ?? '' ),
				(string) ( $row['target'] ?? '' ),
				(string) ( $row['status'] ?? '' ),
				(string) ( $row['match_type'] ?? '' ),
				(string) ( $row['order'] ?? '' ),
				(string) ( $row['motivo'] ?? '' ),
			) );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * Redirect di errore con messaggio.
	 *
	 * @param string $message  Messaggio.
	 * @param string $redirect URL di ritorno.
	 * @return void
	 */
	private function fail( $message, $redirect ) {
		set_transient( 'palladio_rdr_fail_' . get_current_user_id(), $message, 120 );
		wp_safe_redirect( add_query_arg( 'failed', '1', $redirect ) );
		exit;
	}

	// -------------------------------------------------------------------------
	// Admin.
	// -------------------------------------------------------------------------

	/**
	 * Voce di menu "Redirect".
	 *
	 * @return void
	 */
	public function menu() {
		add_submenu_page(
			'edit.php?post_type=pll_edificio',
			__( 'Redirect', 'palladio' ),
			__( 'Redirect', 'palladio' ),
			self::CAP,
			'palladio-redirects',
			array( $this, 'page' )
		);
	}

	/**
	 * Salva regole e impostazione generale (editor manuale).
	 *
	 * @return void
	 */
	public function save() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permesso negato.', 'palladio' ) );
		}
		check_admin_referer( 'palladio_redirects_save' );

		$rules   = array();
		$sources = isset( $_POST['rule_source'] ) ? (array) wp_unslash( $_POST['rule_source'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$targets = isset( $_POST['rule_target'] ) ? (array) wp_unslash( $_POST['rule_target'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$codes   = isset( $_POST['rule_code'] ) ? (array) wp_unslash( $_POST['rule_code'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$matches = isset( $_POST['rule_match'] ) ? (array) wp_unslash( $_POST['rule_match'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$orders  = isset( $_POST['rule_order'] ) ? (array) wp_unslash( $_POST['rule_order'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$hits    = isset( $_POST['rule_hits'] ) ? (array) wp_unslash( $_POST['rule_hits'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$lasts   = isset( $_POST['rule_last'] ) ? (array) wp_unslash( $_POST['rule_last'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		foreach ( $sources as $i => $source ) {
			$source = trim( sanitize_text_field( (string) $source ) );
			if ( '' === $source ) {
				continue;
			}

			$match = in_array( $matches[ $i ] ?? '', array( 'exact', 'regex' ), true ) ? $matches[ $i ] : 'exact';
			$code  = (int) ( $codes[ $i ] ?? 301 );
			if ( ! in_array( $code, array( 301, 302, 410 ), true ) ) {
				$code = 301;
			}

			if ( 'regex' === $match ) {
				if ( ! self::regex_valid( $source ) ) {
					continue; // Pattern non compilabile: scartato.
				}
			} else {
				$source = self::normalize_source( $source );
			}

			$rules[] = array(
				'source' => $source,
				'target' => self::normalize_target( (string) ( $targets[ $i ] ?? '' ) ),
				'code'   => $code,
				'match'  => $match,
				'order'  => (int) ( $orders[ $i ] ?? 10 ),
				'hits'   => absint( $hits[ $i ] ?? 0 ),
				'last'   => absint( $lasts[ $i ] ?? 0 ),
			);
		}

		$general_code = (int) ( $_POST['general_code'] ?? 301 );

		$config = array(
			'general' => array(
				'enabled' => empty( $_POST['general_enabled'] ) ? 0 : 1,
				'target'  => self::normalize_target( (string) wp_unslash( $_POST['general_target'] ?? '' ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				'code'    => in_array( $general_code, array( 301, 302 ), true ) ? $general_code : 301,
			),
			'rules'   => $rules,
		);

		update_option( self::OPTION, $config, false );

		if ( ! empty( $_POST['clear_log'] ) ) {
			delete_option( self::LOG_OPTION );
		}

		wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'edit.php?post_type=pll_edificio&page=palladio-redirects' ) ) );
		exit;
	}

	/**
	 * Renderizza la pagina Redirect (schede Regole / Importa).
	 *
	 * @return void
	 */
	public function page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permesso negato.', 'palladio' ) );
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'regole'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Palladio — Redirect', 'palladio' ); ?></h1>

			<h2 class="nav-tab-wrapper">
				<a class="nav-tab <?php echo 'regole' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'edit.php?post_type=pll_edificio&page=palladio-redirects' ) ); ?>"><?php esc_html_e( 'Regole', 'palladio' ); ?></a>
				<a class="nav-tab <?php echo 'importa' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'edit.php?post_type=pll_edificio&page=palladio-redirects&tab=importa' ) ); ?>"><?php esc_html_e( 'Importa', 'palladio' ); ?></a>
			</h2>

			<?php if ( 'importa' === $tab ) : ?>
				<?php $this->render_import_tab(); ?>
			<?php else : ?>
				<?php $this->render_rules_tab(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Scheda Regole: editor manuale, esporta, regola generale, log.
	 *
	 * @return void
	 */
	private function render_rules_tab() {
		$config = self::config();
		$log    = get_option( self::LOG_OPTION, array() );
		$log    = is_array( $log ) ? $log : array();
		uasort( $log, static function ( $a, $b ) {
			return $b['hits'] <=> $a['hits'];
		} );

		$date = static function ( $ts ) {
			return $ts ? date_i18n( get_option( 'date_format' ) . ' H:i', (int) $ts ) : '—';
		};
		?>
		<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Redirect salvati.', 'palladio' ); ?></p></div>
		<?php endif; ?>

		<div class="notice notice-info inline" style="margin:16px 0;">
			<p><strong><?php esc_html_e( 'Quale usare, quando', 'palladio' ); ?></strong></p>
			<ul style="list-style:disc;margin-left:1.5em;">
				<li><?php echo wp_kses_post( __( '<strong>301 (definitivo)</strong> — il contenuto ha una nuova casa. Google trasferisce il valore SEO al nuovo URL.', 'palladio' ) ); ?></li>
				<li><?php echo wp_kses_post( __( '<strong>302 (temporaneo)</strong> — il contenuto tornerà. Google mantiene indicizzato l’URL originale.', 'palladio' ) ); ?></li>
				<li><?php echo wp_kses_post( __( '<strong>410 (eliminato)</strong> — nessun sostituto: Google lo rimuove dall’indice più in fretta di un 404.', 'palladio' ) ); ?></li>
				<li><?php echo wp_kses_post( __( '<strong>Exact vs regex</strong> — le exact (anche con suffisso <code>*</code> per i prefissi) vengono valutate per prime; le regex solo dopo, in ordine di priorità crescente. Il match ignora maiuscole e slash finale; la query string non partecipa al match ma viene accodata alla destinazione.', 'palladio' ) ); ?></li>
			</ul>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'palladio_redirects_save' ); ?>
			<input type="hidden" name="action" value="palladio_save_redirects">

			<h2 style="display:flex;align-items:center;gap:1rem;">
				<?php esc_html_e( 'Regole singole', 'palladio' ); ?>
				<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'action', 'palladio_redirects_export', admin_url( 'admin-post.php' ) ), 'palladio_redirects_export' ) ); ?>"><?php esc_html_e( 'Esporta CSV', 'palladio' ); ?></a>
			</h2>
			<table class="widefat striped" id="pll-redirect-rules" style="max-width:1200px;">
				<thead>
					<tr>
						<th style="width:28%;"><?php esc_html_e( 'Sorgente (percorso o regex)', 'palladio' ); ?></th>
						<th style="width:30%;"><?php esc_html_e( 'Destinazione (URL)', 'palladio' ); ?></th>
						<th style="width:9%;"><?php esc_html_e( 'Codice', 'palladio' ); ?></th>
						<th style="width:10%;"><?php esc_html_e( 'Tipo', 'palladio' ); ?></th>
						<th style="width:7%;"><?php esc_html_e( 'Ordine', 'palladio' ); ?></th>
						<th style="width:11%;"><?php esc_html_e( 'Hit', 'palladio' ); ?></th>
						<th style="width:5%;"></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $config['rules'] as $rule ) : ?>
						<tr>
							<td><input type="text" class="widefat" name="rule_source[]" value="<?php echo esc_attr( $rule['source'] ); ?>" placeholder="/vecchia-pagina/"></td>
							<td><input type="text" class="widefat" name="rule_target[]" value="<?php echo esc_attr( $rule['target'] ); ?>" placeholder="<?php echo esc_attr( home_url( '/nuova-pagina/' ) ); ?>"></td>
							<td>
								<select name="rule_code[]">
									<option value="301" <?php selected( (int) $rule['code'], 301 ); ?>>301</option>
									<option value="302" <?php selected( (int) $rule['code'], 302 ); ?>>302</option>
									<option value="410" <?php selected( (int) $rule['code'], 410 ); ?>>410</option>
								</select>
							</td>
							<td>
								<select name="rule_match[]">
									<option value="exact" <?php selected( $rule['match'], 'exact' ); ?>>exact</option>
									<option value="regex" <?php selected( $rule['match'], 'regex' ); ?>>regex</option>
								</select>
							</td>
							<td><input type="number" step="1" style="width:4.5em;" name="rule_order[]" value="<?php echo esc_attr( (int) $rule['order'] ); ?>"></td>
							<td>
								<?php echo esc_html( palladio_format_number( (int) ( $rule['hits'] ?? 0 ) ) ); ?>
								<small style="display:block;color:#666;"><?php echo esc_html( $date( $rule['last'] ?? 0 ) ); ?></small>
								<input type="hidden" name="rule_hits[]" value="<?php echo esc_attr( (int) ( $rule['hits'] ?? 0 ) ); ?>">
								<input type="hidden" name="rule_last[]" value="<?php echo esc_attr( (int) ( $rule['last'] ?? 0 ) ); ?>">
							</td>
							<td><button type="button" class="button-link-delete pll-redirect-remove" aria-label="<?php esc_attr_e( 'Rimuovi', 'palladio' ); ?>">×</button></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p><button type="button" class="button" id="pll-redirect-add"><?php esc_html_e( 'Aggiungi regola', 'palladio' ); ?></button></p>

			<h2><?php esc_html_e( 'Regola generale', 'palladio' ); ?></h2>
			<table class="form-table" role="presentation" style="max-width:1100px;">
				<tr>
					<th scope="row"><?php esc_html_e( 'Attiva', 'palladio' ); ?></th>
					<td>
						<label><input type="checkbox" name="general_enabled" value="1" <?php checked( ! empty( $config['general']['enabled'] ) ); ?>>
						<?php esc_html_e( 'Reindirizza tutti i contenuti eliminati senza regola singola verso un unico URL (niente pagina 404).', 'palladio' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="pll-general-target"><?php esc_html_e( 'Destinazione', 'palladio' ); ?></label></th>
					<td>
						<input type="text" id="pll-general-target" class="regular-text" name="general_target" value="<?php echo esc_attr( $config['general']['target'] ); ?>" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>">
						<p class="description"><?php esc_html_e( 'Vuoto = home page.', 'palladio' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="pll-general-code"><?php esc_html_e( 'Codice', 'palladio' ); ?></label></th>
					<td>
						<select id="pll-general-code" name="general_code">
							<option value="301" <?php selected( (int) $config['general']['code'], 301 ); ?>>301 — <?php esc_html_e( 'definitivo', 'palladio' ); ?></option>
							<option value="302" <?php selected( (int) $config['general']['code'], 302 ); ?>>302 — <?php esc_html_e( 'temporaneo', 'palladio' ); ?></option>
						</select>
					</td>
				</tr>
			</table>

			<?php if ( $log ) : ?>
				<h2><?php esc_html_e( 'Intercettati dalla regola generale', 'palladio' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Gli URL più richiesti tra quelli finiti nella regola generale: valuta se promuoverli a regola singola verso una destinazione pertinente.', 'palladio' ); ?></p>
				<table class="widefat striped" style="max-width:1100px;">
					<thead><tr><th><?php esc_html_e( 'URL richiesto', 'palladio' ); ?></th><th style="width:12%;"><?php esc_html_e( 'Hit', 'palladio' ); ?></th><th style="width:22%;"><?php esc_html_e( 'Ultimo', 'palladio' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $log as $lpath => $entry ) : ?>
							<tr>
								<td><code><?php echo esc_html( $lpath ); ?></code></td>
								<td><?php echo esc_html( palladio_format_number( (int) $entry['hits'] ) ); ?></td>
								<td><?php echo esc_html( $date( $entry['last'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p><label><input type="checkbox" name="clear_log" value="1"> <?php esc_html_e( 'Svuota l’elenco al salvataggio', 'palladio' ); ?></label></p>
			<?php endif; ?>

			<?php submit_button( __( 'Salva redirect', 'palladio' ) ); ?>
		</form>

		<script>
		(function () {
			var table = document.getElementById('pll-redirect-rules');
			document.getElementById('pll-redirect-add').addEventListener('click', function () {
				var row = table.tBodies[0].insertRow(-1);
				row.innerHTML = '<td><input type="text" class="widefat" name="rule_source[]" placeholder="/vecchia-pagina/"></td>' +
					'<td><input type="text" class="widefat" name="rule_target[]" placeholder="<?php echo esc_js( home_url( '/nuova-pagina/' ) ); ?>"></td>' +
					'<td><select name="rule_code[]"><option value="301">301</option><option value="302">302</option><option value="410">410</option></select></td>' +
					'<td><select name="rule_match[]"><option value="exact">exact</option><option value="regex">regex</option></select></td>' +
					'<td><input type="number" step="1" style="width:4.5em;" name="rule_order[]" value="10"></td>' +
					'<td>0<input type="hidden" name="rule_hits[]" value="0"><input type="hidden" name="rule_last[]" value="0"></td>' +
					'<td><button type="button" class="button-link-delete pll-redirect-remove" aria-label="<?php echo esc_js( __( 'Rimuovi', 'palladio' ) ); ?>">×</button></td>';
			});
			table.addEventListener('click', function (e) {
				if (e.target.classList.contains('pll-redirect-remove')) {
					e.target.closest('tr').remove();
				}
			});
		}());
		</script>
		<?php
	}

	/**
	 * Scheda Importa: upload, anteprima, conferma, report.
	 *
	 * @return void
	 */
	private function render_import_tab() {
		if ( ! current_user_can( self::CAP_IMPORT ) ) {
			echo '<p>' . esc_html__( 'L’importazione richiede la capability manage_options.', 'palladio' ) . '</p>';
			return;
		}

		$fail = get_transient( 'palladio_rdr_fail_' . get_current_user_id() );
		delete_transient( 'palladio_rdr_fail_' . get_current_user_id() );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$show_preview = isset( $_GET['preview'] );
		$imported     = isset( $_GET['imported'] );
		// phpcs:enable

		if ( $fail ) : ?>
			<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $fail ); ?></p></div>
		<?php endif; ?>

		<?php
		// -------------------------------------------------- report finale.
		if ( $imported ) :
			$report = get_transient( self::transient_key( 'report' ) );
			$errors = get_transient( self::transient_key( 'errors' ) );
			$report = is_array( $report ) ? $report : array();
			?>
			<div class="notice notice-success"><p>
				<strong><?php esc_html_e( 'Importazione completata.', 'palladio' ); ?></strong>
				<?php
				printf(
					/* translators: 1-4: conteggi. */
					esc_html__( 'Nuove: %1$d — Aggiornate: %2$d — Saltate: %3$d — In errore: %4$d.', 'palladio' ),
					(int) ( $report['nuove'] ?? 0 ),
					(int) ( $report['aggiornate'] ?? 0 ),
					(int) ( $report['saltate'] ?? 0 ),
					(int) ( $report['errori'] ?? 0 )
				);
				?>
				<?php if ( is_array( $errors ) && $errors ) : ?>
					<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'action', 'palladio_redirects_errors_csv', admin_url( 'admin-post.php' ) ), 'palladio_redirects_export' ) ); ?>"><?php esc_html_e( 'Scarica il CSV delle righe in errore', 'palladio' ); ?></a>
				<?php endif; ?>
			</p></div>
		<?php endif; ?>

		<?php
		// -------------------------------------------------- anteprima.
		if ( $show_preview ) :
			$stash = get_transient( self::transient_key( 'preview' ) );
			if ( ! is_array( $stash ) || empty( $stash['rows'] ) ) :
				?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'Anteprima scaduta o vuota: ricarica il file.', 'palladio' ); ?></p></div>
			<?php else : ?>
				<?php
				$counts = array( 'nuova' => 0, 'aggiorna' => 0, 'salta' => 0, 'errore' => 0 );
				foreach ( $stash['rows'] as $entry ) {
					$esito = (string) ( $entry['esito'] ?? '' );
					if ( isset( $counts[ $esito ] ) ) {
						$counts[ $esito ]++;
					}
				}
				?>
				<h2><?php esc_html_e( 'Anteprima importazione (nessuna modifica ancora scritta)', 'palladio' ); ?></h2>
				<p>
					<?php
					printf(
						/* translators: 1-4: conteggi. */
						esc_html__( 'Nuove: %1$d — Da aggiornare: %2$d — Da saltare: %3$d — In errore: %4$d.', 'palladio' ),
						(int) $counts['nuova'],
						(int) $counts['aggiorna'],
						(int) $counts['salta'],
						(int) $counts['errore']
					);
					?>
				</p>
				<table class="widefat striped" style="max-width:1200px;">
					<thead><tr>
						<th style="width:5%;"><?php esc_html_e( 'Riga', 'palladio' ); ?></th>
						<th style="width:30%;">source</th>
						<th style="width:27%;">target</th>
						<th style="width:7%;">status</th>
						<th style="width:8%;">tipo</th>
						<th style="width:6%;">ordine</th>
						<th><?php esc_html_e( 'Esito', 'palladio' ); ?></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $stash['rows'] as $entry ) : ?>
							<?php
							$rule  = isset( $entry['rule'] ) ? $entry['rule'] : null;
							$esito = (string) ( $entry['esito'] ?? '' );
							$color = 'errore' === $esito ? '#b32d2e' : ( 'salta' === $esito ? '#996800' : '#1a6b41' );
							?>
							<tr>
								<td><?php echo esc_html( (string) $entry['line'] ); ?></td>
								<td><code><?php echo esc_html( $rule ? $rule['source'] : (string) ( $entry['raw']['source'] ?? '' ) ); ?></code></td>
								<td><code><?php echo esc_html( $rule ? $rule['target'] : (string) ( $entry['raw']['target'] ?? '' ) ); ?></code></td>
								<td><?php echo esc_html( $rule ? (string) $rule['code'] : (string) ( $entry['raw']['status'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( $rule ? $rule['match'] : (string) ( $entry['raw']['match_type'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( $rule ? (string) $rule['order'] : (string) ( $entry['raw']['order'] ?? '' ) ); ?></td>
								<td style="color:<?php echo esc_attr( $color ); ?>;font-weight:600;">
									<?php echo esc_html( $esito ); ?>
									<?php if ( ! empty( $entry['motivo'] ) ) : ?>
										<small style="display:block;font-weight:400;"><?php echo esc_html( (string) $entry['motivo'] ); ?></small>
									<?php endif; ?>
									<?php if ( ! empty( $entry['nota'] ) ) : ?>
										<small style="display:block;font-weight:400;color:#996800;"><?php echo esc_html( (string) $entry['nota'] ); ?></small>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1em;">
					<?php wp_nonce_field( 'palladio_redirects_confirm' ); ?>
					<input type="hidden" name="action" value="palladio_redirects_import">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Conferma importazione', 'palladio' ); ?></button>
					<a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=pll_edificio&page=palladio-redirects&tab=importa' ) ); ?>"><?php esc_html_e( 'Annulla', 'palladio' ); ?></a>
				</form>
			<?php endif; ?>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Importa regole da CSV', 'palladio' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Separatore virgola, UTF-8, intestazione obbligatoria: source,target,status,match_type,order. Max 1 MB. Le regole importate partono con contatore hit a 0; sorgenti assolute vengono ridotte al percorso, i target sul dominio del sito diventano relativi, le catene tra regole vengono risolte al target finale.', 'palladio' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<?php wp_nonce_field( 'palladio_redirects_import' ); ?>
			<input type="hidden" name="action" value="palladio_redirects_preview">
			<table class="form-table" role="presentation" style="max-width:800px;">
				<tr>
					<th scope="row"><label for="pll-rdr-csv"><?php esc_html_e( 'File CSV', 'palladio' ); ?></label></th>
					<td><input type="file" id="pll-rdr-csv" name="csv" accept=".csv" required></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Regole già esistenti', 'palladio' ); ?></th>
					<td>
						<label style="display:block;"><input type="radio" name="update_existing" value="1" checked> <?php esc_html_e( 'Aggiorna le regole esistenti (destinazione, codice e ordine; gli hit restano)', 'palladio' ); ?></label>
						<label style="display:block;"><input type="radio" name="update_existing" value=""> <?php esc_html_e( 'Salta le regole già presenti', 'palladio' ); ?></label>
					</td>
				</tr>
			</table>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Anteprima', 'palladio' ); ?></button></p>
		</form>
		<?php
	}
}
