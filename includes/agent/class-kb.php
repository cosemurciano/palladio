<?php
/**
 * Modulo Agent — Knowledge Base (RAG).
 *
 * Alla pubblicazione/modifica dei CPT indicizza i contenuti in chunk con
 * embeddings (text-embedding-3-small) nella tabella palladio_kb; la ricerca
 * usa la cosine similarity in PHP (a poche centinaia di chunk non serve un
 * vector DB, §5.5).
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Indicizzazione e ricerca semantica.
 */
class Palladio_Agent_KB {

	const DB_VERSION = 1;

	/**
	 * CPT indicizzati.
	 *
	 * @var string[]
	 */
	private $post_types = array( 'pll_edificio', 'pll_unita', 'pll_scenario', 'pll_storia' );

	/**
	 * Registra hook di indicizzazione.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_upgrade' ) );
		add_action( 'save_post', array( $this, 'on_save' ), 20, 2 );
		add_action( 'before_delete_post', array( $this, 'on_delete' ) );
	}

	/**
	 * Nome tabella.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'palladio_kb';
	}

	/**
	 * Crea/aggiorna la tabella KB.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			post_type VARCHAR(30) NOT NULL DEFAULT '',
			lang VARCHAR(10) NOT NULL DEFAULT '',
			chunk_index INT NOT NULL DEFAULT 0,
			content LONGTEXT NULL,
			embedding LONGTEXT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY post_id (post_id)
		) {$collate};";

		dbDelta( $sql );
		update_option( 'palladio_kb_db_version', self::DB_VERSION );
	}

	/**
	 * Aggiorna lo schema se necessario.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( (int) get_option( 'palladio_kb_db_version', 0 ) < self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Reindicizza alla pubblicazione/aggiornamento.
	 *
	 * @param int     $post_id ID post.
	 * @param WP_Post $post    Post.
	 * @return void
	 */
	public function on_save( $post_id, $post ) {
		if ( ! in_array( $post->post_type, $this->post_types, true ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( 'publish' !== $post->post_status ) {
			$this->remove_post( $post_id );
			return;
		}

		// Solo se l'AI è configurata (serve per gli embeddings).
		if ( ! class_exists( 'Palladio_AI_Settings' ) || ! Palladio_AI_Settings::is_ready() ) {
			return;
		}

		$this->index_post( $post_id );
	}

	/**
	 * Rimuove dalla KB all'eliminazione.
	 *
	 * @param int $post_id ID post.
	 * @return void
	 */
	public function on_delete( $post_id ) {
		$this->remove_post( $post_id );
	}

	/**
	 * Indicizza un post.
	 *
	 * @param int $post_id ID post.
	 * @return int|WP_Error Numero di chunk indicizzati.
	 */
	public function index_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'palladio_kb_no_post', __( 'Post non trovato.', 'palladio' ) );
		}

		$chunks = $this->build_chunks( $post );
		if ( empty( $chunks ) ) {
			$this->remove_post( $post_id );
			return 0;
		}

		$embeddings = Palladio_AI_Openai::embeddings( $chunks );
		if ( is_wp_error( $embeddings ) ) {
			return $embeddings;
		}

		$this->remove_post( $post_id );

		global $wpdb;
		$now  = current_time( 'mysql' );
		$lang = class_exists( 'Palladio_I18n_Languages' ) ? Palladio_I18n_Languages::source() : '';

		foreach ( $chunks as $i => $chunk ) {
			if ( empty( $embeddings[ $i ] ) ) {
				continue;
			}
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				self::table(),
				array(
					'post_id'     => $post_id,
					'post_type'   => $post->post_type,
					'lang'        => $lang,
					'chunk_index' => $i,
					'content'     => $chunk,
					'embedding'   => wp_json_encode( $embeddings[ $i ] ),
					'updated_at'  => $now,
				),
				array( '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
			);
		}

