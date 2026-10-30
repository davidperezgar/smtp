# SMTP

Send WordPress emails through an SMTP server, with an extensible provider API and a simple email log.

This is the technical documentation for developers. The user-facing description lives in [`readme.txt`](readme.txt).

- Requires WordPress 6.3+ and PHP 7.4+.
- Namespace `WordPress\SMTP\`, slug and text domain `smtp`.
- Repository: [github.com/wordpress/smtp](https://github.com/wordpress/smtp)

## Constants

Constants defined in `wp-config.php` override the saved value and lock the field in the settings screen. They are the recommended way to provide credentials in production.

| Constant | Field |
|---|---|
| `SMTP_HOST` | Host |
| `SMTP_PORT` | Port |
| `SMTP_ENCRYPTION` | Encryption (`none`, `ssl` or `tls`) |
| `SMTP_USER` | Username |
| `SMTP_PASSWORD` | Password |
| `SMTP_FROM_EMAIL` | From email |

## Adding a provider

Providers are added with the `smtp_providers` filter. A provider configures the `PHPMailer` instance used by `wp_mail()`, so it works with any service that offers SMTP. The built-in Generic SMTP provider is registered through the same filter.

```php
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
			'api_key'  => array(
				'label'    => 'API key',
				'type'     => 'password',
				'constant' => 'MY_PROVIDER_KEY',
			),
		),
		'configure' => function ( $phpmailer, $values ) {
			$phpmailer->isSMTP();
			$phpmailer->Host     = 'smtp.my-provider.example';
			$phpmailer->Port     = 587;
			$phpmailer->SMTPAuth = true;
			$phpmailer->Username = $values['api_user'];
			$phpmailer->Password = $values['api_key'];
		},
	);

	return $providers;
} );
```

| Key | Description |
|---|---|
| `label` (string) | Name shown in the provider select. Required. |
| `configure` (callable) | Receives the `PHPMailer` instance and the resolved field values. Required. |
| `fields` (array) | Settings fields, keyed by field key. |
| `required` (string[]) | Field keys that must have a value before the provider is used. Until then, mail is sent as usual. |

Each field accepts:

| Key | Description |
|---|---|
| `label` | Field label. |
| `type` | `text` (default), `number`, `email`, `password`, `select` or `checkbox`. |
| `default` | Value used when nothing is saved. |
| `options` | Value => label pairs, for `select`. |
| `description` | Help text. |
| `constant` | Name of a constant that overrides the value and locks the field. |

Fields of type `password` are stored apart from the other settings, are never printed back into the page, and are redacted from the connection log of the test email.

Providers with an invalid definition (no label, or a `configure` that is not callable) are ignored. Providers that talk to an HTTP API instead of SMTP are not supported yet, see [#1](https://github.com/davidperezgar/smtp/issues/1).

## Other filters

| Filter | Description |
|---|---|
| `smtp_log_enabled` | Return `false` to skip logging an email. Receives `$enabled`, `$mail_data` and `$status` (`sent` or `failed`). |
| `smtp_log_retention_days` | Number of days log entries are kept. Default `30`. A value of `0` or less disables the purge. |

## Migration from the legacy plugin

This plugin replaces the SMTP plugin by Sorin Iclanzan (1.x) that was published under the same slug, so sites upgrade in place and the activation hook does not run. `WordPress\SMTP\Migration` imports the legacy settings once, on `init` (and on activation).

| Legacy (`smtp_options`) | This plugin |
|---|---|
| `host` | Host, without a scheme |
| `port` | Port (`25` when invalid) |
| `smtp_secure` (`''`, `ssl`, `tls`) | Encryption (`none`, `ssl`, `tls`) |
| `username` | Username. Authentication is on when it is not empty, like before |
| `password` | Password, decrypted and stored as a secret |
| *(always the first user)* | From email and From name of user 1, with "Force from" on |

- Nothing is imported when `smtp_settings` already exists, or when the legacy plugin still has its activation placeholder (`http://localhost`, no username).
- The legacy option is never modified. It is deleted on uninstall, together with `smtp_legacy_migrated` and `smtp_migration_notice`.
- The legacy plugin encrypted the password with `mcrypt` (Rijndael-256, CBC) and a key hardcoded in its source. `mcrypt` was removed in PHP 7.2 and nothing else supports Rijndael-256, so the password is only imported on servers that still provide it. Otherwise an admin notice asks the user to enter it again.

## Email log

Every email sent with `wp_mail()` is logged in the `{prefix}smtp_logs` table with its recipient, subject, status, error and provider. The message body, headers and attachments are never stored. A daily cron event (`smtp_purge_logs`) deletes old entries.

## Development

```bash
composer install        # PHP dependencies and coding standards tooling
composer lint           # Run PHPCS
composer format         # Fix coding standards issues
npm ci                  # Node dependencies (@wordpress/env)
npm run wp-env:start:tests
npm run test-php        # Run PHPUnit in the test stack (requires Docker)
```

Run a single test class or method:

```bash
npm run test-php -- --filter Settings_Tests
```

See [`AGENTS.md`](AGENTS.md) for the project structure, coding standards and test requirements.

## Releasing

Build the distribution with production dependencies only:

```bash
composer install --no-dev -o
```

Files that are not part of the release are listed in `.distignore`.

## License

GPL-2.0-or-later.
