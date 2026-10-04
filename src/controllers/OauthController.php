<?php

namespace justinholtweb\my\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use justinholtweb\my\errors\MyobApiException;
use justinholtweb\my\models\LogEntry;
use justinholtweb\my\Plugin;
use yii\web\Response;

/**
 * The OAuth handshake with MYOB.
 *
 * The redirect URI registered on developer.myob.com has to point here, exactly. MYOB matches it
 * character for character, and a mismatch produces `invalid_request` at the *token* step rather
 * than at the redirect — which is one reason the settings screen prints the URL to copy.
 */
class OauthController extends Controller
{
    private const STATE_KEY = 'my.oauth.state';

    /**
     * Send the merchant to MYOB.
     */
    public function actionConnect(): Response
    {
        $this->requireAdmin();

        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->isConfigured()) {
            Craft::$app->getSession()->setError(Craft::t('my', 'Enter your MYOB API key and secret first.'));

            return $this->redirect('settings/plugins/my');
        }

        // Round-tripped by MYOB and checked on the way back, so the callback cannot be driven by
        // anybody but the person who started the flow.
        $state = Craft::$app->getSecurity()->generateRandomString(32);
        Craft::$app->getSession()->set(self::STATE_KEY, $state);

        return $this->redirect(Plugin::getInstance()->getAuth()->getAuthorizationUrl($state));
    }

    /**
     * Where MYOB sends the merchant back.
     */
    public function actionCallback(): Response
    {
        $this->requireAdmin();

        $request = Craft::$app->getRequest();
        $session = Craft::$app->getSession();

        $expected = $session->get(self::STATE_KEY);
        $session->remove(self::STATE_KEY);

        // State first, before anything on the query string is believed — including an `error`.
        // Otherwise a crafted link could write arbitrary text into the connection log and the
        // admin's flash message without the flow ever having been started.
        $state = (string)$request->getQueryParam('state', '');

        if ($expected === null || !hash_equals((string)$expected, $state)) {
            $session->setError(Craft::t('my', 'The MYOB authorisation did not come back the way it left. Try connecting again.'));

            return $this->redirect('settings/plugins/my');
        }

        $error = $request->getQueryParam('error');

        if ($error !== null) {
            $description = (string)$request->getQueryParam('error_description', $error);

            Plugin::getInstance()->getLog()->write('oauth.callback', [
                'level' => LogEntry::LEVEL_ERROR,
                'summary' => Craft::t('my', 'MYOB refused the authorisation'),
                'message' => $description,
            ]);

            $session->setError(Craft::t('my', 'MYOB refused the authorisation: {error}', ['error' => $description]));

            return $this->redirect('settings/plugins/my');
        }

        $code = (string)$request->getQueryParam('code', '');

        if ($code === '') {
            $session->setError(Craft::t('my', 'MYOB sent no authorisation code.'));

            return $this->redirect('settings/plugins/my');
        }

        try {
            $connection = Plugin::getInstance()->getAuth()->exchangeCode($code);
        } catch (MyobApiException $e) {
            $session->setError($e->getMessage());

            return $this->redirect('settings/plugins/my');
        }

        // API keys issued after 12 March 2025 carry the company file on the redirect, because the
        // file list is no longer readable. Older keys do not, and pick a file on the next screen.
        $businessId = (string)($request->getQueryParam('businessId') ?? $request->getQueryParam('companyFileId') ?? '');

        if ($businessId !== '' && $connection->companyFileId === null) {
            try {
                Plugin::getInstance()->getAuth()->selectCompanyFile($businessId);
            } catch (MyobApiException $e) {
                $session->setNotice(Craft::t('my', 'Connected, but the company file could not be verified: {error}', [
                    'error' => $e->getMessage(),
                ]));

                return $this->redirect('settings/plugins/my');
            }
        }

        $session->setNotice(Craft::t('my', 'Connected to MYOB.'));

        return $this->redirect(UrlHelper::cpUrl('settings/plugins/my'));
    }
}
