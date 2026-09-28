<?php

declare(strict_types=1);

// Router for `php -S`: a Restate service deployment in REQUEST_RESPONSE mode, protocol V5.
// One workflow `Spike`: `run` (activity, sleep, promise) and `approve` (completes the promise).

require __DIR__.'/Wire.php';

const PROTOCOL = 5;
const T_START = 0x0000, T_SUSPENSION = 0x0001, T_END = 0x0003, T_PROPOSE_RUN = 0x0005;
const T_INPUT = 0x0400, T_OUTPUT = 0x0401, T_GET_PROMISE = 0x0409, T_COMPLETE_PROMISE = 0x040B;
const T_SLEEP = 0x040C, T_RUN = 0x0411;
const T_CALL = 0x040D, T_SEND_SIGNAL = 0x0410, T_SIGNAL = 0xFBFF;
const SIGNAL_CANCEL = 1;   // BuiltInSignal.CANCEL

final class Suspend extends Exception
{
}

/** The runtime delivered SIGNAL_CANCEL to this invocation and the handler was waiting. */
final class Cancelled extends Exception
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
    /** @var array<int, true> built-in signal index => received */
    private array $signals = [];
    public readonly string $input;

    public function __construct(string $body)
    {
        foreach (Wire::frames($body) as [$type, $message]) {
            if (T_SIGNAL === $type) {   // field 1 is reserved here: the signal index is field 2
                $this->signals[Wire::decode($message)[2][0] ?? 0] = true;
            } elseif ($type >= 0x8000) {
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
        $this->out .= Wire::frame(T_PROPOSE_RUN, Wire::varint(1, $id).Wire::bytes(14, $result = $effect()));

        return $result;   // no suspend: the server stores the proposal and replays it as a RunCompletionNotification
    }

    public function sleep(int $seconds): void
    {
        $id = ++$this->completion;
        $wakeUp = (int) (microtime(true) * 1000) + $seconds * 1000;
        $this->next(T_SLEEP, Wire::varint(1, $wakeUp).Wire::varint(11, $id));
        isset($this->notifications[$id]) || throw $this->wait($id);
    }

    /** @return array{int, int} [invocation id completion, result completion] */
    public function call(string $service, string $handler, string $parameter): array
    {
        $ids = [++$this->completion, ++$this->completion];
        $this->next(T_CALL, Wire::bytes(1, $service).Wire::bytes(2, $handler).Wire::bytes(3, $parameter)
            .Wire::varint(10, $ids[0]).Wire::varint(11, $ids[1]));

        return $ids;
    }

    public function awaitCall(int $resultId): string
    {
        $fields = $this->notifications[$resultId] ?? throw $this->wait($resultId);
        isset($fields[6]) && throw new RuntimeException('call failed: '.(Wire::decode($fields[6][0])[2][0] ?? ''));

        return self::value($fields[5][0]);
    }

    /** The callee's invocation id, which a cancellation has to name; suspends until the runtime sent it. */
    public function invocationId(int $idCompletion): string
    {
        return (string) ($this->notifications[$idCompletion][16][0] ?? throw $this->suspend([$idCompletion]));
    }

    /** What an SDK does to cancel a child: a SendSignal CANCEL command, journaled like any other. */
    public function cancelInvocation(string $invocationId): void
    {
        $this->next(T_SEND_SIGNAL, Wire::bytes(1, $invocationId).Wire::varint(2, SIGNAL_CANCEL).Wire::bytes(4, ''));
    }

    public function fail(int $code, string $message): string
    {
        $this->next(T_OUTPUT, Wire::bytes(15, Wire::varint(1, $code).Wire::bytes(2, $message)));

        return $this->out.Wire::frame(T_END, '');
    }

    public function promise(string $key): string
    {
        $id = ++$this->completion;
        $this->next(T_GET_PROMISE, Wire::bytes(1, $key).Wire::varint(11, $id));

        return isset($this->notifications[$id]) ? self::value($this->notifications[$id][5][0]) : throw $this->wait($id);
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

    /**
     * An await that cannot complete yet: cancelled if the runtime already delivered CANCEL, suspended
     * otherwise, and the suspension lists CANCEL so that the runtime wakes the invocation for it (the
     * SDKs' shared core always awaits it next to the user's future).
     */
    private function wait(int $completion): Cancelled|Suspend
    {
        return isset($this->signals[SIGNAL_CANCEL]) ? new Cancelled() : $this->suspend([$completion], [SIGNAL_CANCEL]);
    }

    /**
     * @param list<int> $completions
     * @param list<int> $signals
     */
    private function suspend(array $completions, array $signals = []): Suspend
    {
        // V5/V6 SuspensionMessage (legacy.proto): waiting_completions = 1, waiting_signals = 2
        $this->out .= Wire::frame(T_SUSPENSION, Wire::packed(1, $completions).Wire::packed(2, $signals));

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
        ]], ['name' => 'Cancel', 'ty' => 'WORKFLOW', 'handlers' => [
            ['name' => 'run', 'ty' => 'WORKFLOW'],
        ]], ['name' => 'Act', 'ty' => 'SERVICE', 'handlers' => [
            ['name' => 'a'], ['name' => 'b'],
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
    // Scenario 1 (#641): A is called and never awaited, B is awaited, then the workflow is cancelled
    // from the admin API. Mode "none" propagates nothing; mode "bridge" cancels B only, which is what
    // Durable's AwaitableCancellation::cancelUnsettled() would target.
    '/invoke/Cancel/run' => static function (Invocation $ctx): string {
        $mode = json_decode($ctx->input);
        $ctx->call('Act', 'a', '"A"');
        [$bId, $bResult] = $ctx->call('Act', 'b', '"B"');
        try {
            return $ctx->awaitCall($bResult);
        } catch (Cancelled $cancelled) {
            if ('bridge' === $mode) {
                $ctx->cancelInvocation($ctx->invocationId($bId));
            }
            throw $cancelled;
        }
    },
    '/invoke/Act/a', '/invoke/Act/b' => static function (Invocation $ctx): string {
        $ctx->sleep(6);
        file_put_contents(__DIR__.'/var/act.log', $ctx->input." completed\n", \FILE_APPEND);

        return '"done"';
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
} catch (Cancelled) {
    file_put_contents(__DIR__.'/var/act.log', $path.' '.$ctx->input." cancelled\n", \FILE_APPEND);
    echo $ctx->fail(409, 'cancelled');
}
