# Administration API setup

The administrative API uses Laravel Sanctum's stateful SPA authentication. The browser holds the Laravel session and XSRF cookies; the React app does not persist an access token.

## Local development

Configure the Laravel environment with the local React origin in `FRONTEND_URL` and `SANCTUM_STATEFUL_DOMAINS` (include host and port, without a scheme). Use `localhost` consistently for both applications; mixing it with `127.0.0.1` changes the browser cookie host. Set the frontend's `VITE_API_URL` to the Laravel origin, normally `http://localhost:8000` (or `http://localhost:8001` when using the configured Laragon server).

Review the five new migrations before applying them to a local database. They add nullable roles and inactive-by-default access to existing users, separate administrative workflow states, library-resource storage, one-time account invitations, and a database lock row that serializes initial super-administrator creation and account privilege changes. **This change does not run migrations.** Existing contact, partnership, and public-catalogue records are not copied, deleted, or rewritten.

Create the initial account interactively with:

```text
php artisan admin:create-super-admin
```

The command prompts for name, e-mail, and a confirmed password without echoing the password. It refuses to create another active `super_admin`; the shared account lock serializes concurrent initialization and role changes. It does not create accounts during deployment.

The super administrator can generate 24-hour, one-use account invitations from the admin page. No invitation e-mail is sent. The invitation URL contains a bearer secret in its fragment; share it only through a trusted channel. The database stores only its SHA-256 hash.

## Donation return verification

Stripe returns to `/don/success` with its Checkout Session ID. The React page asks Laravel for the stored donation status; it displays a confirmed payment only after the server-side webhook has marked that donation `completed`. Missing, unknown, pending, and failed sessions are never presented as paid. The public status endpoint returns only a normalized status and is rate-limited; it does not return donor details. Checkout and webhook tests use a fake Stripe client or signed synthetic events, not live payments.

## Production prerequisites

Use HTTPS for both the SPA and API. Set `SESSION_SECURE_COOKIE=true`, explicitly allow the exact frontend origin in `FRONTEND_URL`, and configure `SANCTUM_STATEFUL_DOMAINS` with the frontend host and any required port. If the SPA and API share a registrable domain, configure `SESSION_DOMAIN` for that domain and retain a restrictive `SameSite` policy. Do not use wildcard CORS origins with credentials.

Vercel's default `*.vercel.app` host and an unrelated API host are cross-site; browsers may block the session cookies. Prefer a custom frontend domain and an API subdomain under the same site, then test cookie, CSRF, CORS, and session-expiry behavior from the deployed HTTPS origin. No proxy, tunnel, DNS, or deployment is configured by this change.

## Remaining catalogue transition

The public books and manifestos remain in frontend TypeScript data. The new administrative resource table starts empty, accepts metadata and external URLs, and does not import or replace the existing public catalogue. The public API exposes only published resource fields, but the public React catalogue has not been switched to it because the existing book/manifesto content model and local cover assets have not yet been migrated. Review and perform that content import before treating the new admin resource records as the public catalogue's source of truth. File uploads are not implemented.

The administrator preferences `dark_mode` and `email_notifications` are persisted, but no interface theme behavior or notification workflow is currently defined. Decide their intended behavior before implementing either effect.

## Pre-production checklist

Complete and verify these items in a staging environment before production:

1. **Origins and transport — pending human configuration and remote test:** decide the final frontend origin and API origin. Serve both over HTTPS and, where possible, use a custom frontend domain and an API subdomain on the same site. Set the frontend API URL to the API origin; allow only the exact frontend origin with credentialed CORS. Configure Sanctum's stateful domains with the frontend host and required port, set the session domain only when appropriate for the shared site, use secure cookies, and verify the CSRF-cookie/bootstrap flow from the deployed frontend origin. Do not use wildcard origins with credentials. The versioned CORS/Sanctum configuration was reviewed locally; no production origins or browser flow have been tested.
2. **Production configuration — pending human/hosting action:** set production mode and disable debug output; provision the application key and session/cache/database/mail/Stripe configuration through the hosting secret manager. Confirm that storage and logs are writable and that logs do not expose credentials or unnecessary personal data. Do not copy local development origins or settings into production.
3. **Database and migrations — MySQL validated locally; production version and data-preservation staging remain pending:** the target engine was confirmed as MySQL, but its production version was not specified. A dedicated disposable MySQL 9.7.0 instance and empty schema were used for this validation; all repository migrations, including the five administrative migrations, applied successfully there. The migration ledger records all five, and the lock table contains its `id=1` sentinel. Versioned `config/database.php` defaults to SQLite but also defines MySQL, MariaDB, PostgreSQL, and SQL Server; the default is not evidence of the production engine. Before staging/production, confirm the exact MySQL version, review a backup, apply migrations to an isolated staging copy, and verify existing user/contact/partnership/catalogue records are preserved. No existing application database was accessed or migrated.

   **MySQL version-sensitive checks:** the library and user migrations use native JSON columns (MySQL support begins at 5.7.8; that fact alone does not certify Laravel 13 support for an older server). The indexed 255-character invitation e-mail column needs a compatible InnoDB index limit, character set, and row format. The shared initialization/account lock depends on transactional row-level `SELECT ... FOR UPDATE`; confirm `users` and `admin_account_locks` use InnoDB and inspect the target transaction isolation setting. The temporary MySQL 9.7.0 run verified the migration and lock behavior only on that version/default configuration. Repeat the migration, schema/index inspection, and concurrent lock tests after the hosting target and exact MySQL version are selected.
