<?php
/**
 * Class WordPress\SMTP\Admin\Log_List_Table
 *
 * @package smtp
 */

namespace WordPress\SMTP\Admin;

use WordPress\SMTP\Log_Repository;

/**
 * Lists the email log.
 *
 * @since 1.2.0
 */
class Log_List_Table extends \WP_List_Table {

	/**
	 * Entries shown per page.
	 *
	 * @since 1.2.0
	 */
	const PER_PAGE = 20;

	/**
	 * Log repository.
	 *
	 * @since 1.2.0
	 * @var Log_Repository
	 */
	private $repository;

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 *
	 * @param Log_Repository $repository Log repository.
	 */
	public function __construct( Log_Repository $repository ) {
		parent::__construct(
			array(
				'singular' => 'email',
				'plural'   => 'emails',
				'ajax'     => false,
			)
		);

		$this->repository = $repository;
	}

	/**
	 * Returns the table columns.
	 *
	 * @since 1.2.0
	 *
	 * @return string[] Column labels keyed by column slug.
	 */
	public function get_columns() {
		return array(
			'created_at' => __( 'Date', 'smtp' ),
			'recipient'  => __( 'To', 'smtp' ),
			'subject'    => __( 'Subject', 'smtp' ),
			'status'     => __( 'Status', 'smtp' ),
			'error'      => __( 'Error', 'smtp' ),
			'provider'   => __( 'Provider', 'smtp' ),
		);
	}

	/**
	 * Returns the status filter links.
	 *
	 * @since 1.2.0
	 *
	 * @return string[] Links keyed by status.
	 */
	protected function get_views() {
		$current = $this->get_current_status();
		$base    = Admin_Page::get_url( 'log' );

		$statuses = array(
			''       => __( 'All', 'smtp' ),
			'sent'   => __( 'Sent', 'smtp' ),
			'failed' => __( 'Failed', 'smtp' ),
		);

		$views = array();
		foreach ( $statuses as $status => $label ) {
			$views[ '' === $status ? 'all' : $status ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$d)</span></a>',
				esc_url( '' === $status ? $base : add_query_arg( 'status', $status, $base ) ),
				$status === $current ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				$this->repository->count( $status )
			);
		}

		return $views;
	}

	/**
	 * Loads the entries for the current page.
	 *
	 * @since 1.2.0
	 */
	public function prepare_items() {
		$status = $this->get_current_status();
		$total  = $this->repository->count( $status );
		$paged  = $this->get_pagenum();

		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$this->items           = $this->repository->get_items( $status, self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	/**
	 * Renders a column.
	 *
	 * @since 1.2.0
	 *
	 * @param array  $item        Log entry.
	 * @param string $column_name Column slug.
	 * @return string Column content.
	 */
	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'created_at':
				$local = get_date_from_gmt( $item['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
				return esc_html( $local );

			case 'status':
				return esc_html( 'sent' === $item['status'] ? __( 'Sent', 'smtp' ) : __( 'Failed', 'smtp' ) );

			case 'recipient':
			case 'subject':
			case 'error':
			case 'provider':
				return esc_html( (string) $item[ $column_name ] );
		}

		return '';
	}

	/**
	 * Message shown when there are no entries.
	 *
	 * @since 1.2.0
	 */
	public function no_items() {
		esc_html_e( 'No emails logged yet.', 'smtp' );
	}

	/**
	 * Returns the status filter from the request.
	 *
	 * @since 1.2.0
	 *
	 * @return string `sent`, `failed` or an empty string.
	 */
	private function get_current_status(): string {
		// Read-only filter parameter, no state is changed.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';

		return in_array( $status, array( 'sent', 'failed' ), true ) ? $status : '';
	}
}
