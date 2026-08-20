<?php

namespace justinholtweb\my\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use DateTime;
use GuzzleHttp\Client;
use justinholtweb\my\db\Table;
use justinholtweb\my\errors\MyobApiException;
use justinholtweb\my\models\Connection;
use justinholtweb\my\models\LogEntry;
use justinholtweb\my\models\Settings;
use justinholtweb\my\Plugin;

/**
 * The MYOB connection: getting one, keeping it, and giving it up.
 *
 * Two modes behind one interface. Cloud files use the OAuth 2 authorisation-code flow against
 * `secure.myob.com`; a local AccountRight server uses HTTP Basic and has no tokens at all.
 *
 * **Access tokens last twenty minutes.** Refreshing is therefore part of ordinary operation, not
 * an error path — and it happens under a mutex, because MYOB invalidates a refresh token as it
 * issues the next one. Two queue workers refreshing concurrently would leave one of them holding
 * a token that no longer works and the merchant disconnected for no visible reason.
 */
class Auth extends Component
{
    public const AUTHORIZE_URL = 'https://secure.myob.com/oauth2/account/authorize';
    public const TOKEN_URL = 'https://secure.myob.com/oauth2/v1/authorize';

    /**
     * The only scope MYOB's Business API defines.
     */
    public const SCOPE = 'CompanyFile';

    private const MUTEX_KEY = 'my:token-refresh';
    private const MUTEX_TIMEOUT = 15;

    private ?Connection $_connection = null;

    /**
     * The stored connection, or an empty one in the configured mode.
     */
    public function getConnection(): Connection
    {
        if ($this->_connection !== null) {
            return $this->_connection;
        }

        $row = (new Query())->from([Table::CONNECTION])->orderBy(['id' => SORT_ASC])->one();

        $connection = $row ? new Connection($row) : new Connection();

        // The mode always follows settings. A merchant who switches from cloud to local should not
        // be left with a row insisting otherwise.
        $connection->mode = Plugin::getInstance()->getSettings()->mode;

        return $this->_connection = $connection;
    }

    public function saveConnection(Connection $connection): bool
    {
        $db = Craft::$app->getDb();

        $attributes = [
            'mode' => $connection->mode,
            'accessToken' => $connection->accessToken,
            'refreshToken' => $connection->refreshToken,
            'expiryDate' => Db::prepareDateForDb($connection->expiryDate),
            'scope' => $connection->scope,
            'companyFileId' => $connection->companyFileId,
            'companyFileUri' => $connection->companyFileUri,
            'companyFileName' => $connection->companyFileName,
            'country' => $connection->country,
            'productVersion' => $connection->productVersion,
            'dateConnected' => Db::prepareDateForDb($connection->dateConnected),
            'dateLastVerified' => Db::prepareDateForDb($connection->dateLastVerified),
            'dateUpdated' => Db::prepareDateForDb(new DateTime()),
        ];

        if ($connection->id) {
            $db->createCommand()->update(Table::CONNECTION, $attributes, ['id' => $connection->id])->execute();
        } else {
            $db->createCommand()->insert(Table::CONNECTION, $attributes + [
                'dateCreated' => Db::prepareDateForDb(new DateTime()),
                'uid' => StringHelper::UUID(),
            ])->execute();

            $connection->id = (int)$db->getLastInsertID();
        }

        $this->_connection = $connection;

        return true;
    }

    public function disconnect(): void
    {
        Craft::$app->getDb()->createCommand()->delete(Table::CONNECTION)->execute();
        $this->_connection = null;

        Plugin::getInstance()->getLog()->write('disconnect', [
            'summary' => Craft::t('my', 'Disconnected from MYOB'),
        ]);
    }

    // OAuth
    // -------------------------------------------------------------------------

    /**
     * Where to send the merchant to authorise. `$state` is round-tripped by MYOB and checked on
     * the way back, so the callback cannot be driven by a third party.
     */
    public function getAuthorizationUrl(string $state): string
    {
        $settings = Plugin::getInstance()->getSettings();

        return self::AUTHORIZE_URL . '?' . http_build_query([
            'client_id' => $settings->getParsedClientId(),
            'redirect_uri' => $settings->getRedirectUri(),
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'state' => $state,
        ]);
    }

    /**
     * Exchange an authorisation code for tokens.
     *
     * @throws MyobApiException
     */
    public function exchangeCode(string $code): Connection
    {
        $settings = Plugin::getInstance()->getSettings();

        $data = $this->postToken([
            'client_id' => $settings->getParsedClientId(),
            'client_secret' => $settings->getParsedClientSecret(),
            'scope' => self::SCOPE,
            'code' => $code,
            'redirect_uri' => $settings->getRedirectUri(),
            'grant_type' => 'authorization_code',
        ], 'oauth.exchange');

        $connection = $this->getConnection();
        $connection->mode = Settings::MODE_CLOUD;
        $connection->dateConnected = new DateTime();

        $this->applyToken($connection, $data);
        $this->saveConnection($connection);

        return $connection;
    }

