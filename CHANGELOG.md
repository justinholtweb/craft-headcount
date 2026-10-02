# Changelog

## 5.3.3 - 2026-10-01

### Security

- **Webhooks fail closed when they can't be verified.** With no PayPal webhook ID, every PayPal webhook was accepted, so anyone who knew a subscription's `I-…` id could activate, suspend or cancel it. With no Stripe signing secret, Stripe's check ran with an empty key that anyone can compute, so a forged `checkout.session.completed` could create an active membership. Both are now refused, and the settings screens warn while a secret is missing.
- **The API key and outgoing webhook secret resolve environment variables.** The settings fields offered `$ENV_VAR` suggestions but the values were used as typed, so entering `$HEADCOUNT_API_KEY` made that literal, guessable string the API key — enough to read any member's details. A variable that doesn't resolve now counts as unset: key access is off, and outgoing webhooks aren't sent.
- **The billing portal only returns members to this site.** `returnUrl` came from the query string unchecked, so a link could send a member through a genuine Stripe page and on to someone else's site. Paths are made absolute against the site; other hosts fall back to the site URL.
- **The anonymous Apple Wallet log endpoint is bounded** — ten lines of up to 500 characters per request, and ten requests per address per minute.

### Fixed

- The unit and integration suites pass again: the access-rule tests predated 5.2.0's element-type scoping. Scope validation is now covered by the integration suite, and new integration tests cover the fixes above.

## 5.3.2 - 2026-08-26

### Fixed

- **The subscriptions index returned HTTP 500 whenever the status column was shown.** Craft 5 expects `statuses()` to return `craft\enums\Color` cases; the string colours this plugin returned made `Cp::componentStatusLabelHtml()` fail with "Attempt to read property `value` on string`", leaving the index blank. `'disabled'` is not a Color case and is now `Color::Gray`.

## 5.3.1 - 2026-08-15

### Changed
- **The settings screens are now a navigable section rather than a set of orphaned URLs.**
  `/settings/stripe`, `/paypal`, `/emails` and the new wallet screen existed but nothing
  linked to them — the only way in was to type the URL, while the main screen duplicated a
  subset of their fields inline. Each topic now owns one screen, reachable from a sidebar
  shared by all of them, and the fields live in fragments so a setting is defined once and
  appears wherever it belongs
- **New screen: API & Webhooks**, splitting the REST API key and the outgoing webhook
  settings out of the general screen, alongside the list of events Headcount emits

### Security
- **The settings screens now render read-only when `allowAdminChanges` is off**, instead of
  offering editable fields and a save button that the save action would then refuse. Inputs
  are disabled, the form and its save button are gone, and Craft's standard read-only notice
  explains why. Saving was already blocked, so this closes a misleading screen rather than a
  hole — an admin could fill the form in and lose the work to a 403
- The admin-changes guard is now expressed as the list of actions that only *read* settings,
  so any action added later is treated as a write until stated otherwise. It previously named
  the single write action, which would have silently let the next one through in an
  environment that forbids administrative changes

### Fixed
- **`GET /api/plans` reported the wrong `seasonStart`.** It returned the date a member
  joining *right now* would start, not the start of the season being sold — so mid-season it
  answered "today", and a client rendering "Season: {seasonStart} to {seasonEnd}" described
  the season wrongly. It now returns the season window; what today's joiner would pay is
  still `currentPrice`. **Behaviour change** for anything reading `seasonStart` from 5.3.0
- Backticks in the API & Webhooks screen's description rendered literally rather than as
  code, since a plain paragraph isn't run through Markdown the way a field's instructions are
- **The *Enforce Access Rules* setting could not be changed from the control panel.** The
  field was on the settings screen and the setting was honoured at runtime, but the save
  action never read it, so toggling it silently reverted — the escape hatch added in 5.2.0
  for sites whose templates gate content themselves was only reachable through
  `config/headcount.php`
- **The Settings screen rendered with no styling.** `headcount/settings` served the raw
  settings fields with no control-panel layout, stylesheet or JavaScript, because the
  controller rendered a template that was written as a fields-only *fragment* for Craft's
  plugin-settings page — which supplies its own page and form. The fragment moved to
  `settings/_fields`, and `settings/index` is now a proper control-panel page that includes
  it, so both callers get what they need. Present since the screen was first added
- The wallet card settings were unreachable in standalone Headcount. Nothing linked to
  `headcount/settings/wallet`, so short of typing the URL there was no way in — the only
  link to it lived in the Showtime bundle's settings screen. The all-in-one settings screen
  now carries the wallet switches and links through to the full screen

## 5.3.0 - 2026-08-15

