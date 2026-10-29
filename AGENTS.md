# SMTP – AI Coding Agent Instructions

This file provides context for AI coding agents (Claude Code, OpenAI Codex, Gemini CLI, GitHub Copilot, etc.) working on the SMTP plugin repository.

## Project Overview

**SMTP** is a minimal, community-maintained WordPress plugin that sends the emails of a site through an SMTP server. It is designed to follow WordPress.org canonical plugin conventions (slug and text domain `smtp`, owned by `wordpressdotorg`).

- Settings under _Tools > SMTP_: provider, host, port, encryption, authentication, From email, From name and "Force from".
- Credentials can be defined as constants in `wp-config.php` (`SMTP_HOST`, `SMTP_PORT`, `SMTP_ENCRYPTION`, `SMTP_USER`, `SMTP_PASSWORD`, `SMTP_FROM_EMAIL`). A constant overrides the saved value and locks the field.
- Extensible through the `smtp_providers` filter. Generic SMTP is registered through the same filter.
- Metadata-only email log (no message body, no attachments) with automatic retention.
- A test email tab that shows the connection log with credentials redacted.

Existing users of the legacy SMTP plugin keep their settings through an automatic import (see "Legacy plugin migration").

Out of scope for v0.1: HTTP-API providers (tracked in [#1](https://github.com/davidperezgar/smtp/issues/1)), multisite network settings, encryption of stored credentials.

## Repository Structure

```
smtp/
├── smtp.php                  # Main plugin file: header, constants, requirements checks, bootstrap
├── uninstall.php             # Removes options, the log table and the cron event
├── readme.txt                # WordPress.org readme (Stable tag, changelog)
├── includes/                 # PSR-4 autoloaded under WordPress\SMTP\
│   ├── Plugin_Main.php       # Wires every component, activation/deactivation, purge schedule
│   ├── Provider_Registry.php # smtp_providers filter, provider validation, Generic SMTP provider
│   ├── Settings.php          # Field resolution (constant > saved value > default) and sanitization
│   ├── Credentials.php       # The only place that reads/writes secrets
│   ├── Migration.php         # One-time import of the legacy SMTP plugin (smtp_options) settings
│   ├── Mailer.php            # phpmailer_init and wp_mail_from(_name) integration
│   ├── Log_Repository.php    # Log table schema (dbDelta) and queries
│   ├── Logger.php            # wp_mail_succeeded / wp_mail_failed listeners and retention purge
│   └── Admin/                # Tools > SMTP screen
│       ├── Admin_Page.php    # Tabs, Settings API registration, field rendering
│       ├── Log_List_Table.php
│       └── Test_Email.php    # admin-post handler for the test email
├── tests/
│   └── phpunit/
│       ├── bootstrap.php
│       ├── tests/            # PHPUnit test classes (mirror includes/)
│       └── utils/            # Shared test helpers (traits)
├── .github/workflows/        # php-lint.yml, php-test.yml
├── phpcs.xml.dist            # WordPress Coding Standards ruleset
├── phpunit.xml.dist
├── .wp-env.json              # Development site
└── .wp-env.tests.json        # Test stack (PHP 7.4, ports 8920/8921)
```

## Architecture

### Providers

A provider is an array registered through the `smtp_providers` filter:

- `label` (string): human readable name.
- `fields` (array): settings fields keyed by field key. Each field accepts `label`, `type` (`text`, `number`, `email`, `password`, `select`, `checkbox`), `default`, `options`, `description` and `constant`.
- `required` (string[]): field keys that must have a value before the provider is used.
- `configure` (callable): receives the `PHPMailer` instance and the resolved field values.

`Provider_Registry::get_all()` discards invalid providers. Providers only configure PHPMailer; they must not send mail themselves.

### Settings and credentials

- A field value resolves in this order: constant, secret (for `password` fields) or saved option, field default. See `Settings::resolve()`.
- Options: `smtp_settings` (everything except secrets) and `smtp_credentials` (secrets, not autoloaded).
- **Never read or write secrets outside `Credentials`.** WordPress 7.2 is expected to add a credentials encryption API, and `Credentials` is the single place that will change.
- **Never print a saved secret into HTML.** Password fields render empty with a placeholder, and an empty submission keeps the current secret.
- The plugin must do nothing until the active provider has its `required` fields (`Settings::is_configured()`), so activating it never breaks a site's email.

### Legacy plugin migration

This plugin replaces the SMTP plugin 1.x published under the same slug, so sites upgrade in place and the activation hook does not run. `Migration::maybe_migrate()` runs on `init` (priority 0) and on activation, once, guarded by the `smtp_legacy_migrated` option.

- Never overwrite existing `smtp_settings`, never modify or delete the legacy `smtp_options` option (it is removed on uninstall only).
- The legacy password is decrypted with mcrypt when available. When it can not be, the user is asked to enter it again through an admin notice. Never block or fail the import because of the password.
- Any change to the mapping needs a matching test in `Migration_Tests`.

### Email log

- Table `{prefix}smtp_logs`, created with `dbDelta()` by `Log_Repository::maybe_upgrade()` (schema version in the `smtp_db_version` option).
- Only metadata is stored: recipient, subject, status, error and provider. **Never store the message body, headers or attachments.**
- Entries older than 30 days are deleted daily by the `smtp_purge_logs` cron event.

### Hooks provided

- `smtp_providers` (filter): add or modify providers.
- `smtp_log_enabled` (filter): return `false` to skip logging an email. Receives the mail data and the status.
- `smtp_log_retention_days` (filter): days to keep log entries. Default `30`; `0` or less disables the purge.

## Coding Standards

Enforced by `phpcs.xml.dist` (WordPress, WordPress-Extra, WordPress-Docs and PHPCompatibilityWP):

- **PHP minimum version**: 7.4. **WordPress minimum version**: 6.3.
- **Namespace**: `WordPress\SMTP\` (PSR-4, mapped to `includes/`). Test helpers use `WordPress\SMTP\Test_Utils\` (mapped to `tests/phpunit/utils/`).
- Use tabs for indentation, and Yoda conditions.
- Text domain: `smtp`.
- Global prefixes: `wp_smtp`, `WP_SMTP`, `smtp`, `WordPress\SMTP`.
- All public methods and classes in `includes/` must have PHPDoc blocks with `@since`.
- Do **not** add docblocks to unit test classes or methods (excluded in `phpcs.xml.dist` for `tests/*`).
- Sanitize input, escape output, and use `$wpdb->prepare()` for every query with dynamic values.
- PHPMailer exposes camelCase properties (`$phpmailer->SMTPSecure`); wrap those lines with the `UsedPropertyNotSnakeCase` `phpcs:disable`/`phpcs:enable` comments, as in `Provider_Registry::configure_generic()`.
- All user-facing text must be in English and translatable.
- Ensure `composer lint` passes before considering a change complete.

## Development Commands

All commands run from the plugin root.

| Command | Purpose |
|---|---|
| `composer install` | Install PHP dependencies, including the PHPCS tooling and PHPUnit |
| `composer lint` | Run PHPCS with `phpcs.xml.dist` |
| `composer format` | Fix coding standards issues with PHPCBF |
| `npm ci` | Install Node dependencies (`@wordpress/env`) |
| `npm run wp-env:start:tests` | Start the Docker based test stack (requires Docker) |
| `npm run wp-env:stop:tests` | Stop the test stack |
| `npm run test-php` | Run PHPUnit inside the test stack |
| `npm run test-php-coverage` | Run PHPUnit with a Clover coverage report in `build/logs/` |
| `npm run wp-env start` | Start the development site |

**Run a single test class or method:**

```bash
npm run test-php -- --filter Settings_Tests
npm run test-php -- --filter Settings_Tests::test_sanitize_is_idempotent
```

## Tests

- `tests/phpunit/tests/` — all PHPUnit test classes, one per class in `includes/` and named `<Class>_Tests` (for example `Settings_Tests`, `Admin/Admin_Page_Tests`). Files end in `_Tests.php`, which is what `phpunit.xml.dist` picks up.
- `tests/phpunit/utils/` — shared helpers, as traits (`With_Log_Table`, `With_PHPMailer`).
- `tests/phpunit/bootstrap.php` — loads the Composer autoloader and the WordPress test suite, and forces the plugin to be active.
- Test classes extend `WP_UnitTestCase` and use `set_up()` / `tear_down()`.
- The log table is created with `dbDelta()`, which the WordPress test suite turns into a temporary table. Call `create_log_table()` (from `With_Log_Table`) in `set_up()` for any test that sends mail or touches the log.
- Constants can not be undefined in PHP. Tests that need a constant use a unique name (for example `SMTP_TESTS_LOCKED_TOKEN`) and attach it to a provider through the `smtp_providers` filter.

### Test Enforcement

- Every new class, method or behavior change in `includes/` **must** come with tests in the matching file under `tests/phpunit/tests/`. A bug fix needs a test that fails without the fix.
- Run `npm run test-php` and `composer lint` locally before pushing. Do not mark a task as done while either fails.
- Security-sensitive behavior must always be covered: secrets are never printed or logged, output is escaped, capability and nonce checks reject unauthorized requests, and credentials are redacted from the test email connection log.
- CI runs on every pull request to `main` and `feature/**`:
  - `php-test.yml` runs the PHPUnit suite on PHP 7.4, 8.0, 8.1, 8.2 and 8.3 with the latest WordPress, on PHP 7.4 with WordPress 6.3, and on PHP 8.4 with WordPress trunk (experimental, allowed to fail).
  - `php-lint.yml` runs `composer lint`.
- Both workflows must be green before merging. Configure them as required status checks in the branch protection rules of `main`.
- Never skip, delete or weaken a test to make a change pass. Fix the code or discuss the test change in the pull request.

## Key Files

- `smtp.php` — plugin header, `WP_SMTP_*` constants, PHP/autoloader requirement notices, activation and deactivation hooks.
- `includes/Plugin_Main.php` — the composition root. Add new components here.
- `includes/Settings.php` — the only class that knows how a field value is resolved and sanitized.
- `includes/Credentials.php` — the only class that touches secrets.
- `includes/Provider_Registry.php` — the provider contract and the Generic SMTP provider.

## Release Process

Current version: `0.1.0` (declared in the `smtp.php` header and the `WP_SMTP_VERSION` constant).

1. Bump the version in the `smtp.php` header (`Version:`), and the `WP_SMTP_VERSION` constant in `smtp.php`. Keep both locations in sync.
2. Update the `readme.txt` **Changelog** section with a summary of notable changes.
3. Update the `readme.txt` **Stable tag** to match the new version, and **Tested up to** when needed.
4. Build the distribution with production dependencies only: `composer install --no-dev -o`. Files excluded from the release are listed in `.distignore`.
5. Open a pull request, get it merged into `main` and tag the release.

There is no deploy workflow in this repository yet: publishing to WordPress.org is done by the Plugins Team, not from CI. Do not add or describe an automated deploy until a workflow exists.

## CI/CD

- `.github/workflows/php-lint.yml` — runs `composer lint` on pushes to `main` and on pull requests when PHP or PHPCS files change.
- `.github/workflows/php-test.yml` — starts the `wp-env` test stack and runs `npm run test-php` across the PHP and WordPress matrix, when PHP, test or tooling files change.
- Documentation goes in `/docs/` (if added) and must be listed in `.distignore`.

## Contribution Guidelines

- All code is licensed under **GPL-2.0-or-later**.
- Open issues and pull requests on [GitHub](https://github.com/davidperezgar/smtp).
- Keep the plugin minimal: prefer extension points (filters) over built-in features.
