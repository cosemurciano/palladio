<?php
/**
 * Modulo Lingue — menu di navigazione per lingua.
 *
 * Duplica un menu di WordPress in un'altra lingua rimappando le voci sulle
 * versioni tradotte (post collegati nel gruppo di traduzione, home →
 * /{lang}/) e lo associa alla lingua: sul frontend, quando la pagina è in
 * quella lingua, il tema riceve automaticamente il menu tradotto al posto
 * dell'originale. Le etichette (titolo, attributo title, descrizione) si
 * traducono tutte insieme con l'AI.
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Menu tradotti e associazione per lingua.
 */
class Palladio_I18n_Menus {

	const CAP    = 'manage_palladio';
	const OPTION = 'palladio_lang_menus'; // [lang => menu term_id].

	/**
	 * CPT del plugin (per la rimappatura delle voci).
	 *
	 * @var string[]
	 */
	private $post_types = array( 'pll_edificio', 'pll_unita', 'pll_scenario', 'pll_storia', 'pll_territorio' );

	/**
	 * Registra gli hook.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'wp_nav_menu_args', array( $this, 'swap_menu' ), 20 );

		if ( is_admin() ) {
			add_action( 'palladio/languages/after_settings', array( $this, 'settings_section' ) );
			add_action( 'admin_post_palladio_duplicate_menu', array( $this, 'handle_duplicate' ) );
			add_action( 'admin_post_palladio_translate_menu', array( $this, 'handle_translate' ) );
			add_action( 'admin_post_palladio_save_lang_menus', array( $this, 'handle_save_mapping' ) );
		}
	}

	/**
	 * Mappa lingua => menu (solo menu ancora esistenti).
	 *
	 * @return array<string,int>
	 */
	public static function mapping() {
		$map = get_option( self::OPTION, array() );
		$map = is_array( $map ) ? $map : array();

		$clean = array();
		foreach ( $map as $lang => $menu_id ) {
			if ( $menu_id && wp_get_nav_menu_object( (int) $menu_id ) ) {
				$clean[ sanitize_key( $lang ) ] = (int) $menu_id;
			}
		}

		return $clean;
	}

	/**
	 * Frontend: sostituisce il menu con la versione della lingua corrente.
	 *
	 * @param array $args Argomenti wp_nav_menu.
	 * @return array
	 */
	public function swap_menu( $args ) {
		if ( is_admin() ) {
			return $args;
		}

		$current = Palladio_I18n_Languages::current();
		if ( $current === Palladio_I18n_Languages::source() ) {
			return $args;
		}

		$map = self::mapping();
		if ( ! empty( $map[ $current ] ) ) {
			$args['menu'] = (int) $map[ $current ];
		}

		return $args;
	}

	// -------------------------------------------------------------------------
	// Duplicazione e traduzione.
	// -------------------------------------------------------------------------

