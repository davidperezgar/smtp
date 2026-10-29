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

* **Simple settings:** host, port, encryption, authentication, From email and From name under _Tools > SMTP_.
* **Constants support:** define `SMTP_HOST`, `SMTP_PORT`, `SMTP_ENCRYPTION`, `SMTP_USER`, `SMTP_PASSWORD` and `SMTP_FROM_EMAIL` in `wp-config.php` to override the saved values. Recommended for production, so credentials stay out of the database.
* **Email log:** every email sent with `wp_mail()` is logged with its recipient, subject, status and error. The message body and attachments are never stored. Entries are deleted after 30 days.
* **Test email:** send a test message to yourself and see the connection log if something goes wrong. Credentials are redacted.
* **Extensible:** developers can add more providers. See the technical documentation on [GitHub](https://github.com/wordpress/smtp).

The plugin does nothing until a host is configured, so activating it never breaks your site's email.

== Installation ==

1. Upload the plugin to the `/wp-content/plugins/` directory, or install it from the Plugins screen.
2. Activate the plugin.
3. Go to _Tools > SMTP_ and enter your SMTP server details.
4. Use the _Test_ tab to send yourself an email.

== Frequently Asked Questions ==

= Where are my credentials stored? =

Passwords can be defined with the `SMTP_PASSWORD` constant in `wp-config.php`. Otherwise they are stored in the database, in an option that is not loaded on every request.

= I was using the previous SMTP plugin. Do I lose my settings? =

No. When this version loads for the first time it imports your host, port, encryption, username and password, and keeps sending email as the first user of the site, like before. You will see a notice with a link to review the settings. If your server can no longer decrypt the old password, the notice asks you to enter it again. Your old settings are left untouched.

= Does it support providers that use an HTTP API instead of SMTP? =

Not yet. Providers can configure PHPMailer, which covers any service that offers SMTP.

== Contributing ==

SMTP is developed in the open. Bug reports, ideas and pull requests are welcome at [github.com/wordpress/smtp](https://github.com/wordpress/smtp).

== Changelog ==

= 0.1.0 =

* Initial release, maintained by the WordPress Plugins Team and the community.
* Settings of the previous SMTP plugin are imported automatically.
