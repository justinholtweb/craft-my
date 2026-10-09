<?php

namespace justinholtweb\my\services;

use Craft;
use craft\base\Component;
use craft\helpers\Json;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use justinholtweb\my\errors\MyobApiException;
use justinholtweb\my\models\LogEntry;
use justinholtweb\my\Plugin;
use Psr\Http\Message\ResponseInterface;

/**
 * The MYOB Business API v2 client.
 *
 * **Every request My makes to MYOB goes through `request()`.** Header assembly, token refresh,
 * throttling, retry, error parsing and logging happen exactly once, so no caller can accidentally
 * talk to MYOB without a log line, and no caller has to know that a 401 mid-flight means "refresh
 * and try again" rather than "give up".
 *
 * Two transports behind one method:
 *
 * - **Cloud** — `https://api.myob.com/accountright/{cf_id}/…` with `Authorization: Bearer`,
 *   `x-myobapi-key`, `x-myobapi-version: v2` and `x-myobapi-cftoken`.
 * - **Local** — an AccountRight desktop server, HTTP Basic, no developer key.
 */
class Api extends Component
{
    public const VERSION = 'v2';

    /**
     * MYOB pages at 400 records; asking for more is silently capped.
     */
    public const PAGE_SIZE = 400;

    /**
     * How many times a retryable failure is retried inside a single call. Distinct from the queue
     * job's own attempts, which cover the whole push.
     */
    public const MAX_RETRIES = 3;

    /**
     * When the last request went out, so the configured rate can be respected without a sleep on
     * every single call.
     */
    private float $_lastRequestAt = 0.0;

    /**
     * Whether a 401 has already been answered with a token refresh during this call.
     */
    private bool $_refreshed = false;

    /**
     * The bearer token the last request went out with, so a 401 can say which token it rejected.
     */
    private ?string $_sentToken = null;

    /**
     * Extra Guzzle client options, merged over the defaults. Empty in production; the test suite
     * puts a `MockHandler` stack here (`['handler' => …]`), because the harness has no network.
     *
     * @var array<string, mixed>
     */
    public array $clientConfig = [];

    // Verbs
    // -------------------------------------------------------------------------

    /**
     * @throws MyobApiException
     */
    public function get(string $path, array $options = []): mixed
    {
        return $this->request('GET', $path, $options);
    }

    /**
     * Create a record.
     *
     * `?returnBody=true` is always sent: without it MYOB answers 201 with an empty body and only a
     * `Location` header, and the UID would cost a second round-trip to read back — during which
     * anything could happen.
     *
     * @throws MyobApiException
     */
    public function post(string $path, array $body, array $options = []): mixed
    {
        $options['body'] = $body;
        $options['query'] = ($options['query'] ?? []) + ['returnBody' => 'true'];

        return $this->request('POST', $path, $options);
    }

    /**
     * @throws MyobApiException
     */
    public function put(string $path, array $body, array $options = []): mixed
    {
        $options['body'] = $body;
        $options['query'] = ($options['query'] ?? []) + ['returnBody' => 'true'];

        return $this->request('PUT', $path, $options);
    }

    /**
     * @throws MyobApiException
     */
    public function delete(string $path, array $options = []): mixed
    {
        return $this->request('DELETE', $path, $options);
    }

    // Reading
    // -------------------------------------------------------------------------

    /**
     * One page of a collection.
     *
     * @return array{Items: array, Count: int, NextPageLink: string|null}
     * @throws MyobApiException
     */
    public function getPage(string $path, array $query = [], int $top = self::PAGE_SIZE, int $skip = 0): array
    {
        $data = $this->get($path, [
            'query' => $query + ['$top' => $top, '$skip' => $skip],
            'action' => $query['action'] ?? 'read',
        ]);

        if (!is_array($data)) {
            return ['Items' => [], 'Count' => 0, 'NextPageLink' => null];
        }

        return [
            'Items' => is_array($data['Items'] ?? null) ? $data['Items'] : [],
            'Count' => (int)($data['Count'] ?? 0),
            'NextPageLink' => $data['NextPageLink'] ?? null,
        ];
    }

