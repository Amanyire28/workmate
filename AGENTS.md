# AGENTS.md: Instructions for AI coding assistants

These rules apply to every AI coding assistant (and every human) working on this repository. Read this whole file before changing anything.

> **WorkMate is live in production at https://wm.resnetsystems.com.**
> Every push to `main` deploys automatically to the live server. Real people's accounts, records and files depend on it. When a rule here conflicts with finishing a task quickly, follow the rule.

---

## 1. Project overview

WorkMate is a marketplace that connects customers with service providers. It has a public customer site, an account area, a provider application flow and an internal admin panel. It is built and maintained by ResNet Systems. See `README.md` for what is implemented, what is in development and what is planned.

### Stack (do not change versions without approval)

| Layer | Technology |
|---|---|
| Backend | Laravel 8.83, PHP 7.4 (code must run on PHP 7.4: no PHP 8 features such as `match`, named arguments, nullsafe `?->`, constructor property promotion, enums or `readonly`) |
| Auth | Laravel Sanctum 2.x in SPA cookie mode (`/sanctum/csrf-cookie` + `X-XSRF-TOKEN`) |
| Database | MariaDB 10.4 |
| Frontend | Vue 3, Vue Router 4, built with Vite 6 |
| Styling | Tailwind CSS 4 |
| HTTP client | The project's own fetch wrapper in `resources/js/api/client.js` (no axios, no Pinia) |
| Tests | PHPUnit (`tests/Feature/Api`, `tests/Unit`) |

Do not add packages that need a newer PHP or Laravel version. Check compatibility before running `composer require` or `npm install`.

### Where things live

- **API:** `routes/api.php`, all under `/api/v1`. Controllers are in `app/Http/Controllers/Api/V1`, and admin endpoints are in `.../Api/V1/Admin`.
- **Business logic:** `app/Services`. Controllers stay thin, and services run their changes inside database transactions.
- **Permissions:** `config/permissions.php` (permission keys and roles), mirrored in `resources/js/admin/permissions.js`. `tests/Unit/PermissionDefinitionsTest.php` keeps the two in sync.
- **Platform settings:** `config/workmate.php`, editable through the admin settings API.
- **Frontend:** `resources/js` (pages, components, stores, router), with the admin panel in `resources/js/admin`.
- **Seeders:** `database/seeders`. The `SampleMarketplaceSeeder` runs only when `APP_ENV=local` and must never run in production.

---

## 2. Production environment

| Item | Value |
|---|---|
| Live URL | https://wm.resnetsystems.com |
| Server | Webdock VPS managed with HestiaCP |
| Site root on server | `/home/admin/web/wm.resnetsystems.com/` (HestiaCP layout) |
| Deploy script on server | `/home/admin/web/wm.resnetsystems.com/private/deploy-workmate.sh` |
| Public web directory | Laravel's `public/` folder is what the web server serves. Under HestiaCP the domain's document root is `/home/admin/web/wm.resnetsystems.com/public_html`. Check how it points at the app in `deploy-workmate.sh` and the HestiaCP web template **before** relying on it. |
| Application directory | Set inside `deploy-workmate.sh` on the server. Read the script; do not guess. |

Only `public/` may be web-accessible. The application code, `.env`, `storage/` and `vendor/` must never sit inside the public document root.

### Automated deployment

- `.github/workflows/deploy.yml` runs on **every push to `main`** (and on manual `workflow_dispatch`).
- It connects to the VPS over SSH using GitHub repository secrets (`VPS_HOST`, `VPS_USER`, `VPS_PORT`, `VPS_SSH_KEY`, `VPS_KNOWN_HOSTS`) and runs the server-side deploy script above.
- Deployments are serialised (`concurrency: workmate-production`, not cancelled mid-run).
- **Pushing to `main` is the same as deploying to production.** Treat it that way.

Do **not** modify `.github/workflows/deploy.yml`, the server deploy script, SSH settings or repository secrets unless the repository owner explicitly asks for that specific change.

---

## 3. Protecting production data and secrets

### Never do any of these

- Never read out, print, log, commit or paste the contents of any `.env` file, SSH keys, database passwords, API keys, mail credentials or tokens. This applies in chat, commits, issues, PR descriptions and logs.
- Never commit `.env`, `.env.backup`, `vendor/`, `node_modules/`, `public/build/`, `storage/` uploads or logs. `.gitignore` already excludes these; keep it that way.
- Never overwrite, regenerate or "fix" the production `.env`. Do not run `php artisan key:generate` against production, because changing `APP_KEY` breaks every session and any encrypted data.
- Never delete, truncate, reseed or bulk-edit production database records.
- Never delete or move user-uploaded files (for example, profile photos in `storage/app/avatars`) or other files under `storage/`.
- Never hardcode production data, credentials or real personal data in code, tests, seeders or the frontend.
- Never run sample or demo seeders against production.

