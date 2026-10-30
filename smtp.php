<?php
/**
 * Plugin Name: SMTP
 * Plugin URI: https://github.com/davidperezgar/smtp
 * Description: Send WordPress emails through an SMTP server, with an extensible provider API and a simple email log.
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * Version: 1.2.0
 * Author: WordPress Plugins Team
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain: smtp
 *
 * @package smtp
 */

use WordPress\SMTP\Plugin_Main;

define( 'WP_SMTP_VERSION', '1.2.0' );
define( 'WP_SMTP_MINIMUM_PHP', '7.4' );
define( 'WP_SMTP_MAIN_FILE', __FILE__ );

/**
 * Checks basic requirements and loads the plugin.
 *
 * @since 1.2.0
 */
function wp_smtp_load() {
	if ( version_compare( phpversion(), WP_SMTP_MINIMUM_PHP, '<' ) ) {
		add_action( 'admin_notices', 'wp_smtp_display_php_version_notice' );
		return;
	}

	$autoload = plugin_dir_path( WP_SMTP_MAIN_FILE ) . 'vendor/autoload.php';
	if ( ! file_exists( $autoload ) ) {
		add_action( 'admin_notices', 'wp_smtp_display_autoload_notice' );
		return;
	}

	require_once $autoload;

	register_activation_hook( WP_SMTP_MAIN_FILE, array( Plugin_Main::class, 'activate' ) );
	register_deactivation_hook( WP_SMTP_MAIN_FILE, array( Plugin_Main::class, 'deactivate' ) );

	$plugin = new Plugin_Main( WP_SMTP_MAIN_FILE );
	$plugin->add_hooks();
}

/**
 * Displays an admin notice when the PHP version is not supported.
 *
 * @since 1.2.0
 */
function wp_smtp_display_php_version_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			printf(
				/* translators: 1: required PHP version, 2: current PHP version */
				esc_html__( 'SMTP requires PHP %1$s or newer. Your site is running PHP %2$s.', 'smtp' ),
				esc_html( WP_SMTP_MINIMUM_PHP ),
				esc_html( phpversion() )
			);
			?>
		</p>
	</div>
	<?php
}

/**
 * Displays an admin notice when the Composer autoloader is missing.
 *
 * @since 1.2.0
 */
function wp_smtp_display_autoload_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			printf(
				/* translators: %s: composer install command */
				esc_html__( 'SMTP is missing its autoloader. Please run %s in the plugin directory.', 'smtp' ),
				'<code>composer install</code>'
			);
			?>
		</p>
	</div>
	<?php
}

wp_smtp_load();
