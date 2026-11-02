# WorkMate

![Laravel](https://img.shields.io/badge/Laravel-8.83-FF2D20?logo=laravel&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-7.4-777BB4?logo=php&logoColor=white)
![Vue](https://img.shields.io/badge/Vue-3.5-42B883?logo=vuedotjs&logoColor=white)
![Vite](https://img.shields.io/badge/Vite-6-646CFF?logo=vite&logoColor=white)
![Tailwind CSS](https://img.shields.io/badge/Tailwind%20CSS-4-06B6D4?logo=tailwindcss&logoColor=white)
![Status](https://img.shields.io/badge/status-live%2C%20in%20development-F59E0B)

**WorkMate is a location-based service marketplace for Uganda, starting in Kampala.** It helps people find nearby plumbers, electricians, cleaners, mechanics, tailors, hairdressers, drivers and other local professionals, and gives those professionals a place to be found, receive requests and grow their work.

**Live at <https://wm.resnetsystems.com>.** Every push to `main` deploys there automatically (see [Deployment](#deployment-and-cicd)).

> **One person. One account. Multiple ways to use the platform.**
> Everyone registers once and can hire people straight away. The same account can apply to offer services, and an approved provider keeps every customer capability.

**Developed by ResNet Systems.** AI assistants and contributors must follow [`AGENTS.md`](AGENTS.md) before changing anything.

---

## Contents

- [How it works](#how-it-works)
- [Project status](#project-status)
- [Features](#features)
- [User types and the account model](#user-types-and-the-account-model)
- [Location-based matching](#location-based-matching)
- [Requests, completion and ratings](#requests-completion-and-ratings)
- [Reports and complaints](#reports-and-complaints)
- [Provider verification and the tick](#provider-verification-and-the-tick)
- [Installable app and push notifications](#installable-app-and-push-notifications)
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

1. A customer tells WorkMate what they need (for example "plumber") and where they are, by sharing their device location or by typing any place in Uganda, such as Kyebando or Gulu.
2. WorkMate shows providers closest first, widening the search when there are too few nearby. Providers offering the skill as their **main** skill come first.
3. The customer compares profiles, ratings and starting prices, then messages a provider, sends them a request, or posts a job that any suitable provider can apply for.
4. The provider sees the request under **My Work → Received**, accepts or declines it, and marks it done when the work is finished.
5. The next time the customer opens the app, they're asked to rate the provider (optional; stars are required if they do).
6. Providers manage their profile, skills, places they serve and availability from the same account they use as a customer, and upload documents to get the grey tick.
7. Administrators review applications and documents, handle reports and moderate the marketplace from a role-based admin area.

---

## Project status

WorkMate is **live and in active development**. It started as a Vue prototype on mock data and has moved, phase by phase, to a Laravel-powered marketplace on a production server.

The customer website, the installable app and the admin area all read from and write to the database. Sample providers, reviews and jobs exist only on local development databases. **Bookings and payments are not built yet.**

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
- Email address confirmation by signed link (valid 24 hours), with WorkMate-branded emails
- Forgotten password and reset by email link; password change from the account page
- Profile: name, phone number (Ugandan numbers are stored in one standard form, `+256…`), language preference (English or Luganda), and a "your area" place
- Profile photo upload and removal. Photos are cropped to a square and re-encoded, which removes hidden data such as GPS location.

**Becoming a provider (same account)**
- Apply as an **individual**, **group** or **company**
- Choose up to **5 skills** from **22 categories and about 80 professions**; the first is the **main** skill
- A skill that isn't listed can be typed under **Other**. Customers can't find anyone by it until an administrator adds it to the list, matches it to an existing profession or declines it
- **Where you're based** is required before sending the application; other places they travel to are added by typing
- Public listing details: starting price, price basis, availability, usual hours
- Application lifecycle with administrator review (see [statuses](#service-providers))
- A provider viewing their own profile sees "This is your profile" with their own tools, not request, message or report buttons

**Places and search**
- **Every populated place in Uganda** (about 11,300 towns, trading centres, villages and suburbs, from GeoNames) with its district, plus 20 popular quick picks
- One **type-to-search place box** used everywhere: search, provider details, posting a job, requesting a service, filters and the account page
- Nearby search with a radar-style animation, using device location or a chosen place (see [Location-based matching](#location-based-matching))
- **Main skill first:** in an area, someone offering a skill as one of their other skills is shown only when nobody based there has it as their main skill

**Marketplace**
- Provider directory with profiles, skills, starting prices, availability and reviews
- **Post a job** for any provider to apply to; **apply for a job** with a message, start time and optional price (approved providers only)
- **Hire from applicants:** the customer compares applicants (rating, main skill, price, start time, message) and hires one; the others are told the job is filled. The job then works like a request: the provider marks it done and the customer rates them
- **Request a specific provider** directly
- **Received requests** for providers: accept, decline with a reason, mark as done, message the customer
- **Ratings and reviews** written by customers after a provider finishes their request; ratings on profiles are always calculated from published reviews
- **My Work:** received requests (providers), sent requests, posted jobs and applications
- **Messages** between customers and providers, visible only to the two sides of the chat
- **Reports and complaints** (see [Reports and complaints](#reports-and-complaints)): customers and providers report each other, or anything else that went wrong, with up to 3 photos, and follow the outcome

**Verification**
- **Get verified:** National ID (front and back) plus up to 5 supporting documents, stored privately; reviewers accept or send back each document and can ask for further documents; approval gives the **grey tick**

**Installable app (PWA)**
- Install prompt on Android and desktop, instructions on iPhone, an offline page, and automatic updates
- Device-by-device **push notifications**, with administrator announcements
- Install and first-launch tracking for the admin's Downloads and Installs page

**Administration**
- Sign-in protected admin area with **9 roles** and **24 permissions**
- Live data for customers, providers, applications, typed professions, verification, jobs, quotations, reviews, reports, area coverage, installs, push announcements, admin users, the activity feed and global search
- Connected actions (see [Administration](#administration)), each requiring a reason
- Append-only **audit log** of every important action, with who, when and why
- Platform settings for search radii and online-presence timings

**Quality**
- **154 automated tests** (feature and unit) on a separate MariaDB test database

### 🟡 In development

| Area | What exists | What's missing |
|---|---|---|
| Location matching | Distance ranking and widening run in the browser, from every place in Uganda | Server-side matching. The search still uses fixed radii (5 km / 10 km) in `resources/js/utils/nearbySearch.js` rather than the admin-editable settings |
| Places | GeoNames places loaded automatically, with districts | A few small places are missing from GeoNames (e.g. Najjera); there is no admin screen yet to add a place by hand |
| Document requirements | Real document checks (ID + supporting + requested documents) | The per-profession Document Requirements admin screen is still a browser-only prototype |
| Performance tick | Tick display and admin rules screen | Automatic evaluation isn't running; only sample providers carry a blue tick |
| Push notifications | Per-device opt-in, a reminder pop-up, administrator announcements, and automatic notifications for requests, replies, messages and decisions | No in-app notification list yet, and no per-type choices (one switch covers all) |
| Email | Branded confirmation and reset emails | Delivery depends on the mail settings in `.env`; check with `php artisan workmate:mail-test` |
| Languages | Language preference is stored | The interface is English only |
| Provider teams | `provider_members` table; every rule already checks membership, not only ownership | Adding and managing group or company members |
| Photos on requests | A photo picker on the request form | Photos aren't uploaded (the form says so) |

### ⬜ Planned

- Bookings: payment-linked booking status tracking and booking history
- Payments with Ugandan mobile money (MTN MoMo, Airtel Money), commission, refunds, payouts and reconciliation
- In-app and email notifications
- Saved providers and saved locations
- Admin management of categories, professions and places
- Real-time messaging (the chat currently checks for new messages every few seconds)
- "WorkMate in Action" short provider videos with moderation
- Luganda interface translations
- Android and iOS apps on the same API

---

## User types and the account model

### Regular users

Every account starts as a customer. There is no "customer or provider?" question at sign-up. A customer can:

- browse services and search for nearby providers
- view provider profiles, ratings and reviews
- message providers, send requests and post jobs
- track their requests, jobs and applications in **My Work**
- rate providers who finished work for them
- manage their profile, photo, password, email confirmation and notifications

### Service providers

A customer applies to offer services from **Account → Offer your services**. Provider capabilities attach to the **same account**: an approved provider can still search for and hire other providers, but never themselves.

Provider application statuses (implemented):

| Status | Meaning |
|---|---|
| Not applied | No provider details saved yet |
| Draft | Details saved but not sent for review |
| Pending review | Sent and waiting for an administrator |
| More information required | An administrator asked for changes; the applicant edits and resends |
| Approved | Listed publicly, can receive requests and apply for jobs |
| Rejected | Not approved; the applicant sees the reason and can start a new application |
| Suspended | Listing removed by an administrator; the customer account keeps working |

Before sending, the email address must be confirmed and **Where you're based** chosen. An administrator can't review an application, typed profession or document for a provider they belong to.

Document verification (the grey tick) is a separate step with its own statuses: draft, pending review, under review, more information needed, approved, rejected.

### Administrators

Administrators are ordinary accounts that hold one or more admin roles. Roles are granted with an artisan command or by a Super Administrator in the admin area. Every request is checked against the account's permissions on the server; hiding menus in Vue is only for convenience. See [Administration](#administration).

---

## Location-based matching

The intended matching order is:

1. providers closest to the user's current location
2. providers within the selected place
3. providers in the wider town or district
4. providers from nearby places, or those who list the place as one they travel to, when local results are too few

**What works today ✅ (in the browser)**

- **Any place in Uganda.** Typing two letters suggests places with their district ("Kyebando, Kampala"), closest to the user first. Before typing, 20 popular places are offered. Places are identified by a unique id, because names repeat across districts.
- **Device location with permission.** The browser's Geolocation API is used only when the user taps "Use my location". The position stays in memory for that visit; it is sent to the server once, rounded, to name the nearest place ("near Kisaasi"), and is never stored.
- **Distance ranking.** Straight-line distance from the user (or the chosen place) to each provider's registered base.
- **Widening the search** in three groups: *nearby* (within 5 km), *more around the town* (within 10 km or based in the same district), and *providers serving your area* (further away, but listing the place among those they travel to).
- **"Near" filters.** Browse and Find work show providers and jobs within 10 km of the chosen place, plus providers who travel there.
- **Main skill first** (see [Features](#features)).

**Privacy:** a provider's base is a place's centre point, never a live position. The public API rounds coordinates to about 1 km.

**Data source:** place names and positions come from [GeoNames](https://www.geonames.org/) (CC BY 4.0), credited in the site footer. `database/seeders/data/places-ug.csv` is a trimmed copy loaded by a migration.

**Still to do 🟡 / ⬜**

- Move matching to the server (indexed distance queries, paging) so it scales beyond the current provider count
- Use the admin-configured radii in the customer search
- Live provider availability and online presence

---

## Requests, completion and ratings

A request sent to one provider goes through these steps (implemented ✅):

| Step | Who | What happens |
|---|---|---|
| Sent | Customer | Request appears in the provider's **My Work → Received** with a "New request" badge |
| Accepted / Declined | Provider | Accepting marks it in progress; declining needs a reason, which the customer sees |
| Done | Provider | **Mark as done** completes the request |
| Rated | Customer | The next time the customer opens the app (or returns to it), a pop-up asks them to rate the provider |

**Rating rules**
- Stars (1–5) are required to post; a message is optional.
- The pop-up can be skipped with **Continue to WorkMate**. A skipped job isn't asked about again, but stays rateable from My Work (**Rate this job**).
- Only the customer who sent that request can rate it, once, and only after the provider finished it. Nobody can request, message, report or rate their own provider business.
- Reviews are published under the customer's short name ("Aisha N.") and can be hidden by administrators with content permissions.

The provider sees the customer's name and photo, never their email or phone number; they reply through WorkMate's messages.

---

## Reports and complaints

Implemented ✅. A report is an allegation until the safety team acts on it: it never penalises anyone by itself, and the reported person is never told who reported them.

**Raising one**
- **About a provider:** from their profile, a chat, or a request or hired job on My Work.
- **About a customer:** a provider reports a customer from a request they received or a chat with them. A provider can only report customers they've dealt with.
- **Something else:** a payment problem, a safety concern or a problem with WorkMate, from **Account → Reports and complaints**.
- Each has its own reasons, a required description and up to **3 photos** (JPG or PNG, re-encoded and stored privately under `storage/app/reports`).

**Following it** (Account → Reports and complaints): status (Received, Being looked at, Your answer needed, Resolved, Closed), the safety team's question and the reporter's answer, and WorkMate's reply. Warnings sent to the account appear here as **Notices from WorkMate**, never naming the reporter. Push notifications announce a question, an outcome or a notice without including their text.

**Working it** (admin → Trust and Safety → Reports and Abuse, `trust.view`): take the report, ask the reporter a question, add internal notes, open evidence photos (each opening audited), and resolve with an outcome that is carried out straight away: no violation, resolved, **warning** to the reported person, **suspend the provider listing**, or **suspend the account**. Nobody can handle a report they made or one about a provider they belong to. Every step is in the audit log.

**Data:** `reports` and `account_warnings`. Reports made before this existed were copied from `provider_reports`, which is kept unchanged until its removal is approved.

---

## Provider verification and the tick

Providers show **one tick**. It is grey once their documents are approved, and turns blue when WorkMate's rules qualify them for strong performance. A provider never shows two badges, and the site doesn't explain the tick to customers.

### Standard verification (document checks) ✅

1. The provider adds their **National ID** (front and back, required) and up to **5 supporting documents** of their choice (Account → Get verified). Files are JPG, PNG or PDF, up to 8 MB, up to 4 pages each.
2. They send them for review (email must be confirmed).
3. A reviewer (`documents.review`) accepts each document or sends it back with a reason, and can **ask for further documents** for this applicant, for example a permit their profession needs.
4. The provider replaces or adds what was asked and presses **Send my changes for review**.
5. The reviewer approves (every document accepted, nothing outstanding) or rejects. Approval sets `document_verified_at`, which shows the grey tick.

Files are stored on the private `local` disk under `storage/app/verification`. Photos are re-drawn as JPEG (hidden data such as GPS removed); PDFs are kept as uploaded. Files are served only to their owner and to administrators with `documents.view`, never cached, and every administrator opening is written to the audit log. Replaced documents are kept as a record. Reopening a decision needs `verification.configure`.

**Still a prototype:** the per-profession Document Requirements screen in the admin keeps its changes in the browser only.

### Performance tick ⬜

The blue tick is separate from document checks and is awarded **automatically**. It can't be bought or applied for. Intended criteria include completed jobs, ratings, response and completion rates, account standing and the absence of confirmed serious violations. Unproven allegations don't count against a provider.

**Current state:** the rules screen is a frontend prototype and no evaluation runs.

---

## Installable app and push notifications

**Progressive Web App ✅**
- `public/manifest.webmanifest` and the service worker `public/sw.js` (registered only in production builds, not with `npm run dev`).
- An install banner and button on Android and desktop; step-by-step instructions on iPhone.
- An offline page, and automatic updates: a new deploy is picked up the next time the app is opened.
- Anonymous install tracking (prompt shown, installed, first launch per device) feeds **Usage → Downloads and Installs** in the admin.

**Push notifications ✅ / 🟡**
- People turn on notifications per device under **Account → Notifications**, or from a reminder pop-up (at most weekly, three times per device). iPhones receive them only when WorkMate is installed on the home screen (iOS 16.4+).
- **Automatic notifications** (`EventNotifications`): a provider hears about new requests; a customer hears when their request is accepted, declined or done (with a prompt to rate) and when someone applies to their posted job; both sides hear about new chat messages; applicants hear decisions on their provider application and documents. They never include message text, reasons or contact details, and the person who caused the event isn't notified.
- Administrators with `comms.send` send announcements from **Communications → Push Announcements**. Sending happens after the response, with no queue worker; devices that unsubscribed are removed automatically.
- Requires VAPID keys in `.env` (see [Environment variables](#environment-variables)). Without them the switch is hidden.

---

## Administration

The admin area lives at **`/admin`**. It requires sign-in with an admin role; other accounts are redirected with an explanation.

**What's in the sidebar (19 screens):**

| Section | Screens |
|---|---|
| Overview | Executive Dashboard; Activity and Audit Log (tabs: Activity, Audit Logs) |
| Marketplace | Customers, Service Providers, Provider Applications, Jobs and Requests, Job Applications, Typed Professions, Service Categories, Reviews and Ratings |
| Places | Places (tabs: By place, Provider Coverage) |
| Trust and Safety | Reports and Abuse |
| Verification Management | Applications, Document Requirements |
| Communications | Push Announcements |
| Usage | Downloads and Installs |
| Administration | Admin Users, Roles and Permissions, Settings (tabs: Platform, Matching Configuration) |

Screens for features that aren't built yet (finance, bookings, disputes, videos, content, notification templates and campaigns, search logs, presence, system monitoring, blue tick and more) are **hidden, not deleted**: they are marked `hidden` in `resources/js/admin/navigation.js`, still open from old links, and come back by removing the flag once the feature is built. A tabbed screen opens for anyone allowed one of its tabs, and only shows the tabs that role may open.

**Roles (9):** Super Administrator, Operations Administrator, Provider Review Administrator, Customer Support Agent, Finance Administrator, Trust and Safety Moderator, Content Moderator, Analytics Viewer, Technical Administrator. Permissions are defined in `config/permissions.php`. The admin frontend mirrors them in `resources/js/admin/permissions.js`, and a test keeps the two identical.

**What's live ✅**

- The executive dashboard and every records screen read from the database through `GET /api/v1/admin/data`. Each section is filtered to the administrator's permissions, and contact details are masked.
- **Connected actions:**
  - provider applications: approve, reject, request information
  - **typed professions** (Marketplace → Typed Professions): add to the list, match to an existing profession, decline
  - **verification** (Verification Management → Applications): open documents, accept or send back each one, ask for another document, request information, approve, reject, reopen, add notes
  - providers and accounts: suspend, reinstate, reactivate
  - reviews: hide or restore; reports: assign and resolve
  - **push announcements**: send to every subscribed device
  - admin roles: grant and remove
- Sample records are listed with "(sample)" after the name but left out of every figure.
- Anything not recorded yet (payments, bookings, disputes, searches) is shown as **Not tracked yet**, never as zero.
- Actions that call the API are listed in `resources/js/admin/modules/connected.js` (or call the API from their module or page). Other actions aren't offered; the record shows how many aren't connected.
- Every action needs a reason and is written to the audit log.
- The six search and presence settings are saved server-side; the other settings are marked "Not connected yet".
- Admin screens work with the popular places and the places in use, not all 11,300.

**Still a prototype in the sidebar ⬜:** Document Requirements (changes are kept in the browser only).

---

## Technology stack

### Backend

| Technology | Version | Use |
|---|---|---|
| PHP | 7.4 (code must stay PHP 7.4 compatible) | Runtime |
| Laravel | 8.83 | Framework |
| Laravel Sanctum | 2.x | Cookie-based sign-in for the website (SPA mode) |
| MariaDB | 10.4 | Database |
| minishlink/web-push | 7.0 | Signing and encrypting Web Push messages (VAPID) |
| PHPUnit | 9.6 | Tests |
| Guzzle, Laravel Tinker, fruitcake/laravel-cors | 7.x, 2.x, 2.x | Laravel defaults |

PHP extensions used: `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, and **`gd` with JPEG and PNG support** (profile photos and verification documents). `exif` is optional; it straightens sideways phone photos.

### Frontend

| Technology | Version | Use |
|---|---|---|
| Vue | 3.5 | UI (`<script setup>` single-file components) |
| Vue Router | 4 | Client-side routing (history mode) |
| Vite | 6 | Dev server and build, via `laravel-vite-plugin` |
| Tailwind CSS | 4 (`@tailwindcss/vite`) | Styling (design tokens in `@theme`) |
| Bootstrap Icons | 1.11.3 (CDN) | Icons |
| Inter | Google Fonts | Typeface |

There is **no Axios, Pinia or Vuex**. API calls go through a small `fetch` wrapper, and shared state uses Vue reactive modules.

### Not used yet

Redis, queue workers (the queue runs `sync`), broadcasting and WebSockets, Swagger/OpenAPI, and automated test runs in CI (the GitHub workflow only deploys).

---

## Frontend architecture

The Vue app is mounted by a single Blade view (`resources/views/app.blade.php`). Laravel's catch-all web route sends every non-API URL to it, and Vue Router takes over.

| Area | Location | Notes |
|---|---|---|
| Entry point | `resources/js/app.js`, `App.vue` | Starts the session check and the PWA; picks the site or admin shell; mounts the rating pop-up |
| Router | `resources/js/router/index.js` | Lazy admin routes; guards for sign-in-only pages and the admin area |
| API client | `resources/js/api/client.js` | `fetch` wrapper: session cookie, CSRF header, one error format (`ApiError`), file uploads |
| State | `resources/js/stores/` | `auth` (account, own provider ids), `catalog` (categories, known places, providers, reviews, jobs; place search), `location`, `nearbySearch`, `messages`, `reviews` (rating pop-up), `push`, `pwa`, `verification` (requirements prototype) |
| Site pages | `resources/js/pages/` | Home, Browse, Explore, provider profile, request a service, Post a job, Find work, My Work, Messages, Get verified, Account, sign-in and password pages |
| Components | `resources/js/components/` | `PlacePicker` (type-to-search places), the location sheet and radar, the provider application panel, `RateProviderDialog`, push and install controls, the photo editor, the tick badge |
| Utilities | `resources/js/utils/` | Nearby search and main-skill ranking, trust and tick helpers, formatting |
| Admin | `resources/js/admin/` | Its own layout, sidebar and top bar; definition-driven modules (`modules/*.js`) rendered by shared workspaces (records, analytics, settings, roles); SVG charts with no chart library |

**Design.** A blue, light-blue and navy palette with a "liquid glass" look: translucent header and cards on a soft blue background. The glass falls back to solid surfaces where the browser lacks `backdrop-filter` or the user prefers reduced transparency. The admin area keeps blue tones but uses solid cards so data stays easy to read. Green is reserved for status (success), not branding.

**Responsive behaviour.** Mobile-first: a bottom tab bar (Home, Providers, Messages, My Work, Account) on phones with 16 px side gutters and no sideways scrolling; two-column grids on tablets; a top navigation bar, three-column grids and a collapsible admin sidebar on desktop.

**Accessibility.** Skip links, visible focus rings, labelled form errors that receive focus, live regions for status messages, keyboard-usable place search (combobox), reduced-motion support, and data tables behind charts.

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
| Controllers | `app/Http/Controllers/Api/V1/` | `Auth/` (sessions, passwords, email confirmation), directory and place search, jobs, received requests, reviews, messages, provider application, verification documents, push subscriptions, app events, photos, reports; `Admin/` (data, applications, typed professions, document verification, push announcements, provider standing, roles, settings, audit log, actions) |
| Services | `app/Services/` | `Accounts`, `ProviderApplications` (application lifecycle), `ProfessionSuggestions`, `DocumentVerifications`, `Marketplace` (jobs, requests, replies), `Reviews`, `Messaging`, `PushNotifications`, `ProfilePhotos`, `RoleAssignment`, `AdminActions`, `AdminSnapshot`, `PlatformSettings`, `AuditLogger` |
| Jobs | `app/Jobs/SendPushAnnouncement.php` | Sent after the response (`dispatchAfterResponse`), so no queue worker is needed |
| Validation | `app/Http/Requests/` and controller `validate()` calls | Phone normalisation, password rules (8+ characters, letters and numbers), allow-listed settings, file types and sizes |
| Middleware | `app/Http/Middleware/EnsureAccountIsActive.php` (`active`) | Ends the session of a suspended or deactivated account on its next request |
| Authorisation | `app/Providers/AuthServiceProvider.php` | Every permission key is a Gate ability; plus `act-as-provider` and `admin-access` |
| Errors | `app/Exceptions/Handler.php` | One JSON error format for `/api/*`; no SQL or stack traces in responses |
| Console | `app/Console/Commands/` | `workmate:role`, `workmate:mail-test`, `workmate:push-keys` |
| Configuration | `config/permissions.php`, `config/workmate.php`, `config/services.php` (`webpush`) | Roles and permissions; allowed platform settings; VAPID keys |

---

## Database

### Implemented tables

| Table | Purpose |
|---|---|
| `users` | Every account. Adds phone, locale, status (`active` / `suspended` / `deactivated`), photo path, last seen, soft deletes and `is_sample` to Laravel's default table |
| `password_resets`, `personal_access_tokens`, `failed_jobs` | Laravel defaults: reset tokens, Sanctum tokens (for future mobile apps), failed queue jobs |
| `roles`, `permissions`, `permission_role`, `role_user` | Admin roles and permissions (`role_user` records who granted each role) |
| `audit_logs` | Append-only record of actions: actor, action, subject, before and after values, reason, IP. Sensitive values are redacted |
| `platform_settings` | Settings changed from their defaults (allowed keys are defined in `config/workmate.php`) |
| `categories`, `professions` | 22 service categories and the professions within them |
| `profession_suggestions` | Professions applicants typed under "Other", and the administrator's decision |
| `areas` | Every place in Uganda with its district and centre point; `is_featured` marks the 20 quick picks, `geonames_id` the loaded places |
| `provider_profiles` | Provider capabilities for an account: type, display name, status, base place, listing details, verification and tick dates, `is_sample` |
| `provider_members` | People linked to a provider profile (currently the owner) |
| `provider_professions` | A provider's professions (up to 5, one main) |
| `provider_service_areas` | Places a provider travels to |
| `provider_applications` | Each review round, with a snapshot of what was submitted, the decision, the reason shown to the applicant and internal notes |
| `document_verifications`, `verification_documents`, `document_requests` | A provider's document check, its private files, and extra documents reviewers asked for |
| `service_requests` | Posted jobs (`is_posted_job`; the provider is set once someone is hired) and requests to one provider, with the provider's reply (`responded_at`, `provider_note`) and when the customer was asked to rate (`review_prompted_at`) |
| `quotations` | A provider's application or price for a posted job (one per provider per job) |
| `reviews` | Ratings and comments, linked to the request they're about (`service_request_id`, one review per request); status for moderation |
| `conversations`, `messages` | Customer–provider chats and their messages, with read status |
| `reports` | Reports and complaints: reporter and side, who or what it's about, linked request or chat, reason, details, private photos, severity, status, the question to the reporter and their answer, outcome and the message to the reporter |
| `account_warnings` | Warnings the safety team sent an account, with when they were read |
| `provider_reports` | Earlier reports about providers, copied into `reports`; kept unchanged until removal is approved |
| `push_subscriptions`, `push_announcements` | Devices that turned on notifications, and announcements sent with their delivery counts |
| `app_install_events` | Anonymous install prompts, installs and first launches per device and day |

**Key relationships**
- A user **owns at most one** provider profile (`provider_profiles.owner_id`), may belong to providers through `provider_members`, and holds roles through `role_user`.
- A provider profile has many professions (each in a category), places it travels to, applications, reviews, members and one document verification.
- A service request belongs to a customer and a category, and optionally to one provider (a direct request). It has many quotations and at most one review.
- A conversation joins one customer and one provider profile and has many messages.

Migrations are in `database/migrations/`. They are **forward-only on production** (see `AGENTS.md`): never edit a migration that has been pushed; add a new one. Every migration still has a working `down()`. Deletion rules preserve history (`restrict` / `nullOnDelete` where records must survive), and accounts and requests use soft deletes.

### Seeders

| Seeder | Adds | When |
|---|---|---|
| `RolesAndPermissionsSeeder` | 24 permissions and 9 roles from `config/permissions.php` | Always (safe to re-run) |
| `CatalogueSeeder` | The original 10 categories (all 22 categories and about 80 professions are added by a migration) | Always (keeps administrator edits) |
| `AreaSeeder` | The 20 popular quick-pick places (`database/seeders/data/areas.json`); every other Uganda place comes from `data/places-ug.csv` through a migration | Always |
| `SampleMarketplaceSeeder` | 14 invented providers, 24 reviews and 7 open jobs (`data/sample-marketplace.json`), all marked `is_sample` | **Only when `APP_ENV=local`**, and only once. Never on production |

Sample accounts use the reserved `@workmate.invalid` domain and random unknown passwords, so nobody can sign into them.

### Planned data architecture ⬜

Not yet in the schema: bookings and booking event history; versioned document requirements per profession; performance-tick rules, evaluations and award history; payments, ledger entries, commissions, refunds and payouts; in-app notifications and notification preferences; provider videos and moderation; saved providers and saved locations.

---

## Authentication and security

- **Method:** Laravel Sanctum in **SPA cookie mode**. The website first calls `GET /sanctum/csrf-cookie`, then signs in with `POST /api/v1/auth/login`. The session cookie authenticates later requests, and changing requests carry the `X-XSRF-TOKEN` header. No tokens are kept in JavaScript or `localStorage`.
- **Stateful domains:** sessions only start for requests from domains in `SANCTUM_STATEFUL_DOMAINS`.
- **Registration and login:**
  - New accounts are signed in straight away and sent a confirmation email.
  - Suspended accounts can't sign in.
  - A wrong password always gets the same message, and the forgot-password form answers the same way whether or not an account exists, so neither reveals which emails are registered.
- **Passwords:** hashed with bcrypt; at least 8 characters with letters and numbers. Changing or resetting a password revokes API tokens.
- **Rate limits** (in `RouteServiceProvider`):
  - sign-in: 5 per minute per email and device, and 20 per minute per device
  - registration and emails: 6 per minute
  - jobs, requests, replies, ratings, applications, messages, reports, uploads and push subscriptions: 30 per minute
  - place search: 120 per minute; install events: 10 per minute
  - general API: 60 per minute
- **Authorisation and data isolation:**
  - Admin routes use `can:<permission>`, and an account must be active to hold any permission.
  - Provider actions check membership of that provider business; requests, chats and documents return "not found" to anyone outside them.
  - Nobody can request, message, report or rate their own provider business, or review their own application or documents as an administrator.
- **Responses:** API Resources decide every field. Passwords, tokens, file paths and internal fields are never returned, the admin area masks phone numbers and email addresses, providers never see a customer's contact details, and public provider locations are rounded.
- **Uploads:** profile photos and document images are type- and size-checked, re-encoded (removing GPS and other hidden data) and stored under random names on the private disk. Verification files are served only to their owner and permitted administrators, with `no-store` caching, and administrator views are audited.

> Configure your own `.env`. Never commit it, and never copy real credentials into documentation or issues.

---

## API

**Base URL:** `/api/v1`. Every endpoint is defined in `routes/api.php`; there is no Swagger/OpenAPI setup yet.

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

| Group | Access | Endpoints |
|---|---|---|
| Public | Anyone | `GET health`, `GET catalog` (everything the first page needs), `GET categories`, `GET areas` (quick picks and places in use), `GET areas/search?q=` (type-to-search), `GET areas/nearest`, `GET providers`, `GET providers/{id}`, `GET reviews/recent`, `GET jobs`, `GET jobs/{id}`, `GET avatars/{file}`, `GET push/config`, `POST app-events` |
| Auth | Guests (website session) | `POST auth/register`, `auth/login`, `auth/logout`, `auth/forgot-password`, `auth/reset-password`; `GET auth/verify-email/{id}/{hash}` (signed link) |
| Account | Signed in | `GET/PATCH me` (includes `provider_ids`), `PUT me/password`, `POST/DELETE me/photo`, `POST me/email/verification-notification`, `POST/DELETE me/push-subscriptions` |
| My work | Signed in | `GET me/requests`, `GET me/applications`, `GET me/jobs/{id}/applications`, `POST me/jobs/{id}/applications/{application}/hire`, `GET me/received-requests`, `POST me/received-requests/{id}/accept`, `…/decline`, `…/complete`, `…/message`, `GET me/reviews/waiting`, `POST me/requests/{id}/review`, `POST me/requests/{id}/review/later`, `GET me/reports`, `POST me/reports/{id}/reply`, `GET me/reports/{id}/files/{n}`, `GET me/warnings`, `POST me/warnings/{id}/read` |
| Provider application | Signed in | `GET/PUT me/provider`, `POST me/provider/submit`, `POST me/provider/reopen` |
| Verification | Signed in (provider) | `GET me/verification`, `POST me/verification/documents`, `POST me/verification/documents/{id}/replace`, `DELETE me/verification/documents/{id}`, `GET me/verification/documents/{id}/files/{n}`, `POST me/verification/submit` |
| Marketplace | Signed in | `POST jobs`, `POST jobs/{id}/applications` (approved providers), `POST providers/{id}/requests`, `POST reports` (and the older `POST providers/{id}/reports`) |
| Messages | Chat participants | `GET me/conversations`, `GET me/conversations/unread-count`, `POST me/conversations/provider/{id}`, `GET me/conversations/{id}`, `POST me/conversations/{id}/messages` |
| Admin | Admin permissions | `GET admin/data`; provider applications (`approve`, `reject`, `request-information`); `profession-suggestions/{id}/add`, `match`, `decline`; `verifications/{id}/assign`, `documents/{doc}/accept`, `documents/{doc}/send-back`, `request-document`, `request-information`, `approve`, `reject`, `reopen`, `note`, `documents/{doc}/files/{n}`; `push-announcements`; `providers/{id}/suspend` and `reinstate`; `users/{id}/suspend` and `reactivate`; `PATCH reviews/{id}`; `reports/{id}/assign`, `request-information`, `note`, `resolve`, `files/{n}`; roles and permissions, `PUT/DELETE users/{id}/roles/{role}`; `GET/PATCH settings`; `GET audit-logs` |

The legacy Laravel route `GET /api/user` (Sanctum default) is still present.

---

## Getting started

### Requirements

- PHP 7.4 with the extensions listed under [Technology stack](#technology-stack)
- Composer 2
- Node.js 18 or later and npm
- MariaDB 10.4 (or a compatible MySQL)

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
php artisan key:generate     # local only: never on production
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
php artisan migrate      # creates the tables and loads every Uganda place (a few seconds)
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

The admin area is at <http://127.0.0.1:8000/admin>. On the live server, run the same command over SSH in the application folder. If roles are missing there, run only `php artisan db:seed --class=RolesAndPermissionsSeeder --force`.

**Notes**
- `php artisan storage:link` is **not needed**. Photos and documents are kept in `storage/app` and served through the API.
- The service worker (install, offline page, push) only runs on a production build. To try it locally, stop `npm run dev`, make sure `public/hot` is gone, run `npm run build`, and open `http://127.0.0.1:8000`.
- On Windows, sending push messages needs PHP started with `OPENSSL_CONF` set (see [Environment variables](#environment-variables)).
- `php artisan serve` handles one request at a time, so a page can occasionally wait for an idle connection. Production servers don't have this limitation.

---

## Development commands

### Backend

| Command | What it does |
|---|---|
| `php artisan serve` | Runs the app locally at `http://127.0.0.1:8000` |
| `php artisan migrate` | Applies new migrations |
| `php artisan migrate:status` | Lists which migrations have run |
| `php artisan db:seed` | Runs all seeders (safe to re-run; sample data only when `APP_ENV=local`) |
| `php artisan workmate:role {email} {role}` | Grants an admin role to an existing account |
| `php artisan workmate:role {email} {role} --revoke` | Removes it (the last active Super Administrator can't be removed) |
| `php artisan workmate:mail-test {email}` | Sends a test email to check the mail settings (shows the mailer and sender, never the password) |
| `php artisan workmate:push-keys` | Creates the VAPID keys for push notifications and prints them for `.env` (asks before replacing existing keys) |
| `php artisan route:list --path=api` | Lists the API routes |
| `php artisan test` | Runs the test suite on `workmate_test` |

`migrate:fresh`, `migrate:refresh`, `migrate:reset`, `migrate:rollback` and `db:wipe` are for **local and test databases only**. See [Deployment](#deployment-and-cicd).

### Frontend

| Command | What it does |
|---|---|
| `npm run dev` | Starts Vite with hot reload (writes `public/hot`) |
| `npm run build` | Builds production assets into `public/build` |
| `npm run preview` | Serves the built assets for a quick check |

`php artisan route:cache` will **fail** for now, because two routes still use closures: the web catch-all and the legacy `/api/user`. Move them into controllers before enabling route caching.

---

## Testing

- **154 tests** in `tests/Feature/Api` and `tests/Unit`. They cover accounts, permissions and the permission lists staying identical, provider applications and typed professions, place search, the marketplace, received requests and ratings, messaging, document verification (including file privacy), push notifications, install events, photos, admin data and actions, settings and the audit log. New endpoints include a test proving another user or provider can't read or change the data.
- Tests use `RefreshDatabase`, which **rebuilds the database on every run**. `phpunit.xml` therefore points them at the separate **`workmate_test`** database. Never point it at a database whose data you need.
- Mail, cache, sessions and queues use in-memory drivers during tests; uploads use a fake disk; push delivery is mocked.

```bash
php artisan test                         # all tests
php artisan test --filter=ReviewTest
vendor/bin/phpunit                       # same suite through PHPUnit directly
npm run build                            # the frontend must build without errors
```

---

## Environment variables

Placeholders only: use your own values.

```env
APP_NAME=WorkMate
APP_ENV=local                 # "production" on live servers (sample data is never seeded there)
APP_KEY=                      # set once by php artisan key:generate; never regenerate on production
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

# Push notifications (VAPID). Create once with: php artisan workmate:push-keys
VAPID_PUBLIC_KEY=
VAPID_PRIVATE_KEY=
# Optional: a mailto: or https address push services can contact (defaults to APP_URL)
VAPID_SUBJECT=

QUEUE_CONNECTION=sync
SESSION_DRIVER=file
```

**Notes**
- Until mail is configured, people can register but can't confirm their email, and a confirmed email is required before sending a provider application or documents.
- Keep the same VAPID keys once set: new keys turn notifications off on every device. After changing `.env` on the server, run `php artisan config:clear` (and `config:cache` if the server caches config).
- On Windows, push messages can only be encrypted when PHP starts with `OPENSSL_CONF` set to PHP's bundled config (for XAMPP, `C:/xampp/php/extras/ssl/openssl.cnf`). Linux servers need nothing extra.
- `.env.example` also contains Laravel's default Redis, AWS and Pusher keys; WorkMate doesn't use them.
- New environment variables must be documented in `.env.example` with placeholder values and added to the server's `.env` by hand before the code that needs them is deployed.

---

## Project structure

```text
workmate/
├── .github/workflows/deploy.yml   # Deploys main to the production VPS
├── AGENTS.md                      # Rules for AI assistants and contributors
├── app/
│   ├── Console/Commands/          # workmate:role, workmate:mail-test, workmate:push-keys
│   ├── Exceptions/                # JSON error handling, BusinessRuleException
│   ├── Http/
│   │   ├── Controllers/Api/V1/    # Public, Auth/, Admin/ controllers
│   │   ├── Middleware/            # EnsureAccountIsActive, Authenticate, ...
│   │   ├── Requests/              # Form requests (register, profile, provider details)
│   │   └── Resources/             # API response shapes
│   ├── Jobs/                      # SendPushAnnouncement
│   ├── Listeners/                 # Verification email sender (tolerates mail failures)
│   ├── Models/                    # 24 Eloquent models
│   ├── Providers/                 # Gates, rate limiters, the Web Push client
│   ├── Services/                  # Business logic
│   └── Support/                   # PhoneNumber normalisation
├── config/
│   ├── permissions.php            # Admin roles and permissions (source of truth)
│   └── workmate.php               # Allowed platform settings and defaults
├── database/
│   ├── migrations/
│   └── seeders/                   # + data/areas.json, data/places-ug.csv, data/sample-marketplace.json
├── public/                        # Web root: build/, manifest.webmanifest, sw.js, offline page, icons
├── resources/
│   ├── css/app.css                # Tailwind v4 theme and glass utilities
│   ├── js/
│   │   ├── admin/                 # Admin area: layouts, components, modules, pages, data
│   │   ├── api/client.js          # API client
│   │   ├── components/            # Site components
│   │   ├── data/                  # Remaining frontend-only data (requirements prototype)
│   │   ├── pages/                 # Site pages
│   │   ├── router/                # Vue Router and guards
│   │   ├── stores/                # Shared reactive state
│   │   └── utils/                 # Search, trust, formatting helpers
│   └── views/app.blade.php        # The single page that loads the Vue app
├── routes/
│   ├── api.php                    # /api/v1
│   └── web.php                    # Catch-all for the Vue app (excludes /api)
├── storage/app/                   # avatars/ and verification/ (private; served via the API)
└── tests/
    ├── Feature/Api/
    └── Unit/
```

`resources/js/bootstrap.js` and `webpack.mix.js` are unused leftovers from Laravel's default setup. They are kept until their removal is agreed.

---

## Deployment and CI/CD

**Production:** <https://wm.resnetsystems.com>, on a Webdock VPS managed with HestiaCP.

```text
Push to main
     ↓
GitHub Actions: "Deploy WorkMate to VPS" (.github/workflows/deploy.yml)
     ↓
SSH to the VPS (credentials in GitHub repository secrets)
     ↓
Server deploy script: update code, install dependencies, build, php artisan migrate --force
     ↓
https://wm.resnetsystems.com
```

- **Pushing to `main` is deploying to production.** Deployments run one at a time and aren't cancelled midway. Work on a branch, run the tests and the build, and push to `main` only when the change is ready.
- After pushing, check that the workflow run succeeded, then check the site and `GET /api/v1/health`.
- **Application code lives in Git; secrets don't.** `.env` is ignored by Git, and production credentials live only on the server and in GitHub's repository secrets. Don't change the workflow, the server deploy script, SSH settings or secrets without the owner's approval.
- **Schema changes go through migrations only**, which must be safe for existing data: add, don't destroy; new columns nullable or with defaults; data loads idempotent. Release risky changes in steps.
- Only Laravel's `public/` folder is web-accessible; the application code, `.env`, `storage/` and `vendor/` are not.
- Take a database backup before risky releases.
- Once queues or scheduled jobs exist, run a queue worker under a process manager and the Laravel scheduler from cron.

> ⚠️ **Never run destructive database commands on production**, including:
>
> ```bash
> php artisan migrate:fresh     # drops every table
> php artisan migrate:refresh   # rolls back and re-runs everything
> php artisan migrate:reset     # rolls back everything
> php artisan db:wipe           # drops everything
> php artisan key:generate      # breaks every session and encrypted value
> ```
>
> They destroy real customer and provider data. They're for local and test databases only.

---

## Future mobile applications

The Laravel API is meant to be the single backend for:

- the website ✅
- the installable Progressive Web App ✅
- future Android and iOS apps ⬜

The mobile apps will use the **same `/api/v1` endpoints**, so the marketplace backend won't need rebuilding. Already in place: one JSON response and error format, server-side validation and permission checks on every endpoint, and Sanctum's `personal_access_tokens` table for token-based sign-in. Token sign-in endpoints for apps are not built yet; the website uses cookie sessions.

---

## Roadmap

### Foundation
- [x] Laravel API under `/api/v1` with one response and error format
- [x] Roles, permissions and audit log
- [x] MariaDB development and test databases
- [x] Platform settings allow-list
- [x] Git repository and automatic deployment
- [ ] Automated tests in CI before deploying

### Authentication
- [x] Registration, sign-in, sign-out (Sanctum SPA cookies)
- [x] Email confirmation, password reset and change
- [x] Rate limiting and account suspension
- [ ] Token sign-in for mobile apps
- [ ] Two-factor sign-in for administrators

### Provider capabilities
- [x] Apply from the same account (individual, group or company)
- [x] Up to 5 skills with a main skill; typed professions reviewed by administrators
- [x] Required base place and places travelled to
- [x] Review, approval, rejection, more-information and suspension workflow
- [x] Profile photos
- [ ] Group and company member management

### Marketplace
- [x] 22 categories and about 80 professions from the database
- [x] Provider directory with ratings calculated from reviews
- [x] Post a job, request a provider, apply for a job
- [x] Provider inbox for direct requests (accept, decline, done)
- [x] Messaging between customers and providers
- [x] Two-way reports and complaints with evidence photos, warnings and actions
- [x] Customers view applicants and hire one for a posted job
- [ ] Saved providers and locations
- [ ] Admin management of categories, professions and places

### Location matching
- [x] Every populated place in Uganda with type-to-search
- [x] Device location (with permission) naming the nearest place
- [x] Distance ranking, widening and main-skill priority in the browser
- [ ] Server-side matching with indexed distance queries
- [ ] Customer search reading the admin-configured radii

### Bookings and reviews
- [x] Customer ratings after a provider finishes a request
- [x] Hiring, completion and ratings for posted jobs
- [ ] Payment-linked bookings
- [ ] Booking history

### Verification and the tick
- [x] Single grey/blue tick on profiles
- [x] Document uploads to private storage
- [x] Admin document review, requests for extra documents, grey tick on approval
- [ ] Profession-specific, versioned requirements on the server
- [ ] Automatic performance evaluation (blue tick)

### Payments
- [ ] Mobile money (sandbox first), commission, refunds, payouts, reconciliation

### Notifications
- [x] Browser push (Web Push): admin announcements
- [x] Automatic push for requests, replies, messages and decisions
- [ ] In-app notifications
- [ ] Email notifications

### Administration
- [x] Sign-in protected, permission-based admin area on live data
- [x] Connected moderation, verification, typed-profession and account actions
- [x] Install tracking and push announcements
- [ ] Finance, disputes, content and communication modules
- [ ] System health monitoring

### Mobile and PWA
- [x] Progressive Web App (install, offline page, automatic updates)
- [ ] Android and iOS apps

### Production readiness
- [x] Hosting on HTTPS with automatic deployment
- [ ] Queue worker and scheduler
- [ ] Backups and monitoring
- [ ] Security and performance review
- [ ] PHP 8.1+ upgrade

---

## Contributing

1. Read [`AGENTS.md`](AGENTS.md).
2. Create a branch from `main` (`feature/short-description` or `fix/short-description`).
3. Make focused changes that follow the existing conventions:
   - business rules in services, thin controllers, responses through API Resources
   - `<script setup>` components with the existing Tailwind tokens and components
   - new permissions added to both `config/permissions.php` and `resources/js/admin/permissions.js`
   - PHP 7.4-compatible code; forward-only, data-safe migrations
4. Add or update tests, then run `php artisan test` and `npm run build`.
5. Commit with a clear message describing what changed and why.
6. Merge to `main` only when the change is complete and tested: that deploys it.

`.env`, `vendor/`, `node_modules/`, compiled assets in `public/build/` and uploaded files in `storage/app/` are ignored by Git.

---

## Security

- Never commit secrets. Keep `.env` private and use separate credentials for each environment.
- Verification documents and other private uploads live on the **private disk**, are served only through authorised controllers, and every administrator view is logged.
- Treat sample data as development-only. It is never seeded when `APP_ENV` isn't `local`.
- If you find a vulnerability, report it privately to the ResNet Systems team rather than in a public issue, and give them time to fix it before disclosure. A dedicated security contact hasn't been set up yet.

---

## Licence

No licence has been chosen yet. `composer.json` still says `MIT`, inherited from Laravel's project template, but no `LICENSE` file has been added. Until ResNet Systems publishes one, treat the code as proprietary.

Place names and positions: [GeoNames](https://www.geonames.org/), licensed under CC BY 4.0.

---

**WorkMate**, developed by **ResNet Systems**.
