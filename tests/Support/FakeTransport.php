<?php

declare(strict_types=1);

namespace NotificationChannels\SmsTunnel\Tests\Support;

use SmsTunnel\Exception\TransportException;
use SmsTunnel\Transport\HttpResponse;
use SmsTunnel\Transport\TransportInterface;

/**
 * Stands in for SMSTunnel: answers every send with a new message id (or a queued error) and keeps the requests.
 *
 * Like SMSTunnel, it answers a repeat of an `Idempotency-Key` with the first answer instead of sending a new text,
 * and refuses the same key with other words or another number (422 IDEMPOTENCY_KEY_REUSED). A send with `sendAt` is
 * kept as a scheduled text and answered as SMSTunnel does.
 */
final class FakeTransport implements TransportInterface
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, json: array<string, mixed>}> */
    public array $requests = [];

    /** @var list<string> the message id of every text it sent, a replayed answer not counted */
    public array $texts = [];

    /** @var list<string> the id of every text it scheduled, a replayed answer not counted */
    public array $scheduled = [];

    /** @var array<string, array{int, array<string, mixed>}> number => [status, body] */
    private array $failures = [];

    /** @var array<string, array{string, string}> Idempotency-Key => [what was asked, the answer] */
    private array $answers = [];

    private bool $loseNextAnswer = false;

    private int $counter = 0;

    /**
     * @param  array<string, mixed>  $body
     */
    public function failFor(string $number, int $status, array $body): self
    {
        $this->failures[$number] = [$status, $body];

        return $this;
    }

    /** The next text goes out, but its answer never comes back: the client gets a TransportException. */
    public function loseNextAnswer(): self
    {
        $this->loseNextAnswer = true;

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

        $key = $headers['Idempotency-Key'] ?? null;
        $asked = $url."\n".$body;
        if ($key !== null && isset($this->answers[$key])) {
            [$askedBefore, $answer] = $this->answers[$key];
            if ($askedBefore !== $asked) {
                return new HttpResponse(422, json_encode([
                    'statusCode' => 422,
                    'error' => 'Unprocessable Entity',
                    'code' => 'IDEMPOTENCY_KEY_REUSED',
                    'message' => 'This Idempotency-Key was already used in the last 24 hours for a different request (another endpoint, recipient, text, phone or country code). Use a new key for a new text.',
                ], \JSON_THROW_ON_ERROR), ['Content-Type' => 'application/json']);
            }

            return new HttpResponse(200, $answer, ['Content-Type' => 'application/json', 'Idempotent-Replayed' => 'true']);
        }

        $id = \sprintf('%024x', ++$this->counter);
        if (isset($json['sendAt'])) {
            $this->scheduled[] = $id;
            $answer = json_encode([
                'success' => true,
                'scheduled' => true,
                'scheduledId' => $id,
                'sendAt' => $json['sendAt'],
                'data' => [
                    'id' => $id,
                    'to' => $to,
                    'message' => $json['message'] ?? '',
                    'sendAt' => $json['sendAt'],
                    'status' => 'scheduled',
                    'deviceId' => $json['deviceId'] ?? null,
                    'messageId' => null,
                    'error' => null,
                    'createdAt' => '2026-11-15T10:12:44.120Z',
                ],
            ], \JSON_THROW_ON_ERROR);
        } else {
            $this->texts[] = $id;
            $answer = json_encode([
                'success' => true,
                'messageId' => $id,
                'data' => ['messageId' => $id, 'branded' => false, 'remaining' => ['clean' => -1, 'total' => 'unlimited']],
            ], \JSON_THROW_ON_ERROR);
        }
        if ($key !== null) {
            $this->answers[$key] = [$asked, $answer];
        }

        if ($this->loseNextAnswer) {
            $this->loseNextAnswer = false;

            throw new TransportException('Could not reach SMSTunnel at https://sms.example.test: no answer within 30 s.');
        }

        return new HttpResponse(200, $answer, ['Content-Type' => 'application/json']);
    }
}
