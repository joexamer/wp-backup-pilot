# Content Plan

Backup Pilot should build technical authority around difficult WordPress plugin engineering problems, then repurpose each topic across GitHub, portfolio, LinkedIn, and short demo clips.

## Core Series

1. **How I built chunked background jobs in a WordPress plugin**
   - Explain request timeouts, resumable job state, admin status polling, and failure recovery.
   - Demo: create a backup job and show progress/status.

2. **Safely replacing serialized URLs during WordPress migration**
   - Explain why plain SQL replace breaks serialized data.
   - Demo: migrate source URL to current URL and validate serialized options.

3. **Designing rollback for destructive WordPress restore workflows**
   - Explain restore staging, confirmation, restore history, and rollback foundations.
   - Demo: validate package, confirm restore, inspect restore record.

4. **S3-compatible backups from WordPress without a heavy SDK**
   - Explain signing, credentials, provider compatibility, dependency tradeoffs, and admin UX.
   - Demo: upload a backup to an S3-compatible provider.

5. **What makes backup plugins hard: files, database, timeouts, and trust**
   - Explain the real risks behind backup tooling.
   - Demo: inspect backup ZIP manifest, checksums, SQL, and managed files.

## Repurposing Template

For each article:

- Publish the long-form version on the portfolio.
- Share a concise LinkedIn post with the engineering lesson.
- Link the article in a GitHub Discussion.
- Mention the improvement in release notes when relevant.
- Record a short clip or GIF showing the workflow.

## Portfolio CTA

Use a consistent call to action:

Hire me for WordPress plugin development, migrations, maintenance tooling, audits, and custom integrations.
