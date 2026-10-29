<?php
/**
 * Class WordPress\SMTP\Admin\Admin_Page
 *
 * @package smtp
 */

namespace WordPress\SMTP\Admin;

use WordPress\SMTP\Log_Repository;
use WordPress\SMTP\Provider_Registry;
use WordPress\SMTP\Settings;

/**
 * Registers and renders the Settings > SMTP screen.
 *
 * @since 0.1.0
 */
class Admin_Page {

	/**
	 * Menu and settings page slug.
	 *
	 * @since 0.1.0
	 */
	const PAGE_SLUG = 'smtp';

	/**
	 * Settings.
	 *
	 * @since 0.1.0
	 * @var Settings
	 */
	private $settings;

	/**
	 * Provider registry.
	 *
	 * @since 0.1.0
	 * @var Provider_Registry
	 */
	private $registry;

	/**
	 * Log repository.
	 *
	 * @since 0.1.0
	 * @var Log_Repository
	 */
	private $repository;

	/**
	 * Path to the main plugin file.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private $main_file;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param Settings          $settings   Settings.
	 * @param Provider_Registry $registry   Provider registry.
	 * @param Log_Repository    $repository Log repository.
	 * @param string            $main_file  Absolute path to the main plugin file.
	 */
	public function __construct( Settings $settings, Provider_Registry $registry, Log_Repository $repository, string $main_file ) {
		$this->settings   = $settings;
		$this->registry   = $registry;
		$this->repository = $repository;
		$this->main_file  = $main_file;
	}

