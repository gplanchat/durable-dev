<?php

declare(strict_types=1);

use Gplanchat\Durable\Activity\PayloadToContractMethodInvoker;
use Gplanchat\Durable\Handler\FireWorkflowTimersHandler;
use Gplanchat\Durable\Handler\ResumeWorkflowHandler;
use Gplanchat\Durable\Timer\TimerWakeDelayCalculator;
use Gplanchat\Durable\Workflow\AsyncChildWorkflowFailureProjector;
use Rector\Config\RectorConfig;
use Rector\Renaming\Rector\Name\RenameClassRector;

/**
 * What moves **inside** Durable, from one version to the next.
 *
 * `temporal-sdk.php` brings a project into Durable; this one moves it forward. They are separate
 * because they are not loaded at the same moment: the first one once, the second on every upgrade.
 *
 * The repository's rule is explicit — **every public break comes with its migration
 * procedure**: Rector first, a script when Rector cannot, and documentation in every case. A class
 * rename is precisely the case where Rector can, so there is no excuse for letting a project
 * discover it through an autoloading error.
 *
 * ⚠ Class moves **between packages** cannot be seen at install time: Composer installs both
 * packages without a word, and the old name simply disappears. Worse on a Symfony project, whose
 * **compiled container** holds the fully qualified name: the failure lands on the first call after
 * an update with no cache clear, far from its cause.
 */
return RectorConfig::configure()
    ->withConfiguredRule(RenameClassRector::class, [
        // 0.1.0-alpha8 — the payload → contract-method adapter moves down from the bundle
        // package to the core: it imported nothing from Symfony, and Magento needs it word for
        // word. After this upgrade, clear the container cache (`bin/console cache:clear`), without
        // which the compiled container keeps asking for the old name.
        'Gplanchat\Durable\Bundle\Activity\PayloadToContractMethodInvoker' => PayloadToContractMethodInvoker::class,
        // 0.1.0-alpha8 — computing the next timer wake moves down to the core as well, and for a
        // more serious reason: `InMemoryWorkflowRunner`, which **is** core, called it. A host that
        // does not install the bundle took a fatal error on the first resume.
        'Gplanchat\Durable\Bundle\Messenger\TimerWakeDelayCalculator' => TimerWakeDelayCalculator::class,

        // 0.1.0-alpha8 — the resume orchestration moves down to the core. Out of 279 lines, 21
        // touched Symfony, and they served two things only: a v7 identifier and the timer wake-up.
        // The first is already in the core, the second has become a port. Six hosts on the selector
        // do not ride the bundle and would each have had to carry a copy.
        'Gplanchat\Durable\Bundle\Handler\ResumeWorkflowHandler' => ResumeWorkflowHandler::class,
        'Gplanchat\Durable\Bundle\Handler\FireWorkflowTimersHandler' => FireWorkflowTimersHandler::class,
        'Gplanchat\Durable\Bundle\Support\AsyncChildWorkflowFailureProjector' => AsyncChildWorkflowFailureProjector::class,

        // 0.1.0-alpha8 — every declaration attribute takes the `As` prefix. The repository carried
        // two conventions: the core named its attributes with no prefix, the Symfony bundle had a
        // single one, prefixed, and neither the Illuminate bridge nor the Magento module had any.
        // Serving Nexus meant adding some, and therefore choosing. The method attributes follow, so
        // that there is one rule to remember rather than a rule and its exception.
        'Gplanchat\Durable\Attribute\Workflow' => 'Gplanchat\Durable\Attribute\AsWorkflow',
        'Gplanchat\Durable\Attribute\Activity' => 'Gplanchat\Durable\Attribute\AsActivity',
        'Gplanchat\Durable\Attribute\WorkflowMethod' => 'Gplanchat\Durable\Attribute\AsWorkflowMethod',
        'Gplanchat\Durable\Attribute\ActivityMethod' => 'Gplanchat\Durable\Attribute\AsActivityMethod',
        'Gplanchat\Durable\Attribute\QueryMethod' => 'Gplanchat\Durable\Attribute\AsQueryMethod',
        'Gplanchat\Durable\Attribute\SignalMethod' => 'Gplanchat\Durable\Attribute\AsSignalMethod',
        'Gplanchat\Durable\Attribute\UpdateMethod' => 'Gplanchat\Durable\Attribute\AsUpdateMethod',

        // And this version's second move between packages, exactly the same trap as the one
        // above: the declaration attribute of an activity implementation leaves the bundle for the
        // core, under the name that pairs it with `AsNexusServiceHandler`. It is **read by a
        // compiler pass**, so the compiled container holds on to it.
        'Gplanchat\Durable\Bundle\Attribute\AsDurableActivity' => 'Gplanchat\Durable\Attribute\AsActivityHandler',
    ]);
