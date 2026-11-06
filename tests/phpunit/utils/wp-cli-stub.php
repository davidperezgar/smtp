<?php
/**
 * Class WP_CLI
 *
 * @package smtp
 */

/**
 * Minimal stand-in for WP-CLI, which is not loaded in the PHPUnit stack.
 */
class WP_CLI {

	public static $commands  = array();
	public static $logs      = array();
	public static $warnings  = array();
	public static $successes = array();

	public static function reset() {
		self::$commands  = array();
		self::$logs      = array();
		self::$warnings  = array();
		self::$successes = array();
	}

	public static function add_command( $name, $command ) {
		self::$commands[ $name ] = $command;
	}

	public static function log( $message ) {
		self::$logs[] = $message;
	}

	public static function warning( $message ) {
		self::$warnings[] = $message;
	}

	public static function success( $message ) {
		self::$successes[] = $message;
	}

	// The real method exits, so nothing after it may run.
	public static function error( $message ) {
		throw new RuntimeException( $message );
	}
}

require_once __DIR__ . '/wp-cli-stub-utils.php';
