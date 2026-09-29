<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ScalarNodeDefinition;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\ScalarNode;

/**
 * `durable.temporal.dsn`, which refuses an empty or blank DSN — but not a `%env()%` placeholder.
 *
 * At compile time Symfony checks a placeholder with an empty string when the variable has no
 * `env()` default, and a validation closure cannot tell that string from a literal one: the blank
 * rule of #334 refused every `dsn: '%env(DURABLE_DSN)%'` written without a default. Only the node
 * knows whether it is checking a placeholder, hence a node of its own.
 *
 * @internal
 *
 * @psalm-suppress MissingTemplateParam ScalarNodeDefinition's TParent defaults to null, which Psalm
 *                                      ignores; an @extends would break the Symfony 6.4 line, where
 *                                      the builder is not generic
 */
final class TemporalDsnNodeDefinition extends ScalarNodeDefinition
{
    protected function instantiateNode(): ScalarNode
    {
        return new class ($this->name, $this->parent, $this->pathSeparator) extends ScalarNode {
            protected function finalizeValue(mixed $value): mixed
            {
                if (!$this->isHandlingPlaceholder() && \is_string($value) && '' === trim($value)) {
                    throw new InvalidConfigurationException(\sprintf('Invalid configuration for path "%s": A temporal://… DSN string is expected, got "%s".', $this->getPath(), $value));
                }

                return parent::finalizeValue($value);
            }
        };
    }
}
