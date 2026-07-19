<?php

namespace justinholtweb\headcount\tests\integration;

use craft\test\TestCase;
use justinholtweb\headcount\Headcount;
use justinholtweb\headcount\records\CouponRecord;
use justinholtweb\headcount\services\Coupons;

/**
 * Integration coverage for {@see Coupons::validateCoupon()} and
 * {@see Coupons::incrementUsage()} — the coupon redemption gate. These read and
 * write real CouponRecords, so they run against the booted Craft test app.
 *
 * @covers \justinholtweb\headcount\services\Coupons
 */
class CouponsServiceTest extends TestCase
{
    private Coupons $coupons;

    protected function _before(): void
    {
        $this->coupons = Headcount::getInstance()->coupons;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function makeCoupon(array $attributes = []): CouponRecord
    {
        $record = new CouponRecord();
        $record->setAttributes(array_merge([
            'code' => 'SAVE10',
            'discountType' => 'percent',
            'discountAmount' => 10,
            'currentUses' => 0,
            'enabled' => true,
        ], $attributes), false);

        self::assertTrue($record->save(), implode(', ', $record->getErrorSummary(true)));

        return $record;
    }

    public function testValidateRejectsUnknownCode(): void
    {
        $result = $this->coupons->validateCoupon('DOES-NOT-EXIST');

        self::assertFalse($result['valid']);
        self::assertSame('Coupon not found.', $result['error']);
    }

    public function testValidateRejectsDisabledCoupon(): void
    {
        $this->makeCoupon(['code' => 'DISABLED', 'enabled' => false]);

        $result = $this->coupons->validateCoupon('DISABLED');

        self::assertFalse($result['valid']);
        self::assertSame('Coupon is no longer active.', $result['error']);
    }

    public function testValidateRejectsExpiredCoupon(): void
    {
        $this->makeCoupon([
            'code' => 'EXPIRED',
            'expiresAt' => date('Y-m-d H:i:s', strtotime('-1 day')),
        ]);

        $result = $this->coupons->validateCoupon('EXPIRED');

        self::assertFalse($result['valid']);
        self::assertSame('Coupon has expired.', $result['error']);
    }

    public function testValidateAcceptsCouponExpiringInFuture(): void
    {
        $this->makeCoupon([
            'code' => 'FUTURE',
            'expiresAt' => date('Y-m-d H:i:s', strtotime('+1 day')),
        ]);

        $result = $this->coupons->validateCoupon('FUTURE');

        self::assertTrue($result['valid']);
    }

    public function testValidateRejectsCouponAtUsageLimit(): void
    {
        $this->makeCoupon(['code' => 'MAXED', 'maxUses' => 5, 'currentUses' => 5]);

        $result = $this->coupons->validateCoupon('MAXED');

        self::assertFalse($result['valid']);
        self::assertSame('Coupon usage limit reached.', $result['error']);
    }

    public function testValidateRejectsCouponNotApplicableToPlan(): void
    {
        $this->makeCoupon(['code' => 'PLANLOCKED', 'planIds' => json_encode([2, 3])]);

        $result = $this->coupons->validateCoupon('PLANLOCKED', 1);

        self::assertFalse($result['valid']);
        self::assertSame('Coupon is not valid for this plan.', $result['error']);
    }

    public function testValidateAcceptsCouponForApplicablePlan(): void
    {
        $this->makeCoupon(['code' => 'PLANOK', 'planIds' => json_encode([1, 2])]);

        $result = $this->coupons->validateCoupon('PLANOK', 1);

        self::assertTrue($result['valid']);
    }

    public function testValidateReturnsDiscountForValidCoupon(): void
    {
        $this->makeCoupon([
            'code' => 'HALFOFF',
            'discountType' => 'percent',
            'discountAmount' => 50,
        ]);

        $result = $this->coupons->validateCoupon('HALFOFF');

        self::assertTrue($result['valid']);
        self::assertSame('percent', $result['discountType']);
        self::assertSame(50.0, $result['discountAmount']);
        self::assertInstanceOf(CouponRecord::class, $result['coupon']);
    }

    public function testIncrementUsageBumpsCurrentUses(): void
    {
        $this->makeCoupon(['code' => 'COUNTME', 'currentUses' => 2]);

        $this->coupons->incrementUsage('COUNTME');

        self::assertSame(3, (int)$this->coupons->getCouponByCode('COUNTME')->currentUses);
    }
}
