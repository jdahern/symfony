<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpClient\Response;

use Symfony\Component\HttpClient\Chunk\DataChunk;
use Symfony\Component\HttpClient\Chunk\ErrorChunk;
use Symfony\Component\HttpClient\Chunk\FirstChunk;
use Symfony\Component\HttpClient\Chunk\LastChunk;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\Exception\JsonException;
use Symfony\Component\HttpClient\Exception\RedirectionException;
use Symfony\Component\HttpClient\Exception\ServerException;
use Symfony\Component\HttpClient\Exception\TransportException;

/**
 * Implements the common logic for response classes.
 *
 * @author Nicolas Grekas <p@tchwork.com>
 *
 * @internal
 */
trait ResponseTrait
{
    private $headers = [];

    /**
     * @var callable|null A callback that initializes the two previous properties
     */
    private $initializer;

    /**
     * @var resource A php://temp stream typically
     */
    private $content;

    private $info = [
        'response_headers' => [],
        'http_code' => 0,
        'error' => null,
    ];

    private $multi;
    private $handle;
    private $id;
    private $timeout;
    private $finalInfo;
    private $offset = 0;
    private $jsonData;

    /**
     * {@inheritdoc}
     */
    public function getStatusCode(): int
    {
        if ($this->initializer) {
            ($this->initializer)($this);
            $this->initializer = null;
        }

        return $this->info['http_code'];
    }

    /**
     * {@inheritdoc}
     */
    public function getHeaders(bool $throw = true): array
    {
        if ($this->initializer) {
            ($this->initializer)($this);
            $this->initializer = null;
        }

        if ($throw) {
            $this->checkStatusCode();
        }

        return $this->headers;
    }

    /**
     * {@inheritdoc}
     */
    public function getContent(bool $throw = true): string
    {
        if ($this->initializer) {
            ($this->initializer)($this);
            $this->initializer = null;
        }

        if ($throw) {
            $this->checkStatusCode();
        }

        if (null === $this->content) {
            $content = null;
            $chunk = null;

            foreach (self::stream([$this]) as $chunk) {
                if (!$chunk->isLast()) {
                    $content .= $chunk->getContent();
                }
            }

            if (null === $content) {
                throw new TransportException('Cannot get the content of the response twice: the request was issued with option "buffer" set to false.');
            }

            return $content;
        }

        foreach (self::stream([$this]) as $chunk) {
            // Chunks are buffered in $this->content already
        }

        rewind($this->content);

        return stream_get_contents($this->content);
    }

    /**
     * {@inheritdoc}
     */
    public function toArray(bool $throw = true): array
    {
        if ('' === $content = $this->getContent($throw)) {
            throw new TransportException('Response body is empty.');
        }

        if (null !== $this->jsonData) {
            return $this->jsonData;
        }

        $contentType = $this->headers['content-type'][0] ?? 'application/json';

        if (!preg_match('/\bjson\b/i', $contentType)) {
            throw new JsonException(sprintf('Response content-type is "%s" while a JSON-compatible one was expected.', $contentType));
        }

        try {
            $content = json_decode($content, true, 512, JSON_BIGINT_AS_STRING | (\PHP_VERSION_ID >= 70300 ? JSON_THROW_ON_ERROR : 0));
        } catch (\JsonException $e) {
            throw new JsonException($e->getMessage(), $e->getCode());
        }

        if (\PHP_VERSION_ID < 70300 && JSON_ERROR_NONE !== json_last_error()) {
            throw new JsonException(json_last_error_msg(), json_last_error());
        }

        if (!\is_array($content)) {
            throw new JsonException(sprintf('JSON content was expected to decode to an array, %s returned.', \gettype($content)));
        }

        if (null !== $this->content) {
            // Option "buffer" is true
            return $this->jsonData = $content;
        }

        return $content;
    }

    /**
     * Closes the response and all its network handles.
     */
    abstract protected function close(): void;

    /**
     * Adds pending responses to the activity list.
     */
    abstract protected static function schedule(self $response, array &$runningResponses): void;

    /**
     * Performs all pending non-blocking operations.
     */
    abstract protected static function perform(\stdClass $multi, array &$responses): void;

    /**
     * Waits for network activity.
     */
    abstract protected static function select(\stdClass $multi, float $timeout): int;

