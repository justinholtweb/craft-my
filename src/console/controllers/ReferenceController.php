<?php

namespace justinholtweb\my\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\my\errors\MyobApiException;
use justinholtweb\my\Plugin;
use yii\console\ExitCode;

/**
 * MYOB's chart of accounts and tax codes, on the command line.
 *
 * The settings screen asks for account codes like `4-1000`. This is how you find out what they
 * are without alt-tabbing to AccountRight.
 */
class ReferenceController extends Controller
{
    /**
     * List the accounts.
     */
    public function actionAccounts(): int
    {
        try {
            $accounts = Plugin::getInstance()->getReference()->getAccounts();
        } catch (MyobApiException $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        foreach ($accounts as $account) {
            $this->stdout(sprintf("  %-10s %-18s %s\n", $account['DisplayID'], $account['Type'], $account['Name']));
        }

        $this->stdout("\n" . count($accounts) . " accounts.\n");

        return ExitCode::OK;
    }

    /**
     * List the tax codes.
     */
    public function actionTaxCodes(): int
    {
        try {
            $codes = Plugin::getInstance()->getReference()->getTaxCodes();
        } catch (MyobApiException $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        foreach ($codes as $code) {
            $this->stdout(sprintf("  %-6s %6s%%  %s\n", $code['Code'], rtrim(rtrim(number_format($code['Rate'], 2), '0'), '.'), $code['Description']));
        }

        $this->stdout("\n" . count($codes) . " tax codes.\n");

        return ExitCode::OK;
    }

    /**
     * Look up one inventory item by its number.
     *
     * @param string $number The MYOB item number, which is what a Commerce SKU maps onto.
     */
    public function actionItem(string $number): int
    {
        $item = Plugin::getInstance()->getReference()->findItem($number);

        if ($item === null) {
            $this->stdout("No MYOB item numbered “$number”.\n", Console::FG_YELLOW);

            return ExitCode::DATAERR;
        }

        $this->stdout("  UID:    {$item['UID']}\n");
        $this->stdout("  Number: {$item['Number']}\n");
        $this->stdout("  Name:   {$item['Name']}\n");
        $this->stdout('  Sold:   ' . ($item['IsSold'] ? 'yes' : 'no') . "\n");

        return ExitCode::OK;
    }

    /**
     * Throw the cached reference data away and read it again.
     */
    public function actionRefresh(): int
    {
        $reference = Plugin::getInstance()->getReference();
        $reference->flush();
        $result = $reference->refreshAll();

        $this->stdout($result['message'] . "\n", $result['ok'] ? Console::FG_GREEN : Console::FG_RED);

        return $result['ok'] ? ExitCode::OK : ExitCode::UNAVAILABLE;
    }
}
