<?php
/**
 * Modulo WebP: conversione e consegna delle immagini in formato WebP.
 *
 * Converte in .webp le immagini della libreria media (originale + tutte le
 * dimensioni registrate) e riscrive gli URL nell'HTML del frontend quando la
 * versione .webp esiste sul disco. La consegna avviene riscrivendo gli URL
 * nell'HTML (non via .htaccess/Accept), così l'output resta identico per ogni
 * visitatore ed è compatibile con le cache di pagina (Aruba HiSpeed Cache).
 *
 * Perimetro di conversione: solo le immagini effettivamente usate nel sito
 * (immagini in evidenza, contenuti editoriali `_pll_editorial`, immagini nei
 * contenuti, logo) oppure caricate nei media a partire dalla data impostata.
 *
 * I file .webp affiancano gli originali con suffisso: foto.jpg -> foto.jpg.webp
 * (nessuna collisione tra foto.jpg e foto.png, mappatura reversibile).
 *
 * Nello stesso buffer di consegna vengono aggiunti ai tag <img> privi di
 * dimensioni gli attributi width/height espliciti (evita layout shift,
 * segnalato da PageSpeed) e decoding="async".
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Conversione e consegna WebP.
 */
final class Palladio_Media_Webp {

	/**
	 * Capability richiesta per convertire e salvare le impostazioni.
	 */
	const CAP = 'manage_options';

	/**
	 * Option unica (autoload) con le impostazioni del modulo.
	 */
	const OPTION = 'palladio_webp';

	/**
	 * Immagini convertite per ogni richiesta AJAX del bulk.
	 */
	const BATCH = 3;

	/**
	 * Mime type convertibili.
	 *
	 * @var string[]
	 */
	private static $mimes = array( 'image/jpeg', 'image/png' );

	/**
	 * Cache per-request delle dimensioni lette dal filesystem.
	 *
	 * @var array<string,array{0:int,1:int}|false>
	 */
	private static $dims_cache = array();

	/**
	 * Registra gli hook del modulo.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( $this, 'register_image_size' ) );

		// Conversione automatica dei nuovi upload.
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'convert_on_upload' ), 20, 2 );

		// Pagina di amministrazione e azioni.
		add_action( 'admin_menu', array( $this, 'menu' ), 999 );
		add_action( 'admin_post_palladio_webp_settings', array( $this, 'save_settings' ) );
		add_action( 'wp_ajax_palladio_webp_batch', array( $this, 'ajax_batch' ) );

		// Consegna: riscrittura dell'HTML del frontend.
		add_action( 'template_redirect', array( $this, 'start_buffer' ), 1 );
	}

	/**
	 * Dimensione "palladio-hero": display bounded per hero e sfondi a piena
	 * larghezza, al posto dell'originale `full` (che può pesare svariati MB).
	 *
	 * @return void
	 */
	public function register_image_size() {
		add_image_size( 'palladio-hero', 1920, 0, false );
	}

	/**
	 * Impostazioni con default.
	 *
	 * @return array{delivery:int,quality:int,since:string}
	 */
	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();

