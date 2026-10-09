<?php

namespace justinholtweb\my\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\my\Plugin;
use justinholtweb\my\services\Digest;
use yii\console\ExitCode;

/**
 * `php craft my/digest/send` — the scheduled MYOB sync summary, for cron.
 *
 * Run it as often as you like; it sends once per period, and only once the configured day and
 * hour have arrived:
 *
 *     0,15,30,45 * * * * php /path/to/craft my/digest/send
 *
 * Exits 0 for every outcome that is not a fault — not due yet, already sent, nothing to report —
 * so cron stays quiet. Exits non-zero when the summary is switched on but has nowhere to go, or
 * when the send failed (the next run will try again).
 */
class DigestController extends Controller
{
    public $defaultAction = 'send';

    /** Send now, whatever the schedule says and even if this period's summary has gone. */
    public bool $force = false;

    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if ($actionID === 'send') {
            $options[] = 'force';
        }

        return $options;
    }

    public function actionSend(): int
    {
        $result = Plugin::getInstance()->getDigest()->run(null, $this->force);

        [$message, $colour, $code] = match ($result) {
            Digest::RESULT_SENT => ['Summary sent.', Console::FG_GREEN, ExitCode::OK],
            Digest::RESULT_NOTHING_NEW => ['Nothing was pushed and nothing new went wrong, so no summary was sent.', Console::FG_GREY, ExitCode::OK],
            Digest::RESULT_NOT_DUE => ['Not due yet.', Console::FG_GREY, ExitCode::OK],
            Digest::RESULT_ALREADY_SENT => ['This period’s summary has already gone.', Console::FG_GREY, ExitCode::OK],
            Digest::RESULT_DISABLED => ['The sync summary is switched off in My’s settings.', Console::FG_GREY, ExitCode::OK],
            Digest::RESULT_CANCELLED => ['An event handler cancelled the summary.', Console::FG_YELLOW, ExitCode::OK],
            Digest::RESULT_NO_RECIPIENTS => ['The summary has no valid recipients. Add some under Alerts in My’s settings.', Console::FG_RED, ExitCode::CONFIG],
            default => ['The summary could not be sent; see the logs. The next run will try again.', Console::FG_RED, ExitCode::UNSPECIFIED_ERROR],
        };

        $this->stdout($message . "\n", $colour);

        return $code;
    }

    /** What the schedule says: last run, last send, next due. */
    public function actionStatus(): int
    {
        $digest = Plugin::getInstance()->getDigest();
        $settings = Plugin::getInstance()->getSettings();
        $state = $digest->state();
        $next = $digest->nextDueAt();
        $format = fn(?\DateTimeInterface $date) => $date?->format('Y-m-d H:i T') ?? '—';

        $this->stdout(str_pad('Enabled', 18) . ($settings->digestEnabled ? 'yes' : 'no') . "\n");
        $this->stdout(str_pad('Schedule', 18) . $settings->digestFrequency . "\n");
        $this->stdout(str_pad('Recipients', 18) . count($settings->recipientList()) . "\n");
        $this->stdout(str_pad('Last period', 18) . ($state['period'] ?? '—') . "\n");
        $this->stdout(str_pad('Last run', 18) . $format($state['lastRunAt']) . ' ' . ($state['lastResult'] ?? '') . "\n");
        $this->stdout(str_pad('Last sent', 18) . $format($state['lastSentAt']) . "\n");
        $this->stdout(str_pad('Next due', 18) . $format($next) . "\n");

        return ExitCode::OK;
    }
}
