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

**Community**
- A public feed of members' work, tips, experiences, questions and opportunities, with photos and **videos**, likes, comments, saves, follows and moderation (see [Community](#community))
- **Videos** go straight from the phone to private Cloudflare R2 storage; videos over 25 MB are trimmed on the phone before upload
- A **full-screen video viewer**: tap a video in the feed, then swipe up and down through the feed's videos
- **@mentions** in posts, captions, comments and replies: type `@` and choose someone; they're told, and their name links to their profile

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
- **Settings** that change the app straight away: nearby search (radii, maximum distance, fallbacks, GPS, tie-breaks), the default place, support phone and email, accepting provider applications, maintenance mode, and presence timings

**Quality**
- **504 automated tests** (feature and unit) on a separate MariaDB test database

### 🟡 In development

| Area | What exists | What's missing |
|---|---|---|
| Location matching | Distance ranking and widening run in the browser, from every place in Uganda, using the radii, fallbacks and tie-breaks set in Admin → Settings | Server-side matching for large numbers of providers |
| Places | GeoNames places loaded automatically, with districts | A few small places are missing from GeoNames (e.g. Najjera); there is no admin screen yet to add a place by hand |
| Document requirements | Real document checks (ID + profession documents + supporting + requested documents), with versioned per-profession requirements set in the admin | — |
| Performance tick | Tick display and admin rules screen | Automatic evaluation isn't running; only sample providers carry a blue tick |
| Push notifications | Per-device opt-in, a reminder pop-up, administrator announcements, automatic notifications for requests, replies, messages, community activity and decisions, an in-app notification list (the bell) and per-kind choices | Email notifications; announcements aren't added to the list |
| Email | Branded confirmation and reset emails | Delivery depends on the mail settings in `.env`; check with `php artisan workmate:mail-test` |
| Languages | Language preference is stored | The interface is English only |
| Provider teams | `provider_members` table; every rule already checks membership, not only ownership | Adding and managing group or company members |

### ⬜ Planned

- Bookings: payment-linked booking status tracking and booking history
- Payments with Ugandan mobile money (MTN MoMo, Airtel Money), commission, refunds, payouts and reconciliation
- In-app and email notifications
- Saved locations
- Admin management of places
- Real-time messaging (the chat currently checks for new messages every few seconds)
- "WorkMate in Action": a dedicated showcase of providers' videos (community videos already exist, see [Community](#community))
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

## Legal documents and consent

Implemented ✅ (drafts awaiting review by a Ugandan advocate). Eight documents (Terms and Conditions, Privacy Policy, Service Provider Agreement, Customer Agreement, Community Guidelines, Cancellation and Disputes, Verification and Trust, Acceptable Use and Safety) are loaded as version 0.1 from `database/legal/*.md` and shown at `/legal`.

- **First opening on a device:** welcome → Terms and Privacy with an "I agree" tick → device permissions (notifications and location with Allow buttons; photos and camera explained, as the phone asks when a photo is picked). Remembered on the device; signing up records the acceptance on the account.
- **Signed-in people** are asked to agree to any document they haven't accepted, or that changed materially (`legal_pending` on `/me`). Minor versions don't ask again. Providers also accept the Service Provider Agreement when they send their application.
- **Sign-up** needs the age and Terms tick (`accept_terms`); news and offers is a separate, unticked choice. **Account → Legal and privacy** shows what was agreed, the marketing switch and device permissions.
- Requests and posted jobs show the direct-payment notice before sending.
- **Admin → Legal Documents** (`legal.manage`, Super Administrators): draft, preview, publish (material or minor), discard drafts, version history with acceptance counts and CSV export, and **Company facts** (registration number, office, directors, DPO, privacy email…) that fill `{{placeholders}}` in every document; facts not set show as "to be confirmed". `[[notes]]` are drafting notes, highlighted until removed. **Ask people to accept** can be switched off there. Everything is audited.

## Saved providers

Implemented ✅. Signed-in customers tap the heart on a provider card (or **Save for later** on a profile) to keep them in **Account → Saved providers** (`/saved`). Saving twice keeps one entry; nobody can save their own profile. A saved provider who stops being listed stays in the list as "no longer available" so it can be removed. Lists are private to each account.

## Requests, completion and ratings

**Photos of the job ✅.** A request to a provider and a posted job can carry up to **6 photos** (JPG, PNG or WebP, up to 8 MB each), so providers see the leak or the broken door before replying.
- Phones shrink photos before sending; an upload bar shows progress; the form can't be sent while photos are still being prepared.
- The server re-draws each photo as JPEG in two sizes (1600 px and a 720 px thumbnail), which removes hidden data such as the GPS position of the customer's home, and keeps them privately under `storage/app/request-photos` (shared `PhotoVariants` service, also used by the community). If any photo can't be read, nothing is saved and stored files are removed.
- **Who sees them** (`ServiceRequest::photosVisibleTo`): the customer; the provider the request went to or who was hired, and everyone in that business; for an open posted job, approved providers who might apply (and, afterwards, those who did); administrators with `marketplace.view`. Signed-out visitors and everyone else get 404. Served through `/api/v1/requests/{id}/photos/…` with `no-store`, so not even the browser keeps a copy. Find Work shows only how many photos a job has.
- **A bad connection can't send it twice:** each form sends a one-off `client_token`; a retry with the same token returns the first request (HTTP 200) instead of creating another, and the provider is notified once. If the connection drops, the form keeps the details and photos and offers **Try again**.
- Shown as thumbnails that open full screen: in the provider's **Received** requests, the customer's requests and posted jobs, a job's page, and the admin's job record.

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

**Budgets, estimates and "inspection first" (Phase B1) ✅ — off by default.** Admin → Settings → **Pricing** (`finance.pricing_enabled`) switches on a more honest way to talk about price. While it is off nothing changes: the new fields aren't read, the new screens don't show, and everything works as before. It only records what people *say*; **it never prices a job, takes a payment, charges a commission or creates a debt** (no such tables exist yet).
- **Customer budgets:** a set amount, a **range**, **negotiable** (an amount is optional, only where talking starts) or **none**. Saved in `service_requests.budget_type/budget_min/budget_max`; the older `budget` and `budget_basis` are still written (a range leaves `budget` empty), so older apps keep working. Requests made before this have no stored type and are read from the older fields.
- **Provider estimates when applying** (`quotations.estimate_type/amount_max`): a set price, a range, or **"I need to see the job first"** (no figure). An estimate is a guide for the customer, never the price of the job; the price is agreed and confirmed by the customer in a later phase. An older app's plain `amount` still works.
- **The skill applied with** (`quotations.profession_id`): checked by the server. It must be one of the provider's own professions within the job's category; without a choice (older apps) it is their only fitting skill or their main one, and otherwise left empty rather than guessed. `GET /jobs/{id}/application-options` tells the app which skills fit and whether to ask.
- **Listed price ranges:** a service on "Services & Prices" may be priced as a range (`provider_services.price_max`).
- **Inspection first:** the provider business's **owner or a manager** (not an ordinary member) can mark a request or hired job as needing inspection, with a short note (`POST /me/received-requests/{id}/inspection`). It is idempotent, audited, tells the customer, never changes the job's status, and is refused for requests made before bookings existed, for finished or cancelled jobs, and for suspended providers.
- **Server-side rules:** amounts are whole UGX between 1,000 and 100,000,000; a range needs a highest amount above the lowest; a fixed budget or estimate needs its amount; nothing the client sends for another type is kept.
- **Tests:** `PricingOptionsTest` (PHPUnit) and `tests/js/pricing.test.mjs`. There are no new environment variables, packages or scheduled tasks, and three additive migrations (`2026_11_25_000060` to `000062`: only nullable columns).

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

