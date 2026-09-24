<?php

declare(strict_types=1);

// Router for `php -S`: a Restate service deployment in REQUEST_RESPONSE mode, protocol V5.
// One workflow `Spike`: `run` (activity, sleep, promise) and `approve` (completes the promise).

require __DIR__.'/Wire.php';

const PROTOCOL = 5;
const T_START = 0x0000, T_SUSPENSION = 0x0001, T_END = 0x0003, T_PROPOSE_RUN = 0x0005;
const T_INPUT = 0x0400, T_OUTPUT = 0x0401, T_GET_PROMISE = 0x0409, T_COMPLETE_PROMISE = 0x040B;
const T_SLEEP = 0x040C, T_RUN = 0x0411;

final class Suspend extends Exception
{
}

final class Invocation
{
    private int $command = 0;
    private int $completion = 0;
    private string $out = '';
    /** @var list<array{int, array<int, list<int|string>>}> */
    private array $replayed = [];
    /** @var array<int, array<int, list<int|string>>> completion id => notification fields */
    private array $notifications = [];
    public readonly string $input;

    public function __construct(string $body)
    {
        foreach (Wire::frames($body) as [$type, $message]) {
            if ($type >= 0x8000) {
                $fields = Wire::decode($message);
                $this->notifications[$fields[1][0] ?? 0] = $fields;
            } elseif ($type >= 0x0400) {
                $this->replayed[] = [$type, Wire::decode($message)];
            }
        }
        $this->input = self::value($this->next(T_INPUT, '')[14][0] ?? '');
    }

    /** Journaled side effect: runs `$effect` only while the runtime holds no result for it. */
    public function run(string $name, callable $effect): string
    {
        $id = ++$this->completion;
        $this->next(T_RUN, Wire::varint(11, $id).Wire::bytes(12, $name));
        if (isset($this->notifications[$id])) {
            return self::value($this->notifications[$id][5][0]);
        }
        $this->out .= Wire::frame(T_PROPOSE_RUN, Wire::varint(1, $id).Wire::bytes(14, $effect()));

        throw $this->suspend($id);
    }

    public function sleep(int $seconds): void
    {
        $id = ++$this->completion;
        $wakeUp = (int) (microtime(true) * 1000) + $seconds * 1000;
        $this->next(T_SLEEP, Wire::varint(1, $wakeUp).Wire::varint(11, $id));
        isset($this->notifications[$id]) || throw $this->suspend($id);
    }

    public function promise(string $key): string
    {
        $id = ++$this->completion;
        $this->next(T_GET_PROMISE, Wire::bytes(1, $key).Wire::varint(11, $id));

        return isset($this->notifications[$id]) ? self::value($this->notifications[$id][5][0]) : throw $this->suspend($id);
    }

    public function completePromise(string $key, string $value): void
    {
        $this->next(T_COMPLETE_PROMISE, Wire::bytes(1, $key).Wire::bytes(2, Wire::bytes(1, $value)).Wire::varint(11, ++$this->completion));
    }

    public function end(string $output): string
    {
        $this->next(T_OUTPUT, Wire::bytes(14, Wire::bytes(1, $output)));

        return $this->out.Wire::frame(T_END, '');
    }

    public function suspended(): string
    {
        return $this->out;
    }

    /** Replays the next journaled command, or records a new one once the replay is over. */
    private function next(int $type, string $message): array
    {
        $index = $this->command++;
        if ($index < \count($this->replayed)) {
            [$replayedType, $fields] = $this->replayed[$index];
            $replayedType === $type || throw new LogicException(sprintf('Journal mismatch at command %d: 0x%04X replayed, 0x%04X expected', $index, $replayedType, $type));

            return $fields;
        }
        $this->out .= Wire::frame($type, $message);

        return [];
    }

    private function suspend(int $completion): Suspend
    {
        $this->out .= Wire::frame(T_SUSPENSION, Wire::packed(1, [$completion]));

        return new Suspend();
    }

    private static function value(string $valueMessage): string
    {
        return (string) (Wire::decode($valueMessage)[1][0] ?? '');
    }
}

$path = parse_url($_SERVER['REQUEST_URI'], \PHP_URL_PATH);

if ('/discover' === $path) {   // the public spec says /discovery; server 1.7.12 calls /discover
    header('Content-Type: application/vnd.restate.endpointmanifest.v3+json');
    echo json_encode([
        'protocolMode' => 'REQUEST_RESPONSE',
        'minProtocolVersion' => PROTOCOL,
        'maxProtocolVersion' => PROTOCOL,
        'services' => [['name' => 'Spike', 'ty' => 'WORKFLOW', 'handlers' => [
            ['name' => 'run', 'ty' => 'WORKFLOW'],
            ['name' => 'approve', 'ty' => 'SHARED'],
        ]]],
    ]);

    return;
}

file_put_contents(__DIR__.'/var/requests.log', $path."\n", \FILE_APPEND);

$handler = match ($path) {
    '/invoke/Spike/run' => static function (Invocation $ctx): string {
        $charged = $ctx->run('charge', static function (): string {
            file_put_contents(__DIR__.'/var/activity.log', "charge\n", \FILE_APPEND);

            return '"charged"';
        });
        $ctx->sleep(2);
        $approval = $ctx->promise('approval');

        return json_encode(['activity' => json_decode($charged), 'approval' => json_decode($approval)]);
    },
    '/invoke/Spike/approve' => static function (Invocation $ctx): string {
        $ctx->completePromise('approval', $ctx->input);

        return '"ok"';
    },
    default => null,
};
if (null === $handler) {
    http_response_code(404);

    return;
}

header('Content-Type: application/vnd.restate.invocation.v'.PROTOCOL);
$ctx = new Invocation(file_get_contents('php://input'));
try {
    echo $ctx->end($handler($ctx));
} catch (Suspend) {
    echo $ctx->suspended();
}
