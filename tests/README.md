# Tests

Two suites for the Headcount plugin:

- **`unit`** — pure model logic, run by **PHPUnit**. No database, no Craft app.
- **`integration`** — services, the `Subscription` element, and webhook handlers,
  run by **Codeception** against a booted Craft application and a throwaway
  database.

## Running

Everything runs inside DDEV (`ddev exec …`, or from `ddev ssh`):

```bash
composer test              # both suites
composer test-unit         # unit only  (phpunit --testsuite unit)
composer test-integration  # integration only (codecept run integration)

# One file / one test:
vendor/bin/codecept run integration CouponsServiceTest
vendor/bin/codecept run integration CouponsServiceTest:testValidateRejectsUnknownCode
```

## Unit (`tests/unit/`)

Pure model logic: price/interval formatting and Yii validation rules for `Plan`,
`AccessRule`, `DripSchedule`, and `Settings`. These need nothing beyond
Composer's autoloader (`tests/bootstrap.php`):

- `craft\base\Model` is autoloadable from `craftcms/cms`.
- Yii's validators degrade to plain placeholder substitution when no application
  instance exists (`Yii::t()` returns the raw message when `Yii::$app` is null),
  so `$model->validate()` works without booting Craft. The bootstrap loads Yii2's
  global `Yii` class, which Composer's autoloader does not provide on its own.

## Integration (`tests/integration/`)

Boots a real Craft application (via the `craft\test\Craft` Codeception module,
which wraps `codeception/module-yii2`) with Headcount installed, against a
dedicated test database. Test classes extend `craft\test\TestCase`.

### One-time setup

1. Copy the env template and point it at a **throwaway** database — the harness
   drops every table in it on each run:

   ```bash
   cp tests/.env.example tests/.env
   ```

   The defaults target a standard DDEV environment (host `db`, root/root, a
   `test` database). Create it once if needed:

   ```bash
   ddev mysql -uroot -proot -e "CREATE DATABASE IF NOT EXISTS test;"
   ```

2. Generate the Codeception actor classes (rerun after changing suite/module
   config):

   ```bash
   ddev exec vendor/bin/codecept build
   ```

### How it fits together

| File | Role |
|------|------|
| `codeception.yml` | Root config; configures the `craft\test\Craft` module and registers the plugin so its `Install` migration runs. |
| `tests/integration.suite.yml` | Enables `Asserts`, `\craft\test\Craft`, and the suite helper. |
| `tests/_bootstrap.php` | Defines the `CRAFT_*` path constants and calls `TestSetup::configureCraft()`. |
| `tests/_craft/config/*` | `test.php` (app config), `db.php`, `general.php`. |
| `tests/.env` | Test DB credentials + security key (gitignored; copy from `.env.example`). |

Each test runs inside a database transaction that is rolled back afterward, so
tests are isolated. A few services cache loaded rows on the plugin singleton
(`Plans`, `Gating`, `Drip`); tests that seed those tables directly reset the
cache (`savePlan()` does it automatically; `Gating`/`Drip` tests reset it via
`setInaccessibleProperty()`).

## Coverage

The high-risk paths now exercised by the integration suite:

1. **Webhook idempotency** (`WebhookIdempotencyTest`) — an already-processed
   `headcount_webhook_logs` entry short-circuits reprocessing; failed events can
   be retried.
2. **Subscription lifecycle + group sync** (`SubscriptionLifecycleTest`) —
   activating a subscription grants the plan's Craft user group, canceling
   revokes it, and the group is retained while another active subscription still
   maps to it.
3. **Gating decisions** (`GatingServiceTest`) — rule matching by section and the
   subscription check.
4. **Reporting math** (`ReportingServiceTest`) — MRR normalization across billing
   intervals and interval counts, active-member counts.
5. **Coupon redemption** (`CouponsServiceTest`) — validation and usage counting.
6. **JSON column decoding** (`JsonColumnRegressionTest`) — regression guard for
   the `json()`-column double-encoding bug (plan features, subscription metadata,
   drip plan IDs).