### Sensitive files and uploads

- Sensitive files (identity and verification documents, private uploads) are stored on the private `local` disk (`storage/app`) and served only through authorised controllers. **Never** put them on the `public` disk or under `public/`.
- Profile photos are re-encoded on upload (GD, EXIF stripped) and served through `/api/v1/avatars/{uuid}.jpg`. Keep that pattern for new image uploads.

### When a task needs credentials or server access

Stop and ask the repository owner. Do not try to find, guess or work around credentials, and do not ask for secrets to be pasted into chat.

---

## 4. Database migrations

Production holds real data. Every migration must be **safe, incremental and forward-only**.

### Rules

1. **Never edit a migration that has already been committed to `main`.** It has already run in production. Write a new migration instead.
2. **Add, don't destroy.** Prefer adding new tables and columns. New columns on existing tables must be `nullable()` or have a `default()` so existing rows stay valid.
3. **No destructive changes without explicit approval.** This covers dropping tables or columns, renaming columns or tables, narrowing a column type or length, changing a column's meaning, and adding a unique index to a column that may already hold duplicates. If one is truly needed, explain the risk and the data impact, propose a backup and a staged plan (add new, then copy, then switch over, then remove old in a later release), and wait for a clear yes.
4. **Every migration needs a working `down()` method**, but never rely on rollback in production as a recovery plan.
5. **Data backfills** go in a separate migration or command, must be idempotent (safe to run twice) and must process large tables in chunks.
6. **Keep migrations compatible with MariaDB 10.4** and PHP 7.4. `doctrine/dbal` may be needed for `->change()`; check before using it.
7. **Test locally first** with `php artisan migrate` on a local database, then run the full test suite.

### Commands that are forbidden in production

Never run, script or add any of these to deployment:

```text
php artisan migrate:fresh
php artisan migrate:refresh
php artisan migrate:reset
php artisan migrate:rollback   (unless the owner explicitly asks)
php artisan db:wipe
php artisan db:seed            (unless a specific, production-safe seeder is approved)
php artisan key:generate
```

Do not write raw `DROP`, `TRUNCATE` or unscoped `DELETE`/`UPDATE` SQL either. In production, only `php artisan migrate --force` (run by the deploy process) is expected.

### Local and test databases

- Local development uses the `workmate` database. Tests use **`workmate_test`**, which is set in `phpunit.xml`.
- The tests use `RefreshDatabase` and **wipe their database on every run**. Never point `phpunit.xml`, a `.env.testing` file or a test command at the `workmate` database or at production.

---

## 5. Git safety