4. **Initial administrator — not performed; human action required after staging approval:** after the migrations are verified, run `php artisan admin:create-super-admin` once from a restricted, secure console. Enter a unique password at the hidden prompt, confirm access, and securely retain recovery procedures. The command has no default account or password and must not be run automatically during deployment. No real administrator was created.
5. **Invitations and account lifecycle — MySQL concurrency tested locally; exact production version remains to confirm:** automated tests cover role permissions, `401`/`403` behavior, inactive-account denial, single-use invitations, login throttling, and sequential last-super-administrator protection. On the isolated MySQL 9.7.0 instance, two separate initial-admin command workers were held behind the sentinel lock and released concurrently: one succeeded, the other refused, and exactly one active super-administrator remained. Two independent controller updates were also released concurrently against two active test super-administrators: results were `200` and `422`, leaving exactly one active account. The update race exercised the controller transaction directly; middleware authorization is covered separately by the automated tests. Repeat these tests on the production-matching MySQL version in staging. Invitation creation currently does not send e-mail. Deliver invitation links manually only over an approved trusted channel until a mail workflow is implemented.
6. **Public services and content — local regression tests passed; remote integrations pending:** the local suite covers contact and partnership submission/rate limiting and donation checkout using a fake Stripe client, not live Stripe. The signed webhook and real e-mail delivery still require staging credentials and test events. The public book/manifesto catalogue still comes from frontend TypeScript data; importing it into administrative resources is a separate content migration. File uploads and invitation e-mail are not implemented.
7. **Remote end-to-end verification — pending:** a local HTTP check confirmed `/sanctum/csrf-cookie` returns `204`, a valid XSRF token passes CSRF validation, and a missing token is rejected with `419`. The configured origin was returned for the allowed local origin; for an unapproved origin the preflight returned the configured origin rather than echoing the caller, so browsers reject the origin mismatch. From the actual HTTPS frontend origin, still test CSRF initialization, login, authenticated reads and writes, logout, expired sessions, CORS rejection, and browser cookie behavior. Local tests do not establish that a remote production deployment is correctly configured. Do not enable administrator access until this check passes.

### Validation record — 2026-10-20

- The full Laravel suite passed on SQLite `:memory:` and again on the dedicated MySQL 9.7.0 schema: **106 tests, 342 assertions** on each engine. Composer validation/audit, Pint, and frontend lint also passed; Composer reports no security advisories and retains its warning about Sanctum's exact version constraint.
- All project migrations were applied only to the newly initialized temporary MySQL schema. The five administrative migration records and lock sentinel were verified. This empty-schema run does not verify how migrations preserve real pre-existing records.
- The MySQL concurrency checks verified single-winner initialization and protection of the last active super-administrator during concurrent updates, as described above. They were run on MySQL 9.7.0; confirm and repeat against the exact production version.
- Version-sensitive features identified for the eventual target check: native JSON column support, InnoDB index-length/row-format compatibility for the invitation e-mail index, and InnoDB row-level locking/transaction isolation for the shared sentinel. No production version has been inferred from the installed local server.
- Local HTTP checks verified CSRF-cookie bootstrap, accepted/rejected CSRF tokens, and the allowed-origin response behavior. API tests verified `401`/`403`, login/form throttles, invitations, and public endpoints. These tests do not establish production HTTPS, cookie or browser behavior.
- A first MySQL suite attempt was interrupted by a change to the temporary database user's password while that run was active; the subsequent full run used stable temporary credentials and passed. No application or production credentials were used.
- No existing application database was accessed, no real administrator account was created, and no tunnel or deployment was started. The temporary MySQL data directories, schema, database user, synthetic accounts, HTTP server, and helper scripts were removed after validation.
