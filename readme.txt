=== Backup Pilot ===
Contributors: yousefamer
Tags: backup, restore, migration, database backup, s3
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create, validate, restore, and migrate WordPress backup packages from the admin area.

== Description ==

Backup Pilot is a free, open-source WordPress backup, restore, and migration plugin by Yousef Amer.

The plugin creates ZIP backup packages containing a manifest, database SQL export, and managed `wp-content` files. It includes staged restore validation, serialized-safe URL replacement for migrations, local backup history, retention controls, diagnostics, chunked job infrastructure, and optional S3-compatible remote storage.

This project is built as transparent operational tooling for WordPress administrators and as a public engineering case study in production-grade plugin architecture.

= Highlights =

* Create full backup packages from WordPress admin.
* Validate backup packages before restore.
* Restore database and managed `wp-content` paths from a staged package.
* Replace source URLs with current URLs using serialized-safe migration logic.
* Track local backups with size, date, source URL, download, restore, and delete actions.
* Use chunked jobs for long-running backup and restore operations.
* Configure retention and optional S3-compatible storage.
* Protect admin actions with capabilities and nonces.

== Installation ==

1. Upload the `backup-pilot` folder to `/wp-content/plugins/`.
2. Activate **Backup Pilot** through the **Plugins** screen.
3. Go to **Tools > Backup Pilot**.
4. Create a backup or upload an existing Backup Pilot package for validation.

Always test restore operations on a disposable clone before using any backup plugin on a production site.

== Frequently Asked Questions ==

= Is this plugin free? =

Yes. Backup Pilot is licensed under GPLv2 or later.

= Does the plugin include WordPress core files? =

The MVP focuses on the database and managed `wp-content` paths: uploads, themes, and plugins.

= Does restore happen immediately after upload? =

No. Uploaded packages are validated first, then shown in a staged confirmation flow before destructive restore actions are performed.

= Does migration support serialized data? =

Yes. URL replacement is designed to handle normal and serialized WordPress option values.

= Is S3-compatible storage stable? =

S3-compatible storage exists as an integration path. Test with your provider before relying on it for production workflows.

== Screenshots ==

1. Backup history and create backup action.
2. Running backup job status.
3. Restore validation and confirmation.
4. Settings, retention, diagnostics, and remote storage.

== Changelog ==

= 1.0.0 =

* Initial public MVP for local backup, restore, and migration workflows.
* Added ZIP backup package support with manifest, database SQL export, and managed wp-content files.
* Added admin backup history, package upload, validation, download, restore, and delete flows.
* Added chunked job infrastructure for long-running backup and restore operations.
* Added serialized-safe URL replacement for migration workflows.
* Added restore history, rollback foundations, checksum validation, retention controls, diagnostics, and S3-compatible storage settings.
* Improved plugin prefixing, path handling, and WordPress.org repository compliance before public release.
* Removed unnecessary development documentation and non-production files from the release package.
* Removed direct modification of WordPress active plugin options during restore operations.

== Upgrade Notice ==

= 1.0.0 =

Initial public release candidate. Test backup and restore operations on a disposable clone before using on production websites.