- `main` deploys to production. Make changes on a feature branch where possible, and only merge or push to `main` when the change is complete, tested and approved.
- Commit only what the task needs. Review `git status` and `git diff --staged` before every commit, and make sure no secrets, `.env` files, build output or uploads are staged.
- Never force-push (`git push --force`), rewrite published history (rebase, amend or reset of pushed commits) or delete branches or tags on the remote without explicit approval.
- Never use `--no-verify` or skip hooks.
- Before starting, `git fetch` and check that the local branch is up to date with `origin/main`. Others (including GitHub's web editor) also commit to it.
- Use clear, descriptive commit messages that say what changed and why.

---

## 6. Preserving existing functionality

- **Inspect before you change anything.** Read the relevant files, routes, models and tests first. Never assume a file, route, column or component exists.
- **Extend; don't rebuild.** Do not rewrite, replace or restructure working parts of the app as a side effect of another task.
- **Do not remove or disable working features** without explicit approval.
- **No duplicates.** Reuse existing models, services, routes, components and stores rather than creating parallel versions.
- Keep the existing conventions:
  - Successful API responses are `{ "data": ... }`. Errors are `{ "message", "code", "errors"? }`, produced by `app/Exceptions/Handler.php` for `api/*` routes.
  - Business rule failures throw `App\Exceptions\BusinessRuleException`.
  - Important changes are written to the append-only audit log through `AuditLogger`, which redacts sensitive keys. Never update or delete audit log rows.
- Preserve WorkMate's branding, wording and design system.
- Match the surrounding code's style, naming and comment density.
- Keep a change small and focused. Don't make unrelated refactors or formatting sweeps in the same change.

---

## 7. Security requirements

- **Never trust the frontend.** All permission checks, validation, prices, totals, ratings, statuses and other calculations must be enforced on the server. Frontend permission checks only hide UI.
- **Authorise every endpoint.** Use the `auth:sanctum` and `active` middleware and the permission gates (`Gate::before` maps permission keys from `config/permissions.php`, plus `act-as-provider` and `admin-access`). New permissions must be added to both `config/permissions.php` and `resources/js/admin/permissions.js`.
- **Validate all input** with Laravel validation. Use mass-assignment protection (`$fillable` or explicit `forceFill`), never unguarded models filled straight from request data.
- **Prevent injection:** use Eloquent and the query builder with bindings. Never interpolate user input into raw SQL, shell commands or file paths.
- **Protect personal data:** mask contact details in admin listings as the existing admin snapshot does. Round public coordinates. Don't expose emails, phone numbers or exact locations to people who shouldn't see them.
- **Rate-limit** sensitive endpoints (sign-in, email, marketplace writes) using the limiters in `RouteServiceProvider`.
- **Never fabricate** payment confirmations, verification results, reviews, analytics or other figures. If something isn't tracked, show that it isn't tracked.
- Keep `APP_DEBUG=false` in production, and never return stack traces or internal errors to users.

---

## 8. Data isolation between users and provider businesses

WorkMate holds data for many independent customers and provider businesses (a provider profile plus its members, through `ProviderMember`). Treat each account and each provider business as its own tenant.

- **Scope every query to the current user or their provider business.** Never load a record by ID alone and return it. Check ownership or membership, or use a scope such as `Conversation::scopeForParticipant`.
- A provider member can only see and act on their own provider's requests, quotations, conversations and reports. A customer can only see their own requests, messages and activity.
- Public listings expose only approved, publicly listed providers (`ProviderProfile::scopePubliclyListed`) and published reviews (`Review::scopePublished`).
- Admin endpoints must check the specific permission for each action. Having admin access is not the same as having every permission.
- Sample records (`is_sample`) must stay separated: labelled in admin and left out of real figures.
- Every new endpoint that returns user or provider data needs a test proving that **another user or provider cannot read or change it**.

---

## 9. Testing

Before committing any change to application code:

```bash
php artisan test          # or: vendor/bin/phpunit   (uses workmate_test)
npm run build             # the frontend must build without errors
```

- All existing tests must still pass. Never delete, skip or weaken a test to make a change pass. Fix the code, or explain why the test itself is wrong and get approval.
- Add or update feature tests in `tests/Feature/Api` for new endpoints and behaviour. Cover permissions (allowed and denied), validation errors, data isolation and the audit log where relevant.
- Report results honestly. If something fails or wasn't run, say so.
- Documentation-only changes (such as this file or `README.md`) don't need the test suite, but must not touch application files.

---

## 10. Deployment precautions

Because every push to `main` deploys to the live site:

1. **Before pushing:** the tests pass, the frontend builds, the diff contains only the intended change, there are no secrets or build output, and migrations follow section 4.
2. **Be careful with changes that affect deployment:** new environment variables (document them in `.env.example` with placeholder values only, and tell the owner they must be added to the server's `.env` by hand), new PHP extensions, queue or scheduler changes, `composer.json` and `package.json` changes, and cache or config changes. Call these out explicitly so the owner can prepare the server first.
3. **Avoid `route:cache` problems:** closure routes stop `php artisan route:cache` from working. Don't add new closure routes.
4. **Release risky changes in steps:** schema change first, code that uses it next, cleanup last, each as a separate deploy.
5. **After pushing:** check that the GitHub Actions "Deploy WorkMate to VPS" run succeeded, then check https://wm.resnetsystems.com (including `/api/v1/health` if the change touches the backend). If the deploy fails, report it straight away with the error. Don't keep pushing speculative fixes to `main`.
6. **Never test against production.** Don't create test accounts, send test emails to real users or run experiments on the live site without approval.

---

## 11. How to work on a task

1. Read this file and `README.md`.
2. Inspect the relevant code before proposing changes.
3. For anything non-trivial, explain the plan and the files it will touch. Get approval for anything destructive, anything touching production, schema changes that alter existing data, and new dependencies.
4. Implement in small steps, following the existing patterns.
5. Run the tests and the build, and report the results honestly.
6. Summarise what changed, which files were touched, and any follow-up the owner must do on the server (for example, new `.env` keys).

**When in doubt, stop and ask.** A delayed feature is recoverable; lost production data may not be.
