<?php

namespace justinholtweb\headcount\tests\integration;

use craft\test\TestCase;
use justinholtweb\headcount\Headcount;
use justinholtweb\headcount\records\WebhookLogRecord;
use Stripe\Event as StripeEvent;

/**
 * Integration coverage for webhook idempotency
 * ({@see \justinholtweb\headcount\services\Webhooks::processStripeEvent}), the
 * highest-risk untested path: a duplicate delivery of an already-processed event
 * must be a no-op, or payments get double-processed. Backed by the
 * headcount_webhook_logs table.
 *
 * @covers \justinholtweb\headcount\services\Webhooks
 */
class WebhookIdempotencyTest extends TestCase
{
    private function stripeEvent(string $id, string $type): StripeEvent
    {
        return StripeEvent::constructFrom([
            'id' => $id,
            'type' => $type,
            'data' => ['object' => []],
        ]);
    }

    private function seedLog(string $eventId, string $status): void
    {
        $log = new WebhookLogRecord();
        $log->gateway = 'stripe';
        $log->eventId = $eventId;
        $log->eventType = 'customer.subscription.updated';
        $log->payload = '{}';
        $log->status = $status;
        self::assertTrue($log->save(), implode(', ', $log->getErrorSummary(true)));
    }

    private function logCount(string $eventId): int
    {
        return (int)WebhookLogRecord::find()->where(['eventId' => $eventId])->count();
    }

    public function testAlreadyProcessedEventIsSkipped(): void
    {
        $this->seedLog('evt_dupe', 'processed');

        $result = Headcount::getInstance()->webhooks
            ->processStripeEvent($this->stripeEvent('evt_dupe', 'customer.subscription.updated'));

        self::assertSame('already_processed', $result);
    }

    public function testProcessedEventIsNotLoggedTwice(): void
    {
        $this->seedLog('evt_dupe', 'processed');

        Headcount::getInstance()->webhooks
            ->processStripeEvent($this->stripeEvent('evt_dupe', 'customer.subscription.updated'));

        // The idempotency guard returns before writing a second log row.
        self::assertSame(1, $this->logCount('evt_dupe'));
    }

    public function testUnhandledEventTypeIsIgnoredAndLogged(): void
    {
        $result = Headcount::getInstance()->webhooks
            ->processStripeEvent($this->stripeEvent('evt_new', 'customer.updated'));

        self::assertSame('ignored', $result);

        $log = WebhookLogRecord::findOne(['eventId' => 'evt_new']);
        self::assertNotNull($log);
        self::assertSame('ignored', $log->status);
    }

    public function testFailedEventCanBeReprocessed(): void
    {
        // Only a *processed* log short-circuits; a prior failure must not block a retry.
        $this->seedLog('evt_retry', 'failed');

        $result = Headcount::getInstance()->webhooks
            ->processStripeEvent($this->stripeEvent('evt_retry', 'customer.updated'));

        self::assertNotSame('already_processed', $result);
        self::assertSame('ignored', $result);
    }
}
