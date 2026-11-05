<?php
/**
 * Class WordPress\SMTP\Log_Repository
 *
 * @package smtp
 */

namespace WordPress\SMTP;

// The email log lives in its own table, so direct queries are expected here. The table name is
// built from the trusted `$wpdb->prefix` and every dynamic value goes through `$wpdb->prepare()`.
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

/**
 * Stores and queries the email log.
 *
 * @since 1.2.0
 */
class Log_Repository {

	/**
	 * Current version of the log table schema.
	 *
	 * @since 1.2.0
	 */
	const DB_VERSION = '1';

	/**
	 * Name of the option that stores the installed schema version.
	 *
	 * @since 1.2.0
	 */
	const DB_VERSION_OPTION = 'smtp_db_version';

	/**
	 * Returns the full table name.
	 *
	 * @since 1.2.0
	 *
	 * @return string Table name.
	 */
	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'smtp_logs';
	}

	/**
	 * Creates or updates the table when the stored schema version is outdated.
	 *
	 * @since 1.2.0
	 */
	public function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = $this->get_table_name();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				recipient text NOT NULL,
				subject text NOT NULL,
				status varchar(10) NOT NULL,
				error text NOT NULL,
				provider varchar(50) NOT NULL,
				PRIMARY KEY  (id),
				KEY status (status),
				KEY created_at (created_at)
			) {$charset};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Inserts a log entry.
	 *
	 * @since 1.2.0
	 *
	 * @param array $entry Entry with `recipient`, `subject`, `status`, `error` and `provider`.
	 */
	public function insert( array $entry ) {
		global $wpdb;

		$wpdb->insert(
			$this->get_table_name(),
			array(
				'created_at' => current_time( 'mysql', true ),
				'recipient'  => $entry['recipient'],
				'subject'    => $entry['subject'],
				'status'     => $entry['status'],
				'error'      => $entry['error'],
				'provider'   => $entry['provider'],
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Returns a page of log entries, newest first.
	 *
	 * @since 1.2.0
	 *
	 * @param string $status   Status to filter by, or an empty string for all.
	 * @param int    $per_page Entries per page.
	 * @param int    $offset   Offset.
	 * @return array[] Entries.
	 */
	public function get_items( string $status, int $per_page, int $offset ): array {
		global $wpdb;

		$table = $this->get_table_name();

		if ( '' !== $status ) {
			$query = $wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s ORDER BY id DESC LIMIT %d OFFSET %d",
				$status,
				$per_page,
				$offset
			);
		} else {
			$query = $wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			);
		}

		$items = $wpdb->get_results( $query, ARRAY_A );

		return is_array( $items ) ? $items : array();
	}

	/**
	 * Counts log entries.
	 *
	 * @since 1.2.0
	 *
	 * @param string $status Status to filter by, or an empty string for all.
	 * @return int Number of entries.
	 */
	public function count( string $status = '' ): int {
		global $wpdb;

		$table = $this->get_table_name();

		if ( '' !== $status ) {
			$query = $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status );
		} else {
			$query = "SELECT COUNT(*) FROM {$table}";
		}

		return (int) $wpdb->get_var( $query );
	}

	/**
	 * Returns the entries sent to an email address, oldest first.
	 *
	 * @since 1.2.0
	 *
	 * @param string $email  Email address.
	 * @param int    $limit  Maximum number of entries.
	 * @param int    $offset Offset.
	 * @return array[] Entries.
	 */
	public function get_items_by_recipient( string $email, int $limit, int $offset = 0 ): array {
		global $wpdb;

		list( $where, $args ) = $this->get_recipient_condition( $email );

		$table  = $this->get_table_name();
		$args[] = $limit;
		$args[] = $offset;
		// The condition holds one placeholder per argument, see get_recipient_condition().
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY id ASC LIMIT %d OFFSET %d", $args ), ARRAY_A );

		return is_array( $items ) ? $items : array();
	}

	/**
	 * Deletes entries by ID.
	 *
	 * @since 1.2.0
	 *
	 * @param int[] $ids Entry IDs.
	 * @return int Number of deleted entries.
	 */
	public function delete( array $ids ): int {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( array() === $ids ) {
			return 0;
		}

		$table        = $this->get_table_name();
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- One %d placeholder per ID.
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids ) );
	}

	/**
	 * Deletes entries older than the given number of days.
	 *
	 * @since 1.2.0
	 *
	 * @param int $days Number of days to keep.
	 */
	public function purge( int $days ) {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$table  = $this->get_table_name();

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
	}

	/**
	 * Returns the SQL condition that matches an address in the recipient column.
	 *
	 * The column holds a comma separated list where each recipient is either `email` or
	 * `Name <email>`. The address must match a whole entry, so `bob@example.com` does not
	 * match `jbob@example.com`.
	 *
	 * @since 1.2.0
	 *
	 * @param string $email Email address.
	 * @return array SQL condition with placeholders, and its arguments.
	 */
	private function get_recipient_condition( string $email ): array {
		global $wpdb;

		$like = $wpdb->esc_like( $email );

		$patterns = array(
			'%<' . $like . '>%',
			$like . ',%',
			'%,' . $like,
			'%, ' . $like,
			'%,' . $like . ',%',
			'%, ' . $like . ',%',
		);

		$where = '(recipient = %s' . str_repeat( ' OR recipient LIKE %s', count( $patterns ) ) . ')';

		return array( $where, array_merge( array( $email ), $patterns ) );
	}
}
