<?php

namespace justinholtweb\my\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\my\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The connection log screens.
 */
class LogController extends Controller
{
    private const PER_PAGE = 100;

    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission('my-viewLog');

        return true;
    }

    public function actionIndex(): Response
    {
        $request = Craft::$app->getRequest();
        $log = Plugin::getInstance()->getLog();

        $criteria = array_filter([
            'action' => $request->getQueryParam('action_'),
            'level' => $request->getQueryParam('level'),
        ]);

        $page = max(1, (int)$request->getQueryParam('page', 1));

        return $this->renderTemplate('my/log/_index', [
            'entries' => $log->getEntries($criteria, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'actions' => $log->getActions(),
            'criteria' => $criteria,
            'page' => $page,
            'total' => $log->getTotal($criteria),
            'perPage' => self::PER_PAGE,
        ]);
    }

    /**
     * @throws NotFoundHttpException
     */
    public function actionDetail(int $entryId): Response
    {
        $entry = Plugin::getInstance()->getLog()->getEntryById($entryId);

        if ($entry === null) {
            throw new NotFoundHttpException('Log entry not found');
        }

        return $this->renderTemplate('my/log/_detail', [
            'entry' => $entry,
        ]);
    }

    public function actionClear(): Response
    {
        $this->requirePostRequest();
        // Reading the log and destroying it are different powers: the log is the only record of
        // what was sent to MYOB, so a view-only user must not be able to erase it.
        $this->requirePermission('my-clearLog');

        $count = Plugin::getInstance()->getLog()->clear();

        Craft::$app->getSession()->setNotice(Craft::t('my', '{count} log entries deleted.', ['count' => $count]));

        return $this->redirectToPostedUrl();
    }
}
