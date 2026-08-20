<?php

namespace justinholtweb\my\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\my\Plugin;
use yii\console\ExitCode;

/**
 * The connection log, on the command line.
 */
class LogController extends Controller
{
    /**
     * Days to keep. Defaults to the configured retention.
     */
    public ?int $days = null;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return $actionID === 'prune'
            ? array_merge(parent::options($actionID), ['days'])
            : parent::options($actionID);
    }

    /**
     * Delete entries older than the retention period.
     */
    public function actionPrune(): int
    {
        $count = Plugin::getInstance()->getLog()->prune($this->days);

        $this->stdout("$count entries deleted.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Delete the whole log.
     */
    public function actionClear(): int
    {
        if ($this->interactive && !$this->confirm('Delete every log entry?')) {
            return ExitCode::OK;
        }

        $count = Plugin::getInstance()->getLog()->clear();

        $this->stdout("$count entries deleted.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * The most recent entries.
     */
    public function actionIndex(int $limit = 20): int
    {
        foreach (array_reverse(Plugin::getInstance()->getLog()->getEntries([], $limit)) as $entry) {
            $this->stdout(sprintf(
                "  %s  %-18s %-4s %-40s %s\n",
                $entry->dateCreated?->format('Y-m-d H:i:s'),
                $entry->action,
                $entry->statusCode ?: '—',
                mb_substr((string)$entry->endpoint, 0, 40),
                (string)$entry->summary,
            ), match ($entry->level) {
                'error' => Console::FG_RED,
                'warning' => Console::FG_YELLOW,
                default => Console::FG_GREY,
            });
        }

        return ExitCode::OK;
    }
}
