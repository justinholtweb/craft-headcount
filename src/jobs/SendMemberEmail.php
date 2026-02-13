<?php

namespace justinholtweb\headcount\jobs;

use Craft;
use craft\queue\BaseJob;

class SendMemberEmail extends BaseJob
{
    public string $to = '';
    public string $subject = '';
    public string $body = '';

    public function execute($queue): void
    {
        $message = Craft::$app->getMailer()
            ->compose()
            ->setTo($this->to)
            ->setSubject($this->subject)
            ->setTextBody($this->body)
            ->setHtmlBody(nl2br(htmlspecialchars($this->body)));

        if (!$message->send()) {
            Craft::error("Failed to send Headcount email to {$this->to}: {$this->subject}", 'headcount');
        }
    }

    protected function defaultDescription(): ?string
    {
        return "Sending Headcount email to {$this->to}";
    }
}
