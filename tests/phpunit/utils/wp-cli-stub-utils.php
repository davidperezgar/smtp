<?php
/**
 * Stand-in for the WP-CLI utility functions used by the plugin.
 *
 * @package smtp
 */

namespace WP_CLI\Utils;

function format_items( $format, $items, $fields ) {
	\WP_CLI::$logs[] = array( $format, $items, $fields );
}
