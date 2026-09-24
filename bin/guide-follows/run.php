<?php

declare(strict_types=1);

// Runs the guide's first workflow in the guide's one-process profile (`when@test`): the controller
// dispatches, `durable:worker` (the consumer the guide names) drains the in-memory transports, and the journal must
// hold the completion. One kernel for all three, since an in-memory transport dies with its process.
//
// Usage (from the app directory): php run.php

use App\Controller\GreetController;
use App\Kernel;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Dotenv\Dotenv;

require getcwd() . '/vendor/autoload.php';

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
(new Dotenv())->bootEnv(getcwd() . '/.env');
$kernel = new Kernel('test', true);
$kernel->boot();

// The guide gives the controller no route, so the container drops it: build it with what it asks for.
$dispatcher = $kernel->getContainer()->get('test.service_container')->get(WorkflowResumeDispatcher::class);
$response = (new GreetController($dispatcher))('World');
$executionId = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR)['executionId'];
echo "  dispatched $executionId ({$response->getStatusCode()})\n";

$console = new Application($kernel);
$console->setAutoExit(false);
$worker = new BufferedOutput();
$exit = $console->run(new ArrayInput([
    'command' => 'durable:worker',
    '--time-limit' => 5,
    // Between two messages the worker resets services, and an in-memory transport's reset empties
    // it: the activity the resume queued would vanish before the loop reached it.
    '--no-reset' => true,
]), $worker);
echo $said = $worker->fetch();

// The guide promises this line: it is how a reader knows the worker found their transports.
if (0 !== $exit || !str_contains($said, 'Consuming durable_workflows, durable_activities.')) {
    fwrite(\STDERR, "durable:worker did not consume the transports the guide declares (exit $exit).\n");
    exit(1);
}

$diagnosis = new BufferedOutput();
$console->run(new ArrayInput(['command' => 'durable:execution:diagnose', 'executionId' => $executionId, '--json' => true]), $diagnosis);
$events = json_decode($diagnosis->fetch(), true, flags: \JSON_THROW_ON_ERROR)['eventStream']['sample'];
$completed = array_values(array_filter($events, static fn(array $e): bool => 'ExecutionCompleted' === $e['type']));

if ('Hello, World!' !== ($completed[0]['payload']['result'] ?? null)) {
    fwrite(\STDERR, "The first workflow did not complete with 'Hello, World!'. Journal:\n" . json_encode($events, \JSON_PRETTY_PRINT) . "\n");
    exit(1);
}
echo "  completed: Hello, World!\n";
