# Installation & Licensing Cleanup Report

**Scope:** commercial installer, Envato/purchase-code activation, vendor updater, and related release tooling were removed. Normal Laravel routing, settings, authentication, business features, provider integrations, PWA install experience, migrations, queues and scheduler were preserved. No wallet, transaction, payment, P2P, merchant, KYC, card, recharge, subscription or agent business logic was edited.

## Removed

### Files

- Installer: `routes/install.php`, `app/Http/Controllers/InstallerController.php`, `app/Http/Middleware/EnsureApplicationInstalled.php`, `app/Support/InstallationManager.php`, `app/Support/EnvatoLicenseVerifier.php`, `app/Http/Requests/InstallApplicationRequest.php`, `TestInstallDatabaseConnectionRequest.php`, `TestInstallEnvatoLicenseRequest.php`, `resources/views/installer/index.blade.php`, `config/installer.php`.
- Updater/license UI and services: `app/Services/ProjectUpdateServerClient.php`, `app/Services/ProjectUpdaterService.php`, `app/Http/Controllers/Backend/ProjectUpdaterController.php`, its three backend requests (`ActivateProjectLicenseRequest`, `InstallProjectUpdateRequest`, `DownloadProjectBackupRequest`), `app/Models/ProjectLicense.php`, `app/Models/ProjectUpdate.php`, `resources/views/backend/app/updater.blade.php`, `public/backend/css/project-updater.css`, `config/project_updater.php`.
- Commercial release packaging: `app/Console/Commands/BuildReleaseCommand.php` and `config/release.php`; `release:build` is no longer registered.
- Old create migration for updater tables: `database/migrations/2026_05_07_010000_create_project_updater_tables.php`.

### Routes, registration and stored state

- Removed `/install` routes and updater admin routes, the global install-state middleware from both web and API middleware groups, and the updater sidebar/menu entry.
- Removed updater permission definitions from `PermissionTableSeeder` and updater permission records from `DB/digikash.sql`.
- Removed the two updater table definitions from `DB/digikash.sql`.
- Kept the `create_project_updater_tables` entry in the SQL dump's `migrations` history table. It records that the old migration ran; the new cleanup migration removes its tables on deployed databases.
- Added `database/migrations/2026_10_07_000000_drop_project_updater_tables.php` to drop the exclusive `project_updates` and `project_licenses` tables from already-migrated databases. Applying it deletes their stored update/license rows. Rolling it back recreates empty tables; deleted rows are not restored.

### Configuration and secret hygiene

- Removed updater environment variables from `.env.example`.
- Replaced credential-like Twilio and Reverb example values with empty placeholders, cleared the example database password, and changed `APP_DEBUG` to `false`.
- Removed request-time `.env` copying and request-time APP_KEY generation. The existing Composer `post-root-package-install` example-copy script remains; standard deployments should run `php artisan key:generate` after configuring `.env`.
- The real `.env` was not read or changed.

### Packages

No Composer or NPM package was removed or upgraded. No dependency is exclusively for updater/installer behavior: `ext-zip` remains required by `CustomLandingArchiveService` for legitimate custom landing archive import/export. `composer.json`, `composer.lock`, `package.json`, and `package-lock.json` were not changed.

## Preserved

- Laravel bootstrap, normal `web`, `auth`, `admin`, and `api` route registration, `/up` health route, standard middleware aliases, exception handling, and application service providers.
- Guarded runtime settings loading. `AppServiceProvider` now loads dynamic settings only if the `settings` table can be reached; the global `setting()` helper returns its default when the schema is unavailable. This preserves initial CLI/migration boot without an install-state gate.
- Normal migrations, application seeders, Composer scripts, database/cache/session/queue/filesystem configuration, scheduled jobs, provider adapters, payment/SMS/mail/KYC/card/merchant integrations, and existing auth/RBAC definitions unrelated to the updater.
- PWA browser “install app” route and views (`/install-app`, manifest/service worker/offline/launcher) and PWA configuration. This is an end-user browser feature, not the removed server setup wizard.
- Legitimate “license” wording for identity documents, cardholder KYC, subscription activation, customer-configurable footer text, demo disclosure, and marketing attribution. These are not commercial activation or enforcement.
- Existing `app.version` behavior, now set to the local value `2.0` rather than loaded through updater configuration; templates use it for asset cache-busting and display.

Fresh deployment follows regular Laravel setup: configure `.env`, create the database, run `php artisan key:generate`, run `php artisan migrate --seed`, link storage with `php artisan storage:link`, configure queue/scheduler workers, and build assets as needed. The old wizard created a first admin interactively. Do **not** use `CreateAdminUserSeeder` as a substitute: it truncates the admins table and inserts a known default password. Create the first admin through a controlled operator procedure after role seeding.

## Changed

| File | Change |
|---|---|
| `bootstrap/app.php` | Removed installer route/middleware and release command registration; retained Laravel routes, PWA, health, middleware, commands and schedules. Removed request-time sample `.env` creation. |
| `app/Providers/AppServiceProvider.php` | Removed `InstallationManager` injection and runtime key generation; guarded settings initialization by `Schema::hasTable('settings')`. |
| `app/helpers.php` | Replaced `InstallationManager` schema check in `setting()` with guarded Laravel `Schema` lookup and existing default fallback. |
| `config/app.php` | Set local app version `2.0`, preserving version-based asset cache busting without updater config. |
| `routes/admin.php` | Removed the isolated updater route group. |
| `config/admin_menus.php` | Removed updater icon/menu entry. |
| `database/seeders/PermissionTableSeeder.php` | Removed updater-only view/manage permissions and descriptions. |
| `.env.example` | Removed updater variables, replaced credential-like Twilio/Reverb and DB password values with placeholders, set debug default false. |
| `DB/digikash.sql` | Removed updater tables and updater-only permission rows. Other schema and seed data were left intact. |