	/**
	 * Registers the hooks.
	 *
	 * @since 0.1.0
	 */
	public function add_hooks() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( $this->main_file ), array( $this, 'add_action_link' ) );
	}

	/**
	 * Adds the Settings > SMTP menu item.
	 *
	 * @since 0.1.0
	 */
	public function add_menu() {
		add_options_page(
			__( 'SMTP', 'smtp' ),
			__( 'SMTP', 'smtp' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Adds a Settings link to the plugin row.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $links Plugin action links.
	 * @return string[] Plugin action links.
	 */
	public function add_action_link( $links ) {
		$url = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );

		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'smtp' ) . '</a>' );

		return $links;
	}

	/**
	 * Registers the setting and its fields.
	 *
	 * @since 0.1.0
	 */
	public function register_settings() {
		register_setting(
			self::PAGE_SLUG,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this->settings, 'sanitize' ),
				'default'           => array(),
			)
		);

		add_settings_section( 'smtp_main', '', '__return_empty_string', self::PAGE_SLUG );

		add_settings_field(
			'provider',
			__( 'Provider', 'smtp' ),
			array( $this, 'render_provider_field' ),
			self::PAGE_SLUG,
			'smtp_main',
			array( 'label_for' => 'smtp-provider' )
		);

		foreach ( $this->settings->get_fields() as $key => $field ) {
			add_settings_field(
				$key,
				$field['label'],
				array( $this, 'render_field' ),
				self::PAGE_SLUG,
				'smtp_main',
				array(
					'key'       => $key,
					'field'     => $field,
					'label_for' => 'smtp-' . $key,
				)
			);
		}
	}

	/**
	 * Renders the screen.
	 *
	 * @since 0.1.0
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tabs = array(
			'settings' => __( 'Settings', 'smtp' ),
			'log'      => __( 'Email log', 'smtp' ),
			'test'     => __( 'Test', 'smtp' ),
		);

		// Read-only navigation parameter, no state is changed.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
		if ( ! isset( $tabs[ $current ] ) ) {
			$current = 'settings';
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a
						href="<?php echo esc_url( add_query_arg( 'tab', $slug, admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ) ); ?>"
						class="nav-tab<?php echo $slug === $current ? ' nav-tab-active' : ''; ?>"
					>
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<?php
			settings_errors();

			if ( 'log' === $current ) {
				$this->render_log_tab();
			} elseif ( 'test' === $current ) {
				$this->render_test_tab();
			} else {
				$this->render_settings_tab();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Renders the provider select.
	 *
	 * @since 0.1.0
	 */
	public function render_provider_field() {
		$current = $this->settings->get_provider_slug();
		?>
		<select id="smtp-provider" name="<?php echo esc_attr( Settings::OPTION ); ?>[provider]">
			<?php foreach ( $this->registry->get_all() as $slug => $provider ) : ?>
				<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $current, $slug ); ?>>
					<?php echo esc_html( $provider['label'] ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php esc_html_e( 'After changing the provider, save the settings to see its fields.', 'smtp' ); ?>
		</p>
		<?php
	}

	/**
	 * Renders a settings field.
	 *
	 * @since 0.1.0
	 *
	 * @param array $args Field arguments: `key` and `field`.
	 */
	public function render_field( array $args ) {
		$key    = $args['key'];
		$field  = $args['field'];
		$id     = 'smtp-' . $key;
		$name   = Settings::OPTION . '[' . $key . ']';
		$locked = $this->settings->is_locked( $field );
		$value  = $this->settings->get( $key );

		switch ( $field['type'] ) {
			case 'checkbox':
				?>
				<label for="<?php echo esc_attr( $id ); ?>">
					<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( (bool) $value ); ?> <?php disabled( $locked ); ?> />
					<?php echo esc_html( $field['description'] ); ?>
				</label>
				<?php
				$field['description'] = '';
				break;

			case 'select':
				?>
				<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" <?php disabled( $locked ); ?>>
					<?php foreach ( $field['options'] as $option_value => $option_label ) : ?>
						<option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( $value, (string) $option_value ); ?>>
							<?php echo esc_html( $option_label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<?php
				break;

			case 'password':
				// The saved secret is never printed back into the page.
				?>
				<input
					type="password"
					class="regular-text"
					id="<?php echo esc_attr( $id ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					value=""
					autocomplete="new-password"
					placeholder="<?php echo '' !== $value ? esc_attr( str_repeat( '•', 8 ) ) : ''; ?>"
					<?php disabled( $locked ); ?>
				/>
				<?php
				if ( '' !== $value && ! $locked ) {
					$field['description'] = __( 'A password is saved. Leave empty to keep it.', 'smtp' );
				}
				break;

			default:
				$type = in_array( $field['type'], array( 'number', 'email' ), true ) ? $field['type'] : 'text';
				?>
				<input
					type="<?php echo esc_attr( $type ); ?>"
					class="regular-text"
					id="<?php echo esc_attr( $id ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					value="<?php echo esc_attr( (string) $value ); ?>"
					<?php disabled( $locked ); ?>
				/>
				<?php
		}

		if ( $locked ) {
			?>
			<p class="description">
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %s: PHP constant name */
						__( 'Defined by the %s constant.', 'smtp' ),
						'<code>' . esc_html( $field['constant'] ) . '</code>'
					),
					array( 'code' => array() )
				);
				?>
			</p>
			<?php
		} elseif ( '' !== $field['description'] ) {
			?>
			<p class="description"><?php echo esc_html( $field['description'] ); ?></p>
			<?php
		}
	}

	/**
	 * Renders the Settings tab.
	 *
	 * @since 0.1.0
	 */
	private function render_settings_tab() {
		?>
		<form action="options.php" method="post">
			<?php
			settings_fields( self::PAGE_SLUG );
			do_settings_sections( self::PAGE_SLUG );
			submit_button();
			?>
		</form>
		<?php
	}

	/**
	 * Renders the Email log tab.
	 *
	 * @since 0.1.0
	 */
	private function render_log_tab() {
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

		$table = new Log_List_Table( $this->repository );
		$table->prepare_items();
		?>
		<p>
			<?php esc_html_e( 'Only metadata is logged: the message body and attachments are never stored.', 'smtp' ); ?>
		</p>
		<?php
		$table->views();
		$table->display();
	}

	/**
	 * Renders the Test tab.
	 *
	 * @since 0.1.0
	 */
	private function render_test_tab() {
		$user   = wp_get_current_user();
		$result = Test_Email::pull_result( $user->ID );

		if ( ! $this->settings->is_configured() ) {
			?>
			<div class="notice notice-warning inline">
				<p><?php esc_html_e( 'SMTP is not configured yet, so the test email will be sent with the default WordPress mailer.', 'smtp' ); ?></p>
			</div>
			<?php
		}

		if ( $result ) {
			?>
			<div class="notice <?php echo $result['success'] ? 'notice-success' : 'notice-error'; ?> inline">
				<p>
					<?php
					if ( $result['success'] ) {
						/* translators: %s: email address */
						echo esc_html( sprintf( __( 'Test email sent to %s.', 'smtp' ), $result['to'] ) );
					} else {
						/* translators: %s: error message */
						echo esc_html( sprintf( __( 'The test email could not be sent: %s', 'smtp' ), $result['error'] ) );
					}
					?>
				</p>
			</div>
			<?php if ( '' !== $result['debug'] ) : ?>
				<h2><?php esc_html_e( 'Connection log', 'smtp' ); ?></h2>
				<pre style="max-width: 100%; overflow: auto; padding: 1em; background: #fff; border: 1px solid #c3c4c7;"><?php echo esc_html( $result['debug'] ); ?></pre>
			<?php endif; ?>
			<?php
		}
		?>
		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: email address */
					__( 'Send a test email to %s.', 'smtp' ),
					$user->user_email
				)
			);
			?>
		</p>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="<?php echo esc_attr( Test_Email::ACTION ); ?>" />
			<?php
			wp_nonce_field( Test_Email::ACTION );
			submit_button( __( 'Send test email', 'smtp' ), 'secondary' );
			?>
		</form>
		<?php
	}
}
