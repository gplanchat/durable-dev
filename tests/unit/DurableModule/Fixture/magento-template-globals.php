<?php

declare(strict_types=1);

/*
 * Un `.phtml` n'a pas d'espace de noms : il s'exécute dans le global, et une fonction `__()`
 * déclarée dans un fichier de test namespacé n'y est donc pas visible. D'où ce fichier-ci, sans
 * espace de noms, et pas une astuce d'écriture dans le test.
 */

if (!\function_exists('__')) {
    /**
     * `__()` de Magento, réduite au remplacement positionnel que les gabarits de ce module font.
     */
    function __(string $message, mixed ...$arguments): string
    {
        foreach ($arguments as $index => $argument) {
            $message = str_replace('%' . ($index + 1), (string) $argument, $message);
        }

        return $message;
    }
}
