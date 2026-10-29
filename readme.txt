=== SMTP ===

Contributors:      wordpressdotorg
Tested up to:      6.9
Stable tag:        0.1.0
Requires at least: 6.3
Requires PHP:      7.4
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html
Tags:              smtp, email, mail, wp_mail, log

Send WordPress emails through an SMTP server, with an extensible provider API and a simple email log.

== Description ==

SMTP is a minimal, community-maintained plugin that sends the emails of your site through the SMTP server of your choice.

* **Simple settings:** host, port, encryption, authentication, From email and From name under _Settings > SMTP_.
* **Constants support:** define `SMTP_HOST`, `SMTP_PORT`, `SMTP_ENCRYPTION`, `SMTP_USER`, `SMTP_PASSWORD` and `SMTP_FROM_EMAIL` in `wp-config.php` to override the saved values. Recommended for production, so credentials stay out of the database.
* **Email log:** every email sent with `wp_mail()` is logged with its recipient, subject, status and error. The message body and attachments are never stored. Entries are deleted after 30 days.
* **Test email:** send a test message to yourself and see the connection log if something goes wrong. Credentials are redacted.
* **Extensible:** add more providers with the `smtp_providers` filter.

The plugin does nothing until a host is configured, so activating it never breaks your site's email.

= Adding a provider =

Providers are added with the `smtp_providers` filter:

`
add_filter( 'smtp_providers', function ( $providers ) {
	$providers['my-provider'] = array(
		'label'     => 'My provider',
		'required'  => array( 'api_user' ),
		'fields'    => array(
			'api_user' => array(
				'label'    => 'API user',
				'type'     => 'text',
				'constant' => 'MY_PROVIDER_USER',
			),
		),
		'configure' => function ( $phpmailer, $values ) {
			$phpmailer->isSMTP();
			$phpmailer->Host = 'smtp.my-provider.example';
			// ...
		},
	);

	return $providers;
} );
`

= Other filters =

* `smtp_log_enabled`: return `false` to skip logging an email.
* `smtp_log_retention_days`: number of days log entries are kept. Default `30`.

== Installation ==

1. Upload the plugin to the `/wp-content/plugins/` directory, or install it from the Plugins screen.
2. Activate the plugin.
3. Go to _Settings > SMTP_ and enter your SMTP server details.
4. Use the _Test_ tab to send yourself an email.

== Frequently Asked Questions ==

= Where are my credentials stored? =

Passwords can be defined with the `SMTP_PASSWORD` constant in `wp-config.php`. Otherwise they are stored in the database, in an option that is not loaded on every request.

= Does it support providers that use an HTTP API instead of SMTP? =

Not yet. Providers can configure PHPMailer, which covers any service that offers SMTP.

== Changelog ==

= 0.1.0 =

* Initial release.
