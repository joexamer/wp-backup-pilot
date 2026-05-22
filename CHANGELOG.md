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

### Changed

- Updated internal plugin prefixes to use a more unique and distinct plugin-specific naming convention.
- Improved plugin path and directory handling to avoid hardcoded WordPress content directory references.
- Cleaned the production release package to better match WordPress.org submission expectations.

### Fixed

- Removed direct modification of WordPress active plugin options during restore operations.
- Improved compatibility with non-standard WordPress content and plugin directory setups.

### Removed

- Removed unnecessary development documentation files from the release package.
- Removed non-production test files from the WordPress.org submission package.