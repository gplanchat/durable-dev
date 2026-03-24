<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle;

use Gplanchat\Durable\Bundle\DependencyInjection\Compiler\WorkflowPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class DurableBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new WorkflowPass());
    }
}
