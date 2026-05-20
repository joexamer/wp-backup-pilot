# Release Checklist

## GitHub First

- Create `yousefamer/wp-backup-pilot`.
- Push the plugin source with `LICENSE`, `README.md`, `CONTRIBUTING.md`, `SECURITY.md`, and `CHANGELOG.md`.
- Enable Issues and Discussions.
- Add project boards:
  - `v1 Public Release`
  - `WordPress.org Submission`
  - `Reliability Hardening`
- Create release notes from `CHANGELOG.md`.
- Tag releases with semantic versions such as `v1.0.0`.

## Pre-Release Validation

- Run PHP lint.
- Run `composer validate --no-check-publish`.
- Run PHPUnit for settings, manifest/checksum generation, archive validation, retention, and serialized search/replace.
- Run PHPCS with WordPress Coding Standards.
- Create and restore a backup on a disposable WordPress install.
- Test migration URL replacement with serialized options.
- Test S3-compatible upload with at least one provider before calling it stable.
- Confirm no local archives, SQL exports, secrets, or generated test artifacts are committed.

## WordPress.org Submission

- Confirm plugin headers and GPL license.
- Confirm `readme.txt` format.
- Add screenshots and banner/icon assets.
- Confirm `Stable tag` matches the release tag.
- Confirm no external service is required for core features.
- Confirm all public text is supportable and accurate.
- Submit after GitHub docs, tests, screenshots, and support flow are ready.
