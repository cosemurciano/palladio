<?php
/**
 * Modulo Lingue — gestore dei gruppi di traduzione.
 *
 * Modello a pagine clone (§5.4): ogni lingua è un post CPT dedicato, non un
 * insieme di campi dentro l'originale. I post collegati condividono un
 * "gruppo di traduzione"; i dati strutturati (prezzi, stato, misure) sono
 * sincronizzati tra i cloni, mentre i testi restano per-lingua.
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Data layer dei gruppi di traduzione.
 */
class Palladio_I18n_Translator {

	const LANG_META  = '_pll_lang';
	const GROUP_META = '_pll_tgroup';

	/**
	 * Meta strutturati condivisi tra le lingue (sincronizzati sui cloni).
	 *
	 * @return string[]
	 */
	public static function shared_meta_keys() {
		$keys = array(
			'_pll_prezzo', '_pll_prezzo_trattabile', '_pll_millesimi', '_pll_spese_condominiali',
			'_pll_classe_energetica', '_pll_stato_consegna', '_pll_mq_commerciali', '_pll_mq_coperti',
			'_pll_vani', '_pll_camere', '_pll_bagni', '_pll_esposizione', '_pll_terrazza_mq', '_pll_giardino_mq',
			'_pll_virtual_tour_url', '_pll_video_url', '_pll_geo_lat', '_pll_geo_lng',
			'_pll_anno_costruzione', '_pll_mq_totali', '_pll_num_piani', '_pll_num_unita_totali', '_pll_num_unita_vendita',
			'_thumbnail_id',
		);

		/**
		 * Filtra le chiavi meta condivise tra le versioni linguistiche.
		 *
		 * @param string[] $keys Chiavi meta.
		 */
		return apply_filters( 'palladio/i18n/shared_meta', $keys );
	}

	/**
	 * Tassonomie condivise tra le lingue.
	 *
	 * @return string[]
	 */
	public static function shared_taxonomies() {
		return apply_filters( 'palladio/i18n/shared_taxonomies', array( 'pll_tipologia', 'pll_piano', 'pll_stato' ) );
	}

	/**
	 * Lingua di un post (default: lingua sorgente).
	 *
	 * @param int $post_id ID post.
	 * @return string
	 */
	public static function get_lang( $post_id ) {
		$lang = get_post_meta( $post_id, self::LANG_META, true );
		if ( $lang && Palladio_I18n_Languages::is_active( $lang ) ) {
			return $lang;
		}
		return Palladio_I18n_Languages::source();
	}

	/**
	 * Imposta la lingua di un post.
	 *
	 * @param int    $post_id ID post.
	 * @param string $lang    Lingua.
	 * @return void
	 */
	public static function set_lang( $post_id, $lang ) {
		update_post_meta( $post_id, self::LANG_META, sanitize_key( $lang ) );
	}

	/**
	 * Gruppo di traduzione (stringa) di un post.
	 *
	 * @param int $post_id ID post.
	 * @return string
	 */
	public static function get_group( $post_id ) {
		return (string) get_post_meta( $post_id, self::GROUP_META, true );
	}

	/**
	 * Garantisce un gruppo al post (creandolo se assente).
	 *
	 * @param int $post_id ID post.
	 * @return string
	 */
	public static function ensure_group( $post_id ) {
		$group = self::get_group( $post_id );
		if ( '' === $group ) {
			$group = 'g' . (int) $post_id;
			update_post_meta( $post_id, self::GROUP_META, $group );
			// Il post che apre il gruppo eredita la lingua sorgente se non impostata.
			if ( '' === (string) get_post_meta( $post_id, self::LANG_META, true ) ) {
				self::set_lang( $post_id, Palladio_I18n_Languages::source() );
			}
		}
		return $group;
	}