## Community

Implemented ✅ (releases A and B).

A place for members to share their work: **My Work** (finished jobs and before-and-afters), **Work Tips**, **Experience**, **Questions** and **Opportunities**. It's a tab of its own (`/community`, the sixth tab on phones).

- **Reading:** anyone, signed in or not. Newest first, 10 posts at a time as you scroll (cursor pagination). **For you** (providers) shows the people they follow, their professions and their base place; **Following** shows the people you follow; **Latest** shows everything. Filters: kind of post, profession and place. Signed-out visitors are asked to sign in only when they act.
- **Posting:** signed-in members with a confirmed email. Text up to 3,000 characters and up to **6 photos** (JPG, PNG or WebP, up to 8 MB). Phones shrink photos before sending; the server re-draws every photo as JPEG in two sizes (1600 px and a 720 px thumbnail), which removes hidden data such as GPS, stores them under `storage/app/community` and serves them through `/api/v1/community/media/…` only while the post can be seen. Upload progress is shown and double submissions are blocked. Authors edit their words, kind and profession (`edited` is shown) and delete their posts.
- **Feed layout:** every post uses one compact layout: who posted it (name, tick, title, area, time), the words (3 lines with photos or video, 6 for text only, then **See more**), the photos or video, a slim provider row (**View profile**, **Request service**) and the actions. Edge to edge on phones, a card on wider screens. A single photo is shown whole, never cropped, over a blurred copy of itself.
- **Videos ✅:** anyone who may post may add one video to a post. The size limit (25 MB by default) is set in **Admin → Settings → Community videos**; there is no length limit.
  - Phones keep the original recording. A video over the limit is trimmed on the phone (Mediabunny, copy mode, no re-encoding) before upload.
  - Uploads go straight from the phone to a **private Cloudflare R2 bucket** using short-lived signed addresses (`POST /api/v1/community/video-uploads`, then `…/{upload}/complete`). The server never stores or processes the video: it signs addresses and checks the file's size and MP4/WebM header (a few byte ranges, in memory). Samsung files that don't start with `ftyp`, or that end with a Samsung trailer, are accepted.
  - Playback uses a signed address that lasts an hour (`/api/v1/community/media/{id}/playback`), given only while the post can be seen. A page left open gets a fresh address automatically.
  - In the feed, videos play when scrolled into view unless **Data Saver** is on; nothing is downloaded before then. One video plays at a time.
  - **Sound ✅:** every feed video has a sound button in its top-right corner (a 44 px touch target; it never opens the full-screen viewer). There is **one sound choice for all community videos**, feed and full screen: turn sound on for one and the following videos play with sound; mute one and they stay muted. Changing it only changes the volume, so the video keeps playing from where it is. Videos are muted until someone turns sound on. The choice is kept on the device in `localStorage` (`wm-video-sound`: `on` or `off`, nothing personal), so it survives navigation and refreshes. Where the browser refuses sound without a tap (iPhones, some Android browsers), the video plays silently and the button shows **Tap for sound**; if even silent playback is refused (e.g. Low Power Mode), the play button stays for a tap. With Data Saver on, the button only saves the choice: it loads nothing.
  - `workmate:video-cleanup` (hourly, from the scheduler) expires abandoned uploads and removes the videos of deleted posts after 7 days. `workmate:video-check` checks the R2 settings without showing any keys. Settings are in `config/videos.php`; see `docs/community-video-api.md`.
- **Full-screen video viewer ✅:** tapping a video in the feed opens it full screen, from the moment it had reached.
  - Swipe up and down (arrow keys, Page Up/Down, the mouse wheel or the buttons on desktop) through the videos in the feed being viewed; posts without a ready video are skipped and more feed pages load as needed. At either end a short note says so.
  - On screen: close and sound buttons, the author with their tick (linking to their page), title and area, the words (2 lines, **See more**), Like, Comment, Share and Save, and **View provider** / **Request service** on providers' videos. Likes and saves show in the feed straight away; comments open in the usual sheet above the viewer.
  - One video plays at a time. Only the previous, current and next slides are on the page, and only the current one holds a video; with Data Saver off, only the next video's header is fetched in advance.
  - The address gains `?video=<post id>`: the phone's **Back** button, the X or Escape closes it, back to the same scroll position, with focus on the video that was opened. Shared links to `/community?video=<id>` open that video, after the server confirms it can be seen.
  - Code: `resources/js/components/community/VideoViewer.vue`, with `VideoPlayer.vue` and `CommunityPage.vue`.