### Added
- **Season memberships.** A plan can now be a fixed term rather than a billing cycle: set a
  start and end date and every member expires on the same day, whenever they joined. Built
  for clubs selling a membership year — a July–June season, say — where a recurring plan
  billing on each member's own anniversary is the wrong shape. Season windows roll forward a
  year automatically unless told not to, so next season sells itself without anyone editing
  the plan
- **Pro-rata pricing for mid-season joins**, by whole months remaining (a member joining in
  October of a July–June season pays nine twelfths) or by days. Off by default, in which case
  everyone pays the full price whenever they join
- **Apple Wallet and Google Wallet membership cards.** Members can add a card carrying their
  name, plan, status and expiry date, plus a QR code that opens a verification page reading
  **Valid** or **Not valid** — designed to be scanned with a plain phone camera by a shop
  offering a members' discount. Credentials are the site owner's own (an Apple Pass Type ID
  certificate, a Google Wallet issuer account), configured under Settings → Wallet Cards
- **Apple pass updates.** Headcount serves Apple's PassKit web service and sends APNs pushes,
  so a card already on a member's phone greys out when their membership is cancelled instead
  of waiting for its expiry date. Can be switched off, in which case cards still expire on
  their own date
- `headcount/subscriptions/remind` — a console command that sends the expiration reminder
  email. Run it daily
- Season fields on `GET /api/plans`: `termType`, `seasonStart`, `seasonEnd`, and
  `currentPrice`, which is what a member would be charged if they joined right now
- `craft.headcount.walletEnabled()`, `craft.headcount.appleWalletUrl()` and
  `craft.headcount.googleWalletUrl()` for templates

### Fixed
- **Expiration reminder emails were never sent.** `Emails::sendExpirationReminderEmail()` had
  no caller anywhere in the plugin, so the *Send Expiration Reminder Email* setting, the
  *Expiration Reminder Days* setting and the editable `headcount_expiration_reminder` message
  all described an email that never went out. A sweep now sends it, once per term — which
  matters more with season memberships, where the reminder is the only thing between a member
  and silently losing access
- `processExpiredSubscriptions()` only ever acted on subscriptions the member had cancelled;
  anything else sat past its end date as `active` indefinitely. Finished fixed terms are now
  moved to `expired` (and their user group revoked), while a cancelled subscription still
  becomes `canceled` — the two are different things and no longer conflated

### Changed
- Season plans are charged as a one-off Stripe payment rather than a subscription, since
  nothing renews. `Stripe::syncPlan()` skips them: a pro-rata plan has no single price to
  store
- Checkout refuses a PayPal payment for a season plan. PayPal's Subscriptions API can only
  bill on a cycle, and signing a member up to a recurring plan they didn't buy is worse than
  refusing
- Craft's `Craft` class is now loaded in the unit-test bootstrap, so models can build
  validation messages with `Craft::t()` without the suite needing a running application

## 5.2.0 - 2026-07-26

> **Contains security fixes — upgrade promptly.** Also contains breaking changes to the REST
> API and to `POST /api/checkout`; see the Security and Changed sections below.
>
> **Access rules now stop front-end requests on their own.** Read the Fixed section before
> upgrading a site that already has access rules configured.

### Security
- **The settings screen is now admin-only.** `SettingsController` had no authorization check
  of any kind, so any user who could reach the control panel could read the Stripe and PayPal
  secret keys, the webhook signing secret, and the REST API key — or POST new ones. Viewing
  requires an admin; saving additionally requires `allowAdminChanges`, matching Craft's own
  plugin settings screens
- **`/admin/headcount` (the reporting dashboard) now requires `headcount-viewReports`.** The
  permission was enforced on the reports and data actions but not on the dashboard action,
  so MRR, churn and revenue figures were readable by any control-panel user. The check moved
  to `beforeAction()` so it covers the whole controller, as it does elsewhere in the plugin
- **REST API keys are no longer accepted in the query string.** `?apiKey=` leaked keys into
  web server access logs, browser history, and the `Referer` header of outbound links. Pass
  the key in the `X-Headcount-Api-Key` header instead. The comparison is now `hash_equals()`,
  so it no longer leaks the key through timing. **Breaking** for any integration using
  `?apiKey=`
- **`POST /actions/headcount/api/checkout` now requires a CSRF token.** `ApiController` turned
  CSRF validation off for every action, including the one that changes state, so any site
  could make a logged-in member's browser open a checkout session. CSRF stays off for the
  read-only, key-authenticated endpoints, which have no session and no token to send.
  **Breaking** for front-end JS that posts to `/api/checkout` without `CRAFT_CSRF_TOKEN` — the
  equivalent front-end action, `headcount/checkout`, already required one
- `GET /api/subscription?id=` no longer skips its ownership check when there is no session.
  The check read "if there *is* a logged-in user and the subscription isn't theirs, refuse",
  which would have handed any member's subscription to a session-less caller

