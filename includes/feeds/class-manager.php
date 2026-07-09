<?php
/**
 * Modulo Feeds — manager e distribuzione.
 *
 * Registra l'endpoint pubblico del feed protetto da token, con cache e cron
 * di rigenerazione, più la pagina admin con gli URL e le azioni (§5.8).
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Feed manager.
 */
class Palladio_Feeds_Manager {

	const CAP  = 'manage_palladio';
	const CRON = 'palladio_feeds_regen';

	/**
	 * Registra hook frontend, admin e cron.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'init', array( $this, 'rewrite' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render' ) );

		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_palladio_feeds_action', array( $this, 'handle_action' ) );

		add_action( self::CRON, array( __CLASS__, 'regenerate' ) );
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON );
		}
	}

	/**
	 * Adapter disponibili, per slug.
	 *
	 * @return array<string,Palladio_Feeds_Adapter>
	 */
	public static function adapters() {
		$adapters = array();
		foreach ( array( 'Palladio_Feeds_Kyero', 'Palladio_Feeds_Csv' ) as $class ) {
			if ( class_exists( $class ) ) {
				$adapter                    = new $class();
				$adapters[ $adapter->key() ] = $adapter;
			}
		}

		/**
		 * Filtra gli adapter di feed (per registrare tracciati proprietari,
		 * es. Immobiliare.it, estendendo Palladio_Feeds_Adapter).
		 *
		 * @param array $adapters Mappa slug => istanza.
		 */
		return apply_filters( 'palladio/feeds/adapters', $adapters );
	}

	/**
	 * Token del feed (generato se assente).
	 *
	 * @return string
	 */
	public static function token() {
		$token = get_option( 'palladio_feeds_token', '' );
		if ( ! $token ) {
			$token = wp_generate_password( 32, false );
			update_option( 'palladio_feeds_token', $token, false );
		}
		return $token;
	}

	/**
	 * URL del feed di un adapter.
	 *
	 * @param string $key Slug adapter.
	 * @return string
	 */
	public static function feed_url( $key ) {
		return add_query_arg(
			array(
				'palladio_feed' => $key,
				'token'         => self::token(),
			),
			home_url( '/' )
		);
	}

	/**
	 * Registra la rewrite pretty (best effort; il parametro resta il fallback).
	 *
	 * @return void
	 */
	public function rewrite() {
		add_rewrite_rule( '^palladio-feed/([a-z0-9-]+)/?$', 'index.php?palladio_feed=$matches[1]', 'top' );
	}

