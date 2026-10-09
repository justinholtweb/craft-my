<?php

namespace justinholtweb\my\queue\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\my\Plugin;

/**
 * Sends the MYOB sync summary if it is due. Queued by the web fallback trigger.
 *
 * Decides nothing itself: {@see \justinholtweb\my\services\Digest::run()} checks the schedule and
 * claims the period, so a job that runs late, twice, or after cron already sent is a no-op.
 */
class SendDigest extends BaseJob
{
    public function execute($queue): void
    {
        Plugin::getInstance()->getDigest()->run();
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('my', 'Sending the MYOB sync summary');
    }
}
