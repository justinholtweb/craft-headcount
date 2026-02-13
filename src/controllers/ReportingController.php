<?php

namespace justinholtweb\headcount\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\headcount\Headcount;
use yii\web\Response;

class ReportingController extends Controller
{
    public function actionDashboard(): Response
    {
        $stats = Headcount::getInstance()->reporting->getDashboardStats();

        return $this->renderTemplate('headcount/reporting/index', [
            'stats' => $stats,
        ]);
    }

    public function actionIndex(): Response
    {
        $this->requirePermission('headcount-viewReports');

        $days = (int)(Craft::$app->getRequest()->getQueryParam('days', 30));

        $stats = Headcount::getInstance()->reporting->getDashboardStats();
        $subscriptionsOverTime = Headcount::getInstance()->reporting->getNewSubscriptionsOverTime($days);
        $revenueOverTime = Headcount::getInstance()->reporting->getRevenueOverTime($days);
        $couponUsage = Headcount::getInstance()->reporting->getCouponUsageStats();

        return $this->renderTemplate('headcount/reporting/index', [
            'stats' => $stats,
            'subscriptionsOverTime' => $subscriptionsOverTime,
            'revenueOverTime' => $revenueOverTime,
            'couponUsage' => $couponUsage,
            'days' => $days,
        ]);
    }

    public function actionData(): Response
    {
        $this->requirePermission('headcount-viewReports');
        $this->requireAcceptsJson();

        $days = (int)(Craft::$app->getRequest()->getQueryParam('days', 30));

        return $this->asJson([
            'stats' => Headcount::getInstance()->reporting->getDashboardStats(),
            'subscriptionsOverTime' => Headcount::getInstance()->reporting->getNewSubscriptionsOverTime($days),
            'revenueOverTime' => Headcount::getInstance()->reporting->getRevenueOverTime($days),
        ]);
    }
}
