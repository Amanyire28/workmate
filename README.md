# WorkMate

![Laravel](https://img.shields.io/badge/Laravel-8.83-FF2D20?logo=laravel&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-7.3%20%7C%208.x-777BB4?logo=php&logoColor=white)
![Vue](https://img.shields.io/badge/Vue-3.5-42B883?logo=vuedotjs&logoColor=white)
![Vite](https://img.shields.io/badge/Vite-6.4-646CFF?logo=vite&logoColor=white)
![Tailwind CSS](https://img.shields.io/badge/Tailwind%20CSS-4-06B6D4?logo=tailwindcss&logoColor=white)
![Status](https://img.shields.io/badge/status-in%20development-F59E0B)

**WorkMate is a location-based service marketplace for Kampala, Uganda.** It helps people find nearby plumbers, electricians, cleaners, carpenters, painters, movers, drivers and other local professionals, and gives those professionals a place to be found, receive requests and grow their work.

It is built so it can grow beyond Kampala to the rest of Uganda.

> **One person. One account. Multiple ways to use the platform.**
> Everyone registers once and can hire people straight away. The same account can apply to offer services, and an approved provider keeps every customer capability.

**Developed by ResNet Systems.**

---

## Contents

- [How it works](#how-it-works)
- [Project status](#project-status)
- [Features](#features)
- [User types and the account model](#user-types-and-the-account-model)
- [Location-based matching](#location-based-matching)
- [Provider verification and the tick](#provider-verification-and-the-tick)
- [Administration](#administration)
- [Technology stack](#technology-stack)
- [Frontend architecture](#frontend-architecture)
- [Backend architecture](#backend-architecture)
- [Database](#database)
- [Authentication and security](#authentication-and-security)
- [API](#api)
- [Getting started](#getting-started)
- [Development commands](#development-commands)
- [Testing](#testing)
- [Environment variables](#environment-variables)
- [Project structure](#project-structure)
- [Deployment and CI/CD](#deployment-and-cicd)
- [Future mobile applications](#future-mobile-applications)
- [Roadmap](#roadmap)
- [Contributing](#contributing)
- [Security](#security)
- [Licence](#licence)

---

## How it works

1. A customer tells WorkMate what they need (for example "plumber") and where they are, either by sharing their device location or by choosing an area such as Ntinda.
2. WorkMate shows providers closest first, widening the search when there are too few nearby.
3. The customer compares profiles, ratings and starting prices, then messages a provider, sends them a request, or posts a job that any suitable provider can apply for.
4. Providers manage their profile, professions, coverage areas and availability from the same account they use as a customer.
5. Administrators review provider applications, handle reports and moderate the marketplace from a role-based admin area.

---

## Project status

WorkMate is **in active development**. It started as a Vue frontend prototype running on mock data and is moving, phase by phase, to a Laravel-powered marketplace.

The customer website and the admin area now read from and write to the database. Sample providers, reviews and jobs exist only for development. Bookings, payments and most notifications are not built yet.

Throughout this README:

| Label | Meaning |
|---|---|
| ✅ **Implemented** | Works end to end against the database and is covered by tests |
| 🟡 **In development** | Partly built: some layers exist, others don't yet |
| ⬜ **Planned** | Designed or intended, not built |

---

## Features

### ✅ Currently implemented

**Accounts**
- Registration, sign-in (with "keep me signed in"), sign-out
- Email address confirmation by signed link
- Forgotten password and reset by email link; password change from the account page
- Profile: name, phone number (Ugandan numbers are stored in one standard form, `+256…`), language preference (English or Luganda)
- Profile photo upload and removal. Photos are cropped to a square and re-encoded, which removes hidden data such as GPS location.

**Becoming a provider (same account)**
- Apply as an **individual**, **group** or **company**
- Choose up to **5 skills** from 22 categories; the first is the main one
- A skill that isn't listed can be typed under **Other**. Customers can't find anyone by it until an administrator adds it to the list, matches it to an existing profession or declines it (Marketplace → Typed Professions)
- **Main skill first:** in an area, someone with a skill as one of their other skills is shown only when nobody based there has it as their main skill
- **Where you're based** (required to send the application) and other places they travel to, chosen by typing any place in Uganda
- Public listing details: starting price, price basis, availability, usual hours
- Application lifecycle with administrator review (see [statuses](#service-providers))

**Marketplace**
- Service categories, professions and areas served from the database
- Provider directory with profiles, skills, starting prices, availability and reviews
- Ratings are **calculated from stored reviews**, never typed in
- Nearby search with a radar-style animation, using device location or a chosen area (see [Location-based matching](#location-based-matching))
- **Post a job** for any provider to apply to
- **Request a specific provider** directly
- **Apply for a job** with a message, start time and optional price (approved providers only)
- **My work:** the signed-in user's requests, posted jobs and applications
- **Messages** between customers and providers, visible only to the two people in the chat
- **Report a provider** to the safety team (a report is an allegation until confirmed, so it never penalises anyone by itself)

**Administration**
- Sign-in protected admin area with **9 roles** and **23 permissions**
- Live data for customers, providers, applications, jobs, quotations, reviews, reports, area coverage, admin users, the activity feed and global search
- Connected actions: approve, reject or request information on provider applications; suspend or reinstate providers; suspend or reactivate accounts; hide or restore reviews; handle reports; change admin roles
- Append-only **audit log** of every important action, with who, when and why
- Platform settings for search radii and online-presence timings

**Quality**
- 85 automated feature and unit tests on MariaDB

### 🟡 In development

| Area | What exists | What's missing |
|---|---|---|
| Places | Every populated place in Uganda (about 11,300 towns, trading centres, villages and suburbs from GeoNames, CC BY 4.0) loaded by a migration; one type-to-search place box everywhere; "Use my location" names the nearest place | Some small places are missing from GeoNames (e.g. Najjera). There is no admin screen yet to add a place by hand |
| Location matching | Distance ranking and widening run in the browser | Server-side matching. The search still uses fixed radii (5 km / 10 km) in `resources/js/utils/nearbySearch.js` rather than the admin-editable settings. |
| Quotations | Providers can apply to posted jobs with a price | Customers can't yet view or accept applications; providers have no inbox for direct requests |
| Reviews | Display, rating calculation and admin moderation | Customers can't write reviews yet (they will follow completed bookings) |
| Document verification | National ID (front and back) plus up to 5 supporting documents, stored privately; admin review, per-document accept or send back, requests for extra documents, approval gives the grey tick | The per-profession Document Requirements screen is still a browser-only prototype |
| Performance tick | Tick display and admin rules screen | Automatic evaluation isn't running; only sample providers carry a tick |
| Email | WorkMate-branded confirmation (link valid 24 hours) and reset emails are generated | Delivery needs mail settings in `.env`; check them with `php artisan workmate:mail-test` |
| Push notifications | People turn them on per device on the Account page; administrators with `comms.send` send announcements from Communications → Push Announcements | Announcements only, no automatic notifications for messages or requests yet. Needs VAPID keys in `.env` (`php artisan workmate:push-keys`). iPhones receive them only when WorkMate is installed on the home screen (iOS 16.4+) |
| Languages | Language preference is stored | The interface is English only; no translations yet |
| Provider teams | `provider_members` table with an owner row | Adding and managing group or company members |
| Photos on requests | A photo picker on the request form | Photos aren't uploaded (the form says so) |

### ⬜ Planned

- Bookings: accepting a quotation, booking status tracking (agreed → in progress → completed or cancelled), booking history
- Reviews written by customers after completed bookings
- Saved providers and saved locations
- Admin management of categories, professions and areas
- Payments with Ugandan mobile money (MTN MoMo, Airtel Money), commission, refunds, payouts and reconciliation
- In-app and email notifications, and automatic push notifications for messages, requests and decisions
- Real-time messaging (currently the chat checks for new messages every few seconds)
- Progressive Web App (installable app and offline shell)
- "WorkMate in Action" short provider videos with moderation
- App usage, installation and online-presence analytics
- Luganda interface translations
- Android and iOS apps on the same API

---

## User types and the account model

### Regular users

Every account starts as a customer. There is no "customer or provider?" question at sign-up. A customer can:

- browse services and search for nearby providers
- view provider profiles and reviews
- message providers, send requests and post jobs
- track their requests, jobs and applications in **My work**
- manage their profile, photo, password and email confirmation

### Service providers

A customer applies to offer services from **Account → Offer your services**. Provider capabilities attach to the **same account**: an approved provider can still search for and hire other providers.

Provider application statuses (implemented):

| Status | Meaning |
|---|---|
| Not applied | No provider details saved yet |
| Draft | Details saved but not sent for review |
| Pending review | Sent and waiting for an administrator |
| More information required | An administrator asked for changes; the applicant edits and resends |
| Approved | Listed publicly and able to apply for jobs |
| Rejected | Not approved; the applicant sees the reason and can start a new application |
| Suspended | Listing removed by an administrator; the customer account keeps working |

The email address must be confirmed before an application can be sent. An administrator can't review an application for a provider they belong to.

### Administrators

Administrators are ordinary accounts that hold one or more admin roles. Roles are granted with an artisan command or by a Super Administrator in the admin area. Every request is checked against the account's permissions on the server; hiding menus in Vue is only for convenience. See [Administration](#administration).

---

## Location-based matching

The intended matching order is:

1. providers closest to the user's current location
2. providers within the selected area
3. providers in the wider city or region
4. providers from nearby areas, or those who list the area as one they serve, when local results are too few

**What works today ✅ (in the browser)**

- **Device location with permission.** The browser's Geolocation API is used only when the user agrees. The position stays in memory for that visit and is never stored or sent to the server.
- **Manual area selection** from 20 areas (Kampala neighbourhoods plus Kira, Mukono, Wakiso and Entebbe), loaded from the database.
- **Distance ranking.** Straight-line distance from the user (or the chosen area's centre) to each provider's registered base.
- **Widening the search** in three groups: *nearby* (within 5 km), *more around the town* (within 10 km or based in the same town), and *providers serving your area* (further away, but listing the area in their coverage).

**Privacy:** a provider's base is an area's centre point, never a live position. The public API rounds coordinates to about 1 km.

**Still to do 🟡 / ⬜**

- Move matching to the server (indexed distance queries, paging) so it scales beyond the current provider count
- Use the admin-configured radii (stored server-side) in the customer search
- Live provider availability and online presence

---

## Provider verification and the tick

Providers show **one tick**. It is grey once their documents are approved, and turns blue when WorkMate's rules qualify them for strong performance. A provider never shows two badges, and the site doesn't explain the tick to customers.

### Standard verification (document checks) ✅

1. The provider adds their **National ID** (front and back, required) and up to **5 supporting documents** of their choice (Account → Get verified). Files are JPG, PNG or PDF, up to 8 MB, up to 4 pages each.
2. They send them for review (email must be confirmed).
3. A reviewer (`documents.review`) accepts each document or sends it back with a reason, and can **ask for further documents** for this applicant, for example a permit their profession needs.
4. The provider replaces or adds what was asked and sends again.
5. The reviewer approves (every document accepted, nothing outstanding) or rejects. Approval sets `document_verified_at`, which shows the grey tick.

Files are stored on the private `local` disk under `storage/app/verification`. Photos are re-drawn as JPEG (hidden data such as GPS removed); PDFs are kept as uploaded. Files are served only to their owner and to administrators with `documents.view`, with `no-store` caching, and every administrator opening is written to the audit log. Replaced documents are kept as a record. Reopening a decision needs `verification.configure`.

**Still a prototype:** the per-profession Document Requirements screen in the admin keeps its changes in the browser only.

### Performance tick ⬜

The blue tick is separate from document checks and is awarded **automatically**. It can't be bought or applied for. Intended criteria include completed bookings, ratings, response and completion rates, account standing and the absence of confirmed serious violations. Unproven allegations don't count against a provider.

**Current state:** the rules screen is a frontend prototype and no evaluation runs. The database has `document_verified_at` and `blue_tick_awarded_at` on provider profiles, but only sample providers have them set.

---

## Administration

The admin area lives at **`/admin`**. It requires sign-in with an admin role; other accounts are redirected with an explanation.

**Sections (12):** Overview · Marketplace · Location Intelligence · Trust and Safety · Verification Management · Trust and Reputation · Finance · Content and Engagement · Communications · Analytics · System · Administration. They hold 67 screens in total.

**Roles (9):** Super Administrator, Operations Administrator, Provider Review Administrator, Customer Support Agent, Finance Administrator, Trust and Safety Moderator, Content Moderator, Analytics Viewer, Technical Administrator. Permissions are defined in `config/permissions.php`. The admin frontend mirrors them in `resources/js/admin/permissions.js`, and a test keeps the two identical.

**What's live ✅**

- The executive dashboard and every records screen read from the database through `GET /api/v1/admin/data`. Each section is filtered to the administrator's permissions, and contact details are masked.
- Sample records are listed with "(sample)" after the name but left out of every figure.
- Anything not recorded yet (payments, bookings, disputes, searches, app usage) is shown as **Not tracked yet**, never as zero.
- Actions that call the API are listed in `resources/js/admin/modules/connected.js`. Other actions aren't offered; the record shows how many aren't connected.
- Every action needs a reason and is written to the audit log.
- The six search and presence settings are saved server-side; the other settings are marked "Not connected yet".

**Planned ⬜:** catalogue management, verification document review, blue tick rules, payments, refunds and payouts, disputes, video moderation, notification campaigns, app usage analytics, system health monitoring, data requests.

---

## Technology stack

Versions are those installed in `composer.lock` and `package-lock.json`.

### Backend

| Technology | Version | Use |
|---|---|---|
| PHP | `^7.3 \|\| ^8.0` (developed on 7.4) | Runtime |
| Laravel | 8.83 | Framework |
| Laravel Sanctum | 2.15 | Cookie-based sign-in for the website (SPA mode) |
| MySQL / MariaDB | developed on MariaDB 10.4 | Database |
| PHPUnit | 9.6 | Tests |
| Guzzle, Laravel Tinker, fruitcake/laravel-cors | 7.x, 2.x, 2.x | Laravel defaults |

PHP extensions used: `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, and **`gd` with JPEG, PNG and WebP support** (profile photos). `exif` is optional; it straightens sideways phone photos.

### Frontend

| Technology | Version | Use |
|---|---|---|
| Vue | 3.5 | UI (`<script setup>` single-file components) |
| Vue Router | 4.6 | Client-side routing (history mode) |
| Vite | 6.4 | Dev server and build, via `laravel-vite-plugin` 1.3 |
| Tailwind CSS | 4 (`@tailwindcss/vite`) | Styling (design tokens in `@theme`) |
| Bootstrap Icons | 1.11.3 (CDN) | Icons |
| Inter | Google Fonts | Typeface |

There is **no Axios, Pinia or Vuex**. API calls go through a small `fetch` wrapper, and shared state uses Vue reactive modules.

### Not used yet

Redis, queue workers (the queue runs `sync`), broadcasting and WebSockets, service workers and PWA, Swagger/OpenAPI, and CI pipelines.

---

## Frontend architecture

The Vue app is mounted by a single Blade view (`resources/views/app.blade.php`). Laravel's catch-all web route sends every non-API URL to it, and Vue Router takes over.

| Area | Location | Notes |
|---|---|---|
| Entry point | `resources/js/app.js`, `App.vue` | Starts the session check; picks the site or admin shell |
| Router | `resources/js/router/index.js` | Lazy admin routes; guards for sign-in-only pages and the admin area |
| API client | `resources/js/api/client.js` | `fetch` wrapper: session cookie, CSRF header, one error format (`ApiError`), file uploads |
| State | `resources/js/stores/` | `auth` (signed-in account), `catalog` (categories, areas, providers, reviews, jobs from one `/catalog` request), `messages`, `location`, `nearbySearch` |
| Site pages | `resources/js/pages/` | Home, Browse, Explore, provider profile, Post a job, Find work, My work, Messages, Account, sign-in and password pages |
| Components | `resources/js/components/` | Forms, cards, the location sheet and radar, the provider application panel, the photo editor, the tick badge |
| Utilities | `resources/js/utils/` | Nearby search ranking, trust and tick helpers, formatting |
| Admin | `resources/js/admin/` | Its own layout, sidebar and top bar; definition-driven modules (`modules/*.js`) rendered by shared workspaces (records, analytics, settings, roles); SVG charts with no chart library |

**Design.** A blue, light-blue and navy palette with a "liquid glass" look: translucent header and cards on a soft blue background, and a strong light-blue glass sidebar in the admin area. The glass falls back to solid surfaces where the browser lacks `backdrop-filter` or the user prefers reduced transparency. The admin area keeps blue tones but uses solid cards so data stays easy to read. Green is reserved for status (success), not branding.

**Responsive behaviour.** The layout is mobile-first:
- **Phone:** a bottom tab bar (Home, Providers, Messages, My Work, Account) and full-width forms; 16 px side gutters with no sideways scrolling.
- **Tablet:** two-column grids.
- **Desktop:** a top navigation bar, three-column grids and a collapsible admin sidebar.

**Accessibility.** Includes skip links, visible focus rings, labelled form errors that receive focus, live regions for status messages, reduced-motion support, and data tables behind charts.

**Remaining frontend-only data.** Only these still use local frontend data:
- `resources/js/data/verificationRequirements.js` and `stores/verification.js` (the Document Requirements prototype)
- `admin/stores/blueTickRules.js` (the blue tick rules prototype)

---

## Backend architecture

Controllers stay thin; business rules live in service classes and run in database transactions where several records change.

```text
Request → middleware (auth:sanctum, active, can:<permission>, throttle)
        → Form Request / validation
        → Controller → Service (rules, transaction, audit log)
        → API Resource (JSON shape, field hiding, masking)
```

| Layer | Location | Contents |
|---|---|---|
| Routes | `routes/api.php` | Everything under `/api/v1`; `routes/web.php` serves the Vue app |
| Controllers | `app/Http/Controllers/Api/V1/` | `Auth/` (sessions, passwords, email confirmation), public directory, jobs, messages, provider application, photos, reports; `Admin/` (data, applications, provider standing, roles, settings, audit log, actions) |
| Services | `app/Services/` | `Accounts`, `ProviderApplications` (the full status lifecycle), `Marketplace`, `Messaging`, `ProfilePhotos`, `RoleAssignment`, `AdminActions`, `AdminSnapshot`, `PlatformSettings`, `AuditLogger` |
| Validation | `app/Http/Requests/` and controller `validate()` calls | Phone normalisation, password rules (8+ characters, letters and numbers), allow-listed settings |
| Middleware | `app/Http/Middleware/EnsureAccountIsActive.php` (`active`) | Ends the session of a suspended or deactivated account on its next request |
| Authorisation | `app/Providers/AuthServiceProvider.php` | Every permission key is a Gate ability; plus `act-as-provider` and `admin-access` |
| Errors | `app/Exceptions/Handler.php` | One JSON error format for `/api/*`; no SQL or stack traces in responses |
| Console | `app/Console/Commands/ManageAdminRole.php` | `php artisan workmate:role` |
| Configuration | `config/permissions.php`, `config/workmate.php` | Roles and permissions; the allow-list of platform settings |

---

## Database

### Implemented tables

| Table | Purpose |
|---|---|
| `users` | Every account. Adds phone, locale, status (`active` / `suspended` / `deactivated`), photo path, last seen, soft deletes and `is_sample` to Laravel's default table |
| `password_resets`, `personal_access_tokens`, `failed_jobs` | Laravel defaults: reset tokens, Sanctum tokens (for future mobile apps), failed queue jobs |
| `roles`, `permissions`, `permission_role`, `role_user` | Admin roles and permissions (`role_user` records who granted each role) |
| `audit_logs` | Append-only record of actions: actor, action, subject, before and after values, reason, IP. Sensitive values are redacted. |
| `platform_settings` | Settings changed from their defaults (allowed keys are defined in `config/workmate.php`) |
| `categories`, `professions` | Service categories and the professions within them |
| `areas` | Every place in Uganda with its district and centre point; `is_featured` marks the quick picks, `geonames_id` the loaded places |
| `provider_profiles` | Provider capabilities for an account: type, display name, status, base area, listing details, verification and tick dates, `is_sample` |
| `provider_members` | People linked to a provider profile (currently the owner) |
| `provider_professions` | A provider's professions (several per provider, one main) |
| `provider_service_areas` | Areas a provider travels to |
| `provider_applications` | Each review round, with a snapshot of what was submitted, the decision, the reason shown to the applicant and internal notes |
| `reviews` | Ratings and comments; status for moderation |
| `service_requests` | Posted jobs (no provider) and requests to one provider |
| `quotations` | A provider's application or price for a request (one per provider per request) |
| `conversations`, `messages` | Customer–provider chats and their messages, with read status |
| `provider_reports` | Confidential reports about providers and their review status |

**Key relationships**
- A user **owns at most one** provider profile (`provider_profiles.owner_id`) and holds roles through `role_user`.
- A provider profile has many professions (each in a category), service areas, applications, reviews and members.
- A service request belongs to a customer and a category, and optionally to one provider (a direct request). It has many quotations.
- A conversation joins one customer and one provider profile and has many messages.

Migrations are in `database/migrations/`; all of them can be rolled back. Deletion rules preserve history (`restrict` / `nullOnDelete` where records must survive), and accounts and requests use soft deletes.

### Seeders

| Seeder | Adds | When |
|---|---|---|
| `RolesAndPermissionsSeeder` | 23 permissions and 9 roles from `config/permissions.php` | Always (safe to re-run) |
| `CatalogueSeeder` | The original 10 categories (the full list of 22 categories and about 80 professions is added by a migration) | Always (keeps administrator edits) |
| `AreaSeeder` | The 20 popular quick-pick areas (`database/seeders/data/areas.json`); every other Uganda place comes from `places-ug.csv` through a migration | Always |
| `SampleMarketplaceSeeder` | 14 invented providers, 24 reviews and 7 open jobs (`data/sample-marketplace.json`), all marked `is_sample` | **Only when `APP_ENV=local`**, and only once |

Sample accounts use the reserved `@workmate.invalid` domain and random unknown passwords, so nobody can sign into them.

### Planned data architecture ⬜

Not yet in the schema: bookings and booking event history; verification requirements and versions, performance-tick rules, evaluations and award history; payments, ledger entries, commissions, refunds and payouts; notifications and notification preferences; provider videos and moderation; devices, installations and usage sessions; saved providers and saved locations.

---

## Authentication and security

- **Method:** Laravel Sanctum in **SPA cookie mode**. The website first calls `GET /sanctum/csrf-cookie`, then signs in with `POST /api/v1/auth/login`. The session cookie authenticates later requests, and changing requests carry the `X-XSRF-TOKEN` header. No tokens are kept in JavaScript or `localStorage`.
- **Stateful domains:** sessions only start for requests from domains in `SANCTUM_STATEFUL_DOMAINS` (the default covers `localhost` and `127.0.0.1:8000`).
- **Registration and login:**
  - New accounts are signed in straight away and sent a confirmation email.
  - Suspended accounts can't sign in.
  - A wrong password always gets the same message, and the forgot-password form answers the same way whether or not an account exists, so neither reveals which emails are registered.
- **Passwords:** hashed with bcrypt; at least 8 characters with letters and numbers. Changing or resetting a password revokes API tokens.
- **Rate limits:**
  - sign-in: 5 per minute per email and device, and 20 per minute per device
  - registration and emails: 6 per minute
  - creating jobs, requests, applications, messages and reports: 30 per minute
  - general API: 60 per minute
- **Authorisation:**
  - Admin routes use `can:<permission>`, and an account must be active to hold any permission.
  - Provider actions check `act-as-provider`, which requires approval and an active account.
  - Chats return "not found" to anyone outside them.
- **Responses:** API Resources decide every field. Passwords, tokens and internal fields are never returned, the admin area masks phone numbers and email addresses, and public provider locations are rounded.
- **Uploads:** photos are type-, size- and dimension-checked, re-encoded and served under random names.

> Configure your own `.env`. Never commit it, and never copy real credentials into documentation or issues.

---

## API

**Base URL:** `/api/v1`. Every endpoint is defined in `routes/api.php`; there is no Swagger/OpenAPI setup yet, and this section will grow as modules are built.

**Response format**

```jsonc
// Success
{ "data": { ... } }            // lists may add "links" and "meta" when paged

// Error (every /api/* error)
{ "message": "Some of the information provided is not valid.",
  "code": "validation_failed",               // or unauthenticated, forbidden, not_found, ...
  "errors": { "email": ["..."] } }           // validation errors only
```

**Endpoint groups**

| Group | Access | Examples |
|---|---|---|
| Public | Anyone | `GET health`, `GET catalog` (everything the first page needs), `GET categories`, `GET areas` (quick picks and places in use), `GET areas/search?q=` (type-to-search), `GET areas/nearest`, `GET providers`, `GET providers/{id}`, `GET reviews/recent`, `GET jobs`, `GET jobs/{id}`, `GET avatars/{file}` |
| Auth | Guests (website session) | `POST auth/register`, `auth/login`, `auth/logout`, `auth/forgot-password`, `auth/reset-password`; `GET auth/verify-email/{id}/{hash}` (signed link) |
| Account | Signed in | `GET/PATCH me`, `PUT me/password`, `POST/DELETE me/photo`, `POST me/email/verification-notification`, `GET me/requests`, `GET me/applications` |
| Provider application | Signed in | `GET/PUT me/provider`, `POST me/provider/submit`, `POST me/provider/reopen` |
| Marketplace | Signed in | `POST jobs`, `POST jobs/{id}/applications` (approved providers), `POST providers/{id}/requests`, `POST providers/{id}/reports` |
| Messages | Chat participants | `GET me/conversations`, `GET me/conversations/unread-count`, `POST me/conversations/provider/{id}`, `GET me/conversations/{id}`, `POST me/conversations/{id}/messages` |
| Admin | Admin permissions | `GET admin/data`, provider applications (`approve`, `reject`, `request-information`), `providers/{id}/suspend` and `reinstate`, `users/{id}/suspend` and `reactivate`, `PATCH reviews/{id}`, `PATCH reports/{id}`, roles and permissions, `PUT/DELETE users/{id}/roles/{role}`, `GET/PATCH settings`, `GET audit-logs` |

The legacy Laravel route `GET /api/user` (Sanctum default) is still present.

---

## Getting started

### Requirements

- PHP 7.3+ or 8.x with the extensions listed under [Technology stack](#technology-stack)
- Composer 2
- Node.js 18 or later (developed with Node 24) and npm
- MySQL or MariaDB

### 1. Get the code

```bash
git clone <repository-url> workmate
cd workmate
```

### 2. Install dependencies

```bash
composer install
npm ci          # uses package-lock.json; use `npm install` when changing dependencies
```

### 3. Configure the environment

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` and set at least `APP_URL`, the `DB_*` values and the mail settings (see [Environment variables](#environment-variables)).

### 4. Create the databases

Create two empty databases: one for development and one used **only** by the tests.

```sql
CREATE DATABASE workmate      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE workmate_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 5. Run migrations and seeders

```bash
php artisan migrate      # creates the tables
php artisan db:seed      # roles, permissions, categories and areas (+ sample marketplace data when APP_ENV=local)
```

### 6. Start the app

Run these in two terminals:

```bash
npm run dev              # Vite dev server with hot reload
php artisan serve        # Laravel at http://127.0.0.1:8000
```

Open <http://127.0.0.1:8000>.

### 7. Make yourself an administrator

Register on the site, then grant your account a role:

```bash
php artisan workmate:role you@example.com super_admin
```

The admin area is at <http://127.0.0.1:8000/admin>.

**Notes**
- `php artisan storage:link` is **not needed**. Profile photos are kept in `storage/app/avatars` and served through the API.
- `php artisan serve` handles one request at a time. On Windows especially, a page can occasionally wait several seconds for an idle browser connection to close. Production servers don't have this limitation.

---

## Development commands

### Backend

| Command | What it does |
|---|---|
| `php artisan serve` | Runs the app locally at `http://127.0.0.1:8000` |
| `php artisan migrate` | Applies new migrations |
| `php artisan migrate:status` | Lists which migrations have run |
| `php artisan migrate:rollback` | Undoes the last batch of migrations (development only) |
| `php artisan db:seed` | Runs all seeders (safe to re-run) |
| `php artisan db:seed --class=SampleMarketplaceSeeder` | Adds the sample providers, reviews and jobs |
| `php artisan workmate:role {email} {role}` | Grants an admin role to an existing account |
| `php artisan workmate:role {email} {role} --revoke` | Removes it (the last active Super Administrator can't be removed) |
| `php artisan workmate:mail-test {email}` | Sends a test email to check the mail settings (shows the mailer and sender, never the password) |
| `php artisan workmate:push-keys` | Creates the VAPID keys for push notifications and prints them for `.env` (asks before replacing existing keys) |
| `php artisan route:list --path=api` | Lists the API routes |
| `php artisan test` | Runs the test suite on `workmate_test` |

### Frontend

| Command | What it does |
|---|---|
| `npm run dev` | Starts Vite with hot reload (writes `public/hot`) |
| `npm run build` | Builds production assets into `public/build` |
| `npm run preview` | Serves the built assets for a quick check |

### Production optimisation

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan config:cache
php artisan view:cache
```

`php artisan route:cache` will **fail** for now, because two routes still use closures: the web catch-all and the legacy `/api/user`. Move them into controllers before enabling route caching.

---

## Testing

- **85 tests** in `tests/Feature/Api` and `tests/Unit`. They cover accounts, permissions, provider applications, the marketplace, messaging, photos, admin data and actions, settings, the audit log, and the server and frontend permission lists staying identical.
- Tests use `RefreshDatabase`, which **rebuilds the database on every run**. `phpunit.xml` therefore points them at the separate **`workmate_test`** database (`<server name="DB_DATABASE" value="workmate_test"/>`). Never point it at a database whose data you need.
- Mail, cache, sessions and queues use in-memory drivers during tests.

```bash
php artisan test                         # all tests
php artisan test --filter=MarketplaceTest
vendor/bin/phpunit                       # same suite through PHPUnit directly
```

---

## Environment variables

Placeholders only: use your own values.

```env
APP_NAME=WorkMate
APP_ENV=local                 # "production" on live servers (sample data is never seeded there)
APP_KEY=                      # set by php artisan key:generate
APP_DEBUG=true                # must be false in production
APP_URL=http://127.0.0.1:8000 # used to build links in emails

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=workmate
DB_USERNAME=your_database_user
DB_PASSWORD=your_database_password

# Website sign-in: domains (with port) the Vue app is served from
# SANCTUM_STATEFUL_DOMAINS=127.0.0.1:8000,localhost:8000

# Mail: needed for email confirmation and password reset.
# For local development without a mail server, "log" writes emails to storage/logs/laravel.log
MAIL_MAILER=log
MAIL_HOST=your_smtp_host
MAIL_PORT=587
MAIL_USERNAME=your_smtp_user
MAIL_PASSWORD=your_smtp_password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=no-reply@your-domain
MAIL_FROM_NAME="${APP_NAME}"

QUEUE_CONNECTION=sync
SESSION_DRIVER=file
```

**Notes**
- Until mail is configured, people can register but can't confirm their email, and a confirmed email is required before sending a provider application.
- `.env.example` also contains Laravel's default Redis, AWS and Pusher keys; WorkMate doesn't use them yet.
- Push notifications need `VAPID_PUBLIC_KEY` and `VAPID_PRIVATE_KEY`. Create them once with `php artisan workmate:push-keys` (it prints them; nothing is saved) and keep the same keys afterwards: new keys turn notifications off on every device. On Windows, push messages can only be encrypted when PHP starts with `OPENSSL_CONF` set to PHP's bundled config (for XAMPP, `C:/xampp/php/extras/ssl/openssl.cnf`); Linux servers need nothing extra.
- Payment and SMS keys will be added when those integrations are built. Keep every secret in `.env` or the server's secret store, never in Git.

---

## Project structure

```text
workmate/
├── app/
│   ├── Console/Commands/          # workmate:role
│   ├── Exceptions/                # JSON error handling, BusinessRuleException
│   ├── Http/
│   │   ├── Controllers/Api/V1/    # Public, Auth/, Admin/ controllers
│   │   ├── Middleware/            # EnsureAccountIsActive, Authenticate, ...
│   │   ├── Requests/              # Form requests (register, profile, provider details)
│   │   └── Resources/             # API response shapes
│   ├── Listeners/                 # Verification email sender (tolerates mail failures)
│   ├── Models/                    # 17 Eloquent models
│   ├── Providers/                 # Gates, rate limiters, event listeners
│   ├── Services/                  # Business logic
│   └── Support/                   # PhoneNumber normalisation
├── config/
│   ├── permissions.php            # Admin roles and permissions (source of truth)
│   └── workmate.php               # Allowed platform settings and defaults
├── database/
│   ├── migrations/
│   └── seeders/                   # + data/areas.json, data/sample-marketplace.json
├── public/                        # Web root (build/ holds compiled assets)
├── resources/
│   ├── css/app.css                # Tailwind v4 theme and glass utilities
│   ├── js/
│   │   ├── admin/                 # Admin area: layouts, components, modules, pages, data
│   │   ├── api/client.js          # API client
│   │   ├── components/            # Site components
│   │   ├── data/                  # Remaining frontend-only data (verification prototype)
│   │   ├── pages/                 # Site pages
│   │   ├── router/                # Vue Router and guards
│   │   ├── stores/                # Shared reactive state
│   │   └── utils/                 # Search, trust, formatting helpers
│   └── views/app.blade.php        # The single page that loads the Vue app
├── routes/
│   ├── api.php                    # /api/v1
│   └── web.php                    # Catch-all for the Vue app (excludes /api)
├── storage/app/avatars/           # Profile photos (not public; served via the API)
└── tests/
    ├── Feature/Api/
    └── Unit/
```

`resources/js/bootstrap.js` and `webpack.mix.js` are unused leftovers from Laravel's default setup. They are kept until their removal is agreed.

---

## Deployment and CI/CD

> **Not configured yet.** The project has no Git repository history and no CI workflows (no `.github/workflows`). This section describes the intended setup.

```text
Development
     ↓
GitHub (pull requests)
     ↓
Automated tests (php artisan test on a disposable test database, npm run build)
     ↓
Deployment
     ↓
Production server
     ↓
php artisan migrate --force
```

Principles:

- **Application code lives in Git; secrets don't.** `.env` is ignored by Git (`.gitignore`), and production credentials live only on the server or in the hosting platform's secret store.
- **Schema changes go through migrations only.** No manual edits to production tables.
- **Production migrations** run non-interactively with:

  ```bash
  php artisan migrate --force
  ```

- Take a database backup before every production migration.
- Run `npm run build` and `php artisan config:cache` as part of each release; set `APP_ENV=production` and `APP_DEBUG=false`.
- Serve with Nginx or Apache and PHP-FPM, over HTTPS, rather than `php artisan serve`.
- Once queues or scheduled jobs exist, run a queue worker under a process manager and the Laravel scheduler from cron.

> ⚠️ **Never run destructive database commands on production**, including:
>
> ```bash
> php artisan migrate:fresh     # drops every table
> php artisan migrate:refresh   # rolls back and re-runs everything
> php artisan migrate:reset     # rolls back everything
> php artisan db:wipe           # drops everything
> ```
>
> They delete real customer, provider and financial data. They're for local and test databases only.

---

## Future mobile applications

⬜ **Planned architecture.**

The Laravel API is meant to be the single backend for:

- the current Vue website
- a Progressive Web App
- future Android and iOS apps

The mobile apps will use the **same `/api/v1` endpoints**, so the marketplace backend won't need rebuilding. The pieces already in place:

- one JSON response and error format
- server-side validation and permission checks on every endpoint
- Sanctum's `personal_access_tokens` table for token-based sign-in, which native apps need
- clean, documented status codes

Token sign-in endpoints for apps are not built yet; the website uses cookie sessions.

---

## Roadmap

### Foundation
- [x] Laravel API under `/api/v1` with one response and error format
- [x] Roles, permissions and audit log
- [x] MariaDB development and test databases
- [x] Platform settings allow-list
- [ ] Git repository and CI pipeline

### Authentication
- [x] Registration, sign-in, sign-out (Sanctum SPA cookies)
- [x] Email confirmation, password reset and change
- [x] Rate limiting and account suspension
- [ ] Token sign-in for mobile apps
- [ ] Two-factor sign-in for administrators

### Provider capabilities
- [x] Apply from the same account (individual, group or company)
- [x] Several professions per provider
- [x] Review, approval, rejection, more-information and suspension workflow
- [x] Public listing details and service areas
- [x] Profile photos
- [ ] Group and company member management

### Marketplace
- [x] Categories, professions and areas from the database
- [x] Provider directory with ratings calculated from reviews
- [x] Post a job, request a provider, apply for a job
- [x] Messaging between customers and providers
- [x] Reports about providers
- [ ] Customers view and accept applications
- [ ] Provider inbox for direct requests
- [ ] Saved providers and locations
- [ ] Admin management of categories, professions and areas

### Location matching
- [x] Device location (with permission) and manual area selection
- [x] Distance ranking and widening in the browser
- [ ] Server-side matching with indexed distance queries
- [ ] Customer search reading the admin-configured radii

### Bookings and reviews
- [ ] Bookings and status tracking
- [ ] Booking history
- [ ] Customer reviews after completed bookings

### Verification and the tick
- [x] Single grey/blue tick on profiles
- [ ] Document uploads to private storage
- [ ] Profession-specific, versioned requirements on the server
- [ ] Admin document review
- [ ] Automatic performance evaluation

### Payments
- [ ] Mobile money (sandbox first), commission, refunds, payouts, reconciliation

### Notifications
- [ ] In-app notifications
- [ ] Email notifications
- [x] Browser push (Web Push): admin announcements
- [ ] Automatic push for messages, requests and application decisions

### Administration
- [x] Sign-in protected, permission-based admin area on live data
- [x] Connected moderation and account actions
- [ ] Finance, disputes, content and communication modules
- [ ] Usage analytics and system health

### Mobile and PWA
- [ ] Progressive Web App
- [ ] Android and iOS apps

### Production readiness
- [ ] Hosting, HTTPS and PHP 8.1+
- [ ] Queue worker and scheduler
- [ ] Backups and monitoring
- [ ] Security and performance review

---

## Contributing

1. Create a branch from the main branch (`feature/short-description` or `fix/short-description`).
2. Make focused changes that follow the existing conventions:
   - business rules in services, thin controllers, responses through API Resources
   - `<script setup>` components with the existing Tailwind tokens and components
   - new permissions added to both `config/permissions.php` and `resources/js/admin/permissions.js`
3. Add or update tests, then run `php artisan test` and `npm run build`.
4. Commit with a clear message describing what changed and why.
5. Open a pull request explaining the change and how it was tested.

`.env`, `vendor/`, `node_modules/`, compiled assets in `public/build/` and uploaded files in `storage/app/` are ignored by Git. Build assets on the server with `npm run build`.

---

## Security

- Never commit secrets. Keep `.env` private and use separate credentials for each environment.
- Verification documents, once built, must use **private storage** with access limited to authorised reviewers and every view logged.
- Treat sample data as development-only. It is never seeded when `APP_ENV` isn't `local`.
- If you find a vulnerability, report it privately to the ResNet Systems team rather than in a public issue, and give them time to fix it before disclosure. A dedicated security contact hasn't been set up yet.

---

## Licence

No licence has been chosen yet. `composer.json` still says `MIT`, inherited from Laravel's project template, but no `LICENSE` file has been added. Until ResNet Systems publishes one, treat the code as proprietary.

---

**WorkMate**, developed by **ResNet Systems**.