The new cleanup migration is an addition. `INSTALLATION_LICENSE_DEPENDENCY_MAP.md` records the pre-edit dependency inventory and bootstrap separation decisions.

## Verification

### Executed

- Repository-wide source searches for installer, license, updater, release-command, route, model, service, configuration and migration references. No executable references to the removed installer/updater classes, route names, configuration keys or service clients remain. Remaining text references are classified below.
- `php artisan about` — **not run**: failed immediately because `php` is not installed or available on PATH in this environment.
- `composer validate` — **not run**: failed immediately because `composer` is not installed or available on PATH.
- No frontend build was run because frontend dependencies were unchanged. No test suite exists in this checkout (`tests/` is absent).
- `git status` / `git diff` could not be used: this working directory has no `.git` directory and Git reports it is not a repository.

### Not runtime-verified

Because PHP/Composer are unavailable, `php artisan route:list`, `config:clear`, `cache:clear`, `view:clear`, `optimize:clear`, `migrate:status`, and `php artisan test` could not be executed. Therefore route loading, migration execution, PHP syntax, and application behavior have **not** been runtime-verified. Static inspection confirms normal routes still register and no installer middleware remains in `bootstrap/app.php`.

## Remaining References

The remaining source matches are legitimate and do not enforce commercial product activation or block application access:

| Reference | Classification | Reason |
|---|---|---|
| `/install-app`, PWA install page, manifest/service worker install events, PWA settings and related copy | LEGITIMATE APPLICATION FUNCTION | Browser home-screen/PWA installation remains intentionally supported. |
| “Install” in WordPress/API docs, third-party package/plugin instructions, custom landing/archive comments, ordinary Laravel migration comments | DOCUMENTATION / LEGITIMATE APPLICATION FUNCTION | Describes installing plugins, assets or application schema, not DigiKash server activation. |
| `driver_license`, `DRIVERS_LICENSE`, cardholder document field names and KYC copy | LEGITIMATE APPLICATION FUNCTION | Identity/KYC data. |
| Subscription/agent/card “activation”, account verification | LEGITIMATE APPLICATION FUNCTION | Business lifecycle language. |
| Configurable `footer_license_text`, “Licensed” storefront/demo copy and demo disclosure references to a software purchase/license | LEGITIMATE APPLICATION FUNCTION / DEMO CONTENT | User-authored legal footer, product demo content and public attribution; no runtime validation or gate. |
| `APP_DEMO_VENDOR_*` / demo sales attribution | DEMO ATTRIBUTION | Public demo branding, not installation or vendor update authorization. |
| `database/migrations/2026_10_07_000000_drop_project_updater_tables.php` contains names `project_updates` and `project_licenses` | CLEANUP MIGRATION | References the exclusive old tables only to remove them from deployed databases and recreate empty schemas on rollback. |
| `DB/digikash.sql` migration history row for `2026_05_07_010000_create_project_updater_tables` | HISTORICAL DATABASE METADATA | Preserves the record that the removed create migration was applied to the dump; it does not create or use updater tables. |
| `composer.lock` `config/releases.atom` URL | THIRD-PARTY METADATA | A locked dependency's package metadata URL, unrelated to DigiKash updater code. |
| `INSTALLATION_LICENSE_DEPENDENCY_MAP.md` and this report | DOCUMENTATION ONLY | Record removed components, retained legitimate uses, and the migration. |

Searches excluding PWA, identity/KYC, demo content and these audit documents returned no commercial installer/updater implementation, vendor endpoint, activation route, or license enforcement code. Existing databases may retain inert updater permission rows until their data is separately cleaned; no route/menu/controller uses those permissions after this change.

## Risk Assessment

**MEDIUM.** The commercial request gate and updater are isolated and normal route groups remain registered. The settings bootstrap coupling has a guarded replacement. However, PHP/Composer are unavailable, so runtime routes, PHP syntax, migrations and view rendering could not be checked. The new migration intentionally deletes license/update state when deployed; it is limited to two updater-only tables, but those rows cannot be recovered by rollback.

## Regression Assessment

No source changes were made to these business modules; their pre-existing implementations are preserved. Static route/config inspection only:

| Area | Assessment |
|---|---|
| Authentication | No auth controllers, guards, routes or password/2FA code changed. Runtime not tested. |
| Payments, wallets, transactions | No financial code/schema changed. Runtime not tested. |
| P2P, merchant, KYC, cards, recharge, subscriptions, agents | No domain controllers/services/schema changed. Runtime not tested. |
| API and webhooks | Normal API/webhook routes and middleware remain; only the prepended installer gate was removed. Runtime not tested. |
| Queues and scheduled jobs | Normal command/schedule registration remains; only commercial release-build command registration was removed. Runtime not tested. |
| Settings/storage/mail/notifications | Settings initialization remains guarded by schema availability; storage/mail/plugin configuration calls are retained after settings are available. Runtime not tested. |
| Admin dashboard and permissions | Normal admin route group/menu/permissions remain; updater menu and updater-only permissions were removed. Runtime not tested. |

No database migration was executed during this task.