### Fixed
- **Access rules were never enforced.** Craft resolves a front-end element URL and renders
  its template without ever calling `canView()`, so the `authorizeView` hook the rules
  relied on never fired — visiting a gated URL directly served the page in full. Rules are
  now applied in `beforeAction`, where `redirect`, `paywall` and `hide` actually mean
  something. **If your templates were compensating for this, gated pages will start
  redirecting or 404ing; turn off `enforceAccessRules` to keep the old behavior.**
- **No access rule could be saved at all.** `targetUid` was a NOT NULL column that nothing
  ever populated, and the save path wrote null into it, so every save failed on an
  integrity constraint. It's now nullable.
- **The rule editor saved the wrong target.** It rendered one target field per scope and
  only hid the inactive ones — and a hidden input still posts — so with every field named
  `targetId`, whichever came last in the DOM won. Saving a "section" rule stored the empty
  category ID and the rule then matched nothing. Target fields are now keyed by scope.
- Saving a rule whose scope its element type doesn't offer is now a validation error rather
  than a rule that silently never matches.
- **API-key authentication actually works.** It was documented but unreachable: Craft rejects
  a session-less request before a controller's `beforeAction()` runs unless the action is in
  `$allowAnonymous`, so the key check was never consulted — and every key-authenticated action
  then went straight to `getUser()->getIdentity()` and returned 401 anyway. `subscriptions`,
  `subscription` and `member` are now anonymous-allowed and gated by the key check itself,
  which fails closed: with no API key configured, anonymous callers get 401
- A failed API-key check returns `401` instead of falling through to a `404` page. Returning
  `false` from `beforeAction()` left Craft with no action result, which it treats as an
  unmatched route

### Changed
- **API-key requests must name the member they act for**, via `?userId=` or `?email=` on
  `/api/subscriptions`, `/api/subscription` and `/api/member`. Headcount has a single global
  API key, so it is a trusted server credential with no member of its own; a missing selector
  returns `400` and an unknown member returns `404`. Session requests are unchanged — they
  always act for the logged-in user and deliberately **ignore** `userId`/`email`, so a member
  still cannot read another member's billing data. `POST /api/checkout` and `GET /api/portal`
  remain session-only
- **The member lifecycle emails are Craft system messages now.** Welcome, receipt, payment
  failed, expiration reminder, trial ending, cancellation and drip-unlocked were seven
  hardcoded English strings in `services/Emails.php` — no site could change a word of them,
  they couldn't be translated, and because they were sent as hand-built
  `nl2br(htmlspecialchars())` plaintext, the site's HTML email template never reached them.
  They're now registered under **Settings → Email → System Messages** like every other email
  Craft sends, and they render through the same template as the rest of your site's mail.
  **The default wording has changed slightly** in the process; edit it to taste.
- `Headcount::emailDefinitions()` exposes the messages, the settings attribute that switches
  each one on, and the variables its body may use — so a host bundle can list them all on one
  screen, and so the copy can be checked against its own placeholders.
- `SendMemberEmail` carries a message key and a scalar variable set instead of a
  pre-rendered subject and body. A queued job's payload is serialized to the database, so
  keeping elements out of it is worth doing for its own sake.
- `AccessRule::$type` is a plain string rather than a fixed enum, because the set of scopes
  is open. The `entry` scope is migrated to `element`, which is the same thing generalised.
- `Gating::getRulesForEntry()` is deprecated in favour of `getRulesForElement()`. It still
  works.
- `craft.headcount.canAccess()` accepts any element rather than only an `Entry`.

### Added
- Mount seam so Headcount can run as an internal module of a host bundle plugin
  (`mountedUnderShowtime`, `bootFeatures()`/`bootChrome()`, and `saveSettings()` routing
  through an injected writer). Standalone behavior is unchanged
- `craft.headcount.plugin` — resolves the plugin instance in both standalone and mounted
  modes, unlike `craft.app.plugins.getPlugin('headcount')`
- Headcount now refuses to install on a site where a host bundle that already includes it is
  installed. Both copies would register the Subscription element type and share the
  `headcount_*` tables, and uninstalling either would then drop the other's data.
- `Headcount::permissionDefinitions()` exposes Headcount's permissions, so a host bundle can
  list them under a single combined heading instead of one heading per bundled plugin. The
  permission keys are unchanged, so existing user groups keep working. Installed standalone,
  Headcount registers its own "Headcount" heading exactly as before.
