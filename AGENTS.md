# AGENTS.md -- AI Agent Guide for Headcount

This file provides context for AI coding agents working on the Headcount Craft CMS 5 plugin.

## Project Overview

**Headcount** is a membership and subscription management plugin for Craft CMS 5. It provides Stripe and PayPal payment integration, content gating, drip content, coupon management, and user group synchronization.

- **Package:** `justinholtweb/craft-headcount`
- **Namespace:** `justinholtweb\headcount`
- **Plugin handle:** `headcount`
- **Main class:** `src/Headcount.php`
- **PHP:** ^8.2 | **Craft CMS:** ^5.0
- **License:** Proprietary (Craft Plugin Store, $99/yr)

## Architecture

### Hybrid Element + User Group Model

Headcount uses a custom **Subscription element** (`src/elements/Subscription.php`) for billing state, synced to native **Craft User Groups** for content access. This means:

- Subscription data (gateway IDs, status, dates, amounts) lives in the `headcount_subscriptions` table, linked to the `elements` table via element ID
- When a subscription becomes active, the user is added to the plan's mapped Craft user group
- When canceled/expired, the user is removed from that group
- Template developers can use either `craft.headcount.isSubscribed()` or Craft's native `currentUser.isInGroup('pro')`

### Service Layer

All business logic lives in services registered via `Headcount::config()`:

| Service | File | Responsibility |
|---------|------|----------------|
| `plans` | `src/services/Plans.php` | Plan CRUD, caching, reordering |
| `subscriptions` | `src/services/Subscriptions.php` | Subscription lifecycle, status changes, events |
| `gating` | `src/services/Gating.php` | Access rule evaluation, entry matching |
| `stripe` | `src/services/Stripe.php` | Stripe API (StripeClient instance pattern) |
| `paypal` | `src/services/PayPal.php` | PayPal REST API v2 via Guzzle |
| `webhooks` | `src/services/Webhooks.php` | Incoming webhook processing (Stripe + PayPal) |
| `drip` | `src/services/Drip.php` | Drip schedule evaluation |
| `coupons` | `src/services/Coupons.php` | Coupon validation, usage tracking |
| `members` | `src/services/Members.php` | User group sync logic |
| `reporting` | `src/services/Reporting.php` | Analytics queries (MRR, churn, growth) |
| `emails` | `src/services/Emails.php` | Transactional email dispatch via queue |

Access services via: `Headcount::getInstance()->serviceName`

### Content Gating Flow

1. `Headcount::_registerContentGating()` hooks `Entry::EVENT_AUTHORIZE_VIEW` (site requests only)
2. `Gating::evaluateAccess($entry, $user)` finds the highest-priority matching `AccessRule`
3. Rules match by: entry ID > entry type ID > section ID > category ID (priority order)
4. If matched, checks if the user has an active subscription to any of the rule's required plans
5. Also checks drip schedules via `Drip::isUnlocked()`
6. Returns `['allowed' => bool, 'behavior' => 'redirect|paywall|hide', ...]`
7. If denied: sets `$event->authorized = false` and stores result in route params

### Webhook Processing

Webhooks are **idempotent** -- the `headcount_webhook_logs` table tracks `eventId` to prevent duplicate processing. Both Stripe and PayPal webhooks verify signatures before processing.

Key flow: Webhook received -> signature verified -> idempotency check -> event handler -> subscription status updated -> user group synced -> email sent

### Queue Jobs

Long-running or async tasks use Craft's queue:

| Job | Purpose |
|-----|---------|
| `SendMemberEmail` | Async email sending |
| `SyncSubscriptionStatus` | Sync single subscription from gateway |
| `ProcessExpiredSubscriptions` | Batch expire subscriptions past period end |
| `DripContentRelease` | Check and notify on drip content unlocks |

## File Structure

```
src/
  Headcount.php                    # Main plugin class, event registration, CP nav
  models/
    Settings.php                   # Plugin settings (Stripe keys, PayPal keys, emails, URLs)
    Plan.php                       # Plan model with validation
    AccessRule.php                 # Access rule model
    DripSchedule.php               # Drip schedule model
  elements/
    Subscription.php               # Custom element: statuses, sources, table attrs, afterSave
    db/
      SubscriptionQuery.php        # Element query with plan/status/user filters
  records/
    PlanRecord.php                 # ActiveRecord for headcount_plans
    SubscriptionRecord.php         # ActiveRecord for headcount_subscriptions
    AccessRuleRecord.php           # ActiveRecord for headcount_access_rules
    DripScheduleRecord.php         # ActiveRecord for headcount_drip_schedules
    CouponRecord.php               # ActiveRecord for headcount_coupons
    WebhookLogRecord.php           # ActiveRecord for headcount_webhook_logs
  services/                        # (see table above)
  controllers/
    CheckoutController.php         # POST create-session, GET success/cancel
    WebhookController.php          # POST stripe, POST paypal (anonymous, no CSRF)
    PortalController.php           # Stripe Customer Portal redirect
    PlansController.php            # CP CRUD for plans
    SubscriptionsController.php    # CP subscription management
    AccessRulesController.php      # CP access rule CRUD
    DripController.php             # CP drip schedule CRUD
    CouponsController.php          # CP coupon CRUD
    ReportingController.php        # CP dashboard and reports
    SettingsController.php         # CP settings pages
    ApiController.php              # REST API endpoints + outgoing webhooks
  migrations/
    Install.php                    # Creates all 6 database tables
  jobs/                            # (see table above)
  twig/
    HeadcountTwigExtension.php     # Registers headcountGate tag
    HeadcountVariable.php          # craft.headcount template variable
    tokenparsers/
      HeadcountGateTokenParser.php # Parses {% headcountGate %} tag
    nodes/
      HeadcountGateNode.php        # Compiles headcountGate to PHP
  widgets/
    MembershipOverviewWidget.php   # CP dashboard widget (members, MRR, churn)
    RevenueWidget.php              # CP dashboard widget (revenue chart)
  events/
    SubscriptionEvent.php          # Event class for subscription lifecycle
    GatingEvent.php                # Event class for gating decisions
  console/controllers/
    SubscriptionsController.php    # CLI: expire, sync
    SyncController.php             # CLI: plans, status
  assets/
    HeadcountAsset.php             # CP asset bundle
    dist/css/headcount-cp.css      # CP styles (status badges, dashboard cards)
    dist/js/headcount-cp.js        # CP scripts (access rule type selector)
  templates/                       # 18 Twig templates for CP pages
  translations/en/headcount.php    # English translation strings
```