    /**
     * Every record in a collection, paged through.
     *
     * `$limit` is a hard stop rather than a suggestion: a company file with 200,000 items would
     * otherwise page forever inside a web request.
     *
     * @throws MyobApiException
     */
    public function getAll(string $path, array $query = [], int $limit = 5000): array
    {
        $items = [];
        $skip = 0;

        while (count($items) < $limit) {
            $page = $this->getPage($path, $query, min(self::PAGE_SIZE, $limit - count($items)), $skip);

            if ($page['Items'] === []) {
                break;
            }

            array_push($items, ...$page['Items']);

            if (count($page['Items']) < self::PAGE_SIZE) {
                break;
            }

            $skip += self::PAGE_SIZE;
        }

        return $items;
    }

    /**
     * The first record matching an OData filter, or null.
     *
     * @throws MyobApiException
     */
    public function findOne(string $path, string $filter, array $query = []): ?array
    {
        $page = $this->getPage($path, $query + ['$filter' => $filter], 1);

        $first = $page['Items'][0] ?? null;

        return is_array($first) ? $first : null;
    }

    // Transport
    // -------------------------------------------------------------------------

    /**
     * The single door out to MYOB.
     *
     * @param array{
     *     query?: array,
     *     body?: array|null,
     *     action?: string,
     *     orderId?: int|null,
     *     headers?: array,
     * } $options
     * @throws MyobApiException
     */
    public function request(string $method, string $path, array $options = []): mixed
    {
        $base = Plugin::getInstance()->getAuth()->getConnection()->getCompanyFileBaseUri();

        if ($base === null) {
            throw new MyobApiException(Craft::t('my', 'No MYOB company file is connected. Connect one on the settings screen.'));
        }

        return $this->requestAbsolute($method, $base . ltrim($path, '/'), $options);
    }

    /**
     * As `request()`, but against a URL that is not under the company file — which in practice
     * means exactly one thing, the company file list itself.
     *
     * @throws MyobApiException
     */
    public function requestAbsolute(string $method, string $url, array $options = []): mixed
    {
        $this->_refreshed = false;

        return $this->send($method, $url, $options, 0);
    }

