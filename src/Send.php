<?php

namespace NoriaLabs\Send;

use GuzzleHttp\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use NoriaLabs\Send\Exceptions\SendException;
use NoriaLabs\Send\Resources\ApiKeys;
use NoriaLabs\Send\Resources\Domains;
use NoriaLabs\Send\Resources\Emails;
use NoriaLabs\Send\Resources\Messages;
use NoriaLabs\Send\Resources\Workspaces;
use NoriaLabs\Send\Resources\Senders;
use NoriaLabs\Send\Resources\Sms;
use NoriaLabs\Send\Resources\Suppressions;
use NoriaLabs\Send\Resources\Templates;
use NoriaLabs\Send\Resources\Webhooks;

class Send
{
    public const DEFAULT_BASE_URL = 'https://send.noria.co.ke';

    /** @var callable|null */
    protected $handler = null;

    public function __construct(
        protected readonly Factory $http,
        protected readonly string $apiKey,
        protected readonly string $baseUrl = self::DEFAULT_BASE_URL,
        protected readonly int $timeout = 15,
        protected readonly int $retries = 2,
    ) {
        if ($apiKey === '') {
            throw new SendException('validation_error', 0, 'A Noria Send API key is required');
        }

        $scheme = parse_url($baseUrl, PHP_URL_SCHEME);
        $host = parse_url($baseUrl, PHP_URL_HOST);
        if ($scheme !== 'https' && ! ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true))) {
            throw new SendException('validation_error', 0, 'The base URL must be https unless it points at this machine');
        }
    }

    public function emails(): Emails
    {
        return new Emails($this);
    }

    public function sms(): Sms
    {
        return new Sms($this);
    }

    public function messages(): Messages
    {
        return new Messages($this);
    }

    public function domains(): Domains
    {
        return new Domains($this);
    }

    public function senders(): Senders
    {
        return new Senders($this);
    }

    public function templates(): Templates
    {
        return new Templates($this);
    }

    public function suppressions(): Suppressions
    {
        return new Suppressions($this);
    }

    public function webhooks(): Webhooks
    {
        return new Webhooks($this);
    }

    public function workspaces(): Workspaces
    {
        return new Workspaces($this);
    }

    public function apiKeys(): ApiKeys
    {
        return new ApiKeys($this);
    }

    // The only routes the service replays rather than re-runs.
    protected const IDEMPOTENT_POSTS = ['/v1/emails', '/v1/emails/batch', '/v1/sms', '/v1/sms/batch'];

    /**
     * @param  array<string, mixed>|null  $body
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, ?array $body = null, array $headers = []): array
    {
        $attempt = 0;
        $last = null;
        $retryAfter = 0;

        $route = explode('?', $path)[0];
        if ($method === 'POST' && in_array($route, self::IDEMPOTENT_POSTS, true) && ! isset($headers['Idempotency-Key'])) {
            $headers['Idempotency-Key'] = 'sdk_'.bin2hex(random_bytes(16));
        }

        $replayable = $method !== 'POST' || isset($headers['Idempotency-Key']);

        while ($attempt <= $this->retries) {
            if ($attempt > 0) {
                // Jittered, or every queue worker that failed together retries together.
                $backoff = (int) (min(2_000_000, 200_000 * (2 ** ($attempt - 1))) * (0.5 + mt_rand() / mt_getrandmax()));
                usleep(max($retryAfter * 1_000_000, $backoff));
            }

            $attempt++;

            try {
                $response = $this->pending($headers, $body !== null)
                    ->send($method, $this->url($path), $body === null ? [] : ['json' => $body]);
            } catch (ConnectionException $exception) {
                $last = SendException::network($exception->getMessage(), $exception);

                if (! $replayable) {
                    throw $last;
                }

                continue;
            }

            if ($response->status() === 204) {
                return [];
            }

            // The earlier attempt may have landed and only its answer been lost.
            if ($response->status() === 404 && $method === 'DELETE' && $last?->errorCode === 'network_error') {
                return [];
            }

            /** @var array<string, mixed> $decoded */
            $decoded = $response->json() ?? [];

            if ($response->successful()) {
                return $decoded;
            }

            $last = SendException::fromResponse($response->status(), $decoded);
            $retryAfter = min(60, max(0, (int) $response->header('Retry-After')));

            if (! $last->isRetryable()) {
                throw $last;
            }

            if (! $replayable && $last->errorCode !== 'rate_limited') {
                throw $last;
            }
        }

        throw $last ?? SendException::network('Request failed');
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function query(array $parameters): string
    {
        $filtered = array_filter($parameters, static fn (mixed $value): bool => $value !== null && $value !== '');

        return $filtered === [] ? '' : '?'.http_build_query($filtered);
    }

    /**
     * @param  array<string, string>  $headers
     */
    protected function pending(array $headers, bool $hasBody): PendingRequest
    {
        // One handler for this client's life keeps connections open between requests; fakes still sit above it.
        $this->handler ??= Utils::chooseHandler();

        $request = $this->http
            ->setHandler($this->handler)
            ->withToken($this->apiKey)
            ->acceptJson()
            ->withHeaders($headers)
            ->timeout($this->timeout);

        return $hasBody ? $request->asJson() : $request;
    }

    protected function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').$path;
    }
}
