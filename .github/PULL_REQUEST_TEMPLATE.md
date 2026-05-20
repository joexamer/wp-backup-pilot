## Summary

Describe the change and why it belongs in Backup Pilot.

## Testing

- [ ] PHP lint passed.
- [ ] Composer validation passed.
- [ ] PHPUnit passed or the missing test environment is explained.
- [ ] PHPCS passed or the missing tooling is explained.
- [ ] Backup/restore changes were tested on a disposable WordPress install.

## Safety Checklist

- [ ] No backup archives, SQL dumps, credentials, or generated artifacts are committed.
- [ ] Admin actions include capability checks and nonces.
- [ ] User input is sanitized and output is escaped.
- [ ] Destructive restore behavior remains staged behind explicit confirmation.
- [ ] Changelog updated when user-facing behavior changed.
