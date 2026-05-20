# Contributing To WP Backup Pilot

Thanks for helping improve WP Backup Pilot. This plugin handles backups, restores, database imports, filesystem writes, and migration search/replace, so reliability matters more than cleverness.

## Development Setup

1. Clone or copy the plugin into `wp-content/plugins/wp-backup-pilot`.
2. Run `composer install`.
3. Activate the plugin on a disposable WordPress install.
4. Use **Tools > WP Backup Pilot** for manual testing.

## Before Opening A Pull Request

- Run PHP lint across plugin files.
- Run `composer validate --no-check-publish`.
- Run PHPUnit when a WordPress test environment is configured.
- Run PHPCS when WordPress Coding Standards are installed.
- Test backup and restore only on disposable sites.
- Include screenshots or a short clip for admin UI changes.

## Coding Guidelines

- Keep WordPress capability checks and nonces on every admin action.
- Prefer small classes with focused responsibilities.
- Avoid global state unless WordPress APIs require it.
- Escape output in admin views.
- Sanitize and validate all request input.
- Keep destructive restore behavior staged behind explicit confirmation.
- Add or update tests for archive validation, checksums, retention, settings, and serialized search/replace.

## Pull Request Checklist

- The change is scoped and documented.
- Restore, migration, or delete behavior has been tested on a disposable install.
- No credentials, generated archives, database dumps, or local artifacts are committed.
- User-facing text is clear and operational, not marketing-heavy.
- The changelog has been updated for public-facing changes.

## Branches And Releases

Use semantic versioning for releases. The first public milestone is `1.0.0`, followed by hardening releases such as `1.0.1`, `1.1.0`, and `1.2.0`.
