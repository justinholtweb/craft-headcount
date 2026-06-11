# Changelog

## Unreleased
### Fixed
- Outgoing webhooks now actually dispatch. The documented events (`subscription.created`, `subscription.updated`, `subscription.canceled`, `subscription.expired`, `member.upgraded`, `member.downgraded`) were never being sent; subscription lifecycle changes now deliver HMAC-SHA256 signed payloads to the configured endpoint via a queued job

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
