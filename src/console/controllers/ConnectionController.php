<?php

namespace justinholtweb\my\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\my\errors\MyobApiException;
use justinholtweb\my\Plugin;
use yii\console\ExitCode;

/**
 * Checking the MYOB connection without a browser.
 *
 * Useful precisely where the CP is not: a deploy hook confirming the connection survived, a cron
 * job that should not run against the wrong company file.
 */
class ConnectionController extends Controller
{
    /**
     * Ask MYOB who we are.
     */
    public function actionTest(): int
    {
        $result = Plugin::getInstance()->getAuth()->verify();

        $this->stdout($result['message'] . "\n", $result['ok'] ? Console::FG_GREEN : Console::FG_RED);

        return $result['ok'] ? ExitCode::OK : ExitCode::UNAVAILABLE;
    }

    /**
     * List the company files this connection can see.
     */
    public function actionCompanyFiles(): int
    {
        $files = Plugin::getInstance()->getAuth()->getCompanyFiles();

        if ($files === []) {
            $this->stdout("MYOB returned no company files.\n", Console::FG_YELLOW);
            $this->stdout("API keys issued after 12 March 2025 are not allowed to list them; set the ID in settings instead.\n");

            return ExitCode::OK;
        }

        foreach ($files as $file) {
            $this->stdout(sprintf("  %s  %s  %s\n", $file['Id'], $file['Country'] ?: '--', $file['Name']));
        }

        return ExitCode::OK;
    }

    /**
     * Force a token refresh.
     */
    public function actionRefresh(): int
    {
        try {
            $connection = Plugin::getInstance()->getAuth()->refresh();
        } catch (MyobApiException $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        $this->stdout('Token refreshed, valid until ' . $connection->expiryDate?->format('H:i:s') . ".\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