		return count( $chunks );
	}

	/**
	 * Rimuove tutti i chunk di un post.
	 *
	 * @param int $post_id ID post.
	 * @return void
	 */
	public function remove_post( $post_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'post_id' => (int) $post_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Ricerca i chunk più simili alla query.
	 *
	 * @param string $query Testo di ricerca.
	 * @param int    $k     Numero di risultati.
	 * @return array<int,array{content:string,post_id:int,score:float}>
	 */
	public static function search( $query, $k = 5 ) {
		$embedded = Palladio_AI_Openai::embeddings( $query );
		if ( is_wp_error( $embedded ) || empty( $embedded[0] ) ) {
			return array();
		}
		$qvec = $embedded[0];

		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( "SELECT post_id, content, embedding FROM {$table}" ); // phpcs:ignore WordPress.DB

		if ( empty( $rows ) ) {
			return array();
		}

		$scored = array();
		foreach ( $rows as $row ) {
			$vec = json_decode( (string) $row->embedding, true );
			if ( ! is_array( $vec ) ) {
				continue;
			}
			$scored[] = array(
				'content' => (string) $row->content,
				'post_id' => (int) $row->post_id,
				'score'   => self::cosine( $qvec, $vec ),
			);
		}

		usort(
			$scored,
			static function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		return array_slice( $scored, 0, max( 1, (int) $k ) );
	}

	/**
	 * Cosine similarity tra due vettori.
	 *
	 * @param array $a Vettore A.
	 * @param array $b Vettore B.
	 * @return float
	 */
	private static function cosine( $a, $b ) {
		$dot = 0.0;
		$na  = 0.0;
		$nb  = 0.0;
		$len = min( count( $a ), count( $b ) );

		for ( $i = 0; $i < $len; $i++ ) {
			$dot += $a[ $i ] * $b[ $i ];
			$na  += $a[ $i ] * $a[ $i ];
			$nb  += $b[ $i ] * $b[ $i ];
		}

		if ( $na <= 0 || $nb <= 0 ) {
			return 0.0;
		}

		return $dot / ( sqrt( $na ) * sqrt( $nb ) );
	}

	/**
	 * Costruisce i chunk testuali di un post.
	 *
	 * @param WP_Post $post Post.
	 * @return string[]
	 */
	private function build_chunks( $post ) {
		$parts = array();

		$title = get_the_title( $post );
		if ( $title ) {
			$parts[] = $title;
		}

		if ( 'pll_unita' === $post->post_type ) {
			$facts = array();
			foreach ( array( 'mq_commerciali' => 'Superficie m²', 'camere' => 'Camere', 'bagni' => 'Bagni', 'esposizione' => 'Esposizione', 'classe_energetica' => 'Classe energetica', 'destinazione_uso' => 'Destinazione d’uso' ) as $key => $label ) {
				$val = get_post_meta( $post->ID, '_pll_' . $key, true );
				if ( '' !== $val && '0' !== (string) $val ) {
					$facts[] = $label . ': ' . wp_strip_all_tags( (string) $val );
				}
			}
			if ( $facts ) {
				$parts[] = implode( '. ', $facts );
			}
		}

		$content = wp_strip_all_tags( (string) $post->post_content );
		if ( $content ) {
			$parts[] = $content;
		}

		// FAQ generate dal Composer, se presenti.
		$faq = get_post_meta( $post->ID, '_pll_faq', true );
		if ( is_array( $faq ) ) {
			foreach ( $faq as $item ) {
				if ( ! empty( $item['q'] ) && ! empty( $item['a'] ) ) {
					$parts[] = $item['q'] . ' ' . $item['a'];
				}
			}
		}

		// Unisce e spezza in chunk di ~1500 caratteri su confini di frase.
		$text = implode( "\n\n", $parts );
		return $this->split( $text, 1500 );
	}

	/**
	 * Spezza un testo in chunk rispettando i confini di paragrafo/frase.
	 *
	 * @param string $text Testo.
	 * @param int    $max  Lunghezza massima chunk.
	 * @return string[]
	 */
	private function split( $text, $max ) {
		$text = trim( preg_replace( '/\s+\n/', "\n", $text ) );
		if ( '' === $text ) {
			return array();
		}
		if ( strlen( $text ) <= $max ) {
			return array( $text );
		}

		$chunks   = array();
		$sentences = preg_split( '/(?<=[.!?])\s+/', $text );
		$buffer    = '';

		foreach ( $sentences as $sentence ) {
			if ( strlen( $buffer ) + strlen( $sentence ) + 1 > $max && '' !== $buffer ) {
				$chunks[] = trim( $buffer );
				$buffer   = '';
			}
			$buffer .= ' ' . $sentence;
		}

		if ( '' !== trim( $buffer ) ) {
			$chunks[] = trim( $buffer );
		}

		return $chunks;
	}
}
