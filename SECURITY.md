# Security Policy

Backup Pilot handles full-site backup archives, database SQL, filesystem restore operations, migration URL replacement, and optional remote storage credentials. Please report security issues responsibly.

## Supported Versions

Until the first public release, security fixes target the `main` branch.

After public launch, the latest stable release will receive security fixes. Older releases may receive fixes when the risk is severe and the patch is practical.

## Reporting A Vulnerability

Please do not open a public issue for vulnerabilities.

Preferred reporting options:

- Use GitHub Security Advisories for `joexamer/backup-pilot`.
- Contact Yousef Amer through the portfolio contact form once the public portfolio link is live.

Helpful details include:

- Affected Backup Pilot version or commit.
- WordPress and PHP versions.
- Exact steps to reproduce.
- Whether authentication is required.
- Whether the issue affects backup archive disclosure, arbitrary file access, restore deletion, database import, nonce/capability bypass, or credential exposure.

## Security Expectations

- Backup and restore actions must require `manage_options`.
- Destructive actions must be nonce-protected.
- Backup archives should be stored outside casual direct access where possible and protected with deny/index files.
- Package validation must happen before restore.
- Uploaded archives must be treated as untrusted input.
- Remote storage credentials must never be written to logs or backup manifests.
