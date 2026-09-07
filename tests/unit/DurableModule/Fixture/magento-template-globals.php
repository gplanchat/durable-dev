<?php

declare(strict_types=1);

/*
 * A `.phtml` has no namespace: it runs in the global one, and a `__()` function declared in a
 * namespaced test file is therefore not visible there. Hence this file here, with no namespace,
 * rather than a writing trick inside the test.
 */

if (!\function_exists('__')) {
    /**
     * Magento's `__()`, reduced to the positional replacement the templates of this module do.
     */
    function __(string $message, mixed ...$arguments): string
    {
        foreach ($arguments as $index => $argument) {
            $message = str_replace('%' . ($index + 1), (string) $argument, $message);
        }

        return $message;
    }
}
