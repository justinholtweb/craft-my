<?php

namespace justinholtweb\my\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\my\Plugin;
use yii\web\Response;

/**
 * The settings screen's "Send a test alert" button.
 *
 * It sends to the *saved* recipients and webhook, never to anything in the request: taking a URL
 * from the request would turn this button into an SSRF probe for anyone who can reach it.
 */
class AlertsController extends Controller
{
    public function actionTest(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        // Settings are an admin screen; the test only reads them, so admin changes need not be on.
        $this->requireAdmin(false);

        $result = Plugin::getInstance()->getAlerts()->sendTest();

        if ($result['email'] === null && $result['webhook'] === null) {
            return $this->asFailure(Craft::t('my', 'Save some recipients or a webhook URL first.'));
        }

        $parts = [];

        if ($result['email'] !== null) {
            $parts[] = $result['email']
                ? Craft::t('my', 'Email sent.')
                : Craft::t('my', 'Email failed — check Craft’s email settings.');
        }

        if ($result['webhook'] !== null) {
            $parts[] = $result['webhook'] === true
                ? Craft::t('my', 'Webhook sent.')
                : Craft::t('my', 'Webhook failed: {reason}', ['reason' => $result['webhook']]);
        }

        $ok = $result['email'] !== false && ($result['webhook'] === null || $result['webhook'] === true);
        $message = implode(' ', $parts);

        return $ok ? $this->asSuccess($message) : $this->asFailure($message);
    }
}