	/**
	 * Duplica un menu in una lingua rimappando le voci sulle versioni tradotte.
	 *
	 * @param int    $menu_id ID menu sorgente.
	 * @param string $lang    Lingua destinazione.
	 * @return int|WP_Error ID nuovo menu.
	 */
	public static function duplicate_menu( $menu_id, $lang ) {
		$menu = wp_get_nav_menu_object( $menu_id );
		if ( ! $menu ) {
			return new WP_Error( 'palladio_menu_missing', __( 'Menu sorgente non trovato.', 'palladio' ) );
		}

		$name   = $menu->name . ' — ' . strtoupper( $lang );
		$new_id = wp_create_nav_menu( $name );
		if ( is_wp_error( $new_id ) ) {
			// Nome già esistente: aggiunge un suffisso numerico.
			$new_id = wp_create_nav_menu( $name . ' ' . wp_rand( 10, 99 ) );
			if ( is_wp_error( $new_id ) ) {
				return $new_id;
			}
		}

		$items = wp_get_nav_menu_items( $menu_id, array( 'post_status' => 'any' ) );
		$items = is_array( $items ) ? $items : array();
		usort( $items, static function ( $a, $b ) {
			return (int) $a->menu_order <=> (int) $b->menu_order;
		} );

		$id_map    = array(); // vecchio item id => nuovo item id (per la gerarchia).
		$menus_obj = new self();

		foreach ( $items as $item ) {
			$object_id = (int) $item->object_id;
			$url       = (string) $item->url;

			// Voce post: usa la versione in lingua se esiste.
			if ( 'post_type' === $item->type && in_array( (string) $item->object, $menus_obj->post_types, true ) && class_exists( 'Palladio_I18n_Translator' ) ) {
				$sibling = Palladio_I18n_Translator::sibling_in( $object_id, $lang, array( 'publish', 'draft', 'pending', 'future', 'private' ) );
				if ( $sibling ) {
					$object_id = $sibling;
				}
			}

			// Link personalizzato alla home: punta alla home di lingua.
			if ( 'custom' === $item->type && untrailingslashit( $url ) === untrailingslashit( home_url( '/' ) ) ) {
				$url = home_url( '/' . $lang . '/' );
			}

			$new_item = wp_update_nav_menu_item( $new_id, 0, array(
				'menu-item-title'       => $item->title,
				'menu-item-attr-title'  => $item->attr_title,
				'menu-item-description' => $item->description,
				'menu-item-object'      => $item->object,
				'menu-item-object-id'   => $object_id,
				'menu-item-type'        => $item->type,
				'menu-item-url'         => $url,
				'menu-item-target'      => $item->target,
				'menu-item-classes'     => implode( ' ', (array) $item->classes ),
				'menu-item-xfn'         => $item->xfn,
				'menu-item-parent-id'   => isset( $id_map[ (int) $item->menu_item_parent ] ) ? $id_map[ (int) $item->menu_item_parent ] : 0,
				'menu-item-position'    => (int) $item->menu_order,
				'menu-item-status'      => 'publish',
			) );

			if ( ! is_wp_error( $new_item ) ) {
				$id_map[ (int) $item->ID ] = (int) $new_item;
			}
		}

		return (int) $new_id;
	}

