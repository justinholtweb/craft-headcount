# Headcount for Craft CMS 5

Full-featured membership and subscription management plugin for Craft CMS 5. Stripe and PayPal integration, tiered access control, content gating, drip content, and a complete subscription lifecycle -- built for the Craft ecosystem.

## Features

- **Stripe & PayPal** -- Recurring payments via Stripe Checkout Sessions and PayPal Subscriptions API v2
- **Membership Plans** -- Tiered plans with configurable billing intervals (day/week/month/year), trial periods, and per-plan pricing
- **Season Memberships** -- Fixed-term plans where every member shares one calendar window (a club's July–June year), with automatic annual rollover and optional pro-rata pricing for mid-season joins
- **Wallet Cards** -- Members add a membership card to Apple Wallet or Google Wallet, with a scannable QR code that proves membership away from the site. Apple cards update themselves on the device when a membership changes
- **Content Gating** -- Restrict any element type -- entries by section, entry type, category or individually, plus anything a plugin registers as gateable -- with redirect, paywall, or hide behaviors
- **Drip Content** -- Schedule content to unlock N days after subscription start
- **User Group Sync** -- Automatically add/remove users from Craft user groups based on subscription status
- **Coupons & Discounts** -- Percentage or flat-rate codes synced to Stripe Coupons
- **Member Portal** -- Self-service subscription management via Stripe Customer Portal
- **Reporting** -- MRR, churn rate, trial conversion, growth metrics, and dashboard widgets
- **Transactional Emails** -- Welcome, receipt, payment failed, trial ending, cancellation, and drip unlock notifications, as editable Craft system messages
- **REST API** -- JSON endpoints for plans, subscriptions, checkout, portal, and member info with API key auth
- **Twig Extension** -- `craft.headcount` variable and `{% headcountGate %}` tag for template-level gating
- **CLI Commands** -- `headcount/subscriptions/expire`, `headcount/subscriptions/sync`, `headcount/sync/plans`, `headcount/sync/status`

## Requirements

- Craft CMS 5.0+
- PHP 8.2+
- Stripe account (for Stripe payments)
- PayPal Business account (optional, for PayPal payments)

## Installation

```bash
composer require justinholtweb/craft-headcount
php craft plugin/install headcount
```

Or install from the [Craft Plugin Store](https://plugins.craftcms.com/headcount).

## Quick Start

### 1. Configure Payment Gateways

Navigate to **Headcount > Settings** and enter your Stripe API keys. Optionally enable PayPal.

Settings can be overridden via `config/headcount.php`:

```php
<?php

return [
    'stripeSecretKey' => getenv('STRIPE_SECRET_KEY'),
    'stripePublishableKey' => getenv('STRIPE_PUBLISHABLE_KEY'),
    'stripeWebhookSecret' => getenv('STRIPE_WEBHOOK_SECRET'),
    'paypalClientId' => getenv('PAYPAL_CLIENT_ID'),
    'paypalClientSecret' => getenv('PAYPAL_CLIENT_SECRET'),
    'paypalEnabled' => false,
    'defaultCurrency' => 'USD',
    'checkoutSuccessUrl' => '/membership/thank-you',
    'checkoutCancelUrl' => '/membership/plans',
    'loginUrl' => '/login',
    'pricingUrl' => '/membership/plans',
];
```

### 2. Create Plans

Go to **Headcount > Plans** and create membership tiers. Each plan maps to:
- A **Stripe Price** (auto-created on first sync, or enter an existing Price ID)
- A **Craft User Group** (members are automatically added/removed)
- A billing interval, price, and optional trial period

Plans come in two shapes:

- **Recurring** -- the default. Bills on each member's own anniversary and renews until cancelled.
- **Fixed season** -- one payment for a window every member shares. Set the start and end
  dates (say 1 July to 30 June) and everyone expires together on the same day, whenever they
  joined.

A season plan leaves **Repeats Every Year** on by default, so the window rolls forward on its
own once it finishes and next season starts selling without anyone editing the plan. Turn it
off for a one-off season, which stops selling once it ends.

Turn on **Pro-rata Mid-season Joins** to scale the price by how much of the season is left.
By months (the default), someone joining in October of a July–June season pays nine twelfths —
the whole months they can still use. By days is exact but produces less familiar prices.

Season plans are charged as a one-off Stripe payment rather than a subscription, so they have
no stored Stripe Price and nothing to renew. **They can't be paid for with PayPal**, whose
Subscriptions API can only bill on a cycle; checkout refuses a PayPal season purchase rather
than quietly signing the member up to something recurring.

Expiry is not automatic on its own — run `headcount/subscriptions/expire` daily (see
[CLI Commands](#cli-commands)).

### 3. Set Up Webhooks

Point your Stripe webhook to:
```
https://yoursite.com/actions/headcount/webhook/stripe
```

Required Stripe events:
- `checkout.session.completed`
- `customer.subscription.created`
- `customer.subscription.updated`
- `customer.subscription.deleted`
- `invoice.paid`
- `invoice.payment_failed`
- `customer.subscription.trial_will_end`

For PayPal:
```
https://yoursite.com/actions/headcount/webhook/paypal
```

### 4. Create Access Rules

Go to **Headcount > Access Rules** to gate content. Each rule picks what it applies to from a single **Applies To** menu, grouped by element type -- all entries, a section, an entry type, entries related to a category, or one specific entry. Other plugins can add their own element types to that menu (the Showtime bundle adds Owl events, scoped by calendar), so gating isn't limited to entries.

Choose a behavior:

| Behavior | Effect |
|----------|--------|
| **Redirect** | 302 redirect to login or pricing page |
| **Paywall** | Page still renders; your template shows a teaser -- see below |
| **Hide** | Return 404 for unauthorized users |

Redirect and hide are enforced for you. **Paywall withholds nothing on its own** -- it lets the page render so the template can decide what to show:

```twig
{% set gate = craft.headcount.gatingResult %}
{% if gate %}
    {{ entry.body|striptags|slice(0, gate.teaserLength ?? 300) }}...
    <a href="{{ craft.headcount.plugin.settings.pricingUrl }}">Subscribe to read on</a>
{% else %}
    {{ entry.body }}
{% endif %}
```

If your templates already gate content themselves and you don't want rules applied automatically, turn off **Enforce Access Rules** in the settings.

### 5. Add Checkout to Templates

```twig
{# Pricing page #}
{% for plan in craft.headcount.plans() %}
    <div class="plan">
        <h3>{{ plan.name }}</h3>
        <p>{{ plan.currency|upper }} {{ plan.price|number_format(2) }}/{{ plan.billingInterval }}</p>
        {% if plan.features %}
            <ul>
                {% for feature in plan.features %}
                    <li>{{ feature }}</li>
                {% endfor %}
            </ul>
        {% endif %}
        <form method="post">
            {{ csrfInput() }}
            <input type="hidden" name="action" value="headcount/checkout/create-session">
            <input type="hidden" name="planHandle" value="{{ plan.handle }}">
            <button type="submit">Subscribe</button>
        </form>
    </div>
{% endfor %}
```

## Template Reference

### Template Variable: `craft.headcount`

```twig
{# Check if current user has any active subscription #}
{% if craft.headcount.isSubscribed() %}

{# Check specific plan #}
{% if craft.headcount.isSubscribed('pro-monthly') %}

{# Check if user can access an element -- any element type (respects gating + drip) #}
{% if craft.headcount.canAccess(entry) %}
{% if craft.headcount.canAccess(event) %}

{# Get current user's active subscriptions #}
{% set subs = craft.headcount.subscriptions() %}

{# Get all enabled plans #}
{% set plans = craft.headcount.plans() %}

{# Get a specific plan #}
{% set pro = craft.headcount.plan('pro-monthly') %}

{# Checkout URL (form POST is preferred, but this works for links) #}
{{ craft.headcount.checkoutUrl('pro-monthly') }}

{# Customer Portal URL #}
{{ craft.headcount.portalUrl() }}

{# Drip content checks #}
{% if craft.headcount.isUnlocked(entry) %}
{{ craft.headcount.unlocksIn(entry) }} {# days remaining #}

{# Coupon input field helper #}
{{ craft.headcount.couponField() }}

{# Wallet cards -- each returns null when that platform isn't configured #}
{% if craft.headcount.walletEnabled() %}
    {% for sub in craft.headcount.subscriptions() %}
        {% set appleUrl = craft.headcount.appleWalletUrl(sub) %}
        {% if appleUrl %}<a href="{{ appleUrl }}">Add to Apple Wallet</a>{% endif %}

        {% set googleUrl = craft.headcount.googleWalletUrl(sub) %}
        {% if googleUrl %}<a href="{{ googleUrl }}">Save to Google Wallet</a>{% endif %}
    {% endfor %}
{% endif %}

{# Raw subscription query #}
{% set query = craft.headcount.subscriptionQuery() %}
```

### Twig Tag: `{% headcountGate %}`

```twig
{# Gate content to any active subscriber #}
{% headcountGate %}
    <p>Members-only content here.</p>
{% endheadcountGate %}

{# Gate to a specific plan #}
{% headcountGate planHandle='pro-monthly' %}
    <p>Pro content here.</p>
{% endheadcountGate %}
```

### Using Craft's Built-in User Group Checks

Since Headcount syncs subscriptions to Craft user groups, you can also use native Craft checks:

```twig
{% if currentUser and currentUser.isInGroup('pro') %}
    <p>Pro member content</p>
{% endif %}
```

## Wallet Cards

Members can add a membership card to Apple Wallet or Google Wallet and show it to prove
membership away from the site — at a club shop, a partner offering a members' discount, or
the gate.

Both platforms bind a card to the organisation issuing it, so **the credentials are yours,
not Headcount's**: a plugin cannot ship them. You supply them under
**Headcount → Settings → Wallet Cards**, and every field there accepts an environment
variable.

### What each card shows

The member's name, the plan, the status, and the date the membership runs out — plus a QR
code. Scanning it opens a verification page on your own site that answers **Valid** or
**Not valid** in one word, then the member's name and expiry underneath. That page is
deliberately readable by a shop assistant with nothing but a phone camera: no app, no reader
hardware, and nothing to install.

Override it by adding your own `headcount/wallet/verify.twig` to your site's templates
directory; it receives `valid` (bool) and `card` (the card's fields, or null). Requesting it
with `Accept: application/json` returns the same answer as JSON.

### Apple Wallet

From your Apple Developer account you need:

1. A **Pass Type ID** (e.g. `pass.com.yourclub.membership`) registered under Identifiers.
2. Its certificate, downloaded and then exported from Keychain Access as a **`.p12`**.
3. Apple's **WWDR intermediate certificate** in `.pem` form.
4. Your ten-character **team identifier**.

Point the settings at the two files — outside your web root — and give the `.p12` password as
an environment variable. You also need an image directory containing at least `icon.png`
(plus optionally `icon@2x.png`, `logo.png`, `logo@2x.png`); iOS refuses a pass with no icon.

**Keeping cards up to date.** Leave *Keep Cards Up To Date* on and Headcount runs Apple's
pass web service: devices register themselves against each pass, and whenever a membership
changes, Headcount sends a silent push so the phone re-fetches the card. A membership
cancelled in March greys out in the member's wallet without waiting for its expiry date. This
needs your site reachable over HTTPS with a valid certificate — devices silently refuse to
register otherwise — and PHP built with curl and HTTP/2.

Turn it off and cards are still issued and still carry their expiry date, so a season card
stops looking valid on 1 July by itself; only mid-term changes go unnoticed on the device.

### Google Wallet

From the Google Pay & Wallet Console:

1. Create an **issuer account** and note its numeric issuer ID.
2. Create a Google Cloud **service account** with the *Wallet Object Issuer* role, authorise
   it in the console, and download its JSON key.

Point the settings at the key file. Google needs no per-device machinery: the card lives on
Google's servers, so an update is a single API call that reaches every device the member
added it to.

### Linking to cards

See the wallet helpers under [Template Reference](#template-variable-craftheadcount). Each
returns null when that platform isn't configured, so a site issuing only Google cards needs
no conditional of its own. Admins can also download or open any member's card from the
subscription's page in the control panel.

## REST API

All endpoints are prefixed with `/actions/headcount/api/`.

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/plans` | Public | List all enabled plans |
| GET | `/plan?handle=xxx` | Public | Get a specific plan |
| GET | `/subscriptions` | Session / API key | List a member's subscriptions |
| GET | `/subscription?id=xxx` | Session / API key | Get a specific subscription |
| POST | `/checkout` | Session | Create a checkout session |
| GET | `/portal` | Session | Get Stripe Customer Portal URL |
| GET | `/member` | Session / API key | Get a member's info with subscriptions |

### Authentication

**Session** — a logged-in user's own session. The request always acts for the logged-in user;
`userId` / `email` parameters are ignored, so one member can never read another's data.

**API key** — set an API key under *Headcount → Settings* and send it in the
`X-Headcount-Api-Key` header. It is a single, global, trusted server credential, so it is for
server-to-server use only — never ship it to a browser. Requests with no key, or a wrong key,
get `401`. If no API key is configured, key authentication is off and every non-public
endpoint rejects anonymous callers.

Because the key belongs to the server rather than to a member, a key-authenticated request has
to say *which* member it is acting for, with either `userId` or `email`:

```bash
curl -H "X-Headcount-Api-Key: $HEADCOUNT_API_KEY" \
  "https://example.com/actions/headcount/api/member?email=member@example.com"

curl -H "X-Headcount-Api-Key: $HEADCOUNT_API_KEY" \
  "https://example.com/actions/headcount/api/subscriptions?userId=42"
```

| Situation | Response |
|-----------|----------|
| No key / wrong key on a non-public endpoint | `401 Unauthorized` |
| Key present, no `userId` or `email` | `400 Bad Request` |
| Key present, member doesn't exist | `404 Not Found` |
| `/subscription?id=` that the named member doesn't own | `403 Forbidden` |

`POST /checkout` and `GET /portal` act on the current member's own payment session and remain
session-only — an API key does not grant access to them.

> **Breaking in 5.2.0:**
> - The `?apiKey=` query-parameter fallback has been removed — keys in URLs leak into access
>   logs, browser history, and `Referer` headers. Move any integration still using it to the
>   `X-Headcount-Api-Key` header.
> - API-key requests to `/subscriptions`, `/subscription` and `/member` must now pass `userId`
>   or `email`. (These previously returned `401` for any session-less caller, so no working
>   key-based integration can exist, but the endpoints are now reachable where before they
>   were not.)
> - `POST /checkout` now requires a CSRF token, like every other Craft POST action. Front-end
>   JS must send `CRAFT_CSRF_TOKEN` (or the `X-CSRF-Token` header).

## CLI Commands

Both of the first two want a daily cron entry; nothing schedules them for you.

```bash
# Retire memberships whose end date has passed -- cancels those the member cancelled,
# expires finished season terms. Run daily.
php craft headcount/subscriptions/expire

# Email members whose membership runs out within the reminder window. Run daily.
# Each member is reminded once per term, so running it more often sends no more email.
php craft headcount/subscriptions/remind

# Sync all active subscription statuses from Stripe/PayPal
php craft headcount/subscriptions/sync

# Sync plans to payment providers (create Stripe Products/Prices)
php craft headcount/sync/plans

# Check sync health and display stats
php craft headcount/sync/status
```

## Events

Headcount fires events you can listen to in custom modules or plugins:

```php
use justinholtweb\headcount\services\Subscriptions;
use justinholtweb\headcount\events\SubscriptionEvent;
use yii\base\Event;

Event::on(
    Subscriptions::class,
    Subscriptions::EVENT_AFTER_CREATE_SUBSCRIPTION,
    function (SubscriptionEvent $event) {
        $subscription = $event->subscription;
        // Custom logic after subscription creation
    }
);
```

Available events on `Subscriptions` service:
- `EVENT_BEFORE_CREATE_SUBSCRIPTION` / `EVENT_AFTER_CREATE_SUBSCRIPTION`
- `EVENT_BEFORE_UPDATE_SUBSCRIPTION` / `EVENT_AFTER_UPDATE_SUBSCRIPTION`
- `EVENT_BEFORE_CANCEL_SUBSCRIPTION` / `EVENT_AFTER_CANCEL_SUBSCRIPTION`

## Outgoing Webhooks

Configure a webhook URL in **Headcount > Settings** to receive POST notifications for subscription lifecycle events. Payloads are signed with HMAC-SHA256 via the `X-Headcount-Signature` header.

Events: `subscription.created`, `subscription.updated`, `subscription.canceled`, `subscription.expired`, `member.upgraded`, `member.downgraded`

## Architecture

Headcount uses a **hybrid architecture**:

- **Subscription Element** -- A custom Craft element type that stores billing state (gateway IDs, status, dates, amounts) in the `headcount_subscriptions` table linked to the `elements` table
- **User Group Sync** -- Active subscriptions automatically add users to the plan's mapped Craft user group; cancellation/expiration removes them
- **Content Gating** -- Applied in `beforeAction` against the element Craft routed to, so gating works regardless of template code (Craft never calls `canView()` while resolving a front-end URL). `Elements::EVENT_AUTHORIZE_VIEW` is also answered, for anything that does ask. Element types register themselves as gateable via `Gating::EVENT_REGISTER_GATE_TARGETS`
- **Emails** -- The member lifecycle emails are Craft system messages (`headcount_welcome`, `headcount_receipt`, `headcount_payment_failed`, `headcount_expiration_reminder`, `headcount_trial_ending`, `headcount_cancellation`, `headcount_drip_unlocked`), editable under Settings → Email → System Messages and rendered through the site's HTML email template. Sending is queued
- **Payment Gateways** -- Stripe uses the `stripe/stripe-php` SDK with the `StripeClient` instance pattern; PayPal uses the REST API v2 directly via Guzzle

### Database Tables

| Table | Purpose |
|-------|---------|
| `headcount_plans` | Membership plan definitions |
| `headcount_subscriptions` | Subscription element content (FK to `elements`) |
| `headcount_access_rules` | Content gating rules |
| `headcount_drip_schedules` | Drip content timing |
| `headcount_coupons` | Discount codes |
| `headcount_webhook_logs` | Incoming webhook event log (idempotency) |
| `headcount_wallet_registrations` | Which devices hold which Apple Wallet pass, and where to push updates |

## Support

- [GitHub Issues](https://github.com/justinholtweb/craft-headcount/issues)
- [Documentation](https://github.com/justinholtweb/craft-headcount/wiki)
