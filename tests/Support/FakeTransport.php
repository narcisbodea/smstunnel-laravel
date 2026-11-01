<?php

declare(strict_types=1);

namespace NotificationChannels\SmsTunnel\Tests\Support;

use SmsTunnel\Transport\HttpResponse;
use SmsTunnel\Transport\TransportInterface;

/**
 * Stands in for SMSTunnel: answers every send with a new message id (or a queued error) and keeps the requests.
 */
final class FakeTransport implements TransportInterface
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, json: array<string, mixed>}> */
    public array $requests = [];

    /** @var array<string, array{int, array<string, mixed>}> number => [status, body] */
    private array $failures = [];

    private int $counter = 0;

    /**
     * @param  array<string, mixed>  $body
     */
    public function failFor(string $number, int $status, array $body): self
    {
        $this->failures[$number] = [$status, $body];

        return $this;
    }

    public function send(string $method, string $url, array $headers, ?string $body): HttpResponse
    {
        $json = $body === null ? [] : json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        if (! \is_array($json)) {
            $json = [];
        }
        /** @var array<string, mixed> $json */
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'json' => $json];

        $to = \is_string($json['to'] ?? null) ? $json['to'] : '';
        if (isset($this->failures[$to])) {
            [$status, $error] = $this->failures[$to];

            return new HttpResponse($status, json_encode($error, \JSON_THROW_ON_ERROR), ['Content-Type' => 'application/json']);
        }

        $id = \sprintf('%024x', ++$this->counter);

        return new HttpResponse(200, json_encode([
            'success' => true,
            'messageId' => $id,
            'data' => ['messageId' => $id, 'branded' => false, 'remaining' => ['clean' => -1, 'total' => 'unlimited']],
        ], \JSON_THROW_ON_ERROR), ['Content-Type' => 'application/json']);
    }
}
