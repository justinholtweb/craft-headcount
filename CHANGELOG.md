# Changelog

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
