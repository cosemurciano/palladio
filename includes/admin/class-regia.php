<?php
/**
 * Modulo Regia — cabina di regia (admin).
 *
 * Menu, dashboard KPI e pagina pipeline lead. La lista usa una
 * WP_List_Table dedicata; i cambi di stato passano da admin-post con nonce
 * e capability `manage_palladio`.
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pagine admin della regia.
 */
class Palladio_Admin_Regia {

	/**
	 * Capability richiesta per la regia.
	 */
	const CAP = 'manage_palladio';

	/**
	 * Registra gli hook admin.
	 *
	 * @return void
	 */
	public function register() {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_palladio_lead_action', array( $this, 'handle_lead_action' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	/**
	 * Aggiunge le voci di menu sotto "Palladio".
	 *
	 * @return void
	 */
	public function menu() {
		$parent = 'edit.php?post_type=pll_edificio';

		add_submenu_page(
			$parent,
			__( 'Regia', 'palladio' ),
			__( 'Regia', 'palladio' ),
			self::CAP,
			'palladio-regia',
			array( $this, 'render_dashboard' )
		);

		add_submenu_page(
			$parent,
			__( 'Lead', 'palladio' ),
			__( 'Lead', 'palladio' ),
			self::CAP,
			'palladio-leads',
			array( $this, 'render_leads' )
		);
	}

	/**
	 * Accoda il CSS admin solo sulle pagine della regia.
	 *
	 * @param string $hook Hook della pagina corrente.
	 * @return void
	 */
	public function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'palladio-regia' ) && false === strpos( (string) $hook, 'palladio-leads' ) ) {
			return;
		}

		$rel  = 'assets/css/palladio-admin.css';
		$path = PALLADIO_DIR . $rel;
		$ver  = ( is_readable( $path ) && filemtime( $path ) ) ? (string) filemtime( $path ) : PALLADIO_VERSION;

		wp_enqueue_style( 'palladio-admin', PALLADIO_URI . $rel, array(), $ver );
	}

	/**
	 * Dashboard: KPI, distribuzione per stato e fonti, ultimi lead.
	 *
	 * @return void
	 */
	public function render_dashboard() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permesso negato.', 'palladio' ) );
		}

		$total    = Palladio_Leads_Store::count_all();
		$statuses = Palladio_Leads_Store::statuses();
		$counts   = Palladio_Leads_Store::counts_by_status();
		$sources  = Palladio_Leads_Store::counts_by_source();
		$recent   = Palladio_Leads_Store::query( array( 'per_page' => 8 ) );

		$open = $total - $counts['chiuso_vinto'] - $counts['chiuso_perso'];
		?>
		<div class="wrap palladio-regia">
			<h1><?php esc_html_e( 'Palladio — Regia', 'palladio' ); ?></h1>

			<div class="palladio-kpi-row">
				<div class="palladio-kpi">
					<span class="palladio-kpi__value"><?php echo esc_html( number_format_i18n( $total ) ); ?></span>
					<span class="palladio-kpi__label"><?php esc_html_e( 'Lead totali', 'palladio' ); ?></span>
				</div>
				<div class="palladio-kpi">
					<span class="palladio-kpi__value"><?php echo esc_html( number_format_i18n( max( 0, $open ) ) ); ?></span>
					<span class="palladio-kpi__label"><?php esc_html_e( 'In lavorazione', 'palladio' ); ?></span>
				</div>
				<div class="palladio-kpi">
					<span class="palladio-kpi__value"><?php echo esc_html( number_format_i18n( $counts['chiuso_vinto'] ) ); ?></span>
					<span class="palladio-kpi__label"><?php esc_html_e( 'Chiusi vinti', 'palladio' ); ?></span>
				</div>
				<div class="palladio-kpi">
					<span class="palladio-kpi__value"><?php echo esc_html( number_format_i18n( $counts['nuovo'] ) ); ?></span>
					<span class="palladio-kpi__label"><?php esc_html_e( 'Nuovi da gestire', 'palladio' ); ?></span>
				</div>
			</div>

			<div class="palladio-panels">
				<div class="palladio-card">
					<h2><?php esc_html_e( 'Pipeline per stato', 'palladio' ); ?></h2>
					<?php $max = max( 1, max( $counts ) ); ?>
					<ul class="palladio-bars">
						<?php foreach ( $statuses as $slug => $label ) : ?>
							<li>
								<span class="palladio-bars__label"><?php echo esc_html( $label ); ?></span>
								<span class="palladio-bars__track">
									<span class="palladio-bars__fill palladio-status--<?php echo esc_attr( $slug ); ?>"
										style="width: <?php echo esc_attr( round( ( $counts[ $slug ] / $max ) * 100 ) ); ?>%"></span>
								</span>
								<span class="palladio-bars__value"><?php echo esc_html( number_format_i18n( $counts[ $slug ] ) ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>

				<div class="palladio-card">
					<h2><?php esc_html_e( 'Fonti', 'palladio' ); ?></h2>
					<?php if ( $sources ) : ?>
						<table class="widefat striped">
							<tbody>
								<?php foreach ( $sources as $label => $n ) : ?>
									<tr>
										<td><?php echo esc_html( $label ); ?></td>
										<td class="palladio-num"><?php echo esc_html( number_format_i18n( $n ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'Ancora nessun lead.', 'palladio' ); ?></p>
					<?php endif; ?>
				</div>
			</div>

			<div class="palladio-card">
				<h2><?php esc_html_e( 'Ultimi lead', 'palladio' ); ?></h2>
				<?php if ( $recent['items'] ) : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Nome', 'palladio' ); ?></th>
								<th><?php esc_html_e( 'Email', 'palladio' ); ?></th>
								<th><?php esc_html_e( 'Stato', 'palladio' ); ?></th>
								<th><?php esc_html_e( 'Data', 'palladio' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $recent['items'] as $lead ) : ?>
								<tr>
									<td><?php echo esc_html( $lead->nome ); ?></td>
									<td><?php echo esc_html( $lead->email ); ?></td>
									<td><span class="palladio-badge palladio-badge--<?php echo esc_attr( $lead->stato ); ?>"><?php echo esc_html( $statuses[ $lead->stato ] ?? $lead->stato ); ?></span></td>
									<td><?php echo esc_html( mysql2date( get_option( 'date_format' ), $lead->created_at ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p><a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=pll_edificio&page=palladio-leads' ) ); ?>"><?php esc_html_e( 'Vai alla pipeline lead', 'palladio' ); ?></a></p>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'Ancora nessun lead. Pubblica un’unità e aggiungi il form contatti.', 'palladio' ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Pagina pipeline lead (WP_List_Table).
	 *
	 * @return void
	 */
	public function render_leads() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permesso negato.', 'palladio' ) );
		}

		require_once PALLADIO_DIR . 'includes/admin/class-leads-list-table.php';

		$table = new Palladio_Leads_List_Table();
		$table->prepare_items();

		$notice = isset( $_GET['palladio_msg'] ) ? sanitize_key( wp_unslash( $_GET['palladio_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap palladio-regia">
			<h1><?php esc_html_e( 'Pipeline lead', 'palladio' ); ?></h1>

			<?php if ( 'updated' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Stato del lead aggiornato.', 'palladio' ); ?></p></div>
			<?php endif; ?>

			<?php $this->render_contact_clicks_report(); ?>

			<form method="get">
				<input type="hidden" name="post_type" value="pll_edificio">
				<input type="hidden" name="page" value="palladio-leads">
				<?php
				$table->views();
				$table->search_box( __( 'Cerca lead', 'palladio' ), 'palladio-lead' );
				$table->display();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Report dei click sui contatti agenzia (mail/telefono/WhatsApp).
	 *
	 * Mostrato in testa alla Pipeline lead: totali per canale e ultimi click
	 * con pagina di provenienza.
	 *
	 * @return void
	 */
	private function render_contact_clicks_report() {
		if ( ! class_exists( 'Palladio_Leads_Form' ) ) {
			return;
		}

		$clicks = Palladio_Leads_Form::contact_clicks();
		$totals = $clicks['totals'];
		$recent = array_reverse( array_slice( (array) $clicks['recent'], -10 ) );

		if ( ! $totals && ! $recent ) {
			return;
		}

		$labels = array(
			'email'    => __( 'Email', 'palladio' ),
			'telefono' => __( 'Telefono', 'palladio' ),
			'whatsapp' => __( 'WhatsApp', 'palladio' ),
		);
		?>
		<div class="palladio-clicks-report" style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:1rem 1.25rem;margin:1rem 0 1.25rem;">
			<h2 style="margin:0 0 0.5rem;font-size:1rem;"><?php esc_html_e( 'Click sui contatti agenzia', 'palladio' ); ?></h2>

			<p style="display:flex;gap:1.5rem;flex-wrap:wrap;margin:0 0 0.75rem;">
				<?php foreach ( $labels as $key => $label ) : ?>
					<span><strong style="font-size:1.3rem;"><?php echo esc_html( number_format_i18n( (int) ( $totals[ $key ] ?? 0 ) ) ); ?></strong>
					<span style="color:#646970;"> <?php echo esc_html( $label ); ?></span></span>
				<?php endforeach; ?>
			</p>

			<?php if ( $recent ) : ?>
				<details>
					<summary style="cursor:pointer;color:#2271b1;"><?php esc_html_e( 'Ultimi click (max 10)', 'palladio' ); ?></summary>
					<table class="widefat striped" style="margin-top:0.5rem;">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Quando', 'palladio' ); ?></th>
								<th><?php esc_html_e( 'Canale', 'palladio' ); ?></th>
								<th><?php esc_html_e( 'Destinatario', 'palladio' ); ?></th>
								<th><?php esc_html_e( 'Pagina di provenienza', 'palladio' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $recent as $row ) : ?>
								<tr>
									<td><?php echo esc_html( wp_date( 'j M Y · H:i', (int) ( $row['time'] ?? 0 ) ) ); ?></td>
									<td><?php echo esc_html( $labels[ $row['channel'] ?? '' ] ?? (string) ( $row['channel'] ?? '' ) ); ?></td>
									<td><?php echo esc_html( (string) ( $row['target'] ?? '' ) ); ?></td>
									<td><?php $p = (string) ( $row['page'] ?? '' ); if ( $p ) : ?><a href="<?php echo esc_url( $p ); ?>" target="_blank" rel="noopener"><?php echo esc_html( wp_parse_url( $p, PHP_URL_PATH ) ? wp_parse_url( $p, PHP_URL_PATH ) : $p ); ?></a><?php endif; ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</details>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handler del cambio stato lead (admin-post).
	 *
	 * @return void
	 */
	public function handle_lead_action() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permesso negato.', 'palladio' ) );
		}

		$lead_id = isset( $_GET['lead'] ) ? absint( wp_unslash( $_GET['lead'] ) ) : 0;
		$status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';

		check_admin_referer( 'palladio_lead_action_' . $lead_id );

		if ( $lead_id && $status ) {
			Palladio_Leads_Store::update_status( $lead_id, $status );
		}

		$redirect = add_query_arg(
			array(
				'post_type'    => 'pll_edificio',
				'page'         => 'palladio-leads',
				'palladio_msg' => 'updated',
			),
			admin_url( 'edit.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}
}
