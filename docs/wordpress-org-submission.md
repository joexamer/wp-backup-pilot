# WordPress.org Submission Plan

Backup Pilot should be submitted to WordPress.org after the GitHub-first public release proves the documentation, support flow, and screenshots are ready.

## Required Assets

- `readme.txt` in WordPress.org format.
- GPL-compatible plugin headers.
- Screenshots:
  - `screenshot-1.png`
  - `screenshot-2.png`
  - `screenshot-3.png`
  - `screenshot-4.png`
- Optional banner and icon assets.
- No backup archives, SQL exports, generated logs, secrets, or development artifacts.

## Review Readiness

- Core plugin features work without paid services.
- S3-compatible storage is documented accurately as optional.
- Admin text is clear and not exaggerated.
- Security-sensitive actions require capabilities and nonces.
- Uploaded archives are validated before restore.
- Restore documentation tells users to test on disposable clones before production usage.

## Pre-Submission Tests

- PHP lint.
- Composer validation.
- PHPUnit for settings, manifest/checksum generation, archive validation, retention, and serialized search/replace.
- PHPCS with WordPress Coding Standards.
- Manual backup and restore on a disposable install.
- Compatibility spot checks on PHP `7.4`, `8.0`, `8.1`, `8.2`, `8.3+`.
