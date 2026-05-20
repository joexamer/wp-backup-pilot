# Backup Pilot

Backup Pilot is a free, GPL-licensed WordPress plugin by **Yousef Amer** for creating, validating, restoring, and migrating full-site backups from the WordPress admin.

The project is built as a public engineering case study for serious WordPress plugin development: safe restore workflows, chunked background jobs, package validation, checksums, rollback preparation, retention, and S3-compatible storage without locking the core value behind a paid tier.

## Current Status

Backup Pilot is an MVP intended for local testing, portfolio review, and open-source hardening before WordPress.org submission.

- GitHub repository target: `yousefamer/backup-pilot`
- License: `GPL-2.0-or-later`
- Minimum WordPress: `6.0`
- Minimum PHP: `7.4`
- Primary audience: administrators, developers, and site maintainers who need transparent backup, restore, and migration tooling.

## Features

- Manual full-site backup packages as ZIP files.
- Backup package manifest with source URL, site metadata, table prefix, file counts, and checksums.
- Database export and import for active WordPress tables.
- Managed file backup for `wp-content/uploads`, `wp-content/themes`, and `wp-content/plugins`.
- Local backup history with download, restore, delete, retention, and diagnostics actions.
- Staged restore flow with validation before destructive operations.
- Serialized-safe URL replacement for migrations.
- Chunked job infrastructure for long-running backup and restore work.
- Rollback and restore history foundations for safer destructive workflows.
- Optional S3-compatible remote storage integration.
- Capability checks and nonces for administrator-only actions.

## Screenshots And Demo Placeholders

Add these assets before the public launch:

- `assets/screenshot-1.png`: Backup history and create backup action.
- `assets/screenshot-2.png`: Running backup job status.
- `assets/screenshot-3.png`: Restore validation and confirmation screen.
- `assets/screenshot-4.png`: Settings, retention, and S3-compatible storage.
- `assets/demo-backup-restore.gif`: Short backup, restore, and migration demo.

## Installation

1. Copy the plugin folder to `wp-content/plugins/backup-pilot`.
2. Activate **Backup Pilot** from **Plugins** in WordPress admin.
3. Open **Tools > Backup Pilot**.
4. Create a backup, download it, or validate a package before restoring.

For production sites, test restore workflows on a disposable clone before trusting any backup tool with live data.

## Local Development

Install Composer dependencies:

```bash
composer install
```

Run PHP lint:

```bash
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
```

Validate Composer metadata:

```bash
composer validate --no-check-publish
```

Run the available PHPUnit suite when a WordPress test environment is configured:

```bash
composer test
```

Run WordPress Coding Standards when PHPCS dependencies are installed:

```bash
composer phpcs
```

## Architecture

Backup Pilot is intentionally class-based and separated into focused components:

- **Admin controller**: WordPress admin page, actions, notices, settings, uploads, and restore confirmation screens.
- **Backup manager**: Coordinates backup package creation, manifests, cleanup, and backup history.
- **Job manager**: Stores chunked background job state and advances long-running operations safely across requests.
- **Database exporter/importer**: Writes SQL exports and imports staged SQL during restore.
- **Archive builder/reader**: Creates and validates ZIP packages with manifest, SQL, files, and checksums.
- **Restore manager**: Extracts packages, validates contents, imports database data, restores managed paths, and records restore history.
- **Search/replace helper**: Performs serialized-safe URL replacement for migration workflows.
- **Remote storage**: Uploads backup packages to S3-compatible storage with a lightweight implementation.
- **Security/filesystem helpers**: Manage backup directories, index/deny files, path checks, retention, and diagnostics.

### Backup Package Format

```text
backup.zip
├── manifest.json
├── database.sql
└── files/
    └── wp-content/
        ├── uploads/
        ├── themes/
        └── plugins/
```

## Engineering Problems This Project Demonstrates

- Keeping destructive restore flows staged and explicit.
- Validating backup packages before modifying database or files.
- Handling serialized WordPress data during URL migrations.
- Splitting long-running backup and restore operations into resumable chunks.
- Avoiding direct web access to local backup archives.
- Preserving the running plugin during managed file restore.
- Designing a plugin architecture that can grow without becoming a single admin-page script.
- Supporting S3-compatible uploads without forcing a large SDK into the plugin.

## Public Launch Roadmap

- Complete real PHPUnit coverage for manifest generation, archive validation, retention, settings, and serialized search/replace.
- Add PHPCS pass using WordPress Coding Standards.
- Record a short demo GIF/video.
- Add WordPress.org screenshots and assets.
- Test backup and restore on disposable WordPress installs across PHP `7.4`, `8.0`, `8.1`, `8.2`, `8.3+`.
- Test S3-compatible upload with at least one provider before documenting it as stable.
- Open GitHub Discussions for support and ideas.
- Submit to WordPress.org after the public README, `readme.txt`, screenshots, tests, and support flow are ready.

## Built By Yousef Amer

Backup Pilot is a creator-led open-source project by **Yousef Amer**, built to demonstrate senior WordPress plugin engineering through a real operational tool.

- GitHub: https://github.com/joexamer
- Portfolio: `https://yousefamer.com`
- LinkedIn: `https://linkedin.com/in/yousefamer`

CTA: Hire me for WordPress plugin development, migrations, restore tooling, maintenance automation, and custom integrations.

## Contributing

Contributions are welcome. Start with [CONTRIBUTING.md](CONTRIBUTING.md), open an issue for larger changes, and keep restore-related work conservative and well tested.

## Security

Backup and restore plugins touch sensitive files, database contents, credentials, and destructive workflows. Please read [SECURITY.md](SECURITY.md) before reporting vulnerabilities.

## License

Backup Pilot is licensed under `GPL-2.0-or-later`.
