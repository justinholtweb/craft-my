<?php

namespace justinholtweb\my\models;

use craft\base\Model;
use DateTime;
use justinholtweb\my\Plugin;

/**
 * The live MYOB connection: which company file, and the tokens that reach it.
 *
 * Persisted in `{{%my_connection}}` rather than project config. Access tokens last twenty minutes,
 * so this row is rewritten several times an hour — replaying that through YAML into every
 * environment would be both noisy and a way to leak a secret into version control.
 */
class Connection extends Model
{
    public ?int $id = null;
    public string $mode = Settings::MODE_CLOUD;
    public ?string $accessToken = null;
    public ?string $refreshToken = null;
    public ?DateTime $expiryDate = null;
    public ?string $scope = null;
    public ?string $companyFileId = null;
    public ?string $companyFileUri = null;
    public ?string $companyFileName = null;
    public ?string $country = null;
    public ?string $productVersion = null;
    public ?DateTime $dateConnected = null;
    public ?DateTime $dateLastVerified = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    /**
     * @inheritdoc
     */
    public function datetimeAttributes(): array
    {
        return array_merge(parent::datetimeAttributes(), [
            'expiryDate',
            'dateConnected',
            'dateLastVerified',
        ]);
    }

    /**
     * Refresh this many seconds before the token actually expires. MYOB issues twenty-minute
     * tokens; a job that starts at nineteen minutes and fifty seconds should not have to find out
     * the hard way.
     */
    public const REFRESH_MARGIN = 120;

    public function isLocal(): bool
    {
        return $this->mode === Settings::MODE_LOCAL;
    }

    /**
     * Local mode has no tokens at all — the company file id is the whole connection.
     */
    public function isConnected(): bool
    {
        if ($this->getEffectiveCompanyFileId() === null) {
            return false;
        }

        return $this->isLocal() || $this->refreshToken !== null;
    }

    public function isExpired(): bool
    {
        if ($this->isLocal()) {
            return false;
        }

        if ($this->accessToken === null || $this->expiryDate === null) {
            return true;
        }

        return $this->expiryDate->getTimestamp() - self::REFRESH_MARGIN <= time();
    }

    /**
     * A company file id typed into settings (or set per environment) wins over the one stored here,
     * so that a database restored from another environment cannot silently invoice the wrong file.
     */
    public function getEffectiveCompanyFileId(): ?string
    {
        $override = Plugin::getInstance()?->getSettings()->getParsedCompanyFileId() ?? '';

        if ($override !== '') {
            return $override;
        }

        return $this->companyFileId !== '' ? $this->companyFileId : null;
    }

    /**
     * The base URI every resource path hangs off, with a trailing slash.
     */
    public function getCompanyFileBaseUri(): ?string
    {
        $id = $this->getEffectiveCompanyFileId();

        if ($id === null) {
            return null;
        }

        // MYOB hands back a full URI with the file listing; prefer it, because a local server on a
        // non-default port would otherwise be reconstructed wrongly.
        if ($this->companyFileUri && $this->companyFileId === $id) {
            return rtrim($this->companyFileUri, '/') . '/';
        }

        $settings = Plugin::getInstance()?->getSettings();

        return ($settings?->getApiBaseUrl() ?? 'https://api.myob.com/accountright/') . $id . '/';
    }

    public function getLabel(): string
    {
        return $this->companyFileName ?: ($this->getEffectiveCompanyFileId() ?? '');
    }
}