    /**
     * @throws MyobApiException
     */
    private function send(string $method, string $url, array $options, int $attempt): mixed
    {
        $settings = Plugin::getInstance()->getSettings();
        $log = Plugin::getInstance()->getLog();

        $action = (string)($options['action'] ?? strtolower($method));
        $body = $options['body'] ?? null;
        $query = $options['query'] ?? [];
        unset($query['action']);

        $this->throttle();

        $requestOptions = [
            'headers' => $this->headers($options['headers'] ?? []),
            'query' => $query,
            'http_errors' => true,
        ];

        if ($body !== null) {
            // MYOB rejects a body with a `null` where it expected an object, so nulls are stripped
            // in the payload builders rather than sent. JSON_UNESCAPED_SLASHES keeps URIs readable
            // in the log; JSON_PRESERVE_ZERO_FRACTION keeps 12.00 from becoming 12 and reading
            // back as an int.
            $requestOptions['body'] = Json::encode($body, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        }

        $started = microtime(true);

        try {
            $response = $this->client()->request($method, $url, $requestOptions);

            $duration = (int)round((microtime(true) - $started) * 1000);
            $raw = (string)$response->getBody();

            $log->write($action, [
                'method' => $method,
                'endpoint' => $this->shorten($url),
                'statusCode' => $response->getStatusCode(),
                'durationMs' => $duration,
                'orderId' => $options['orderId'] ?? null,
                'summary' => $this->summarise($method, $response, $raw),
                'request' => $body !== null ? Json::encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : null,
                'response' => $raw,
            ]);

            // MYOB accepted the credentials, so any "MYOB refused the connection" alert is over.
            Plugin::getInstance()->getAlerts()->noteAuthSuccess();

            return $this->decode($raw, $response);
        } catch (\Throwable $e) {
            $duration = (int)round((microtime(true) - $started) * 1000);
            $exception = $this->toApiException($e, $method, $url);

            // A token can expire between the check and the call. One refresh, one retry, then the
            // 401 is real.
            if ($exception->statusCode === 401 && !$this->_refreshed && !$settings->isLocal()) {
                $this->_refreshed = true;
                // Forced: the token was rejected, whatever its expiry date says. Passing the
                // rejected token lets a worker that lost the race use the winner's token rather
                // than rotating the refresh token a second time.
                Plugin::getInstance()->getAuth()->refresh(true, $this->_sentToken);

                return $this->send($method, $url, $options, $attempt);
            }

            if ($exception->isRetryable() && $attempt < self::MAX_RETRIES) {
                $wait = $this->backoff($e, $attempt);

                $log->write($action, [
                    'level' => LogEntry::LEVEL_WARNING,
                    'method' => $method,
                    'endpoint' => $this->shorten($url),
                    'statusCode' => $exception->statusCode,
                    'durationMs' => $duration,
                    'orderId' => $options['orderId'] ?? null,
                    'summary' => Craft::t('my', 'Retrying in {seconds}s (attempt {n})', [
                        'seconds' => round($wait, 1),
                        'n' => $attempt + 2,
                    ]),
                    'message' => $exception->getMessage(),
                ]);

                usleep((int)($wait * 1_000_000));

                return $this->send($method, $url, $options, $attempt + 1);
            }

            // Still refused after a fresh token (or, in local mode, refused at all): the token is
            // fine and what stands behind it is not — a revoked grant, or the wrong company file
            // login. Nothing will be pushed until somebody fixes it, so somebody has to be told.
            if ($exception->statusCode === 401) {
                Plugin::getInstance()->getAlerts()->noteAuthFailure(
                    Craft::t('my', 'MYOB answered 401 to {endpoint}: {message}', [
                        'endpoint' => $this->shorten($url),
                        'message' => $exception->getMessage(),
                    ])
                );
            }

            $log->write($action, [
                'level' => LogEntry::LEVEL_ERROR,
                'method' => $method,
                'endpoint' => $this->shorten($url),
                'statusCode' => $exception->statusCode,
                'durationMs' => $duration,
                'orderId' => $options['orderId'] ?? null,
                'summary' => Craft::t('my', 'MYOB request failed'),
                'message' => $exception->getMessage(),
                'request' => $body !== null ? Json::encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : null,
                'response' => $exception->body,
            ]);

            throw $exception;
        }
    }

    /**
     * MYOB's default quota is 8 requests a second per API key. Staying under it costs a few
     * milliseconds; going over it costs a 429, a retry, and a backoff far longer than the wait.
     *
     * This spaces requests within one PHP process, which is the shape the traffic actually takes —
     * a queue worker walking a list of orders. Concurrent workers can still collide, which is what
     * the 429 retry is for.
     */
    private function throttle(): void
    {
        $perSecond = max(1, Plugin::getInstance()->getSettings()->requestsPerSecond);
        $minimumGap = 1 / $perSecond;

        if ($this->_lastRequestAt > 0.0) {
            $elapsed = microtime(true) - $this->_lastRequestAt;

            if ($elapsed < $minimumGap) {
                usleep((int)(($minimumGap - $elapsed) * 1_000_000));
            }
        }

        $this->_lastRequestAt = microtime(true);
    }

    /**
     * Exponential backoff with jitter, deferring to `Retry-After` when MYOB sends one.
     *
     * `Retry-After` is honoured for any status, not only 429 — a maintenance window answers 503
     * with one too, and guessing when the server has told you is rude and slower.
     */
    private function backoff(\Throwable $e, int $attempt): float
    {
        if ($e instanceof RequestException && $e->hasResponse()) {
            $retryAfter = $e->getResponse()->getHeaderLine('Retry-After');

            if (is_numeric($retryAfter)) {
                return min(60.0, max(0.0, (float)$retryAfter));
            }
        }

        // Jitter matters: without it, every worker that hit the same 429 retries in lockstep and
        // produces the same 429 again.
        return min(30.0, (2 ** $attempt)) + (random_int(0, 500) / 1000);
    }

    /**
     * @return array<string, string>
     */
    private function headers(array $extra = []): array
    {
        $settings = Plugin::getInstance()->getSettings();

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            // MYOB responses are large and highly compressible; this is the difference between a
            // 3MB item list and a 200KB one.
            'Accept-Encoding' => 'gzip,deflate',
            'x-myobapi-version' => self::VERSION,
        ];

        if ($settings->isLocal()) {
            // The desktop server takes the company file credentials as plain HTTP Basic and has no
            // notion of a developer key.
            $headers['Authorization'] = 'Basic ' . base64_encode(
                $settings->getParsedCfUsername() . ':' . $settings->getParsedCfPassword()
            );

            return $headers + $extra;
        }

        $headers['x-myobapi-key'] = $settings->getParsedClientId();

        $token = Plugin::getInstance()->getAuth()->getAccessToken();
        $this->_sentToken = $token;

        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        // A company file with no user-level security wants no token at all, not an empty one.
        $cfToken = $settings->getCfToken();

        if ($cfToken !== null) {
            $headers['x-myobapi-cftoken'] = $cfToken;
        }

        return $headers + $extra;
    }