    private static function addResponseHeaders(array $responseHeaders, array &$info, array &$headers): void
    {
        foreach ($responseHeaders as $h) {
            if (11 <= \strlen($h) && '/' === $h[4] && preg_match('#^HTTP/\d+(?:\.\d+)? ([12345]\d\d) .*#', $h, $m)) {
                $headers = [];
                $info['http_code'] = (int) $m[1];
            } elseif (2 === \count($m = explode(':', $h, 2))) {
                $headers[strtolower($m[0])][] = ltrim($m[1]);
            }

            $info['response_headers'][] = $h;
        }

        if (!$info['http_code']) {
            throw new TransportException('Invalid or missing HTTP status line.');
        }
    }

    private function checkStatusCode()
    {
        if (500 <= $this->info['http_code']) {
            throw new ServerException($this);
        }

        if (400 <= $this->info['http_code']) {
            throw new ClientException($this);
        }

        if (300 <= $this->info['http_code']) {
            throw new RedirectionException($this);
        }
    }

    /**
     * Ensures the request is always sent and that the response code was checked.
     */
    private function doDestruct()
    {
        if ($this->initializer && null === $this->info['error']) {
            ($this->initializer)($this);
            $this->initializer = null;
            $this->checkStatusCode();
        }
    }

    /**
     * Implements an event loop based on a buffer activity queue.
     *
     * @internal
     */
    public static function stream(iterable $responses, float $timeout = null): \Generator
    {
        $runningResponses = [];

        foreach ($responses as $response) {
            self::schedule($response, $runningResponses);
        }

        $lastActivity = microtime(true);
        $isTimeout = false;

        while (true) {
            $hasActivity = false;
            $timeoutMax = 0;
            $timeoutMin = $timeout ?? INF;

            foreach ($runningResponses as $i => [$multi]) {
                $responses = &$runningResponses[$i][1];
                self::perform($multi, $responses);

                foreach ($responses as $j => $response) {
                    $timeoutMax = $timeout ?? max($timeoutMax, $response->timeout);
                    $timeoutMin = min($timeoutMin, $response->timeout, 1);
                    $chunk = false;

                    if (isset($multi->handlesActivity[$j])) {
                        // no-op
                    } elseif (!isset($multi->openHandles[$j])) {
                        unset($responses[$j]);
                        continue;
                    } elseif ($isTimeout) {
                        $multi->handlesActivity[$j] = [new ErrorChunk($response->offset)];
                    } else {
                        continue;
                    }

                    while ($multi->handlesActivity[$j] ?? false) {
                        $hasActivity = true;
                        $isTimeout = false;

                        if (\is_string($chunk = array_shift($multi->handlesActivity[$j]))) {
                            $response->offset += \strlen($chunk);
                            $chunk = new DataChunk($response->offset, $chunk);
                        } elseif (null === $chunk) {
                            $e = $multi->handlesActivity[$j][0];
                            unset($responses[$j], $multi->handlesActivity[$j]);
                            $response->close();

                            if (null !== $e) {
                                $response->info['error'] = $e->getMessage();

                                if ($e instanceof \Error) {
                                    throw $e;
                                }

                                $chunk = new ErrorChunk($response->offset, $e);
                            } else {
                                $chunk = new LastChunk($response->offset);
                            }
                        } elseif ($chunk instanceof ErrorChunk) {
                            unset($responses[$j]);
                            $isTimeout = true;
                        }

                        yield $response => $chunk;
                    }

                    unset($multi->handlesActivity[$j]);

                    if ($chunk instanceof FirstChunk && null === $response->initializer) {
                        // Ensure the HTTP status code is always checked
                        $response->getHeaders(true);
                    } elseif ($chunk instanceof ErrorChunk && !$chunk->didThrow()) {
                        // Ensure transport exceptions are always thrown
                        $chunk->getContent();
                    }
                }

                if (!$responses) {
                    unset($runningResponses[$i]);
                }

                // Prevent memory leaks
                $multi->handlesActivity = $multi->handlesActivity ?: [];
                $multi->openHandles = $multi->openHandles ?: [];
            }

            if (!$runningResponses) {
                break;
            }

            if ($hasActivity) {
                $lastActivity = microtime(true);
                continue;
            }

            switch (self::select($multi, $timeoutMin)) {
                case -1: usleep(min(500, 1E6 * $timeoutMin)); break;
                case 0: $isTimeout = microtime(true) - $lastActivity > $timeoutMax; break;
            }
        }
    }
}
