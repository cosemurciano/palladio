<?php
/**
 * Modulo Regia — tabella lista dei lead.
 *
 * Estende WP_List_Table per la pipeline: filtri per stato, ricerca,
 * paginazione e transizioni di stato via row actions (nonce + capability).
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Tabella dei lead.
 */
class Palladio_Leads_List_Table extends WP_List_Table {

	/**
	 * Totale item per la paginazione.
	 *
	 * @var int
	 */
	private $total = 0;

	/**
	 * Costruttore.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'lead',
				'plural'   => 'leads',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Colonne.
	 *
	 * @return array<string,string>
	 */
	public function get_columns() {
		return array(
			'nome'       => __( 'Nome', 'palladio' ),
			'contatto'   => __( 'Contatto', 'palladio' ),
			'unita'      => __( 'Unità', 'palladio' ),
			'source'     => __( 'Fonte', 'palladio' ),
			'stato'      => __( 'Stato', 'palladio' ),
			'score'      => __( 'Score', 'palladio' ),
			'created_at' => __( 'Data', 'palladio' ),
		);
	}

	/**
	 * Colonne ordinabili.
	 *
	 * @return array<string,array>
	 */
	protected function get_sortable_columns() {
		return array(
			'created_at' => array( 'created_at', true ),
			'nome'       => array( 'nome', false ),
			'score'      => array( 'score', false ),
		);
	}

	/**
	 * Colonna primaria.
	 *
	 * @return string
	 */
	protected function get_default_primary_column_name() {
		return 'nome';
	}

	/**
	 * Viste (filtri per stato) con conteggi.
	 *
	 * @return array<string,string>
	 */
	protected function get_views() {
		$counts  = Palladio_Leads_Store::counts_by_status();
		$total   = array_sum( $counts );
		$current = $this->current_status();
		$base    = admin_url( 'edit.php?post_type=pll_edificio&page=palladio-leads' );

		$views = array();

		$views['all'] = sprintf(
			'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
			esc_url( $base ),
			'' === $current ? ' class="current"' : '',
			esc_html__( 'Tutti', 'palladio' ),
			esc_html( number_format_i18n( $total ) )
		);

		foreach ( Palladio_Leads_Store::statuses() as $slug => $label ) {
			$url            = add_query_arg( 'stato', $slug, $base );
			$views[ $slug ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
				esc_url( $url ),
				$current === $slug ? ' class="current"' : '',
				esc_html( $label ),
				esc_html( number_format_i18n( $counts[ $slug ] ) )
			);
		}

		return $views;
	}

	/**
	 * Prepara gli item.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$per_page = 20;
		$paged    = $this->get_pagenum();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$search  = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'created_at';
		$order   = isset( $_REQUEST['order'] ) ? sanitize_key( wp_unslash( $_REQUEST['order'] ) ) : 'desc';
		// phpcs:enable

		$result = Palladio_Leads_Store::query(
			array(
				'stato'    => $this->current_status(),
				'search'   => $search,
				'orderby'  => $orderby,
				'order'    => $order,
				'per_page' => $per_page,
				'offset'   => ( $paged - 1 ) * $per_page,
			)
		);

		$this->items = $result['items'];
		$this->total = $result['total'];

		$this->set_pagination_args(
			array(
				'total_items' => $this->total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $this->total / $per_page ),
			)
		);
	}

	/**
	 * Stato correntemente filtrato.
	 *
	 * @return string
	 */
	private function current_status() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$stato = isset( $_REQUEST['stato'] ) ? sanitize_key( wp_unslash( $_REQUEST['stato'] ) ) : '';
		return array_key_exists( $stato, Palladio_Leads_Store::statuses() ) ? $stato : '';
	}

	/**
	 * Colonna nome con row actions di transizione stato.
	 *
	 * @param object $item Riga lead.
	 * @return string
	 */
	public function column_nome( $item ) {
		$name = $item->nome ? $item->nome : __( '(senza nome)', 'palladio' );

		$actions = array();
		foreach ( Palladio_Leads_Store::statuses() as $slug => $label ) {
			if ( $slug === $item->stato ) {
				continue;
			}
			$url = wp_nonce_url(
				add_query_arg(
					array(
						'action' => 'palladio_lead_action',
						'lead'   => (int) $item->id,
						'status' => $slug,
					),
					admin_url( 'admin-post.php' )
				),
				'palladio_lead_action_' . (int) $item->id
			);

			$actions[ $slug ] = sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( $label ) );
		}

		return sprintf(
			'<strong>%s</strong>%s',
			esc_html( $name ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * Colonna contatto.
	 *
	 * @param object $item Riga lead.
	 * @return string
	 */
	public function column_contatto( $item ) {
		$out = array();
		if ( $item->email ) {
			$out[] = sprintf( '<a href="mailto:%1$s">%1$s</a>', esc_attr( $item->email ) );
		}
		if ( $item->telefono ) {
			$out[] = esc_html( $item->telefono );
		}
		return implode( '<br>', $out );
	}

	/**
	 * Colonna unità collegate.
	 *
	 * @param object $item Riga lead.
	 * @return string
	 */
	public function column_unita( $item ) {
		$ids = json_decode( (string) $item->unita_ids, true );
		if ( empty( $ids ) || ! is_array( $ids ) ) {
			return '—';
		}

		$labels = array();
		foreach ( array_slice( $ids, 0, 3 ) as $id ) {
			$title = get_the_title( (int) $id );
			if ( $title ) {
				$labels[] = sprintf( '<a href="%s">%s</a>', esc_url( get_edit_post_link( (int) $id ) ), esc_html( $title ) );
			}
		}

		return $labels ? implode( ', ', $labels ) : '—';
	}

	/**
	 * Colonna stato (badge).
	 *
	 * @param object $item Riga lead.
	 * @return string
	 */
	public function column_stato( $item ) {
		$statuses = Palladio_Leads_Store::statuses();
		$label    = $statuses[ $item->stato ] ?? $item->stato;
		return sprintf(
			'<span class="palladio-badge palladio-badge--%s">%s</span>',
			esc_attr( $item->stato ),
			esc_html( $label )
		);
	}

	/**
	 * Colonna data.
	 *
	 * @param object $item Riga lead.
	 * @return string
	 */
	public function column_created_at( $item ) {
		return esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $item->created_at ) );
	}

	/**
	 * Fallback per le altre colonne.
	 *
	 * @param object $item        Riga lead.
	 * @param string $column_name Nome colonna.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'source':
				return $item->source ? esc_html( $item->source ) : '—';
			case 'score':
				return esc_html( (string) (int) $item->score );
			default:
				return '';
		}
	}

	/**
	 * Messaggio tabella vuota.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'Nessun lead trovato.', 'palladio' );
	}
}
