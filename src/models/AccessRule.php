<?php

namespace justinholtweb\headcount\models;

use craft\base\Model;

class AccessRule extends Model
{
    public ?int $id = null;
    public string $name = '';
    public string $type = 'section';
    public ?int $targetId = null;
    public ?string $targetUid = null;
    public ?array $planIds = null;
    public string $behavior = 'redirect';
    public ?string $redirectUrl = null;
    public ?int $teaserLength = null;
    public int $sortOrder = 0;
    public bool $enabled = true;
    public ?string $dateCreated = null;
    public ?string $dateUpdated = null;
    public ?string $uid = null;

    public function defineRules(): array
    {
        return [
            [['name', 'type', 'behavior'], 'required'],
            [['name'], 'string', 'max' => 255],
            [['type'], 'in', 'range' => ['section', 'entryType', 'category', 'entry', 'custom']],
            [['behavior'], 'in', 'range' => ['redirect', 'paywall', 'hide']],
            [['targetId', 'teaserLength', 'sortOrder'], 'integer'],
            [['redirectUrl'], 'string', 'max' => 255],
            [['enabled'], 'boolean'],
        ];
    }
}