    private function client(): Client
    {
        return Craft::createGuzzleClient(array_merge([
            'timeout' => Plugin::getInstance()->getSettings()->timeout,
            // Cross-host redirects would leak the Authorization header.
            'allow_redirects' => false,
        ], $this->clientConfig));
    }

    /**
     * A 204, and a 200 with an empty body, are both legitimate — a DELETE says nothing.
     */
    private function decode(string $raw, ResponseInterface $response): mixed
    {
        if (trim($raw) === '') {
            return null;
        }

        $data = Json::decodeIfJson($raw);

        if (is_string($data)) {
            // Not JSON at all. A proxy or a login page, most likely.
            throw new MyobApiException(
                Craft::t('my', 'MYOB returned a non-JSON response ({code}). Check that the API URL is right.', [
                    'code' => $response->getStatusCode(),
                ]),
                $response->getStatusCode(),
                [],
                $raw,
            );
        }

        return $data;
    }

    private function toApiException(\Throwable $e, string $method, string $url): MyobApiException
    {
        if ($e instanceof MyobApiException) {
            return $e;
        }

        $status = null;
        $body = null;
        $errors = [];
        $message = $e->getMessage();

        if ($e instanceof RequestException && $e->hasResponse()) {
            $response = $e->getResponse();
            $status = $response->getStatusCode();
            $body = (string)$response->getBody();

            $data = Json::decodeIfJson($body);

            if (is_array($data) && is_array($data['Errors'] ?? null)) {
                $errors = $data['Errors'];
            }

            $message = $this->describe($status, $errors, $body);
        }

        return new MyobApiException($message, $status, $errors, $body, $method, $this->shorten($url), $e);
    }

    /**
     * Turn a MYOB failure into something a merchant can act on.
     */
    private function describe(int $status, array $errors, string $body): string
    {
        $details = [];

        foreach ($errors as $error) {
            if (!is_array($error)) {
                continue;
            }

            $line = trim((string)($error['Message'] ?? ''));
            $extra = trim((string)($error['AdditionalDetails'] ?? ''));

            if ($extra !== '' && $extra !== $line) {
                $line = $line !== '' ? "$line — $extra" : $extra;
            }

            if ($line !== '') {
                $details[] = $line;
            }
        }

        if ($details !== []) {
            return implode(' / ', array_unique($details));
        }

        return match ($status) {
            400 => Craft::t('my', 'MYOB rejected the request as invalid.'),
            401 => Craft::t('my', 'MYOB rejected the credentials.'),
            403 => Craft::t('my', 'The company file user is not allowed to do that.'),
            404 => Craft::t('my', 'MYOB has no such record.'),
            409 => Craft::t('my', 'The record changed in MYOB since it was read.'),
            429 => Craft::t('my', 'MYOB rate limit exceeded.'),
            default => Craft::t('my', 'MYOB returned {code}.', ['code' => $status]),
        };
    }

    /**
     * The bit of the URL worth reading in a log: everything after the company file id.
     */
    private function shorten(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: $url;

        return preg_replace('~^.*/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/~i', '/', $path) ?: $path;
    }

    private function summarise(string $method, ResponseInterface $response, string $raw): string
    {
        if ($method === 'GET') {
            $data = Json::decodeIfJson($raw);

            if (is_array($data) && isset($data['Count'])) {
                return Craft::t('my', '{count} records', ['count' => (int)$data['Count']]);
            }

            return Craft::t('my', 'OK ({code})', ['code' => $response->getStatusCode()]);
        }

        $location = $response->getHeaderLine('Location');

        if ($location !== '') {
            return Craft::t('my', 'Created {uri}', ['uri' => $this->shorten($location)]);
        }

        return Craft::t('my', 'OK ({code})', ['code' => $response->getStatusCode()]);
    }
}