	/**
	 * Registra la query var.
	 *
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public function query_vars( $vars ) {
		$vars[] = 'palladio_feed';
		return $vars;
	}

	/**
	 * Rende il feed se la richiesta lo indica.
	 *
	 * @return void
	 */
	public function maybe_render() {
		$key = get_query_var( 'palladio_feed' );
		if ( ! $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$key = isset( $_GET['palladio_feed'] ) ? sanitize_key( wp_unslash( $_GET['palladio_feed'] ) ) : '';
		}
		if ( ! $key ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token    = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		$expected = self::token();

		if ( ! $token || ! hash_equals( $expected, $token ) ) {
			status_header( 403 );
			nocache_headers();
			echo esc_html__( 'Token del feed non valido.', 'palladio' );
			exit;
		}

		$adapters = self::adapters();
		if ( ! isset( $adapters[ $key ] ) ) {
			status_header( 404 );
			nocache_headers();
			echo esc_html__( 'Feed non trovato.', 'palladio' );
			exit;
		}

		$adapter = $adapters[ $key ];
		$output  = self::get_cached( $adapter );

		status_header( 200 );
		header( 'Content-Type: ' . $adapter->content_type() );
		// Output grezzo del feed (XML/CSV), già costruito con escaping sicuro.
		echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Restituisce il feed dalla cache o lo rigenera.
	 *
	 * @param Palladio_Feeds_Adapter $adapter Adapter.
	 * @return string
	 */
	private static function get_cached( $adapter ) {
		$transient = 'palladio_feed_' . $adapter->key();
		$cached    = get_transient( $transient );

		if ( false !== $cached ) {
			return (string) $cached;
		}

		$output = $adapter->render( $adapter->query_units() );

		/**
		 * TTL della cache del feed in secondi.
		 *
		 * @param int    $ttl TTL.
		 * @param string $key Adapter.
		 */
		$ttl = (int) apply_filters( 'palladio/feeds/cache_ttl', HOUR_IN_SECONDS, $adapter->key() );

		set_transient( $transient, $output, $ttl );

		return $output;
	}

	/**
	 * Cron: invalida le cache così i feed si rigenerano alla prossima richiesta.
	 *
	 * @return void
	 */
	public static function regenerate() {
		foreach ( array_keys( self::adapters() ) as $key ) {
			delete_transient( 'palladio_feed_' . $key );
		}
	}

	/**
	 * Aggiunge la pagina "Feeds".
	 *
	 * @return void
	 */
	public function menu() {
		add_submenu_page(
			'edit.php?post_type=pll_edificio',
			__( 'Feeds', 'palladio' ),
			__( 'Feeds', 'palladio' ),
			self::CAP,
			'palladio-feeds',
			array( $this, 'page' )
		);
	}

	/**
	 * Pagina admin dei feed.
	 *
	 * @return void
	 */
	public function page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permesso negato.', 'palladio' ) );
		}

		$msg = isset( $_GET['palladio_msg'] ) ? sanitize_key( wp_unslash( $_GET['palladio_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Palladio — Feeds', 'palladio' ); ?></h1>

			<?php if ( 'regenerated' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Feed rigenerati.', 'palladio' ); ?></p></div>
			<?php elseif ( 'token' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Token rigenerato: aggiorna gli URL sui portali.', 'palladio' ); ?></p></div>
			<?php endif; ?>

			<p class="description"><?php esc_html_e( 'URL protetti da token da configurare sui portali. Rigenera il token per revocare gli URL esistenti.', 'palladio' ); ?></p>

			<table class="widefat striped" style="max-width:960px">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Formato', 'palladio' ); ?></th>
						<th><?php esc_html_e( 'URL', 'palladio' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( self::adapters() as $adapter ) : ?>
						<tr>
							<td><?php echo esc_html( $adapter->label() ); ?></td>
							<td><code><?php echo esc_url( self::feed_url( $adapter->key() ) ); ?></code></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<p style="margin-top:1rem;display:flex;gap:.5rem;">
				<a class="button button-primary" href="<?php echo esc_url( $this->action_url( 'regenerate' ) ); ?>"><?php esc_html_e( 'Rigenera ora i feed', 'palladio' ); ?></a>
				<a class="button" href="<?php echo esc_url( $this->action_url( 'reset_token' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Rigenerare il token? Gli URL esistenti smetteranno di funzionare.', 'palladio' ) ); ?>');"><?php esc_html_e( 'Rigenera token', 'palladio' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * URL di un'azione admin-post con nonce.
	 *
	 * @param string $action Azione.
	 * @return string
	 */
	private function action_url( $action ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'palladio_feeds_action',
					'do'     => $action,
				),
				admin_url( 'admin-post.php' )
			),
			'palladio_feeds_' . $action
		);
	}

	/**
	 * Handler delle azioni admin (rigenera / reset token).
	 *
	 * @return void
	 */
	public function handle_action() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permesso negato.', 'palladio' ) );
		}

		$do = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
		check_admin_referer( 'palladio_feeds_' . $do );

		$msg = '';
		if ( 'regenerate' === $do ) {
			self::regenerate();
			$msg = 'regenerated';
		} elseif ( 'reset_token' === $do ) {
			update_option( 'palladio_feeds_token', wp_generate_password( 32, false ), false );
			self::regenerate();
			$msg = 'token';
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type'    => 'pll_edificio',
					'page'         => 'palladio-feeds',
					'palladio_msg' => $msg,
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}
}