- **Taking part:** like, comment and reply (one level), save (Saved posts), share (the phone's share menu, or the link is copied), report. Likes and saves update on screen at once and go back if the server refuses. A post's author can delete comments on it. Maintenance mode pauses new posts and comments.
- **Who's who:** approved providers show their business name, main profession, base place and the tick, and link to their profile (with **View profile** on their work). Other members show as "Aisha N." and link to their community page. No contact details are ever shown. Posts are labelled as shared by members; reviews on profiles are the ones that come from WorkMate jobs.
- **People:** follow and unfollow (from a person's page, Discover people, suggestions or a post's menu). **Discover people** (`/community/people`) searches by name or business and filters by profession; only listed providers and members who have posted can be found. Each member has a community page (`/community/people/{id}`) with their posts and follower and following lists. Provider profiles show **In the community**: followers, a Follow button and their latest posts, kept apart from the jobs and reviews that come from WorkMate. The desktop feed suggests **People to follow** and shows the professions **Active this week** (real post counts).
- **Notifications (push):** a new follower (once a week per person, however often they re-follow), every comment on your post or answer to your question, replies to your comment, and likes and new followers, each grouped into one notification ("Grace A. and 3 others liked your post"; see [Smart notifications](#smart-notifications)). A post's notifications replace each other on the phone. They never include the words of a comment.
- <a id="my-space"></a>**Account numbers and My Space ✅:** the member's own community numbers, from real records only.
  - **On Account** (`/account`), directly under the identity card, four tappable numbers: **All posts**, **Video posts**, **Likes received** and **Followers**, then a **My Space** card. Two by two on narrow screens or with larger text, four across where the labels fit (a container query measured in `rem`, so bigger text switches to two by two by itself). Each tile is a link: posts → `/my-space?tab=posts`, videos → `/my-space?tab=videos`, likes → `/my-space?tab=performance`, followers → the member's public community profile. Loading, error (with "Try again", the rest of Account keeps working) and empty states are real: zeros are shown as zeros. Everything else on Account, the header avatar (→ `/account`) and the five bottom tabs are unchanged.
  - **My Space** (`/my-space`, signed-in only, private) has three tabs in the address: **Posts**, **Videos** and **Performance** (`?tab=posts|videos|performance`; anything else is Posts). Posts lists every post of the member, newest first, 10 at a time ("Show more"), including posts WorkMate removed (with the reason, shown to their owner only); filters: Live / Removed, and topic. Videos is the same list narrowed to posts that contain a video (the same posts, not extra ones). Each post is the ordinary community `PostCard` with its existing Edit and Delete. Performance separates **Your content**, **Your audience** (what other people did: likes, comments and saves received, followers; the member's own likes, comments and saves are not counted) and **Your own activity** (following, likes given, comments written, posts and providers saved). Views and plays are not shown: they aren't tracked.
  - **Definitions.** *All posts* = published, not deleted (videos included, so it equals the "N posts" on the public profile); *Video posts* = those among them with a video; *Followers/Following* count accounts that still exist, as on the public profile; likes, comments and saves received are other people's, on published posts only.
  - **API.** `GET /api/v1/me/community/stats` (`posts`, `videos`, `likes_received`, `followers`; `?detail=1` for My Space: `audience{…}` and `activity{…}`) and `GET /api/v1/me/community/posts?type=all|video&status=all|published|removed&category=&cursor=` (own posts only, cursor-paginated, 15 per page). Both need sign-in and an active account, take no id (they read the signed-in member), and run a fixed number of queries however much content there is (`CommunityStats`, `CommunityController::myStats/myPosts`, `tests/Feature/Api/MySpaceTest.php`). No migrations. Editing and deleting use the existing owner-checked post routes (someone else's post is "not found"). My Work and everything about jobs is unchanged.
  - **Whose numbers.** The numbers are kept per signed-in account in `resources/js/stores/communityStats.js`: they are dropped the instant the account changes (sign-out, signing in as someone else, session ended), an answer that was asked for under a previous account is ignored when it arrives, and a fresh answer is reused for 30 seconds only for the same account. So on a shared phone the next person never sees the previous person's numbers. Unit tests: `node --import ./tests/js/register.mjs --test --test-timeout=20000 "tests/js/*.test.mjs"` (Node's built-in runner, no packages).
- **Many people at once ✅:** the writes around a post and its comments follow one lock order, so a crowd liking, commenting, replying and deleting at the same moment can't deadlock and the counters stay exact. The rule: **lock the post first, then its comments** (`Community::like`, `comment`, `deleteComment`, `removeComment`; `likeComment` locks its own comment row first), and the two accounts of a follow are locked in id order (`CommunityPeople::follow`). Adding a like or a comment takes a shared lock on its post through the foreign key, and two of those then deadlock when they update the counter; locking the post first makes them queue instead. A real deadlock is run again (3 attempts in all) and a transaction that still fails rolls back completely (no comment, no count, no notice). Deleting a comment that is already gone counts nothing twice. Checked with real parallel PHP processes against MariaDB (the test database): 16 simultaneous comments on one post used to fail 14 times; now 288 simultaneous mixed operations (comments, replies, likes of posts and comments, deleting a parent while others reply to it) gave no deadlock and exact counters, 90 simultaneous likes and follows gave one notification group each, and two people following each other at the same instant is safe. `tests/Feature/Api/CommunityConcurrencyTest.php` checks the logic in PHPUnit.
- **Safety:** reports about posts and comments go into the existing Reports and Abuse queue, with the content, its photos and its status. Moderators with `content.view` **remove** a post or comment (the author is notified and sees the reason on their post) or **restore** a post. Suspended accounts' posts disappear. Rate limits: 3 posts a minute and 40 a day, 10 comments a minute and 150 an hour, 90 likes, saves and edits a minute.

Rapid repeat taps on like, save and comment-like are ignored while the first is still being sent.

### Mentions ✅

Members mention people in **posts, photo and video captions, comments and replies** by typing `@` and choosing someone from the suggestions. The person is told, and their name shows in WorkMate blue, linking to their provider profile (listed providers) or their community page (members). Customers and providers can both be mentioned, without following each other.

- **Who can be mentioned:** any active WorkMate account, found by the name the community shows. Suspended and deleted accounts can't be found or mentioned. A mentioned member without a community page of their own gets a minimal public one (the same card anyone's page shows) while the mention is in visible content. The author can mention themselves (they aren't notified).
- **Suggestions while typing:** `GET /api/v1/community/mention-suggestions?q=` (signed in; `mention-search` limit: 60 a minute and 600 an hour). It returns up to 8 people: id, community name, picture, profession, provider id, ticks, the provider's area and whether you follow them. No email, phone or account fields. Matching uses the name the community shows (a provider's business name, or "Aisha N."), case-insensitively and with spaces, so a member's surname can't be used to find them. People you follow come first. The app waits briefly while you type, cancels stale searches, remembers recent ones, and shows loading, "No one called…" and limit messages. The list stays compact, above the comment box (clear of the keyboard) or below the post box. Arrow keys, Enter or Tab choose, Escape closes it; a tap chooses on phones.
- **How a mention is stored:** the app sends a chosen person as a token, `@[u:<account id>]`. The server (`App\Services\CommunityMentions`) checks every token, replaces it with that person's community name, and stores plain text plus a `community_mentions` row: the account id, where the name starts and how long it is, in UTF-16 code units as browsers and phones count. So:
  - text typed without choosing (`@Sarah`), email addresses and broken tokens stay plain text;
  - nobody can be mentioned without being in the text, and posting someone's id elsewhere does nothing;
  - a mention keeps pointing at the same account after a change of name.

  While writing, the app keeps each mention's place as the text around it changes. A mention whose name is edited or partly deleted becomes plain text. Editing a post loads its mentions back as mentions.
- **Limits:** at most **5 different people** per post, comment or reply (the same person twice counts once), checked on the server (`too_many_mentions`). A token for an account that can't be mentioned (missing, deleted, suspended, never took part) refuses the whole text (`mention_not_allowed`), and nothing is saved. With the names filled in, the text must still fit the length limit (`text_too_long`). A video caption is checked when the upload starts, not after.
- **Notifications** (kind `community_mentions`, **Mentions** in the notification settings): "Grace A. mentioned you in a post / comment / reply / answer". Tapping it opens the post; for a comment or reply, it opens `#comment-<id>`, with the thread opened and the comment highlighted. A comment on a later page of a long list is found with `GET /api/v1/community/comments/{id}/thread` (that comment's conversation only, visible comments on a post the viewer can read); if it's gone, the page says so.
  - Notices are queued with `App\Support\AfterCommit` inside the saving transaction: sent when the outermost transaction commits, dropped on rollback (nested ones too), so a retry still tells people.
  - It's sent only after the post or comment is saved, and only while it can still be seen.
  - Each person is told once per post or comment, ever (`community_mention_alerts`): editing tells only people newly added, and removing and re-adding someone doesn't tell them again.
  - Someone already told about the item another way (the post's author, or the person replied to) isn't told twice; followers who are mentioned get the mention instead of the "new post" notice.
  - The same author can notify the same person at most 3 times an hour, and 30 mention notifications an hour in all. Beyond that, mentions still show without a notification.
  - Notifications never include the words.
- **Display and safety:** mentions are drawn from text nodes and router links (`MentionText.vue`), never HTML, so typed markup stays text. Positions that don't fit the text are ignored. A tap on a mention opens that person only (never the video viewer or the post). Previews (3 lines with media, 6 without, See more), the full-screen video caption, comments and replies all show mentions. Someone suspended or deleted stays as plain text.
- **Replies:** replying to a reply starts with a real mention of its author, so they're told. Before this, only the author of the comment that started the thread was told.
- **Blocking (planned):** account blocking doesn't exist yet. When it's built, it plugs into `CommunityMentions::mentionable()` and `deliver()`, and the rest doesn't change.
- **Code and tests:**
  - Code: `CommunityMentions`, `CommunityMention`, `resources/js/utils/mentions.js`, `stores/mentions.js`, `MentionSuggestions.vue`, `MentionText.vue`.
  - Tests: `tests/Feature/Api/CommunityMentionsTest.php`, `AfterCommitTest.php` and two caption tests in `CommunityVideoTest.php`.
  - Configuration: none; no new environment variables.

**Data:** `community_posts` (soft deletes, removal fields, like and comment counts, `likes_notified_at`), `community_post_media` (photos, and videos with their R2 key, size, dimensions and status), `community_video_uploads` (uploads in progress), `community_comments` (`parent_id` for replies), `community_post_likes`, `community_post_saves`, `follows`, and `reports.community_post_id` / `community_comment_id`, `community_mentions` and `community_mention_alerts`.

---

## Start earning (becoming a provider)

Implemented ✅. One guided flow at **`/start-earning`**, reached from the top of Account, from a job's Apply button and from provider notifications:

1. **Your skills**: type to search, or tap a popular trade; the first is the main one; "not listed" adds a typed skill for WorkMate to check.
2. **Where you work**: base place (pre-filled from the place already chosen) and optional places they travel to.
3. **Your profile**: photo, how they work (just me, group, company), name, about, prices, with a preview of their card.
4. **Your ID**: the National ID and anything their main skill needs (the Get verified screen, embedded).
5. **Check and send**: a summary with Edit links, email confirmation with "send the link again", the Service Provider Agreement tick and **one Send** (`POST /me/provider/send`), which sends the details and the documents together. A first application can't be sent without the ID; if either part isn't ready nothing is sent.

Details are saved at each step, so people can stop and come back. Afterwards the same page shows where things stand (Sent → Being checked → Live) and, when WorkMate asks for changes, **What to fix**: the reviewer's message on the details, documents sent back with the reason, and extra documents asked for, each linked to the step that fixes it, then **Send corrections**. Approved providers still edit their details from Account → Your service details, and add documents from Get verified.

**One review.** Reviewers open the applicant in **Verification → Applications**: the service details (skills, place, prices, about) sit above the documents, and one decision covers both (`/api/v1/admin/onboarding/{verification}/…`, needing `documents.review` and `providers.review`):
- **Approve and list provider**: the documents are approved (grey tick) and the details approved, so the provider goes live. Only once the National ID and every document are accepted and any typed skill is settled.
- **Send back for corrections** (before deciding): a message on the details, a message on the documents, and any document sent back with its own reason. The provider sees each under What to fix, corrects it and sends it again; nothing is decided until then.
- **Reject**: closes both, with the reason shared.

**ID before listing.** Nobody is listed on their details alone: the details-only approval in Provider Applications refuses applicants whose ID hasn't been checked (`id_not_verified`). Providers approved before this keep their listing; Start earning asks them to verify, and applicants still waiting with no ID are asked to add it.

## Provider verification and the tick

Providers show **one tick**. It is grey once their documents are approved, and turns blue when WorkMate's rules qualify them for strong performance. A provider never shows two badges, and the site doesn't explain the tick to customers.

### Standard verification (document checks) ✅

1. The provider adds their **National ID** (front and back, required) and up to **5 supporting documents** of their choice (Account → Get verified). Files are JPG, PNG or PDF, up to 8 MB, up to 4 pages each.
2. They send them for review (email must be confirmed).
3. A reviewer (`documents.review`) accepts each document or sends it back with a reason, and can **ask for further documents** for this applicant, for example a permit their profession needs.
4. The provider replaces or adds what was asked and presses **Send my changes for review**.
5. The reviewer approves (every document accepted, nothing outstanding) or rejects. Approval sets `document_verified_at`, which shows the grey tick.

Files are stored on the private `local` disk under `storage/app/verification`. Photos are re-drawn as JPEG (hidden data such as GPS removed); PDFs are kept as uploaded. Files are served only to their owner and to administrators with `documents.view`, never cached, and every administrator opening is written to the audit log. Replaced documents are kept as a record. Reopening a decision needs `verification.configure`.

**Profession documents.** On top of the National ID, each provider adds the documents their **main skill's profession** needs, set in Admin → Verification → Document Requirements (`verification.configure` to publish, `documents.review` to view). Each document is mandatory, optional or conditional ("uses their own vehicle" is asked on the Get verified page; "business or team" comes from the provider type), with its own formats (JPG, PNG, PDF), size limit (up to 8 MB), number of documents, front-and-back or multi-page files, and expiry rule; the server enforces all of them. Publishing adds a new version (`document_requirement_versions`); drafts follow the current version, and documents sent for review keep the version they were sent under (`document_verifications.requirement_version_id`). Sending and approval are blocked while a required document is missing. The first ten professions started with sample requirements loaded as version 1, published by "WorkMate", for administrators to review.

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
- People turn on notifications per device under **Account → Notifications**, or from a reminder pop-up that only appears on calm pages (home, browse, activity, saved, notifications, account) after a few quiet seconds, and never while a field has focus, another sheet is open, or on the composer, message, booking and request pages (at most weekly, three times per device). iPhones receive them only when WorkMate is installed on the home screen (iOS 16.4+).
- **Automatic notifications** (`EventNotifications`): a provider hears about new requests; a customer hears when their request is accepted, declined or done (with a prompt to rate) and when someone applies to their posted job; both sides hear about new chat messages; applicants hear decisions on their provider application and documents. They never include message text, reasons or contact details, and the person who caused the event isn't notified.
- **Notification list (the bell) ✅:** every automatic notification is also saved for the account (`user_notifications`) and listed at **/notifications** (the bell in the header), newest first, grouped Today / This week / Earlier, so people with push off still see what happened. An unread notification with the same subject (for example one chat, or one post's likes) is updated and moved to the top rather than repeated. Tapping one marks it read and opens it; **Mark all as read**. The unread count comes back with the presence heartbeat, so the bell needs no polling of its own. Kept for 90 days.
- **New jobs near you ✅** (`JobAlerts`): when a job is posted, approved providers with a skill in its profession who are within the wider search radius (`search.extended_km`) or list its place among the places they serve are told ("New plumbing job near you"), closest first: at most 40 providers per job and 10 alerts per provider a day. The customer's own business isn't told, and a retried post doesn't alert twice. A failure here never stops the job being posted.
- **Posts from people you follow ✅:** followers are told when someone they follow shares a post, at most once every 3 hours per author.
- **Choices per kind ✅:** Requests and jobs, New jobs near you (providers only), Messages, Comments and replies, Mentions, Posts from people you follow, Likes, New followers can each be turned off (bell → settings, or **Account → What you're notified about**); off stops both the push and the list entry. Account and safety notices (application and document decisions, reports, warnings, removed posts) always arrive. Stored in `users.notification_preferences`.
- <a id="smart-notifications"></a>**Smart notifications ✅ (Step 5):** the bell is now one list for everything, and likes and follows no longer arrive one by one.
  - **Grouped likes and follows.** All the likes on one post or video are ONE notification for its author: "Brian, Sarah and 28 others liked your video", with up to three overlapping real profile pictures (initials when someone has none). New followers are grouped the same way ("Sarah, John and 12 others started following you", opening the newest follower). Group by recipient, content and kind; unrelated posts never share a group; likes and follows never share one.
  - **What the count means.** It is the number of *different, active people who currently like it* (or follow you) since the group started, read live from `community_post_likes` / `follows` when the list is shown. Taking a like back lowers it; liking again raises it again and counts once; a suspended or deleted account stops being counted and shown; your own like never notifies. A group with no one left is hidden and isn't counted as unread. Names and pictures are never copied or invented.
  - **A group is a window.** Once you have read it, the next like starts a new group, and later likes are not added to the one you read. A group is one `user_notifications` row (`group_key` is unique per recipient, so concurrent likes can't make two); who is in it comes from the likes / follows tables.
  - **List:** **All / Unread** tabs, mark one or all as read, an accurate unread count (also on the bell), loading / error / empty states, and 20 per page. `GET /api/v1/me/notifications?filter=unread&cursor=` returns `grouped`, `count`, `actors` (id, community name, picture), `priority`, and the deep link; the list uses a fixed number of queries however many groups are on the page. Comments, replies and mentions carry the person who did it (`actor_id`) for the picture. Previews show only what the notification already says, never the words of a comment, and every row is the signed-in account's own.
  - **Priority.** *High* (booking requests and decisions, messages, account and security notices) is delivered at once, with the Web Push `Urgency: high` header, and is never held back by anything below. *Medium* (mentions, comments and replies, posts from people you follow, new jobs, invites) is sent at once. *Low* (likes, follows) is grouped and limited.
  - **In the list vs on my phone.** Each kind has two switches (bell → settings): **In the list** (what this page keeps) and **On my phone** (whether it also sends a phone notification). Turning the phone off keeps the list; turning a kind off in the list stops both. Turning off the browser's permission doesn't remove anything from the list. The old per-kind choices keep their meaning (`notification_preferences`: `{kind: false}`; the new phone choice is stored as `push:<kind>`).
  - **Quiet hours.** Start and end times (they may cross midnight, e.g. 22:00 to 07:00) in the account's timezone (`users.timezone`; the page sends the device's, and Uganda, `Africa/Kampala`, is used when none is known). While quiet, nothing is delayed or dropped: likes, follows, comments and mentions still update the list at once and still reach the phone at once, but the phone is asked to show them **silently** (where the browser supports it). *High* priority (bookings, messages, account notices) ignores quiet hours. Notices that are not engagement (new jobs near you, posts from people you follow, invites) are not pushed during quiet hours but become ONE summary push when they end ("You have 3 new notifications"; nothing if you read them meanwhile). The control is hidden unless smart push is on.
  - **Real-time push (smart push on)** (`config/notification_policy.php`; used only while **Admin → Settings → Platform → Notifications → Smart push for likes and follows, and quiet hours** (`notifications.smart_push`, **off by default**) is on): **nothing waits.** A like, follow, comment or mention updates the notification list in the same moment and its phone push is tried the moment the change is committed. There are no waiting windows, early-push thresholds, cooldowns or daily limits. Grouping only changes how things are *shown*: the list has one notification per post (or one for new followers) that is updated as more people join, and the phone's notification for it is **replaced** (same tag) rather than stacked, **silently** after the first. Limits only keep a phone from being buzzed over and over, and never remove anything from the list: after `audible_per_hour` (30) sounds in an hour, further pushes still arrive but silent; and a hard `pushes_per_minute` (60) bound per person stops runaway abuse. **Past that bound nothing is dropped:** the pushes over it are replaced by ONE summary push for the minute ("You have 40 new notifications", counting what is unread since the first push that went over, and not sent if it was all read meanwhile); every notification is in the list either way, and booking, message and account notices are not counted or held (they never use the outbox). Nothing is saved at all for someone with no subscribed device. A push that is still undelivered `stale_after_minutes` (3 hours) later is dropped (an old like is not news). Private saves never notify anyone.
  - **What switching smart push OFF actually does** (each point is covered by a test): (1) **New** likes, follows, comments and mentions stop using the outbox and go back to the earlier immediate path with its earlier rules: a like push at most once an hour per post, a follow push once however often they follow again, comments and mentions at once, no quiet hours and no silent updates. That is the old rules returning, **not the end of engagement pushes**: only a person's own "On my phone" choice stops those. (2) **Pushes already saved are still delivered and retried**, and the scheduler keeps working: they are events that already happened, and quiet-hours summaries still go out. (3) Every notification record and the list are untouched, and grouping still shows. (4) Booking, message and account notices are never affected, on or off. (5) Pushes already sent while it was on are not repeated, but the earlier hourly rule does not know about them, so the first like after switching off can push again. Quiet hours and the silent replacement of phone notifications apply only while it is on.
  - **The push outbox.** Likes, follows, comments and mentions use a transactional outbox (`notification_pushes`, `PushDelivery`): a row is saved **in the same database transaction as the event**, so an event that rolls back leaves no push; once the outermost transaction commits (`AfterCommit`), the push is tried at once, after the response has gone back. Laravel's after-response step is only a shortcut: the saved row is what makes the push durable, and the scheduler's next run (`workmate:notifications-flush`) finds anything that did not finish, whether the process died, the server restarted, or no device could be reached. Each push is **claimed** with one atomic update (pending to sending) before anything is sent, so a request, two scheduler runs and a retry can never send it twice. Events that arrive while a push for the same group is still waiting share it (it reads the group as it is when it goes); one that arrives while a push is in flight gets its own follow-up. A person's choices (list off, phone off for the kind) and their quiet hours are looked at **again when the push is sent**.
  - **Per-device results and retries.** What happened to each device is kept (`notification_push_deliveries`: status and HTTP code only, never wording or addresses): *accepted* (the push service took it), *expired* (404/410: the device is removed), *rejected* (refused for good, e.g. 403: not retried) or *failed* (no answer, 408, 429, 5xx: retried). A retry goes **only to the devices that have not accepted it**, after 20 s, 1 min, 5 min and 30 min, then the push is given up (`gave_up`); a device that fails 8 times in a row is forgotten. A claim left by a process that died is made ready again after 10 minutes. A finished push keeps no wording (`payload` is cleared); rows are tidied after 30 days. **What this cannot promise:** *accepted* only means the push service took the message, not that the phone showed it. Browsers, phones, battery settings and permissions can still stop it. Pushes are at-most-once to a device that accepted them; if the process dies after a device accepted but before that was written down, that device may get it once more (same tag, so it shows one notification).
  - **The scheduler.** `php artisan workmate:notifications-flush` (registered to run every minute) is now only the safety net: it sends any push the immediate attempt did not finish, retries, recovers abandoned claims and tidies old rows, at most 200 per run. **Production prerequisite:** the Laravel scheduler must be run by cron every minute: `* * * * * cd <app directory> && php8.3 artisan schedule:run >> /dev/null 2>&1`. Without it, first sends still happen at once (they do not depend on it); only retries and crash recovery wait. `php artisan workmate:notifications-status` shows the switch, when the flush last ran (and warns if that was over 5 minutes ago) and the waiting / sent / skipped / given-up counts, never message content.
  - **On the device (foreground and background).** The service worker (`public/sw.js`) decides for each push, on the device: if a WorkMate window is **both visible and in focus** it skips the phone alert (the app updates itself); otherwise it shows the notification. If it cannot look at the windows at all, it shows it: a notification too many is better than a lost one. With several tabs there is still at most ONE alert per device, and every open tab is told, so each updates its list and bell. Each device (subscription) is handled on its own, and a person may have several. The page listens for the service worker's message (`resources/js/stores/live.js`), asks `GET /api/v1/me/notifications/unread-count`, and reloads the list if it is open. **Without Web Push** (permission not given or blocked, or no support), nothing arrives that way, so while the notification list is open and on screen it checks every 20 seconds; everywhere else the existing presence check-in (every `presence.heartbeat_seconds`, 60 by default) already brings the unread count with it. No WebSockets or other service is used.
  - **Not part of the outbox.** Booking requests and decisions, messages, account notices, new-job alerts, posts from people you follow and invites keep their own immediate path (unchanged by this); making their delivery retry-safe is the separate booking-reliability work.
  - **Upgrading and rolling back.** The migration only adds columns to `notification_pushes` and one table (`notification_push_deliveries`); it was tried on a table of 100,006 rows (about 1.5 seconds, every earlier row identical, earlier values and states intact). Pushes saved by the earlier version are handled by the new code: those still inside an old 15- or 30-minute wait are sent at once, a stuck claim is recovered, a quiet-hours summary keeps its own time. **Rolling the code back** (git revert and redeploy) is safe without undoing the migration: the earlier release runs correctly on the new columns and tables (tested against the earlier release's own code); a push the new code had saved but not yet sent is skipped by the earlier release. Do not run `migrate:rollback` in production. **Before deploying**, take a database backup (for example `mysqldump --single-transaction` of the database, at least the `notification_pushes`, `push_subscriptions` and `user_notifications` tables).
  - **The admin control.** The setting is an ordinary platform setting (`config/workmate.php`, group `notifications`) managed through the existing admin settings API (`GET /api/v1/admin/settings`, `PATCH /api/v1/admin/settings/notifications.smart_push`) and shown as a toggle on the Platform settings screen (`resources/js/admin/modules/administration.js`, mapped in `SERVER_SETTINGS.platform` in `adminApi.js`). Only administrators who hold `admin.manage` (Super Administrators) can see or change it; everyone else gets no entry in the list and a 404 if they name it. The server validates the value (true or false), records who changed it, the old and new value and the reason in the audit log (`settings.updated`), and the change takes effect at once, with no deploy. It is not part of the public settings every visitor receives.
  - **Enabling it safely:** after deploying, confirm the scheduler runs (`workmate:notifications-status` shows a recent last flush), then switch on **Smart push** under **Admin → Settings → Platform → Notifications** (the switch shows ON/OFF and "Live"; turning it on asks for a reason and shows a confirmation that reminds the administrator to check the scheduler first; turning it off needs only a reason). To stop it, switch it off the same way (no deploy): new events return to the earlier immediate path at once, and what the outbox already holds finishes (see above). It does not stop engagement pushes altogether.
- <a id="personal-notifications"></a>**Personal notifications ✅ (Phase C, local; not yet deployed):** notifications say who did what to which content, with the person's real picture.
  - **Pictures and names.** Each notification carries up to **three distinct people, newest first**, each with their own profile photo (a small 96 px copy) or initials when they have none, never a stock picture. Suspended or deleted people are left out, and a changed photo shows at once. Tapping a **person** opens their page (their community page, or their business profile if they have a listed one; people with neither are not links), tapping the **text** opens the content (a single comment opens straight to that comment, `#comment-<id>`), and tapping a **group** opens a sheet listing everyone, or each comment with its preview. The picture, the name and the row never nest links. Photos come in `64 | 96 | 128 | 192` px copies on demand: `GET /api/v1/avatars/{uuid}.jpg?s=96` (any other size serves the original; cached for a year, since a new photo has a new address).
  - **Likes (the ladder).** 1: "A liked your video." · 2: "A and B liked…" · 3: "A, B and C liked…" · 4 and more: "A, B and N others liked…" with the real count (N = people − 2). The content is called what it is: **video, photo, post or question**. Counted live as different, active people, as before (unlike lowers it, liking again raises it, one group per post per window, a read group is never reopened).
  - **Comments.** The **first five comments within a fixed 60 seconds** are individual notifications (person, "commented on your video", a short plain-text **preview**, time, link to that comment). The window starts at the first comment and **never slides**. The **sixth** turns that window's unread notifications into **one group**: "Sarah, John and 3 others commented on your video", "6 new comments"; later comments in the window update the group (no new row, and no new phone alert); a comment after the window starts a new window. People and comments are counted separately: one person's six comments read "Mathew posted 6 comments on your post". **Notifications you have already read are never merged or changed**, and a group you have read is finished. The comments themselves are never touched; deleted, moderated, or suspended-account comments disappear from previews and counts (an emptied group disappears). **Replies are always individual.** Done in `CommentNotifications` inside the comment's own transaction under the post lock (the same order as everything else), so many people at once cannot make two groups or lose a comment (24 simultaneous commenters gave one group holding all 24, checked with parallel processes).
  - **Previews are in the app only**: plain text (tags removed, spaces tidied, about 140 characters), made from the comment when the list is shown; **a phone notification never contains comment text**. The phone shows "Alice P. commented on your video." and the person's own photo as its icon (only a WorkMate avatar address on the same site, never fetched with credentials; a missing or slow photo falls back to the WorkMate icon within about a second).
  - **Phone behaviour is unchanged:** every like and comment still sends its push at once (nothing waits), later ones in the same 60-second window replace the earlier phone notification quietly (same tag, `silent`, even if you opened the group meanwhile), a comment in a new window alerts again, and grouping never sends an extra push. The person's own choices still apply: a kind switched off for the phone sends nothing (the list still keeps it) and quiet hours make it silent, never late.
  - **Storage.** One migration, additive: `user_notifications.context_id` (the post) and `parent_id` (the group a comment belongs to, set when the sixth arrives), both nullable and indexed; nothing is deleted or rewritten. Older rows behave exactly as before. A group's children are hidden from the list and read together with it. New endpoint: `GET /api/v1/me/notifications/{id}/activity` (the people, or the comments, of a group; your own notifications only, otherwise 404). The list rows now include `lead`, `tail`, `detail`, `preview`, `content`, `comments`, `activity` and `actors[].profile` next to the earlier fields; the fixed number of database queries per page does not grow with the number of rows.
  - **Limits.** Pictures reach the lock screen only where the phone supports a notification icon (Android Chrome does; iPhone uses the app icon). Booking and message delivery are unchanged.
- Administrators with `comms.send` send announcements from **Communications → Push Announcements**. Sending happens after the response, with no queue worker; devices that unsubscribed are removed automatically.
- **Signing out stops a device's notifications ✅:** sign-out removes this device's subscription (matched by its push address, or by the session it was created in), and a device that opens WorkMate signed out releases any subscription left on it. `php artisan workmate:push-prune-signed-out` (a dry run; add `--apply` to delete) removes subscriptions of accounts that are signed in nowhere; it needs `SESSION_DRIVER=database`.
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
- Anything not recorded yet (payments, bookings, disputes, the automatic blue tick) is shown as **Not tracked yet**, never as zero.
- **Service categories and professions** (Marketplace → Service Categories and Professions, `catalogue.manage`: Super Administrators and Operations): add, rename, change the icon, reorder (move up or down) and hide or show categories; add, rename, move to another category and hide or show professions. Nothing is deleted (providers, jobs and history refer to them), and slugs never change, so links keep working. A new category starts hidden and can't be shown until it has a visible profession. Hidden ones disappear from customers' choices and new provider applications; providers who already offer them keep them. Every change is audited.
- **What the dashboard counts, per day:** registrations, jobs posted, requests sent, jobs marked done (from the audit log), daily active users, messages sent (counted only, never read), community posts, comments and follows, reports received, notifications, and anonymous searches (with how they turned out) and provider profile views. Also: who is online now, verification and install figures, and failed sign-ins.
- **Daily active users:** the presence heartbeat adds one row per account per day to `user_activity_days`.
- **Searches and profile views** are anonymous daily totals in `app_daily_counts` (`POST /api/v1/app-events/count`): no person, device, place or search text is stored, and repeated profile views in one visit count once, so the figures are approximate.
- **Sign-ins:** every sign-in, failed sign-in (with the address masked, linked to the account when one exists) and attempt on an inactive account is a **Security** event in the audit log. The dashboard's **Needs attention** shows failed sign-ins in the last 24 hours, marked critical when an account has 5 or more failures.
- **Live updates:** while the admin is open and on screen it checks every 10 seconds whether anything changed (`GET /api/v1/admin/pulse`, a cheap fingerprint of the latest activity, rate-limited). When something did, the data reloads quietly and every open screen (dashboard, Activity, record lists, analytics, the alert bell, verification review) updates in place, keeping filters and the open record. The top bar shows **Live**, **Paused** (tab in the background) or **Reconnecting…**. No WebSocket server or extra process is needed.
- **Activity:** community events have their own **Community** category and plain-English titles, and posts and comments link to where they appear in WorkMate.
- Actions that call the API are listed in `resources/js/admin/modules/connected.js` (or call the API from their module or page). Other actions aren't offered; the record shows how many aren't connected.
- Every action needs a reason and is written to the audit log.
- **Settings** (Platform and Matching tabs): every setting marked **Live** is saved on the server (`config/workmate.php`, audited) and sent to the app with `GET /catalog` → `settings`, which applies it. Platform name, currency, languages, road distance and "providers without a map location" are marked **Not available yet**, each with the reason.
- **Presence:** while the app is open and on screen, a signed-in account checks in (`POST me/heartbeat`, every *App check-in interval*). Customers and Service Providers show **Online**, **Recently active** or **Offline** from the presence windows. Only the time is stored, never a location.
- **Maintenance mode** shows a notice on the site and pauses new jobs, requests, applications and hiring (HTTP 503 `maintenance`); browsing, messages and the admin keep working. **Accept new provider applications** off stops new applications (`provider_signups_closed`); requested changes can still be sent.
- Admin screens work with the popular places and the places in use, not all 11,300.

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
| `users` | Every account. Adds phone, locale, status (`active` / `suspended` / `deactivated`), photo path, last seen, notification choices, soft deletes and `is_sample` to Laravel's default table |
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
| `service_requests` | Posted jobs (`is_posted_job`; the provider is set once someone is hired) and requests to one provider, with the provider's reply (`responded_at`, `provider_note`), when the customer was asked to rate (`review_prompted_at`), and the app's one-off `client_token` |
| `service_request_photos` | Photos of the job on a request: private file paths in two sizes, size and order |
| `quotations` | A provider's application or price for a posted job (one per provider per job) |
| `reviews` | Ratings and comments, linked to the request they're about (`service_request_id`, one review per request); status for moderation |
| `conversations`, `messages` | Customer–provider chats and their messages, with read status |
| `reports` | Reports and complaints: reporter and side, who or what it's about, linked request or chat, reason, details, private photos, severity, status, the question to the reporter and their answer, outcome and the message to the reporter |
| `account_warnings` | Warnings the safety team sent an account, with when they were read |
| `community_posts`, `community_post_media` | Community posts (kind, text, profession, place, status and removal reason, counts) and their photos |
| `community_comments` | Comments and one level of replies, with removal fields |
| `community_post_likes`, `community_post_saves` | One like and one save per person per post |
| `follows` | Who follows whom in the community, one row per pair |
| `saved_providers` | Providers a customer saved to come back to |
| `legal_documents`, `legal_document_versions` | Terms, privacy policy and agreements, with draft and published versions (published ones never change) |
| `legal_acceptances` | Who accepted which version, when and how (sign-up, prompt, provider application); append-only |
| `user_consents` | Optional choices such as news and offers; each change adds a row |
| `user_notifications` | Each account's notification list: kind, title, text, link, tag, when it was read, who it is about (`actor_id`) and, for grouped likes and follows, `group_key` (unique per recipient), `subject_type` / `subject_id`, `group_started_at` and the live `group_count` |
| `notification_pushes` | The push outbox: one row per push, saved with the event it is about. Type (`like_group`, `follow_group`, `direct`, `digest`), the notification `kind`, unique `dedupe_key`, status (`pending`, `sending`, `sent`, `skipped`, `gave_up`), attempts and retry time, claim time, whether the phone was asked to show it `silent`, how the last attempt went (devices delivered / failed / expired, last HTTP status), a short note, and for events without a group the `payload` wording, which is cleared as soon as the push is finished |
| `notification_push_deliveries` | What happened to each device for each push (accepted, failed, rejected, expired, with the HTTP code and attempts): so a retry goes only to devices that have not taken it. Never wording or addresses |
| `user_activity_days` | One row per account per day it used the app, for daily active users |
| `app_daily_counts` | Anonymous daily totals: searches and their outcomes, provider profile views |
| `provider_reports` | Earlier reports about providers, copied into `reports`; kept unchanged until removal is approved |
| `push_subscriptions`, `push_announcements` | Devices that turned on notifications (with `failure_count`), and announcements sent with their delivery counts |
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

Not yet in the schema: bookings and booking event history; performance-tick rules, evaluations and award history; payments, ledger entries, commissions, refunds and payouts; in-app notifications and notification preferences; provider videos and moderation; saved providers and saved locations.

---

## Authentication and security

- **Method:** Laravel Sanctum in **SPA cookie mode**. The website first calls `GET /sanctum/csrf-cookie`, then signs in with `POST /api/v1/auth/login`. The session cookie authenticates later requests, and changing requests carry the `X-XSRF-TOKEN` header. No tokens are kept in JavaScript or `localStorage`.
- **Stateful domains:** sessions only start for requests from domains in `SANCTUM_STATEFUL_DOMAINS`.
- **Registration and login:**
  - New accounts are signed in straight away and sent a confirmation email.
  - Suspended accounts can't sign in.
  - A wrong password always gets the same message, and the forgot-password form answers the same way whether or not an account exists, so neither reveals which emails are registered.
- **Passwords:** hashed with bcrypt; at least 8 characters with letters and numbers. Changing a password signs out every other device (sessions, "Keep me signed in" cookies and API tokens); resetting one signs out everywhere.
- **Staying signed in:** "Keep me signed in on this device" is ticked by default and set for new accounts, so people stay signed in for 90 days without use (`SESSION_LIFETIME=129600`, `session.remember_lifetime`), through browser and phone restarts. Unticked (a shared device), the `wm_shared_device` cookie makes the session end after 2 hours idle or when the browser closes (`ShortSessionOnSharedDevice`).
- **Devices:** with `SESSION_DRIVER=database`, **Account → Devices** lists where the account is signed in (browser, system, partial IP address, last active) and signs out one device or all others. Session ids never leave the server (the app sees a hash). Suspending an account signs it out everywhere.
- **Rate limits** (in `RouteServiceProvider`):
  - sign-in: 5 per minute per email and device, and 20 per minute per device
  - registration and emails: 6 per minute
  - jobs, requests, replies, ratings, applications, messages, reports, uploads and push subscriptions: 30 per minute
  - place search: 120 per minute; install events: 10 per minute
  - separate limits for registration, password reset emails, community likes, video uploads, push subscriptions, people search and CSP reports
  - general API: 60 per minute
- **Security headers** (`SecurityHeaders`, on every response): `X-Content-Type-Options`, `Referrer-Policy`, `X-Frame-Options: SAMEORIGIN`, `Permissions-Policy`, and HSTS once `APP_PUBLIC_URL` is https. A **Content Security Policy** is sent in **report-only** mode (`CSP_MODE`): violations are reported to `/api/v1/csp-reports` and logged (without query strings), and nothing is blocked. Switch to `enforce` only after the logs show no unexpected reports.
- **Public address** (`config/security.php`): when `APP_PUBLIC_URL` is set, email links are built only from it, requests for any other host name are refused (`400 bad_host`), CORS allows only that origin (plus `CORS_ALLOWED_ORIGINS`) and HSTS is sent. Until it is set, none of this changes.
- **Email addresses** are checked strictly, and control characters (such as line breaks) are refused (`App\Support\EmailRules`).
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
| Account | Signed in | `GET/PATCH me` (includes `provider_ids`), `POST me/heartbeat`, `PUT me/password`, `POST/DELETE me/photo`, `POST me/email/verification-notification`, `POST/DELETE me/push-subscriptions` |
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
| `php artisan workmate:push-prune-signed-out [--apply]` | Counts (or with `--apply`, deletes) push subscriptions of accounts signed in nowhere |
| `php artisan workmate:video-check` | Checks the community video storage (R2) settings, without showing any keys |
| `php artisan workmate:video-cleanup` | Expires abandoned video uploads and removes unused videos from R2 (runs hourly from the scheduler) |
| `php artisan workmate:reminders` | Sends due booking reminders (runs every 15 minutes from the scheduler) |
| `php artisan workmate:notifications-flush [--limit=]` | The push outbox's safety net: sends pushes the immediate attempt did not finish, retries, recovers abandoned claims, tidies old rows (every minute from the scheduler) |
| `php artisan workmate:notifications-status` | Smart push switch, when the flush last ran, and counts of waiting / sent / skipped / given-up pushes (no content) |
| `php artisan workmate:watermark-photos --dry-run` | Counts public photos added before the watermark; without `--dry-run` it watermarks them (resumable) |
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

- **159 tests** in `tests/Feature/Api` and `tests/Unit`. They cover accounts, permissions and the permission lists staying identical, provider applications and typed professions, place search, the marketplace, received requests and ratings, messaging, document verification (including file privacy), push notifications, install events, photos, admin data and actions, settings and the audit log. New endpoints include a test proving another user or provider can't read or change the data.
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

# Community videos (Cloudflare R2). Check with: php artisan workmate:video-check
R2_ACCOUNT_ID=
R2_ACCESS_KEY_ID=
R2_SECRET_ACCESS_KEY=
R2_VIDEO_BUCKET=workmate-community-videos
R2_ENDPOINT=                  # optional; defaults to https://<account id>.r2.cloudflarestorage.com

# Security (config/security.php). Nothing changes until APP_PUBLIC_URL is set
APP_PUBLIC_URL=               # e.g. https://your-domain on the live server
TRUSTED_HOSTS=                # extra host names to accept, comma-separated
CORS_ALLOWED_ORIGINS=         # extra origins allowed by CORS, comma-separated
HSTS_SECONDS=15552000         # 0 = off; sent only when APP_PUBLIC_URL is https
CSP_MODE=report-only          # report-only or enforce

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
- [x] Customer search reading the admin-configured radii and ranking

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
- [x] In-app notification list, with grouped likes and follows, quiet hours and smart push (Step 5; smart push off until the server scheduler is confirmed)
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
