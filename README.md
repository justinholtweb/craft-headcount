# Headcount for Craft CMS 5

Full-featured membership and subscription management plugin for Craft CMS 5 with Stripe and PayPal integration.

## Features

- **Stripe & PayPal Integration** - Accept recurring payments through Stripe Checkout and PayPal Subscriptions
- **Membership Plans** - Create tiered plans with configurable billing intervals, trial periods, and pricing
- **Content Gating** - Restrict access to entries by section, entry type, category, or individual entry
- **Drip Content** - Schedule content to unlock over time after subscription start
- **User Group Sync** - Automatically manage Craft user group membership based on subscription status
- **Coupons & Discounts** - Create percentage or flat-rate discount codes synced with Stripe
- **Member Portal** - Self-service subscription management via Stripe Customer Portal
- **Reporting** - Track MRR, churn, growth, and revenue with built-in analytics
- **Email Notifications** - Automated transactional emails for the full subscription lifecycle
- **REST API** - Full API for headless and decoupled front-ends
- **Twig Extension** - Rich template API with `craft.headcount` and `{% headcountGate %}` tag

## Requirements

- Craft CMS 5.0 or later
- PHP 8.2 or later
- A Stripe account (for Stripe payments)
- A PayPal Business account (for PayPal payments, optional)

## Installation

Install via Composer:

```bash
composer require justinholtweb/craft-headcount
php craft plugin/install headcount
```

Or install from the Craft Plugin Store.

## Configuration

1. Navigate to **Headcount** > **Settings** in the control panel
2. Enter your Stripe API keys (test or live)
3. Optionally configure PayPal credentials
4. Create your first membership plan under **Headcount** > **Plans**
5. Set up access rules under **Headcount** > **Access Rules**

Settings can be overridden via `config/headcount.php`:

```php
return [
    'stripeSecretKey' => getenv('STRIPE_SECRET_KEY'),
    'stripePublishableKey' => getenv('STRIPE_PUBLISHABLE_KEY'),
    'stripeWebhookSecret' => getenv('STRIPE_WEBHOOK_SECRET'),
];
```

## Template Usage

```twig
{# Check if user is subscribed #}
{% if craft.headcount.isSubscribed() %}
    <p>Welcome, member!</p>
{% endif %}

{# Check specific plan #}
{% if craft.headcount.isSubscribed('pro-monthly') %}
    <p>Pro content here</p>
{% endif %}

{# Content gating tag #}
{% headcountGate planHandle='pro-monthly' %}
    <p>This content is only visible to Pro subscribers.</p>
{% endheadcountGate %}

{# Checkout and portal links #}
<a href="{{ craft.headcount.checkoutUrl('pro-monthly') }}">Subscribe</a>
<a href="{{ craft.headcount.portalUrl() }}">Manage Subscription</a>

{# List all plans #}
{% for plan in craft.headcount.plans() %}
    <h3>{{ plan.name }} - {{ plan.price|currency(plan.currency) }}/{{ plan.billingInterval }}</h3>
{% endfor %}
```

## Documentation

Full documentation is available at [github.com/justinholtweb/craft-headcount/wiki](https://github.com/justinholtweb/craft-headcount/wiki).

## Support

- [GitHub Issues](https://github.com/justinholtweb/craft-headcount/issues)
- [Documentation](https://github.com/justinholtweb/craft-headcount/wiki)
