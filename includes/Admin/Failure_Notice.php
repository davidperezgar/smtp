<?php
/**
 * Class WordPress\SMTP\Admin\Failure_Notice
 *
 * @package smtp
 */

namespace WordPress\SMTP\Admin;

use WordPress\SMTP\Log_Repository;

/**
 * Tells administrators when emails failed recently, so a broken configuration does not go unnoticed.
 *
 * @since 1.2.0
 */
class Failure_Notice {

	/**
	 * Action and nonce name of the dismiss request.
	 *
	 * @since 1.2.0
	 */
	const DISMISS_ACTION = 'smtp_dismiss_failures';

	/**
	 * User meta that stores when the user dismissed the notice, as a GMT MySQL date.
	 *
	 * @since 1.2.0
	 */
	const DISMISSED_META = 'smtp_failures_dismissed';

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
		$this->repository = $repository;
	}

	/**
	 * Registers the hooks.
	 *
	 * @since 1.2.0
	 */
	public function add_hooks() {
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'admin_post_' . self::DISMISS_ACTION, array( $this, 'handle_dismiss' ) );
	}

	/**
	 * Returns how many emails failed in the notice period and after the user dismissed the notice.
	 *
	 * @since 1.2.0
	 *
	 * @param int $user_id User ID.
	 * @return int Number of failed emails.
	 */
	public function count_failures( int $user_id ): int {
		$since     = gmdate( 'Y-m-d H:i:s', time() - $this->get_period() );
		$dismissed = (string) get_user_meta( $user_id, self::DISMISSED_META, true );

		// Both dates use the same format, so they compare as strings.
		if ( '' !== $dismissed && $dismissed > $since ) {
			$since = $dismissed;
		}

		return $this->repository->count_failed_since( $since );
	}

	/**
	 * Renders the notice.
	 *
	 * @since 1.2.0
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) || $this->is_log_screen() ) {
			return;
		}

		$count = $this->count_failures( get_current_user_id() );
		if ( $count < 1 ) {
			return;
		}

		$hours       = max( 1, (int) round( $this->get_period() / HOUR_IN_SECONDS ) );
		$log_url     = add_query_arg( 'status', 'failed', Admin_Page::get_url( 'log' ) );
		$dismiss_url = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::DISMISS_ACTION ), self::DISMISS_ACTION );
		?>
		<div class="notice notice-error">
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: number of emails, 2: number of hours */
						_n( 'SMTP: %1$d email failed to send in the last %2$d hours.', 'SMTP: %1$d emails failed to send in the last %2$d hours.', $count, 'smtp' ),
						$count,
						$hours
					)
				);
				?>
			</p>
			<p>
				<a href="<?php echo esc_url( $log_url ); ?>" class="button button-primary"><?php esc_html_e( 'View failed emails', 'smtp' ); ?></a>
				<a href="<?php echo esc_url( $dismiss_url ); ?>" class="button"><?php esc_html_e( 'Dismiss', 'smtp' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Hides the notice for the current user until new emails fail.
	 *
	 * @since 1.2.0
	 */
	public function handle_dismiss() {
		check_admin_referer( self::DISMISS_ACTION );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'smtp' ), '', array( 'response' => 403 ) );
		}

		update_user_meta( get_current_user_id(), self::DISMISSED_META, current_time( 'mysql', true ) );

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/**
	 * Returns the number of seconds the notice looks back.
	 *
	 * @since 1.2.0
	 *
	 * @return int Seconds.
	 */
	private function get_period(): int {
		/**
		 * Filters how far back, in seconds, the admin notice looks for failed emails.
		 *
		 * @since 1.2.0
		 *
		 * @param int $seconds Seconds. Default one day.
		 */
		$seconds = (int) apply_filters( 'smtp_failure_notice_period', DAY_IN_SECONDS );

		return $seconds > 0 ? $seconds : DAY_IN_SECONDS;
	}

	/**
	 * Whether the email log is already on screen.
	 *
	 * @since 1.2.0
	 *
	 * @return bool True on the SMTP screen.
	 */
	private function is_log_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && 'tools_page_' . Admin_Page::PAGE_SLUG === $screen->id;
	}
}
