<?php
/**
 * Modulo Core — redirect dei contenuti eliminati (mai 404).
 *
 * Intercetta ogni richiesta che WordPress risolverebbe in 404 — pagine,
 * schede del plugin, allegati e file media mancanti: qualsiasi content type —
 * e la reindirizza:
 *
 *  1. REGOLE SINGOLE: percorso sorgente → URL di destinazione, con codice
 *     301 (spostamento definitivo), 302 (temporaneo) o 410 (contenuto
 *     eliminato per sempre, senza sostituto). Supporto prefisso con `*`
 *     finale (es. /vecchia-galleria/* per intere cartelle di immagini).
 *  2. REGOLA GENERALE: tutto ciò che non ha una regola singola va a un unico
 *     URL (di default la home) con un unico codice.
 *
 * Gli URL intercettati dalla regola generale vengono annotati (ultimi 50,
 * con conteggio): dalla pagina Redirect si vede cosa sta arrivando e si
 * possono promuovere i più frequenti a regole singole.
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
	const OPTION     = 'palladio_redirects';
	const LOG_OPTION = 'palladio_redirects_log';
	const LOG_MAX    = 50;

	/**
	 * Registra gli hook.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 0 );

		if ( is_admin() ) {
			// Prima di "Impostazioni" (999) nel menu Palladio.
			add_action( 'admin_menu', array( $this, 'menu' ), 998 );
			add_action( 'admin_post_palladio_save_redirects', array( $this, 'save' ) );
		}
	}

	/**
	 * Configurazione con default.
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
			'rules'   => array(), // [ {source,target,code,hits,last} ]
		);

		$config = get_option( self::OPTION, array() );
		$config = wp_parse_args( is_array( $config ) ? $config : array(), $defaults );

		$config['general'] = wp_parse_args( is_array( $config['general'] ) ? $config['general'] : array(), $defaults['general'] );
		if ( ! is_array( $config['rules'] ) ) {
			$config['rules'] = array();
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

	// -------------------------------------------------------------------------
	// Frontend: intercettazione dei 404.
	// -------------------------------------------------------------------------

	/**
	 * Sostituisce il 404 con il redirect configurato.
	 *
	 * Vale per qualsiasi content type: pagine, schede del plugin, allegati e
	 * file dentro uploads che non esistono più (le richieste di file mancanti
	 * arrivano a WordPress grazie alle rewrite standard).
	 *
	 * @return void
	 */
	public function maybe_redirect() {
		if ( ! is_404() ) {
			return;
		}

		$request = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path    = self::normalize_path( $request );
		$config  = self::config();

		// 1) Regola singola: match esatto, poi prefissi con "*" finale.
		foreach ( $config['rules'] as $i => $rule ) {
			$source = (string) ( $rule['source'] ?? '' );
			if ( '' === $source ) {
				continue;
			}

			$wildcard = ( '*' === substr( $source, -1 ) );
			$base     = $wildcard ? self::normalize_path( substr( $source, 0, -1 ) . '/x' ) : self::normalize_path( $source );
			$base     = $wildcard ? substr( $base, 0, -2 ) : $base; // Rimuove il segnaposto "/x".

			$match = $wildcard ? ( 0 === strpos( $path . '/', untrailingslashit( $base ) . '/' ) ) : ( $path === $base );
			if ( ! $match ) {
				continue;
			}

			$this->track_rule_hit( $i );
			$this->send( (string) ( $rule['target'] ?? '' ), (int) ( $rule['code'] ?? 301 ), $path );
			return;
		}

		// 2) Regola generale: unico redirect per tutto il resto.
		if ( ! empty( $config['general']['enabled'] ) ) {
			$this->track_general_hit( $path );
			$target = (string) $config['general']['target'];
			$this->send( $target ? $target : home_url( '/' ), (int) $config['general']['code'], $path );
		}
	}

	/**
	 * Esegue il redirect (o il 410) e termina.
	 *
	 * @param string $target URL di destinazione (ignorato con 410).
	 * @param int    $code   301|302|410.
	 * @param string $path   Percorso corrente normalizzato (anti-loop).
	 * @return void
	 */
	private function send( $target, $code, $path ) {
		if ( 410 === $code ) {
			// Contenuto eliminato per sempre: Google lo rimuove dall'indice
			// più in fretta di un 404 e non serve alcuna pagina di errore.
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

		// Anti-loop: mai reindirizzare un percorso su sé stesso.
		if ( self::normalize_path( $target ) === $path ) {
			return;
		}

		// Niente cache sui redirect: un 301 senza header di cache viene
		// memorizzato per sempre dal browser — se poi il contenuto torna
		// (es. pagina ripubblicata), l'utente resterebbe rediretto anche
		// quando il server non redirige più. Il valore SEO del 301 sta nel
		// codice di stato, non nella sua cacheabilità.
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

		// Tiene i più recenti.
		if ( count( $log ) > self::LOG_MAX ) {
			uasort( $log, static function ( $a, $b ) {
				return $b['last'] <=> $a['last'];
			} );
			$log = array_slice( $log, 0, self::LOG_MAX, true );
		}

		update_option( self::LOG_OPTION, $log, false );
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
	 * Salva regole e impostazione generale.
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
		$hits    = isset( $_POST['rule_hits'] ) ? (array) wp_unslash( $_POST['rule_hits'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$lasts   = isset( $_POST['rule_last'] ) ? (array) wp_unslash( $_POST['rule_last'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		foreach ( $sources as $i => $source ) {
			$source = trim( sanitize_text_field( (string) $source ) );
			if ( '' === $source ) {
				continue;
			}

			// Conserva solo il percorso (con eventuale "*" finale).
			$wildcard = ( '*' === substr( $source, -1 ) );
			$source   = self::normalize_path( $wildcard ? substr( $source, 0, -1 ) : $source ) . ( $wildcard ? '*' : '' );

			$code = (int) ( $codes[ $i ] ?? 301 );
			if ( ! in_array( $code, array( 301, 302, 410 ), true ) ) {
				$code = 301;
			}

			$rules[] = array(
				'source' => $source,
				'target' => esc_url_raw( trim( (string) ( $targets[ $i ] ?? '' ) ) ),
				'code'   => $code,
				'hits'   => absint( $hits[ $i ] ?? 0 ),
				'last'   => absint( $lasts[ $i ] ?? 0 ),
			);
		}

		$general_code = (int) ( $_POST['general_code'] ?? 301 );

		$config = array(
			'general' => array(
				'enabled' => empty( $_POST['general_enabled'] ) ? 0 : 1,
				'target'  => esc_url_raw( trim( (string) wp_unslash( $_POST['general_target'] ?? '' ) ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
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
	 * Renderizza la pagina Redirect.
	 *
	 * @return void
	 */
	public function page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permesso negato.', 'palladio' ) );
		}

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
		<div class="wrap">
			<h1><?php esc_html_e( 'Palladio — Redirect', 'palladio' ); ?></h1>
			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Redirect salvati.', 'palladio' ); ?></p></div>
			<?php endif; ?>

			<div class="notice notice-info inline" style="margin:16px 0;">
				<p><strong><?php esc_html_e( 'Quale usare, quando', 'palladio' ); ?></strong></p>
				<ul style="list-style:disc;margin-left:1.5em;">
					<li><?php echo wp_kses_post( __( '<strong>301 (definitivo)</strong> — il contenuto ha una nuova casa: pagina spostata, unità venduta con scheda sostitutiva, immagine ricaricata altrove. Google trasferisce il valore SEO al nuovo URL. È la scelta giusta nella maggior parte dei casi.', 'palladio' ) ); ?></li>
					<li><?php echo wp_kses_post( __( '<strong>302 (temporaneo)</strong> — il contenuto tornerà (scheda in revisione, pagina in manutenzione). Google mantiene indicizzato l’URL originale. Usalo solo se davvero temporaneo.', 'palladio' ) ); ?></li>
					<li><?php echo wp_kses_post( __( '<strong>410 (eliminato)</strong> — il contenuto non esiste più e non ha un sostituto. Google lo rimuove dall’indice più in fretta di un 404, senza penalizzazioni. Preferiscilo al redirect verso una pagina non pertinente.', 'palladio' ) ); ?></li>
					<li><?php echo wp_kses_post( __( '<strong>Regola singola vs generale</strong> — per gli URL con traffico o link in ingresso crea sempre una regola singola verso la pagina più pertinente: un redirect di massa verso la home viene trattato da Google come “soft 404” e non trasferisce valore. La regola generale è la rete di sicurezza per tutto il resto.', 'palladio' ) ); ?></li>
					<li><?php echo wp_kses_post( __( '<strong>Immagini e file</strong> — anche i file eliminati da Media vengono intercettati: usa il suffisso <code>*</code> per reindirizzare intere cartelle (es. <code>/wp-content/uploads/2024/06/*</code>).', 'palladio' ) ); ?></li>
				</ul>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'palladio_redirects_save' ); ?>
				<input type="hidden" name="action" value="palladio_save_redirects">

				<h2><?php esc_html_e( 'Regole singole', 'palladio' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Percorso sorgente (es. /vecchia-pagina/ oppure /cartella/* per un prefisso) → URL di destinazione. Hanno la precedenza sulla regola generale.', 'palladio' ); ?></p>
				<table class="widefat striped" id="pll-redirect-rules" style="max-width:1100px;">
					<thead>
						<tr>
							<th style="width:32%;"><?php esc_html_e( 'Sorgente (percorso)', 'palladio' ); ?></th>
							<th style="width:36%;"><?php esc_html_e( 'Destinazione (URL)', 'palladio' ); ?></th>
							<th style="width:14%;"><?php esc_html_e( 'Codice', 'palladio' ); ?></th>
							<th style="width:12%;"><?php esc_html_e( 'Hit', 'palladio' ); ?></th>
							<th style="width:6%;"></th>
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
									<?php echo esc_html( number_format_i18n( (int) ( $rule['hits'] ?? 0 ) ) ); ?>
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
									<td><?php echo esc_html( number_format_i18n( (int) $entry['hits'] ) ); ?></td>
									<td><?php echo esc_html( $date( $entry['last'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p><label><input type="checkbox" name="clear_log" value="1"> <?php esc_html_e( 'Svuota l’elenco al salvataggio', 'palladio' ); ?></label></p>
				<?php endif; ?>

				<?php submit_button( __( 'Salva redirect', 'palladio' ) ); ?>
			</form>
		</div>

		<script>
		(function () {
			var table = document.getElementById('pll-redirect-rules');
			document.getElementById('pll-redirect-add').addEventListener('click', function () {
				var row = table.tBodies[0].insertRow(-1);
				row.innerHTML = '<td><input type="text" class="widefat" name="rule_source[]" placeholder="/vecchia-pagina/"></td>' +
					'<td><input type="text" class="widefat" name="rule_target[]" placeholder="<?php echo esc_js( home_url( '/nuova-pagina/' ) ); ?>"></td>' +
					'<td><select name="rule_code[]"><option value="301">301</option><option value="302">302</option><option value="410">410</option></select></td>' +
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
}
