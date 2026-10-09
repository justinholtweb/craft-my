<?php

namespace justinholtweb\my\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\my\Plugin;
use justinholtweb\my\services\Alerts;
use yii\console\ExitCode;

/**
 * Failure alerts from the command line.
 *
 *     php craft my/alerts/check     # evaluate every incident, send what is owed
 *     php craft my/alerts/test      # send a sample through every configured channel
 *
 * Every push already checks for failures and mismatched invoices, and MYOB refusing the
 * connection is noticed the moment it happens. Cron is what notices an incident *clearing* on a
 * quiet day, when nothing is pushing; `my/sync/retry` runs the same check after its retries.
 */
class AlertsController extends Controller
{
    public $defaultAction = 'check';

    /**
     * Evaluate every incident and send any alert or recovery that is owed.
     */
    public function actionCheck(): int
    {
        $results = Plugin::getInstance()->getAlerts()->check();

        if ($results === []) {
            $this->stdout("My is not connected to a MYOB company file; nothing to check.\n", Console::FG_GREY);

            return ExitCode::OK;
        }

        $open = 0;

        foreach ($results as $result) {
            $isOpen = $result['state'] === Alerts::STATE_OPEN;
            $open += $isOpen ? 1 : 0;

            $this->stdout(str_pad(Alerts::incidentLabel($result['incident']), 40));
            $this->stdout(str_pad($isOpen ? 'open' : 'ok', 6), $isOpen ? Console::FG_RED : Console::FG_GREEN);

            if ($result['transition'] !== null) {
                $this->stdout(' ' . $result['transition'], Console::FG_YELLOW);
            }

            if ($result['notified']) {
                $this->stdout(' · notified', Console::FG_YELLOW);
            }

            $this->stdout("\n");

            if ($isOpen && $result['detail']) {
                $this->stdout('  ' . $result['detail'] . "\n", Console::FG_GREY);
            }
        }

        $this->stdout(sprintf("\n%d open incident%s.\n", $open, $open === 1 ? '' : 's'));

        // Exit 0 either way: an open incident is news, not a failure of this command, and cron
        // would otherwise email somebody about the alert on top of the alert.
        return ExitCode::OK;
    }

    /**
     * Send a sample alert through every configured channel.
     */
    public function actionTest(): int
    {
        $result = Plugin::getInstance()->getAlerts()->sendTest();

        if ($result['email'] === null && $result['webhook'] === null) {
            $this->stderr("No recipients and no webhook are configured — nothing to send to.\n", Console::FG_YELLOW);

            return ExitCode::CONFIG;
        }

        $ok = true;

        if ($result['email'] !== null) {
            $this->stdout('Email:   ' . ($result['email'] ? "sent\n" : "failed — check Craft's email settings\n"), $result['email'] ? Console::FG_GREEN : Console::FG_RED);
            $ok = $result['email'];
        }

        if ($result['webhook'] !== null) {
            $this->stdout('Webhook: ' . ($result['webhook'] === true ? "sent\n" : 'failed — ' . $result['webhook'] . "\n"), $result['webhook'] === true ? Console::FG_GREEN : Console::FG_RED);
            $ok = $ok && $result['webhook'] === true;
        }

        return $ok ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }
}
