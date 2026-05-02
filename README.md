# Headcount for Craft CMS 5

Full-featured membership and subscription management plugin for Craft CMS 5. Stripe and PayPal integration, tiered access control, content gating, drip content, and a complete subscription lifecycle -- built for the Craft ecosystem.

## Features

- **Stripe & PayPal** -- Recurring payments via Stripe Checkout Sessions and PayPal Subscriptions API v2
- **Membership Plans** -- Tiered plans with configurable billing intervals (day/week/month/year), trial periods, and per-plan pricing
- **Content Gating** -- Restrict entries by section, entry type, category, or individual entry with redirect, paywall, or hide behaviors
- **Drip Content** -- Schedule content to unlock N days after subscription start
- **User Group Sync** -- Automatically add/remove users from Craft user groups based on subscription status
- **Coupons & Discounts** -- Percentage or flat-rate codes synced to Stripe Coupons
- **Member Portal** -- Self-service subscription management via Stripe Customer Portal
- **Reporting** -- MRR, churn rate, trial conversion, growth metrics, and dashboard widgets
- **Transactional Emails** -- Welcome, receipt, payment failed, trial ending, cancellation, and drip unlock notifications via Craft's mailer
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

Go to **Headcount > Access Rules** to gate content. Rules can target a section, entry type, category, or individual entry. Choose a behavior:

| Behavior | Effect |
|----------|--------|
| **Redirect** | 302 redirect to login or pricing page |
| **Paywall** | Show truncated teaser content with a CTA |
| **Hide** | Return 404 for unauthorized users |

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

{# Check if user can access an entry (respects gating + drip) #}
{% if craft.headcount.canAccess(entry) %}

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

## REST API

All endpoints are prefixed with `/actions/headcount/api/`.

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/plans` | Public | List all enabled plans |
| GET | `/plan?handle=xxx` | Public | Get a specific plan |
| GET | `/subscriptions` | Session/API key | List current user's subscriptions |
| GET | `/subscription?id=xxx` | Session/API key | Get a specific subscription |
| POST | `/checkout` | Session | Create a checkout session |
| GET | `/portal` | Session | Get Stripe Customer Portal URL |
| GET | `/member` | Session/API key | Get current member info with subscriptions |

**Authentication:** Session-based for logged-in users, or pass an API key via `X-Headcount-Api-Key` header or `?apiKey=` query parameter.

## CLI Commands

```bash
# Process expired subscriptions (cancel those past period end)
php craft headcount/subscriptions/expire

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
- **Content Gating** -- Hooks into `Entry::EVENT_AUTHORIZE_VIEW` at the system level, so gating works regardless of template code
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

## Support

- [GitHub Issues](https://github.com/justinholtweb/craft-headcount/issues)
- [Documentation](https://github.com/justinholtweb/craft-headcount/wiki)