- `$stripeWebhookRouter` — when Headcount runs as a module of a host bundle that owns the
  Stripe account, the host can take over webhook handling so this endpoint and the bundle's
  verify and route identically. Sites that pointed Stripe at
  `/actions/headcount/webhook/stripe` before bundling keep working, and behave the same as
  ones using the bundle's endpoint. Null (standalone) → Headcount handles it itself,
  unchanged.
- **Access rules are no longer entry-only.** A rule names an element *type* as well as a
  scope within it, so anything registered as gateable can be gated — a plugin's own element
  type, or (in the Showtime bundle) Owl events. Two element-type-agnostic scopes, `all` and
  `element`, sit alongside the existing entry scopes.
- `Gating::canAccess(ElementInterface, ?User)` — the plain question, for any element.
- `Gating::EVENT_REGISTER_GATE_TARGETS` and `Gating::EVENT_MATCH_GATE_RULE` let other code
  declare its own gateable element types and the scopes they support. A scope no handler
  claims matches nothing, so a stale rule can never end up gating everything.
- `craft.headcount.gatingResult` exposes the gate that paywalled the current page, so a
  template can render a teaser. (The `paywall` behavior withholds nothing by itself — see
  the variable's docs.)
- An `enforceAccessRules` setting, on by default, for sites whose templates already gate
  content themselves and don't want rules applied automatically.
- `Members::forEmail()` and `Members::subscriptionsForEmail()` — memberships keyed on an
  email address rather than a Craft user ID. Headcount keys on users, but everything that
  wants to ask "is this person a member" (a booking, an order, a support enquiry) arrives
  holding an address. Active subscriptions sort first.

## 5.1.0 - 2026-07-19
### Fixed
- Outgoing webhooks now actually dispatch. The documented events (`subscription.created`, `subscription.updated`, `subscription.canceled`, `subscription.expired`, `member.upgraded`, `member.downgraded`) were never being sent; subscription lifecycle changes now deliver HMAC-SHA256 signed payloads to the configured endpoint via a queued job
- Subscription element index columns (User, Plan, Status, Gateway, Amount, dates) now render again. The custom column renderer overrode Craft 4's `tableAttributeHtml()`, which Craft 5 renamed to `attributeHtml()`, so it was never called and its `parent::` call would fatal
- Stripe `customer.subscription.updated` / `invoice.payment_succeeded` webhooks no longer risk a fatal `TypeError` when Stripe omits a period-end timestamp; an unparseable date now falls back to `null` instead of `false`
- **Content gating by plan no longer throws a fatal `TypeError`.** JSON columns (`planIds`, `features`, `metadata`) were encoded twice on write, and the code paths that read them via a raw query or element population decoded only once — leaving a string where an array was required. This crashed content gating (`Gating`) and drip release (`Drip`) for any rule with required plans, loading a plan with `features` set, and loading a subscription with `metadata` set. These values now decode defensively; existing double-encoded data is read correctly

### Developer
- Static analysis and code style are now wired up: `composer phpstan` (PHPStan level 5, clean) and `composer ecs` / `composer ecs-fix` (craftcms/ecs)
- The `unit` PHPUnit suite runs again — the bootstrap now loads Yii2's global `Yii` class, which Composer's autoloader does not provide
- A Codeception + Craft integration harness (`composer test-integration`) boots a real Craft app against a throwaway database and covers the highest-risk paths: webhook idempotency, subscription lifecycle + user-group sync, gating, MRR reporting, coupon validation, and JSON-column decoding. `composer test` runs both suites. See `tests/README.md`
- `SubscriptionQuery` is annotated with element-query generics so `find()->one()` / `->all()` are typed as `Subscription`

### Added
- Plan change detection: Stripe `customer.subscription.updated` events now sync the subscription's plan and emit `member.upgraded` / `member.downgraded` webhooks based on the price delta
- `Subscriptions::changePlan()` service method for moving a subscription to a different plan

## 5.0.0 - 2026-05-05
### Added
- Initial release for Craft CMS 5
- Stripe payment integration with Checkout Sessions and Customer Portal
- PayPal subscription integration via REST API v2
- Custom Subscription element type with full lifecycle management
- Membership plans with configurable billing intervals and trial periods
- Content gating by section, entry type, category, or individual entry
- Drip content scheduling with day-based delays
- Coupon and discount code system synced with Stripe
- User group synchronization for tiered access control
- Member self-service portal for subscription management
- Transactional email system (welcome, receipts, reminders)
- Reporting dashboard with MRR, churn, and growth metrics
- Dashboard widgets for membership overview and revenue
- REST API for headless/decoupled architectures
- Twig extension with `craft.headcount` template variable
- `{% headcountGate %}` Twig tag for inline content gating
- Console commands for subscription sync and expiration processing
- Webhook processing for Stripe and PayPal events
- Full CP management interface with plans, subscriptions, access rules, drip schedules, and coupons
