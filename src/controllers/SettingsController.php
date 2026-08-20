<?php

namespace justinholtweb\my\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\my\errors\MyobApiException;
use justinholtweb\my\Plugin;
use yii\web\Response;

/**
 * The settings screen's buttons.
 *
 * Every one of these posts over Ajax rather than sitting in a `<form>`. Craft's plugin settings
 * screen is already a form, and a nested one is not merely ignored — the parser drops the inner
 * tag and keeps its children, so a second `action` input ends up next to the real one and Save
 * runs whatever came last.
 */
class SettingsController extends Controller
{
    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requireAdmin();

        return true;
    }

    /**
     * Ask MYOB who we are.
     */
    public function actionTest(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $result = Plugin::getInstance()->getAuth()->verify();

        return $this->asJson([
            'success' => $result['ok'],
            'message' => $result['message'],
        ]);
    }

    /**
     * The company files this connection can see.
     */
    public function actionCompanyFiles(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $files = Plugin::getInstance()->getAuth()->getCompanyFiles();

        return $this->asJson([
            'success' => true,
            'files' => $files,
            // An empty list is a normal outcome for a key issued after 12 March 2025, so say so
            // rather than letting the merchant conclude the connection is broken.
            'message' => $files === []
                ? Craft::t('my', 'MYOB returned no company files. Newer API keys are not allowed to list them — paste the company file ID below instead.')
                : Craft::t('my', 'Found {count} company files.', ['count' => count($files)]),
        ]);
    }

    /**
     * Point the connection at a company file.
     */
    public function actionSelectCompanyFile(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $id = trim((string)$request->getBodyParam('id', ''));

        if ($id === '') {
            return $this->asJson([
                'success' => false,
                'message' => Craft::t('my', 'Choose a company file, or paste its ID.'),
            ]);
        }

        try {
            Plugin::getInstance()->getAuth()->selectCompanyFile(
                $id,
                (string)$request->getBodyParam('uri', '') ?: null,
                (string)$request->getBodyParam('name', '') ?: null,
            );
        } catch (MyobApiException $e) {
            return $this->asJson(['success' => false, 'message' => $e->getMessage()]);
        }

        $result = Plugin::getInstance()->getAuth()->verify();

        return $this->asJson([
            'success' => $result['ok'],
            'message' => $result['message'],
        ]);
    }

    /**
     * Re-read the chart of accounts and tax codes.
     */
    public function actionRefreshReference(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $reference = Plugin::getInstance()->getReference();
        $reference->flush();
        $result = $reference->refreshAll();

        return $this->asJson([
            'success' => $result['ok'],
            'message' => $result['message'],
            'accounts' => $result['accounts'],
            'taxCodes' => $result['taxCodes'],
        ]);
    }

    /**
     * The accounts and tax codes, for the pickers on the settings screen.
     */
    public function actionReference(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $reference = Plugin::getInstance()->getReference();

        try {
            $accounts = $reference->getAccounts();
            $taxCodes = $reference->getTaxCodes();
        } catch (MyobApiException $e) {
            return $this->asJson(['success' => false, 'message' => $e->getMessage()]);
        }

        return $this->asJson([
            'success' => true,
            'accounts' => array_values($accounts),
            'taxCodes' => array_values($taxCodes),
        ]);
    }

    /**
     * Forget the connection. MYOB itself is untouched.
     */
    public function actionDisconnect(): Response
    {
        $this->requirePostRequest();

        Plugin::getInstance()->getAuth()->disconnect();
        Plugin::getInstance()->getReference()->flush();

        // `asSuccess()` answers JSON for the Ajax button and a redirect for a plain post, so the
        // same action serves both without the controller having to guess.
        return $this->asSuccess(Craft::t('my', 'Disconnected from MYOB.'));
    }
}