    /**
     * A valid access token, refreshing first if the current one is close to expiry.
     *
     * Returns null in local mode, which has no tokens.
     *
     * @throws MyobApiException
     */
    public function getAccessToken(): ?string
    {
        $connection = $this->getConnection();

        if ($connection->isLocal()) {
            return null;
        }

        if (!$connection->isExpired()) {
            return $connection->accessToken;
        }

        return $this->refresh()->accessToken;
    }

    /**
     * Trade the refresh token for a new access token.
     *
     * Serialised across processes: MYOB rotates the refresh token on every use, so a second worker
     * refreshing at the same moment would be handed a token that the first one had already
     * replaced. Whoever loses the race re-reads the row — by then it holds the fresh token — and
     * only refreshes for real if it is *still* expired.
     *
     * @throws MyobApiException
     */
    public function refresh(): Connection
    {
        $mutex = Craft::$app->getMutex();
        $acquired = $mutex->acquire(self::MUTEX_KEY, self::MUTEX_TIMEOUT);

        try {
            // Re-read: another process may have refreshed while we waited for the lock.
            $this->_connection = null;
            $connection = $this->getConnection();

            if (!$connection->isExpired()) {
                return $connection;
            }

            if ($connection->refreshToken === null || $connection->refreshToken === '') {
                throw new MyobApiException(Craft::t('my', 'MYOB is not connected. Reconnect on the settings screen.'));
            }

            $settings = Plugin::getInstance()->getSettings();

            $data = $this->postToken([
                'client_id' => $settings->getParsedClientId(),
                'client_secret' => $settings->getParsedClientSecret(),
                'refresh_token' => $connection->refreshToken,
                'grant_type' => 'refresh_token',
            ], 'oauth.refresh');

            $this->applyToken($connection, $data);
            $this->saveConnection($connection);

            return $connection;
        } finally {
            if ($acquired) {
                $mutex->release(self::MUTEX_KEY);
            }
        }
    }

    // Company files
    // -------------------------------------------------------------------------

    /**
     * The company files this connection can reach.
     *
     * MYOB stopped answering this for API keys issued after 12 March 2025 — those get the company
     * file id on the OAuth redirect instead — so an empty list is a normal outcome, not an error,
     * and the settings screen always offers a text field alongside it.
     *
     * @return array<int, array{Id: string, Name: string, Uri: string, Country: string, ProductVersion: string, SerialNumber: string}>
     */
    public function getCompanyFiles(): array
    {
        $api = Plugin::getInstance()->getApi();

        try {
            $data = $api->requestAbsolute('GET', Plugin::getInstance()->getSettings()->getApiBaseUrl(), [
                'action' => 'companyfiles',
            ]);
        } catch (MyobApiException $e) {
            Plugin::getInstance()->getLog()->write('companyfiles', [
                'level' => LogEntry::LEVEL_WARNING,
                'statusCode' => $e->statusCode,
                'summary' => Craft::t('my', 'Could not list company files'),
                'message' => $e->getMessage(),
            ]);

            return [];
        }

        if (!is_array($data)) {
            return [];
        }

        $files = [];

        foreach ($data as $file) {
            if (!is_array($file) || empty($file['Id'])) {
                continue;
            }

            $files[] = [
                'Id' => (string)$file['Id'],
                'Name' => (string)($file['Name'] ?? $file['Id']),
                'Uri' => (string)($file['Uri'] ?? ''),
                'Country' => (string)($file['Country'] ?? ''),
                'ProductVersion' => (string)($file['ProductVersion'] ?? ''),
                'SerialNumber' => (string)($file['SerialNumber'] ?? ''),
            ];
        }

        return $files;
    }

    /**
     * Point the connection at a company file and confirm the credentials actually open it.
     *
     * @throws MyobApiException
     */
    public function selectCompanyFile(string $id, ?string $uri = null, ?string $name = null): Connection
    {
        $connection = $this->getConnection();
        $connection->companyFileId = $id;
        $connection->companyFileUri = $uri ?: null;
        $connection->companyFileName = $name ?: null;
        $connection->dateConnected ??= new DateTime();

        $this->saveConnection($connection);

        $this->verify();

        return $this->getConnection();
    }

    /**
     * Ask MYOB who we are on this company file.
     *
     * A `GET` on the file root returns `{"CompanyFile": {...}, "UserAccess": {...}}` — which is the
     * only way to find out whether the company file credentials are right, as opposed to the OAuth
     * ones. The two fail in completely different ways and merchants confuse them constantly.
     *
     * @return array{ok: bool, message: string, companyFile?: array, userAccess?: array}
     */
    public function verify(): array
    {
        $connection = $this->getConnection();

        if ($connection->getEffectiveCompanyFileId() === null) {
            return [
                'ok' => false,
                'message' => Craft::t('my', 'No company file has been chosen yet.'),
            ];
        }

        try {
            $data = Plugin::getInstance()->getApi()->get('', ['action' => 'verify']);
        } catch (MyobApiException $e) {
            return [
                'ok' => false,
                'message' => $this->explainVerifyFailure($e),
            ];
        }

        $file = $data['CompanyFile'] ?? [];
        $access = $data['UserAccess'] ?? [];

        $connection->companyFileName = (string)($file['Name'] ?? $connection->companyFileName);
        $connection->country = (string)($file['Country'] ?? $connection->country);
        $connection->productVersion = (string)($file['ProductVersion'] ?? $connection->productVersion);
        $connection->dateLastVerified = new DateTime();
        $this->saveConnection($connection);

        return [
            'ok' => true,
            'message' => Craft::t('my', 'Connected to {name} as {user}.', [
                'name' => $connection->companyFileName ?: $connection->getEffectiveCompanyFileId(),
                'user' => $access['UserName'] ?? Craft::t('my', 'the company file user'),
            ]),
            'companyFile' => is_array($file) ? $file : [],
            'userAccess' => is_array($access) ? $access : [],
        ];
    }

