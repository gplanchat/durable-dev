<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bridge;

use Gplanchat\Durable\Awaitable\Awaitable;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

/**
 * Bridge optionnel vers ReactPHP Promise.
 * Nécessite : composer require react/promise.
 *
 * Permet de convertir un Awaitable durable en PromiseInterface ReactPHP,
 * utile pour du code à l'intérieur d'une activité qui consomme des APIs React.
 */
final class ReactPromise
{
    public static function toReactPromise(Awaitable $awaitable): PromiseInterface
    {
        $deferred = new Deferred();

        $awaitable->then(
            static fn (mixed $value) => $deferred->resolve($value),
            static fn (\Throwable $reason) => $deferred->reject($reason),
        );

        return $deferred->promise();
    }
}