		return array(
			'delivery' => isset( $saved['delivery'] ) ? (int) $saved['delivery'] : 1,
			'quality'  => isset( $saved['quality'] ) ? max( 40, min( 100, (int) $saved['quality'] ) ) : 82,
			'since'    => isset( $saved['since'] ) ? (string) $saved['since'] : '',
		);
	}

	// -------------------------------------------------------------------------
	// Conversione.
	// -------------------------------------------------------------------------

	/**
	 * Converte i nuovi upload subito dopo la generazione delle dimensioni.
	 *
	 * @param array $metadata      Metadati immagine.
	 * @param int   $attachment_id ID allegato.
	 * @return array Metadati invariati.
	 */
	public function convert_on_upload( $metadata, $attachment_id ) {
		static $busy = false;

		if ( $busy ) {
			return $metadata;
		}

		$busy = true;
		$this->convert_attachment( (int) $attachment_id, false );
		$busy = false;

		return $metadata;
	}

	/**
	 * Converte un allegato: originale + tutte le dimensioni registrate.
	 *
	 * @param int  $attachment_id ID allegato.
	 * @param bool $regen_sizes   Se true genera le dimensioni registrate mancanti
	 *                            (es. palladio-hero per gli upload più vecchi).
	 * @return array{converted:int,skipped:int,saved:int}
	 */
	public function convert_attachment( $attachment_id, $regen_sizes = true ) {
		$result = array(
			'converted' => 0,
			'skipped'   => 0,
			'saved'     => 0,
		);

		$attachment_id = (int) $attachment_id;
		$mime          = get_post_mime_type( $attachment_id );

		if ( ! in_array( (string) $mime, self::$mimes, true ) ) {
			return $result;
		}

		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return $result;
		}

		// Genera le dimensioni registrate mancanti (una sola volta per immagine).
		if ( $regen_sizes ) {
			$meta = wp_get_attachment_metadata( $attachment_id );
			if ( is_array( $meta ) && empty( $meta['sizes']['palladio-hero'] ) && (int) ( $meta['width'] ?? 0 ) > 1920 ) {
				if ( ! function_exists( 'wp_update_image_subsizes' ) ) {
					require_once ABSPATH . 'wp-admin/includes/image.php';
				}
				wp_update_image_subsizes( $attachment_id );
			}
		}

		$quality = self::settings()['quality'];
		$paths   = array( $file );
		$meta    = wp_get_attachment_metadata( $attachment_id );
		$dir     = trailingslashit( dirname( $file ) );

		if ( is_array( $meta ) && ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$paths[] = $dir . $size['file'];
				}
			}
		}

		foreach ( array_unique( $paths ) as $path ) {
			$outcome = self::make_webp( $path, $quality );
			if ( 'ok' === $outcome['status'] ) {
				$result['converted']++;
				$result['saved'] += $outcome['saved'];
			} else {
				$result['skipped']++;
			}
		}

		update_post_meta(
			$attachment_id,
			'_palladio_webp',
			array(
				'time'      => time(),
				'converted' => $result['converted'],
				'saved'     => $result['saved'],
			)
		);

		return $result;
	}

	/**
	 * Crea il file .webp accanto a un'immagine, se conviene.
	 *
	 * Salta se il .webp esiste ed è aggiornato; elimina il .webp se risultasse
	 * più pesante dell'originale (nessun guadagno).
	 *
	 * @param string $path    Percorso assoluto del file sorgente.
	 * @param int    $quality Qualità WebP (40-100).
	 * @return array{status:string,saved:int}
	 */
	private static function make_webp( $path, $quality ) {
		$none = array(
			'status' => 'skip',
			'saved'  => 0,
		);

		if ( ! file_exists( $path ) || ! preg_match( '/\.(jpe?g|png)$/i', $path, $m ) ) {
			return $none;
		}

		$dest = $path . '.webp';
		if ( file_exists( $dest ) && filemtime( $dest ) >= filemtime( $path ) ) {
			return $none;
		}

		$is_png = 'png' === strtolower( $m[1] );
		$made   = false;

		// Imagick, se disponibile e con supporto WebP.
		if ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) {
			try {
				$formats = Imagick::queryFormats( 'WEBP' );
				if ( $formats ) {
					$im = new Imagick( $path );
					$im->setImageFormat( 'webp' );
					$im->setImageCompressionQuality( $quality );
					if ( $is_png ) {
						$im->setOption( 'webp:alpha-quality', '100' );
					}
					$made = $im->writeImage( $dest );
					$im->clear();
					$im->destroy();
				}
			} catch ( Exception $e ) {
				$made = false;
			}
		}

		// Fallback GD.
		if ( ! $made && function_exists( 'imagewebp' ) ) {
			$src = $is_png ? @imagecreatefrompng( $path ) : @imagecreatefromjpeg( $path );
			if ( $src ) {
				if ( $is_png ) {
					imagepalettetotruecolor( $src );
					imagealphablending( $src, false );
					imagesavealpha( $src, true );
				}
				$made = @imagewebp( $src, $dest, $quality );
				imagedestroy( $src );
			}
		}

		if ( ! $made || ! file_exists( $dest ) ) {
			return $none;
		}

		$orig_size = (int) filesize( $path );
		$webp_size = (int) filesize( $dest );

		// Più pesante dell'originale: inutile, si rimuove.
		if ( $webp_size >= $orig_size ) {
			@unlink( $dest );
			return array(
				'status' => 'larger',
				'saved'  => 0,
			);
		}

		return array(
			'status' => 'ok',
			'saved'  => $orig_size - $webp_size,
		);
	}

	// -------------------------------------------------------------------------
	// Perimetro: immagini usate nel sito + upload dalla data impostata.
	// -------------------------------------------------------------------------

	/**
	 * ID degli allegati immagine effettivamente usati nel sito pubblicato.
	 *
	 * Fonti: immagini in evidenza, contenuti editoriali `_pll_editorial`
	 * (image/poster/map_image/city_image), classi wp-image-N nei contenuti,
	 * logo del tema e icona del sito.
	 *
	 * @return int[]
	 */
	public static function used_ids() {
		global $wpdb;

		$ids = array();

		// Immagini in evidenza dei contenuti pubblicati.
		$thumbs = $wpdb->get_col(
			"SELECT DISTINCT pm.meta_value
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_thumbnail_id' AND p.post_status = 'publish'"
		);
		foreach ( $thumbs as $tid ) {
			$ids[] = (int) $tid;
		}

		// Contenuti editoriali Palladio.
		$rows = $wpdb->get_col(
			"SELECT pm.meta_value
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_pll_editorial' AND p.post_status = 'publish'"
		);
		foreach ( $rows as $row ) {
			$data = maybe_unserialize( $row );
			if ( is_array( $data ) ) {
				self::collect_image_ids( $data, $ids );
			}
		}

		// Immagini inserite nei contenuti (classe wp-image-N degli editor).
		$contents = $wpdb->get_col(
			"SELECT post_content FROM {$wpdb->posts}
			 WHERE post_status = 'publish' AND post_content LIKE '%wp-image-%'"
		);
		foreach ( $contents as $content ) {
			if ( preg_match_all( '/wp-image-(\d+)/', $content, $m ) ) {
				foreach ( $m[1] as $cid ) {
					$ids[] = (int) $cid;
				}
			}
		}

		// Logo del tema e icona del sito.
		$logo = (int) get_theme_mod( 'custom_logo' );
		if ( $logo ) {
			$ids[] = $logo;
		}
		$icon = (int) get_option( 'site_icon' );
		if ( $icon ) {
			$ids[] = $icon;
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Raccoglie ricorsivamente gli ID immagine dai contenuti editoriali.
	 *
	 * @param array $data Struttura editoriale (anche annidata).
	 * @param int[] $ids  Accumulatore (per riferimento).
	 * @return void
	 */
	private static function collect_image_ids( $data, &$ids ) {
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				self::collect_image_ids( $value, $ids );
				continue;
			}
			if ( in_array( (string) $key, array( 'image', 'poster', 'map_image', 'city_image' ), true ) && (int) $value > 0 ) {
				$ids[] = (int) $value;
			}
		}
	}

	/**
	 * ID candidati alla conversione: usati nel sito, oppure caricati dalla
	 * data impostata in poi. Solo JPEG/PNG.
	 *
	 * @return int[]
	 */
	public static function candidate_ids() {
		$ids   = self::used_ids();
		$since = self::settings()['since'];

		if ( $since && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $since ) ) {
			$dated = get_posts(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'post_mime_type' => self::$mimes,
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'date_query'     => array(
						array( 'after' => $since, 'inclusive' => true ),
					),
				)
			);
			$ids   = array_merge( $ids, array_map( 'intval', $dated ) );
		}

		$ids = array_values( array_unique( array_filter( $ids ) ) );
		if ( ! $ids ) {
			return array();
		}

		// Tiene solo gli allegati JPEG/PNG reali.
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$valid        = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE ID IN ($placeholders)
				 AND post_type = 'attachment'
				 AND post_mime_type IN ('image/jpeg','image/png')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$ids
			)
		);

		return array_map( 'intval', $valid );
	}

	/**
	 * Candidati non ancora processati dal bulk.
	 *
	 * @return int[]
	 */
	public static function pending_ids() {
		$ids = self::candidate_ids();
		return array_values(
			array_filter(
				$ids,
				static function ( $id ) {
					return ! get_post_meta( $id, '_palladio_webp', true );
				}
			)
		);
	}

	// -------------------------------------------------------------------------
	// Amministrazione.
	// -------------------------------------------------------------------------

	/**
	 * Voce di menu sotto Palladio.
	 *
	 * @return void
	 */
	public function menu() {
		add_submenu_page(
			'edit.php?post_type=pll_edificio',
			__( 'Immagini WebP', 'palladio' ),
			__( 'Immagini WebP', 'palladio' ),
			self::CAP,
			'palladio-webp',
			array( $this, 'page' )
		);
	}

	/**
	 * Salva le impostazioni del modulo.
	 *
	 * @return void
	 */
	public function save_settings() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permessi insufficienti.', 'palladio' ) );
		}
		check_admin_referer( 'palladio_webp_settings' );

		$since = sanitize_text_field( wp_unslash( $_POST['since'] ?? '' ) );
		if ( $since && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $since ) ) {
			$since = '';
		}

		update_option(
			self::OPTION,
			array(
				'delivery' => empty( $_POST['delivery'] ) ? 0 : 1,
				'quality'  => max( 40, min( 100, (int) ( $_POST['quality'] ?? 82 ) ) ),
				'since'    => $since,
			)
		);

		wp_safe_redirect( add_query_arg( array( 'post_type' => 'pll_edificio', 'page' => 'palladio-webp', 'saved' => 1 ), admin_url( 'edit.php' ) ) );
		exit;
	}

	/**
	 * Un passo del bulk: converte fino a BATCH immagini e riporta lo stato.
	 *
	 * @return void
	 */
	public function ajax_batch() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permessi insufficienti.', 'palladio' ) ), 403 );
		}
		check_ajax_referer( 'palladio_webp_batch' );

		$force = ! empty( $_POST['force'] );
		$ids   = $force ? self::candidate_ids() : self::pending_ids();

		if ( $force ) {
			// In modalità forzata processa comunque solo chi non è stato
			// toccato in questo giro (il marcatore viene azzerato all'avvio).
			$ids = array_values(
				array_filter(
					$ids,
					static function ( $id ) {
						return ! get_post_meta( $id, '_palladio_webp', true );
					}
				)
			);
		}

		$batch     = array_slice( $ids, 0, self::BATCH );
		$processed = array();

		foreach ( $batch as $id ) {
			$r           = $this->convert_attachment( $id, true );
			$processed[] = array(
				'id'        => $id,
				'title'     => get_the_title( $id ),
				'converted' => $r['converted'],
				'saved'     => size_format( $r['saved'] ),
			);
		}

		wp_send_json_success(
			array(
				'processed' => $processed,
				'remaining' => max( 0, count( $ids ) - count( $batch ) ),
			)
		);
	}

	/**
	 * Azzera i marcatori di conversione (per rilanciare il bulk da capo).
	 *
	 * @return void
	 */
	private static function reset_markers() {
		global $wpdb;
		$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_palladio_webp' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	}

	/**
	 * Pagina di amministrazione: impostazioni + conversione bulk con progresso.
	 *
	 * @return void
	 */
	public function page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		// Azzeramento marcatori richiesto insieme all'avvio forzato.
		if ( ! empty( $_GET['palladio_webp_reset'] ) && check_admin_referer( 'palladio_webp_reset' ) ) {
			self::reset_markers();
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Stato conversione azzerato: il prossimo avvio riconvertirà tutte le immagini candidate.', 'palladio' ) . '</p></div>';
		}

		$s          = self::settings();
		$candidates = self::candidate_ids();
		$pending    = self::pending_ids();
		$gd         = function_exists( 'imagewebp' );
		$imagick    = extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) && Imagick::queryFormats( 'WEBP' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Immagini WebP', 'palladio' ); ?></h1>

			<?php if ( ! empty( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Impostazioni salvate.', 'palladio' ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! $gd && ! $imagick ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Il server non dispone né di GD con supporto WebP né di Imagick: la conversione non è possibile. Contatta il provider hosting.', 'palladio' ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Impostazioni', 'palladio' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="palladio_webp_settings">
				<?php wp_nonce_field( 'palladio_webp_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Consegna WebP', 'palladio' ); ?></th>
						<td>
							<label><input type="checkbox" name="delivery" value="1" <?php checked( $s['delivery'] ); ?>>
							<?php esc_html_e( 'Servi le versioni .webp nel sito pubblico (riscrittura degli URL nell\'HTML)', 'palladio' ); ?></label>
							<p class="description"><?php esc_html_e( 'La riscrittura avviene solo quando il file .webp esiste. Compatibile con le cache di pagina: l\'HTML è identico per tutti i visitatori.', 'palladio' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="palladio-webp-quality"><?php esc_html_e( 'Qualità (40–100)', 'palladio' ); ?></label></th>
						<td>
							<input type="number" id="palladio-webp-quality" name="quality" min="40" max="100" value="<?php echo esc_attr( $s['quality'] ); ?>" class="small-text">
							<p class="description"><?php esc_html_e( '82 è il compromesso consigliato: riduzione forte del peso senza perdita visibile.', 'palladio' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="palladio-webp-since"><?php esc_html_e( 'Converti i media caricati dal', 'palladio' ); ?></label></th>
						<td>
							<input type="date" id="palladio-webp-since" name="since" value="<?php echo esc_attr( $s['since'] ); ?>">
							<p class="description"><?php esc_html_e( 'Le immagini usate nel sito (in evidenza, contenuti, gallerie, logo) sono sempre incluse. Questa data aggiunge al perimetro anche i media caricati da quel giorno in poi, usati o meno. Lascia vuoto per limitarti alle immagini usate.', 'palladio' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Salva impostazioni', 'palladio' ) ); ?>
			</form>

			<hr>

			<h2><?php esc_html_e( 'Conversione', 'palladio' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: 1: totale candidate, 2: da convertire. */
					esc_html__( 'Immagini candidate: %1$d — da convertire: %2$d.', 'palladio' ),
					count( $candidates ),
					count( $pending )
				);
				?>
				<?php esc_html_e( 'Vengono convertiti l\'originale e tutti i formati generati da WordPress; i file .webp affiancano gli originali senza sostituirli.', 'palladio' ); ?>
			</p>
			<p>
				<button type="button" class="button button-primary" id="palladio-webp-run" <?php disabled( ! $gd && ! $imagick ); ?>><?php esc_html_e( 'Converti ora', 'palladio' ); ?></button>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'post_type' => 'pll_edificio', 'page' => 'palladio-webp', 'palladio_webp_reset' => 1 ), admin_url( 'edit.php' ) ), 'palladio_webp_reset' ) ); ?>"
					onclick="return confirm('<?php echo esc_js( __( 'Azzerare lo stato e riconvertire tutto al prossimo avvio?', 'palladio' ) ); ?>');"><?php esc_html_e( 'Azzera stato', 'palladio' ); ?></a>
			</p>
			<div id="palladio-webp-progress" style="display:none;max-width:640px;">
				<p><strong id="palladio-webp-progress-label"></strong></p>
				<div style="background:#dcdcde;border-radius:3px;height:14px;overflow:hidden;"><div id="palladio-webp-bar" style="background:#2271b1;height:14px;width:0;transition:width .3s;"></div></div>
				<ul id="palladio-webp-log" style="font-family:monospace;font-size:12px;max-height:240px;overflow:auto;margin-top:12px;"></ul>
			</div>
			<p class="description"><?php esc_html_e( 'Al termine svuota la cache di Aruba HiSpeed Cache: le pagine in cache sono state generate con gli URL delle immagini originali.', 'palladio' ); ?></p>

			<script>
			(function () {
				var btn = document.getElementById('palladio-webp-run');
				if (!btn) { return; }
				var box = document.getElementById('palladio-webp-progress');
				var bar = document.getElementById('palladio-webp-bar');
				var lab = document.getElementById('palladio-webp-progress-label');
				var log = document.getElementById('palladio-webp-log');
				var total = <?php echo (int) count( $pending ); ?>;
				var done = 0;

				function step() {
					var body = new URLSearchParams();
					body.append('action', 'palladio_webp_batch');
					body.append('_ajax_nonce', '<?php echo esc_js( wp_create_nonce( 'palladio_webp_batch' ) ); ?>');
					window.fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
						.then(function (r) { return r.json(); })
						.then(function (r) {
							if (!r || !r.success) { throw new Error((r && r.data && r.data.message) || 'errore'); }
							r.data.processed.forEach(function (p) {
								done++;
								var li = document.createElement('li');
								li.textContent = '#' + p.id + ' ' + p.title + ' — ' + p.converted + ' file, -' + p.saved;
								log.insertBefore(li, log.firstChild);
							});
							var pct = total ? Math.min(100, Math.round(done / total * 100)) : 100;
							bar.style.width = pct + '%';
							lab.textContent = done + ' / ' + total;
							if (r.data.remaining > 0 && r.data.processed.length > 0) {
								step();
							} else {
								lab.textContent = '<?php echo esc_js( __( 'Conversione completata.', 'palladio' ) ); ?> (' + done + ')';
								bar.style.width = '100%';
								btn.disabled = false;
							}
						})
						.catch(function (e) {
							lab.textContent = '<?php echo esc_js( __( 'Errore durante la conversione:', 'palladio' ) ); ?> ' + e.message;
							btn.disabled = false;
						});
				}

				btn.addEventListener('click', function () {
					btn.disabled = true;
					box.style.display = 'block';
					lab.textContent = '0 / ' + total;
					step();
				});
			})();
			</script>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Consegna sul frontend.
	// -------------------------------------------------------------------------

	/**
	 * Avvia il buffer di riscrittura dell'HTML pubblico.
	 *
	 * @return void
	 */
	public function start_buffer() {
		if ( is_admin() || is_feed() || is_customize_preview() ) {
			return;
		}
		if ( ! self::settings()['delivery'] ) {
			return;
		}

		ob_start( array( $this, 'process_html' ) );
	}

	/**
	 * Post-processa l'HTML finale: URL .webp + width/height + decoding async.
	 *
	 * @param string $html HTML della pagina.
	 * @return string
	 */
	public function process_html( $html ) {
		if ( ! is_string( $html ) || '' === $html || false === stripos( $html, '<html' ) ) {
			return $html;
		}

		$uploads = wp_get_upload_dir();
		$baseurl = (string) $uploads['baseurl'];
		$basedir = (string) $uploads['basedir'];
		$basepath = (string) wp_parse_url( $baseurl, PHP_URL_PATH );

		if ( '' === $baseurl || '' === $basepath ) {
			return $html;
		}

		// 1) Sostituzione con la versione .webp quando esiste su disco.
		$pattern = '#(?:' . preg_quote( $baseurl, '#' ) . '|' . preg_quote( $basepath, '#' ) . ')[^\s"\'<>()]+\.(?:jpe?g|png)#i';
		$html    = preg_replace_callback(
			$pattern,
			static function ( $m ) use ( $basepath, $basedir ) {
				$url = $m[0];
				$pos = strpos( $url, $basepath );
				if ( false === $pos ) {
					return $url;
				}
				$rel = substr( $url, $pos + strlen( $basepath ) );
				if ( file_exists( $basedir . $rel . '.webp' ) ) {
					return $url . '.webp';
				}
				return $url;
			},
			$html
		);

		// 2) width/height espliciti e decoding async sui tag <img>.
		$html = preg_replace_callback(
			'#<img\b[^>]*>#i',
			function ( $m ) use ( $basepath, $basedir ) {
				return $this->enrich_img_tag( $m[0], $basepath, $basedir );
			},
			$html
		);

		return $html;
	}

	/**
	 * Aggiunge width/height (se assenti e ricavabili) e decoding="async".
	 *
	 * @param string $tag      Tag <img> completo.
	 * @param string $basepath Path pubblico della cartella uploads.
	 * @param string $basedir  Cartella uploads sul filesystem.
	 * @return string
	 */
	private function enrich_img_tag( $tag, $basepath, $basedir ) {
		$insert = '';

		if ( false === stripos( $tag, 'decoding=' ) ) {
			$insert .= ' decoding="async"';
		}

		if ( ! preg_match( '/\bwidth\s*=/i', $tag ) || ! preg_match( '/\bheight\s*=/i', $tag ) ) {
			$dims = null;
			if ( preg_match( '/\bsrc\s*=\s*["\']([^"\']+)["\']/i', $tag, $sm ) ) {
				$dims = $this->image_dimensions( $sm[1], $basepath, $basedir );
			}
			if ( $dims ) {
				if ( ! preg_match( '/\bwidth\s*=/i', $tag ) ) {
					$insert .= ' width="' . (int) $dims[0] . '"';
				}
				if ( ! preg_match( '/\bheight\s*=/i', $tag ) ) {
					$insert .= ' height="' . (int) $dims[1] . '"';
				}
			}
		}

		if ( '' === $insert ) {
			return $tag;
		}

		// Inserisce gli attributi prima della chiusura del tag.
		if ( '/>' === substr( $tag, -2 ) ) {
			return substr( $tag, 0, -2 ) . $insert . ' />';
		}
		return substr( $tag, 0, -1 ) . $insert . '>';
	}

	/**
	 * Dimensioni di un'immagine locale a partire dal suo URL.
	 *
	 * Prima prova con il suffisso -LxA nel nome file (formati intermedi di
	 * WordPress), poi legge dal filesystem (con cache per-request).
	 *
	 * @param string $url      URL dell'immagine.
	 * @param string $basepath Path pubblico della cartella uploads.
	 * @param string $basedir  Cartella uploads sul filesystem.
	 * @return array{0:int,1:int}|null
	 */
	private function image_dimensions( $url, $basepath, $basedir ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$pos  = strpos( $path, $basepath );
		if ( false === $pos ) {
			return null;
		}

		$rel = substr( $path, $pos + strlen( $basepath ) );

		// La consegna WebP appende ".webp": le dimensioni restano quelle del file base.
		$probe = preg_replace( '/\.webp$/i', '', $rel );

		if ( preg_match( '/-(\d+)x(\d+)\.(?:jpe?g|png|webp|gif|avif)$/i', $probe, $m ) ) {
			return array( (int) $m[1], (int) $m[2] );
		}

		$file = $basedir . $rel;
		if ( isset( self::$dims_cache[ $file ] ) ) {
			return self::$dims_cache[ $file ] ? self::$dims_cache[ $file ] : null;
		}

		$size = file_exists( $file ) ? @getimagesize( $file ) : false;
		self::$dims_cache[ $file ] = ( $size && ! empty( $size[0] ) && ! empty( $size[1] ) ) ? array( (int) $size[0], (int) $size[1] ) : false;

		return self::$dims_cache[ $file ] ? self::$dims_cache[ $file ] : null;
	}
}
