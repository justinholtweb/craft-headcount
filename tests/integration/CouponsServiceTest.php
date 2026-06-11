<?php

namespace justinholtweb\headcount\tests\integration;

use PHPUnit\Framework\TestCase;

/**
 * Example integration test for the Coupons service.
 *
 * Coupons::validateCoupon() reads a CouponRecord (an ActiveRecord) from the
 * database, so unlike the model tests it cannot run as a pure unit test — it
 * needs a booted Craft application with a migrated test database.
 *
 * This class documents the cases worth covering once a Craft test harness is
 * wired up (see tests/README.md). Each test is skipped until then so the
 * `integration` suite stays green rather than failing on an empty directory.
 *
 * @covers \justinholtweb\headcount\services\Coupons
 */
class CouponsServiceTest extends TestCase
{
    protected function setUp(): void
    {
        self::markTestSkipped(
            'Requires a Craft test application + database. See tests/README.md ' .
            '("Integration tests") for the harness this needs.'
        );
    }

    public function testValidateRejectsUnknownCode(): void
    {
        // Expect: ['valid' => false, 'error' => 'Coupon not found.']
    }

    public function testValidateRejectsDisabledCoupon(): void
    {
        // Given an enabled=false coupon, expect 'Coupon is no longer active.'
    }

    public function testValidateRejectsExpiredCoupon(): void
    {
        // Given expiresAt in the past, expect 'Coupon has expired.'
    }

    public function testValidateRejectsCouponAtUsageLimit(): void
    {
        // Given currentUses >= maxUses, expect 'Coupon usage limit reached.'
    }

    public function testValidateRejectsCouponNotApplicableToPlan(): void
    {
        // Given planIds = [2,3] and a request for planId 1, expect
        // 'Coupon is not valid for this plan.'
    }

    public function testValidateReturnsDiscountForValidCoupon(): void
    {
        // Expect ['valid' => true, 'discountType' => ..., 'discountAmount' => ...]
    }

    public function testIncrementUsageBumpsCurrentUses(): void
    {
        // After incrementUsage($code), currentUses should increase by 1.
    }
}
