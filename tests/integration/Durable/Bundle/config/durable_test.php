<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->import('test_null_logger.php');
    $container->extension('framework', [
        'secret' => 'test',
        'http_method_override' => false,
        'test' => true,
    ]);
    $container->extension('durable', []);
};
