# Changelog

All notable changes to Backup Pilot will be documented in this file.

The format follows Keep a Changelog, and releases use semantic versioning.

## [1.0.0] - Unreleased

### Added

- Initial public MVP for local backup, restore, and migration workflows.
- ZIP package format with `manifest.json`, `database.sql`, and managed `wp-content` files.
- Admin backup history, package upload, validation, download, restore, and delete flows.
- Chunked job infrastructure for backup and restore work.
- Serialized-safe URL replacement for migration.
- Restore history, rollback foundations, checksum validation, retention, diagnostics, and S3-compatible storage settings.
- GPL open-source metadata, contribution guide, security policy, WordPress.org readme, and public launch docs.
