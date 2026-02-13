<?php

namespace justinholtweb\headcount\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\headcount\Headcount;
use justinholtweb\headcount\models\AccessRule;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class AccessRulesController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('headcount-manageAccessRules');
        return true;
    }

    public function actionIndex(): Response
    {
        $rules = Headcount::getInstance()->gating->getAllRules();

        return $this->renderTemplate('headcount/access-rules/index', [
            'rules' => $rules,
        ]);
    }

    public function actionEdit(?int $ruleId = null, ?AccessRule $rule = null): Response
    {
        if ($rule === null) {
            if ($ruleId !== null) {
                $rule = Headcount::getInstance()->gating->getRuleById($ruleId);
                if (!$rule) {
                    throw new NotFoundHttpException('Access rule not found');
                }
            } else {
                $rule = new AccessRule();
            }
        }

        $isNew = !$rule->id;

        $plans = Headcount::getInstance()->plans->getAllPlans();
        $planOptions = [];
        foreach ($plans as $plan) {
            $planOptions[] = ['label' => $plan->name, 'value' => $plan->id];
        }

        // Get sections for target selection
        $sections = Craft::$app->getEntries()->getAllSections();
        $sectionOptions = [];
        foreach ($sections as $section) {
            $sectionOptions[] = ['label' => $section->name, 'value' => $section->id];
        }

        // Get entry types
        $entryTypeOptions = [];
        foreach ($sections as $section) {
            foreach ($section->getEntryTypes() as $entryType) {
                $entryTypeOptions[] = ['label' => $section->name . ' - ' . $entryType->name, 'value' => $entryType->id];
            }
        }

        return $this->renderTemplate('headcount/access-rules/edit', [
            'rule' => $rule,
            'isNew' => $isNew,
            'title' => $isNew ? Craft::t('headcount', 'New Access Rule') : $rule->name,
            'planOptions' => $planOptions,
            'sectionOptions' => $sectionOptions,
            'entryTypeOptions' => $entryTypeOptions,
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $ruleId = $request->getBodyParam('ruleId');

        if ($ruleId) {
            $rule = Headcount::getInstance()->gating->getRuleById($ruleId);
            if (!$rule) {
                throw new NotFoundHttpException('Access rule not found');
            }
        } else {
            $rule = new AccessRule();
        }

        $rule->name = $request->getBodyParam('name', $rule->name);
        $rule->type = $request->getBodyParam('type', $rule->type);
        $rule->targetId = $request->getBodyParam('targetId') ?: null;
        $rule->planIds = $request->getBodyParam('planIds') ?: null;
        $rule->behavior = $request->getBodyParam('behavior', $rule->behavior);
        $rule->redirectUrl = $request->getBodyParam('redirectUrl', $rule->redirectUrl);
        $rule->teaserLength = $request->getBodyParam('teaserLength') ?: null;
        $rule->sortOrder = (int)$request->getBodyParam('sortOrder', $rule->sortOrder);
        $rule->enabled = (bool)$request->getBodyParam('enabled', $rule->enabled);

        if (!Headcount::getInstance()->gating->saveRule($rule)) {
            return $this->asFailure(
                Craft::t('headcount', 'Couldn\'t save access rule.'),
                ['rule' => $rule]
            );
        }

        return $this->asSuccess(
            Craft::t('headcount', 'Access rule saved.'),
            ['rule' => $rule],
            'headcount/access-rules/' . $rule->id
        );
    }

    public function actionDelete(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $ruleId = Craft::$app->getRequest()->getRequiredBodyParam('id');
        Headcount::getInstance()->gating->deleteRuleById($ruleId);

        return $this->asSuccess(Craft::t('headcount', 'Access rule deleted.'));
    }
}