	/**
	 * Traduce con l'AI tutte le etichette del menu (titolo, title, descrizione).
	 *
	 * @param int    $menu_id ID menu.
	 * @param string $lang    Lingua destinazione.
	 * @return true|WP_Error
	 */
	public static function translate_menu( $menu_id, $lang ) {
		$items = wp_get_nav_menu_items( $menu_id, array( 'post_status' => 'any' ) );
		if ( ! $items ) {
			return new WP_Error( 'palladio_menu_empty', __( 'Il menu non ha voci.', 'palladio' ) );
		}

		$payload = array();
		foreach ( $items as $item ) {
			$payload[] = array(
				'id'          => (int) $item->ID,
				'title'       => (string) $item->title,
				'attr_title'  => (string) $item->attr_title,
				'description' => (string) $item->description,
			);
		}

		$catalog      = Palladio_I18n_Languages::catalog();
		$instructions = sprintf(
			'Sei un traduttore madrelingua %1$s. Traduci le voci di un menu di navigazione di un sito immobiliare di pregio dal %2$s al %1$s: etichette brevi, naturali e idiomatiche, coerenti col tono luxury real estate. ' .
			'Non modificare gli id. Restituisci SOLO il JSON con la stessa struttura: array di oggetti {id, title, attr_title, description} con i testi tradotti (lascia vuoti i campi vuoti).',
			$catalog[ $lang ] ?? $lang,
			$catalog[ Palladio_I18n_Languages::source() ] ?? Palladio_I18n_Languages::source()
		);

		$result = Palladio_AI_Openai::responses(
			$instructions,
			"Traduci il seguente JSON e restituisci SOLO il JSON tradotto:\n" . wp_json_encode( array( 'items' => $payload ), JSON_UNESCAPED_UNICODE ),
			array( 'json' => true, 'max_tokens' => 6000, 'timeout' => 180 )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$decoded = json_decode( (string) $result['text'], true );
		$rows    = isset( $decoded['items'] ) && is_array( $decoded['items'] ) ? $decoded['items'] : ( is_array( $decoded ) ? $decoded : array() );

		$valid_ids = wp_list_pluck( $items, 'ID' );
		foreach ( $rows as $row ) {
			$id = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
			if ( ! $id || ! in_array( $id, array_map( 'absint', $valid_ids ), true ) ) {
				continue;
			}

			// Voce di menu = post nav_menu_item: titolo, excerpt (attr title)
			// e contenuto (descrizione).
			$update = array( 'ID' => $id );
			if ( isset( $row['title'] ) && is_string( $row['title'] ) && '' !== trim( $row['title'] ) ) {
				$update['post_title'] = sanitize_text_field( $row['title'] );
			}
			if ( isset( $row['attr_title'] ) && is_string( $row['attr_title'] ) ) {
				$update['post_excerpt'] = sanitize_text_field( $row['attr_title'] );
			}
			if ( isset( $row['description'] ) && is_string( $row['description'] ) ) {
				$update['post_content'] = sanitize_textarea_field( $row['description'] );
			}
			if ( count( $update ) > 1 ) {
				wp_update_post( wp_slash( $update ) );
			}
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Admin (sezione nella pagina Lingue).
	// -------------------------------------------------------------------------

	/**
	 * Sezione "Menu di navigazione" nella pagina Palladio → Lingue.
	 *
	 * @return void
	 */
	public function settings_section() {
		$catalog = Palladio_I18n_Languages::catalog();
		$active  = Palladio_I18n_Languages::active();
		$source  = Palladio_I18n_Languages::source();
		$langs   = array_values( array_diff( $active, array( $source ) ) );
		$menus   = wp_get_nav_menus();
		$map     = self::mapping();
		$msg     = isset( $_GET['palladio_menu_msg'] ) ? sanitize_key( wp_unslash( $_GET['palladio_menu_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $langs ) {
			return;
		}
		?>
		<hr style="margin:2em 0;">
		<h2><?php esc_html_e( 'Menu di navigazione per lingua', 'palladio' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Duplica il menu in una lingua (le voci puntano automaticamente alle versioni tradotte), poi traduci le etichette con l’AI. Il menu associato sostituisce quello originale su tutte le pagine di quella lingua.', 'palladio' ); ?></p>

		<?php if ( 'duplicated' === $msg ) : ?>
			<div class="notice notice-success inline"><p><?php esc_html_e( 'Menu duplicato e associato alla lingua. Ora puoi tradurre le etichette.', 'palladio' ); ?></p></div>
		<?php elseif ( 'translated' === $msg ) : ?>
			<div class="notice notice-success inline"><p><?php esc_html_e( 'Voci del menu tradotte: controlla in Aspetto → Menu.', 'palladio' ); ?></p></div>
		<?php elseif ( 'saved' === $msg ) : ?>
			<div class="notice notice-success inline"><p><?php esc_html_e( 'Associazioni menu salvate.', 'palladio' ); ?></p></div>
		<?php elseif ( 'error' === $msg ) : ?>
			<?php $err = get_transient( 'palladio_menu_error_' . get_current_user_id() ); delete_transient( 'palladio_menu_error_' . get_current_user_id() ); ?>
			<div class="notice notice-error inline"><p><?php echo esc_html( $err ? $err : __( 'Operazione non riuscita.', 'palladio' ) ); ?></p></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'palladio_lang_menus' ); ?>
			<input type="hidden" name="action" value="palladio_save_lang_menus">

			<table class="widefat striped" style="max-width:900px;">
				<thead><tr>
					<th><?php esc_html_e( 'Lingua', 'palladio' ); ?></th>
					<th><?php esc_html_e( 'Menu associato', 'palladio' ); ?></th>
					<th><?php esc_html_e( 'Azioni', 'palladio' ); ?></th>
				</tr></thead>
				<tbody>
					<?php foreach ( $langs as $lang ) : ?>
						<tr>
							<td><?php echo esc_html( Palladio_Admin_Translations::flag( $lang ) . ' ' . ( $catalog[ $lang ] ?? strtoupper( $lang ) ) ); ?></td>
							<td>
								<select name="menu[<?php echo esc_attr( $lang ); ?>]">
									<option value="0"><?php esc_html_e( '— Nessuno (usa il menu originale) —', 'palladio' ); ?></option>
									<?php foreach ( $menus as $menu ) : ?>
										<option value="<?php echo esc_attr( $menu->term_id ); ?>" <?php selected( $map[ $lang ] ?? 0, $menu->term_id ); ?>><?php echo esc_html( $menu->name ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
							<td style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
								<?php if ( $menus ) : ?>
									<select name="duplicate_source[<?php echo esc_attr( $lang ); ?>]">
										<?php foreach ( $menus as $menu ) : ?>
											<option value="<?php echo esc_attr( $menu->term_id ); ?>"><?php echo esc_html( $menu->name ); ?></option>
										<?php endforeach; ?>
									</select>
									<button type="submit" class="button" name="do_duplicate" value="<?php echo esc_attr( $lang ); ?>"><?php esc_html_e( 'Duplica in questa lingua', 'palladio' ); ?></button>
								<?php endif; ?>
								<?php if ( ! empty( $map[ $lang ] ) ) : ?>
									<button type="submit" class="button button-primary" name="do_translate" value="<?php echo esc_attr( $lang ); ?>"
										onclick="this.textContent='<?php echo esc_js( __( 'Traduzione in corso…', 'palladio' ) ); ?>';">
										<?php esc_html_e( 'Traduci le voci (AI)', 'palladio' ); ?>
									</button>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<p><button type="submit" class="button"><?php esc_html_e( 'Salva associazioni', 'palladio' ); ?></button></p>
		</form>
		<?php
	}

	/**
	 * Salvataggio associazioni + azioni duplica/traduci (stesso form).
	 *
	 * @return void
	 */
	public function handle_save_mapping() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permesso negato.', 'palladio' ) );
		}
		check_admin_referer( 'palladio_lang_menus' );

		$map    = array();
		$posted = isset( $_POST['menu'] ) && is_array( $_POST['menu'] ) ? wp_unslash( $_POST['menu'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		foreach ( $posted as $lang => $menu_id ) {
			$lang    = sanitize_key( $lang );
			$menu_id = absint( $menu_id );
			if ( $menu_id ) {
				$map[ $lang ] = $menu_id;
			}
		}

		$redirect = admin_url( 'edit.php?post_type=pll_edificio&page=palladio-lingue' );

		// Azione: duplica un menu nella lingua.
		if ( ! empty( $_POST['do_duplicate'] ) ) {
			$lang    = sanitize_key( wp_unslash( $_POST['do_duplicate'] ) );
			$sources = isset( $_POST['duplicate_source'] ) && is_array( $_POST['duplicate_source'] ) ? wp_unslash( $_POST['duplicate_source'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$src     = absint( $sources[ $lang ] ?? 0 );

			$new_id = $src ? self::duplicate_menu( $src, $lang ) : new WP_Error( 'palladio_menu_missing', __( 'Seleziona il menu da duplicare.', 'palladio' ) );
			if ( is_wp_error( $new_id ) ) {
				set_transient( 'palladio_menu_error_' . get_current_user_id(), $new_id->get_error_message(), 120 );
				update_option( self::OPTION, $map, false );
				wp_safe_redirect( add_query_arg( 'palladio_menu_msg', 'error', $redirect ) );
				exit;
			}

			$map[ $lang ] = (int) $new_id;
			update_option( self::OPTION, $map, false );
			wp_safe_redirect( add_query_arg( 'palladio_menu_msg', 'duplicated', $redirect ) );
			exit;
		}

		update_option( self::OPTION, $map, false );

		// Azione: traduci le voci del menu associato.
		if ( ! empty( $_POST['do_translate'] ) ) {
			$lang = sanitize_key( wp_unslash( $_POST['do_translate'] ) );
			if ( empty( $map[ $lang ] ) ) {
				set_transient( 'palladio_menu_error_' . get_current_user_id(), __( 'Nessun menu associato a questa lingua.', 'palladio' ), 120 );
				wp_safe_redirect( add_query_arg( 'palladio_menu_msg', 'error', $redirect ) );
				exit;
			}

			$result = self::translate_menu( (int) $map[ $lang ], $lang );
			if ( is_wp_error( $result ) ) {
				set_transient( 'palladio_menu_error_' . get_current_user_id(), $result->get_error_message(), 120 );
				wp_safe_redirect( add_query_arg( 'palladio_menu_msg', 'error', $redirect ) );
				exit;
			}

			wp_safe_redirect( add_query_arg( 'palladio_menu_msg', 'translated', $redirect ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( 'palladio_menu_msg', 'saved', $redirect ) );
		exit;
	}

	/**
	 * Compat: endpoint dedicati (non usati dal form unico).
	 *
	 * @return void
	 */
	public function handle_duplicate() {
		$this->handle_save_mapping();
	}

	/**
	 * Compat: endpoint dedicati (non usati dal form unico).
	 *
	 * @return void
	 */
	public function handle_translate() {
		$this->handle_save_mapping();
	}
}
