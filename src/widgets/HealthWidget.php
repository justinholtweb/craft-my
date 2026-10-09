<?php

namespace justinholtweb\my\widgets;

use Craft;
use craft\base\Widget;
use justinholtweb\my\Plugin;

/**
 * A Dashboard tile: whether MYOB is connected, the last seven days of the ledger, when an invoice
 * last went through, and any open alert.
 *
 * The documents screen is where the detail lives; this is what makes somebody go there. It shows
 * the same latch rows the alert emails come from, so the tile and the inbox cannot disagree.
 */
class HealthWidget extends Widget
{
    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('my', 'MYOB health');
    }

    /**
     * @inheritdoc
     */
    public static function icon(): ?string
    {
        return Craft::getAlias('@justinholtweb/my/icon-mask.svg');
    }

    /**
     * @inheritdoc
     */
    public static function isSelectable(): bool
    {
        return Craft::$app->getUser()->checkPermission('my-viewDocuments');
    }

    /**
     * @inheritdoc
     */
    public function getTitle(): string
    {
        return Craft::t('my', 'MYOB health');
    }

    /**
     * @inheritdoc
     */
    public function getBodyHtml(): ?string
    {
        // A widget outlives the permission that let somebody add it.
        if (!Craft::$app->getUser()->checkPermission('my-viewDocuments')) {
            return null;
        }

        return Craft::$app->getView()->renderTemplate('my/_widgets/health', [
            'overview' => Plugin::getInstance()->getAlerts()->overview(),
        ]);
    }
}
