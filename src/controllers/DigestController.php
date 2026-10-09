<?php

namespace justinholtweb\my\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\my\Plugin;
use Throwable;
use yii\web\Response;

/**
 * The settings screen's "Send a test summary now" button.
 *
 * POST only, CSRF-checked by Craft, admin only (the settings screen is), and rate-limited per user:
 * it sends email, so it should not be something that can be fired on a loop.
 */
class DigestController extends Controller
{
    /** One test per user per this many seconds. */
    public const TEST_COOLDOWN = 30;

    public function actionSendTest(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        // Reads the settings, changes nothing — admin changes need not be on.
        $this->requireAdmin(false);

        $plugin = Plugin::getInstance();
        $user = Craft::$app->getUser()->getIdentity();

        // To the alert recipients, so the test shows exactly who the real one reaches. A site that
        // has not set any up yet gets it sent to the person pressing the button.
        $recipients = $plugin->getSettings()->recipientList();

        if ($recipients === [] && $user?->email) {
            $recipients = [$user->email];
        }

        if ($recipients === []) {
            return $this->asFailure(Craft::t('my', 'The summary has no recipients to send a test to.'));
        }

        if (!Craft::$app->getCache()->add('my:digest:test:' . ($user->id ?? 0), 1, self::TEST_COOLDOWN)) {
            return $this->asFailure(Craft::t('my', 'A test summary was sent a moment ago. Give it half a minute.'));
        }

        try {
            $sent = $plugin->getDigest()->sendTest($recipients);
        } catch (Throwable $e) {
            Craft::error('Could not send a test MYOB summary: ' . $e->getMessage(), 'my');
            $sent = 0;
        }

        if ($sent === 0) {
            return $this->asFailure(Craft::t('my', 'The test summary could not be sent. Check Craft’s email settings and the logs.'));
        }

        return $this->asSuccess(Craft::t('my', 'Test summary sent to {count, plural, =1{one recipient} other{# recipients}}.', [
            'count' => $sent,
        ]));
    }
}