    /**
     * MYOB's 401 is the same whichever credential is wrong, so say which two things to check
     * rather than repeating "Unauthorized" at somebody.
     */
    private function explainVerifyFailure(MyobApiException $e): string
    {
        if ($e->statusCode === 401) {
            return Craft::t('my', 'MYOB rejected the credentials. Either the connection needs re-authorising, or the company file username and password are wrong — they are the login for the file itself, not your MYOB account.');
        }

        if ($e->statusCode === 404) {
            return Craft::t('my', 'MYOB has no company file with that ID on this account.');
        }

        return $e->getMessage();
    }

    // Internals
    // -------------------------------------------------------------------------

    /**
     * @throws MyobApiException
     */
    private function postToken(array $params, string $action): array
    {
        $started = microtime(true);
        $log = Plugin::getInstance()->getLog();

        try {
            $response = $this->tokenClient()->post(self::TOKEN_URL, [
                'form_params' => $params,
            ]);

            $body = (string)$response->getBody();
            $data = Json::decodeIfJson($body);

            if (!is_array($data) || empty($data['access_token'])) {
                throw new MyobApiException(
                    Craft::t('my', 'MYOB returned no access token.'),
                    $response->getStatusCode(),
                    [],
                    $body,
                    'POST',
                    self::TOKEN_URL,
                );
            }

            $log->write($action, [
                'method' => 'POST',
                'endpoint' => self::TOKEN_URL,
                'statusCode' => $response->getStatusCode(),
                'durationMs' => (int)round((microtime(true) - $started) * 1000),
                'summary' => Craft::t('my', 'Token issued, valid {seconds}s', [
                    'seconds' => (int)($data['expires_in'] ?? 0),
                ]),
                'response' => $body,
            ]);

            return $data;
        } catch (MyobApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $status = null;
            $body = null;

            if ($e instanceof \GuzzleHttp\Exception\RequestException && $e->hasResponse()) {
                $status = $e->getResponse()->getStatusCode();
                $body = (string)$e->getResponse()->getBody();
            }

            $message = $this->explainTokenFailure($status, $body, $e->getMessage());

            $log->write($action, [
                'level' => LogEntry::LEVEL_ERROR,
                'method' => 'POST',
                'endpoint' => self::TOKEN_URL,
                'statusCode' => $status,
                'durationMs' => (int)round((microtime(true) - $started) * 1000),
                'summary' => Craft::t('my', 'Token request failed'),
                'message' => $message,
                'response' => $body,
            ]);

            throw new MyobApiException($message, $status, [], $body, 'POST', self::TOKEN_URL, $e);
        }
    }

    /**
     * MYOB's token endpoint answers with an OAuth-style `{"error": "invalid_grant"}`, which means
     * something quite specific and quite common: the refresh token has been used, revoked, or the
     * merchant changed their MYOB password.
     */
    private function explainTokenFailure(?int $status, ?string $body, string $fallback): string
    {
        $data = $body !== null ? Json::decodeIfJson($body) : null;
        $error = is_array($data) ? (string)($data['error'] ?? '') : '';

        return match ($error) {
            'invalid_grant' => Craft::t('my', 'MYOB rejected the refresh token. This happens when the connection is authorised again elsewhere, revoked, or the MYOB password changes — reconnect on the settings screen.'),
            'invalid_client' => Craft::t('my', 'MYOB rejected the API key and secret.'),
            'invalid_request' => Craft::t('my', 'MYOB rejected the token request. Check that the redirect URI registered on developer.myob.com matches this site exactly.'),
            default => $fallback,
        };
    }

    private function applyToken(Connection $connection, array $data): void
    {
        $connection->accessToken = (string)$data['access_token'];
        $connection->scope = isset($data['scope']) ? (string)$data['scope'] : $connection->scope;

        // MYOB sends `expires_in` as a *string* of seconds.
        $expiresIn = (int)($data['expires_in'] ?? 1200);
        $connection->expiryDate = (new DateTime())->modify('+' . max(60, $expiresIn) . ' seconds');

        // A refresh response always carries a new refresh token; keep the old one if it somehow
        // does not, rather than nulling the connection out.
        if (!empty($data['refresh_token'])) {
            $connection->refreshToken = (string)$data['refresh_token'];
        }
    }

    private function tokenClient(): Client
    {
        return Craft::createGuzzleClient([
            'timeout' => Plugin::getInstance()->getSettings()->timeout,
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]);
    }
}
