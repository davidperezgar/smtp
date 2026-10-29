<?php
/**
 * Trait WordPress\SMTP\Test_Utils\Traits\With_PHPMailer
 *
 * @package smtp
 */

namespace WordPress\SMTP\Test_Utils\Traits;

use PHPMailer\PHPMailer\PHPMailer;

trait With_PHPMailer {

	/**
	 * Returns a fresh PHPMailer instance, loading the core classes when needed.
	 */
	protected function get_phpmailer(): PHPMailer {
		if ( ! class_exists( PHPMailer::class ) ) {
			require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
			require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
			require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
		}

		return new PHPMailer( true );
	}
}