## Database Schema

### headcount_plans
Primary plan definitions. `handle` is unique. `userGroupId` FK to `usergroups`. `features` is a JSON array of strings. `billingInterval` is enum: day/week/month/year.

### headcount_subscriptions
Element content table. `id` FK to `elements` (CASCADE). `userId` FK to `users` (CASCADE). `planId` FK to `headcount_plans` (SET NULL). `status` values: active, trialing, past_due, canceled, expired, paused. `gateway` values: stripe, paypal, manual.

### headcount_access_rules
`type` enum: section, entryType, category, entry, custom. `targetId` references the ID of the target (section ID, entry type ID, etc.). `planIds` is a JSON array of plan IDs. `behavior` enum: redirect, paywall, hide. Rules are evaluated in `sortOrder` (lower = higher priority).

### headcount_drip_schedules
`planIds` JSON array. `delayDays` is days after subscription `startDate` to unlock content. Same `type`/`targetId` pattern as access rules.

### headcount_coupons
`code` is unique. `discountType` enum: percent, flat. `planIds` JSON (null = all plans). `stripeCouponId` for Stripe sync.

### headcount_webhook_logs
`eventId` for idempotency. `status` enum: processed, failed, ignored. `payload` stores raw JSON.

## Key Patterns and Conventions

### Craft CMS Patterns Used
- **Custom element type** with `afterSave()` for database persistence (not ActiveRecord for the element itself)
- **ActiveRecord** (`craft\db\ActiveRecord`) for non-element tables (plans, rules, coupons, etc.)
- **Service components** registered via static `config()` method
- **Event system** via Yii2 `Event::on()` in `init()`
- **Template hooks** via `Craft::$app->getView()->hook()`
- **CP URL rules** via `UrlManager::EVENT_REGISTER_CP_URL_RULES`
- **Permissions** via `UserPermissions::EVENT_REGISTER_PERMISSIONS`
- **Queue jobs** extending `craft\queue\BaseJob`
- **Environment variable support** via `Craft::parseEnv()` for API keys
- **Controller responses** use `asSuccess()` / `asFailure()` (Craft 5 pattern)

### Naming Conventions
- Controllers: PascalCase, suffixed with `Controller`
- Services: PascalCase, no suffix
- Models: PascalCase, no suffix
- Records: PascalCase, suffixed with `Record`
- CP URL routes follow: `headcount/{section}/{action}`
- Action URLs follow: `headcount/{controller}/{action}`
- Database tables prefixed with `headcount_`
- Permissions prefixed with `headcount-`

### Testing Webhooks Locally
```bash
stripe listen --forward-to localhost/actions/headcount/webhook/stripe
```

### Common Modifications

**Adding a new subscription status:**
1. Add constant in `Subscription.php`
2. Add to `statuses()` array in `Subscription.php`
3. Add to validation rule in `Subscription::defineRules()`
4. Add to status mapping in `Webhooks::_mapStripeStatus()`
5. Update `Members::syncUserGroups()` if the status affects group membership

**Adding a new email notification:**
1. Add boolean setting in `Settings.php`
2. Add send method in `Emails.php`
3. Call the method from the appropriate webhook handler or service
4. Add toggle in `templates/settings/emails.twig`

**Adding a new access rule type:**
1. Add to enum in `Install.php` migration (requires new migration)
2. Add matching logic in `Gating::getMatchingRule()`
3. Add UI option in `templates/access-rules/edit.twig`

**Adding a new REST API endpoint:**
1. Add action method in `ApiController.php`
2. Add to `$allowAnonymous` array if public
3. Document the endpoint

## Dependencies

- `craftcms/cms: ^5.0` -- Craft CMS framework
- `stripe/stripe-php: ^13.0 || ^14.0 || ^15.0 || ^16.0` -- Stripe SDK
- `guzzlehttp/guzzle` -- Included by Craft, used for PayPal REST API calls

## Permissions

| Permission Handle | Description |
|-------------------|-------------|
| `headcount-managePlans` | Create, edit, delete membership plans |
| `headcount-manageSubscriptions` | View and manage subscriptions |
| `headcount-manageAccessRules` | Create, edit, delete access rules |
| `headcount-viewReports` | View reporting dashboard |
| `headcount-manageCoupons` | Create, edit, delete coupons |
| `headcount-manageDrip` | Create, edit, delete drip schedules |