	/**
	 * Post collegati (per lingua) nello stesso gruppo, incluso il post stesso.
	 *
	 * @param int      $post_id  ID post.
	 * @param string[] $statuses Stati post ammessi.
	 * @return array<string,int> lingua => ID post.
	 */
	public static function siblings( $post_id, $statuses = array( 'publish', 'draft', 'pending', 'future', 'private' ) ) {
		$group = self::get_group( $post_id );
		if ( '' === $group ) {
			return array( self::get_lang( $post_id ) => (int) $post_id );
		}

		$ids = get_posts(
			array(
				'post_type'      => get_post_type( $post_id ),
				'post_status'    => $statuses,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => self::GROUP_META, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => $group, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);

		$map = array();
		foreach ( $ids as $id ) {
			$map[ self::get_lang( $id ) ] = (int) $id;
		}

		return $map;
	}

	/**
	 * ID del post collegato in una data lingua (0 se assente).
	 *
	 * @param int      $post_id  ID post.
	 * @param string   $lang     Lingua.
	 * @param string[] $statuses Stati ammessi.
	 * @return int
	 */
	public static function sibling_in( $post_id, $lang, $statuses = array( 'publish' ) ) {
		$map = self::siblings( $post_id, $statuses );
		return isset( $map[ $lang ] ) ? (int) $map[ $lang ] : 0;
	}

	/**
	 * Clona un post nella lingua indicata (bozza collegata).
	 *
	 * @param int    $source_id ID sorgente.
	 * @param string $lang      Lingua di destinazione.
	 * @return int|WP_Error ID del nuovo post.
	 */
	public static function clone_post( $source_id, $lang ) {
		$lang = sanitize_key( $lang );
		$src  = get_post( $source_id );

		if ( ! $src ) {
			return new WP_Error( 'palladio_i18n_no_source', __( 'Post sorgente non trovato.', 'palladio' ) );
		}
		if ( ! Palladio_I18n_Languages::is_active( $lang ) || $lang === self::get_lang( $source_id ) ) {
			return new WP_Error( 'palladio_i18n_bad_lang', __( 'Lingua non valida.', 'palladio' ) );
		}

		// Evita duplicati: se esiste già, restituiscilo.
		$existing = self::sibling_in( $source_id, $lang, array( 'publish', 'draft', 'pending', 'future', 'private' ) );
		if ( $existing ) {
			return $existing;
		}

		$group = self::ensure_group( $source_id );

		// Il genitore, per le unità, è la versione dell'edificio nella stessa lingua se esiste.
		$parent = $src->post_parent;
		if ( $parent ) {
			$parent_sibling = self::sibling_in( $parent, $lang, array( 'publish', 'draft', 'pending', 'future', 'private' ) );
			if ( $parent_sibling ) {
				$parent = $parent_sibling;
			}
		}

		$new_id = wp_insert_post(
			array(
				'post_type'    => $src->post_type,
				'post_status'  => 'draft',
				'post_title'   => $src->post_title,
				'post_content' => $src->post_content,
				'post_excerpt' => $src->post_excerpt,
				'post_parent'  => $parent,
				'menu_order'   => $src->menu_order,
			),
			true
		);

		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		update_post_meta( $new_id, self::GROUP_META, $group );
		self::set_lang( $new_id, $lang );

		// Copia i meta (esclusi quelli di controllo).
		$skip = array( self::LANG_META, self::GROUP_META, '_edit_lock', '_edit_last', '_pll_ai_draft' );
		foreach ( get_post_meta( $source_id ) as $key => $values ) {
			if ( in_array( $key, $skip, true ) ) {
				continue;
			}
			foreach ( $values as $value ) {
				add_post_meta( $new_id, $key, maybe_unserialize( $value ) );
			}
		}

		// Copia le tassonomie.
		foreach ( get_object_taxonomies( $src->post_type ) as $taxonomy ) {
			$terms = wp_get_object_terms( $source_id, $taxonomy, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $terms ) && $terms ) {
				wp_set_object_terms( $new_id, $terms, $taxonomy );
			}
		}

		return (int) $new_id;
	}

	/**
	 * Sincronizza i dati strutturati del post verso i cloni collegati.
	 *
	 * @param int $post_id ID post (sorgente della modifica).
	 * @return void
	 */
	public static function sync_shared( $post_id ) {
		static $running = false;
		if ( $running ) {
			return;
		}

		$siblings = self::siblings( $post_id );
		if ( count( $siblings ) < 2 ) {
			return;
		}

		$running = true;

		$shared      = self::shared_meta_keys();
		$taxonomies  = self::shared_taxonomies();
		$source_lang = self::get_lang( $post_id );

		foreach ( $siblings as $lang => $sib_id ) {
			if ( $lang === $source_lang || (int) $sib_id === (int) $post_id ) {
				continue;
			}

			foreach ( $shared as $key ) {
				$value = get_post_meta( $post_id, $key, true );
				if ( '' === $value || null === $value ) {
					delete_post_meta( $sib_id, $key );
				} else {
					update_post_meta( $sib_id, $key, $value );
				}
			}

			foreach ( $taxonomies as $taxonomy ) {
				$terms = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
				if ( ! is_wp_error( $terms ) ) {
					wp_set_object_terms( $sib_id, $terms, $taxonomy );
				}
			}
		}

		$running = false;
	}
}
