# Tests

Two-tier suite for the Headcount plugin.

## Running

```bash
composer install          # installs phpunit/phpunit (require-dev)
composer test             # runs everything
composer test-unit        # runs only the unit suite
vendor/bin/phpunit --testsuite unit
vendor/bin/phpunit --testsuite integration
```

## Tiers

### Unit (`tests/unit/`) — runnable today

Pure model logic: price/interval formatting and Yii validation rules for
`Plan`, `AccessRule`, `DripSchedule`, and `Settings`. These need nothing beyond
Composer's autoloader:

- `craft\base\Model` is autoloadable from `craftcms/cms` (a `require`
  dependency, installed in dev too).
- Yii's built-in validators fall back to plain placeholder substitution when no
  application instance exists (`Yii::t()` returns the raw message when
  `Yii::$app` is `null`), so `$model->validate()` works without booting Craft.

No database, no running Craft application.

### Integration (`tests/integration/`) — needs a harness

The services, the `Subscription` element, and the webhook handlers all touch the
database (ActiveRecords, element queries) or call `Craft::t()` / `Craft::$app`,
so they require a booted Craft application with a migrated test database. These
tests are written but **skipped** until that harness is wired up — see
`CouponsServiceTest` for the documented case list.

To enable them, add a Craft test application bootstrap. The supported path is
[`craftcms/cms` testing support](https://craftcms.com/docs/5.x/extend/testing.html):

1. Add `craftcms/cms` test dependencies and a `tests/_craft` config dir
   (`general.php`, `db.php`, a project config, and a `test.env` pointing at a
   throwaway database).
2. Point the `integration` suite at a bootstrap that instantiates the Craft test
   app and runs migrations against that database.
3. Replace the `markTestSkipped()` call in the integration tests with real
   fixtures.

## Priority coverage gaps

Highest-risk untested paths, in rough order of payoff:

1. **Webhook signature verification** (`services/Webhooks.php`) — HMAC checks and
   idempotency via `headcount_webhook_logs`. A bad change here silently accepts
   forged events or double-processes payments.
2. **Subscription lifecycle** (`services/Subscriptions.php`) — status
   transitions and the user-group sync in `services/Members.php`.
3. **Gating decisions** (`services/Gating.php`) — rule matching by
   entry/type/section/category and the drip overlay in `services/Drip.php`.
4. **Reporting math** (`services/Reporting.php`) — MRR normalization across
   billing intervals, churn, and trial-conversion rates.
