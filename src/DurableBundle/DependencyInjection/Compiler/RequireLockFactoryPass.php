<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Dit ce qu'il faut configurer quand le verrou de reprise n'a pas de fabrique.
 *
 * Le backend DBAL n'a pas de serveur pour sérialiser les tâches d'une même exécution : c'est
 * `SingleResumeLockMiddleware` qui s'en charge, et sans lui deux workers rejouent le même journal
 * en même temps. Sa fabrique est prise dans le conteneur de l'application.
 *
 * Sans `framework.lock`, ce service n'existe pas et la compilation échoue déjà — sur un « service
 * inexistant » qui nomme `lock.factory` et laisse chercher. Ce que l'exploitant doit savoir n'est
 * pas quel service manque, mais quelle section de configuration l'aurait posé, et pourquoi elle
 * n'est pas optionnelle ici.
 *
 * Vérifié dans une passe et non dans l'extension : au moment où les extensions se chargent, celle
 * qui pose `lock.factory` n'a pas forcément tourné, et un test d'existence y répondrait faux pour
 * une application correctement configurée.
 */
final class RequireLockFactoryPass implements CompilerPassInterface
{
    private const LOCK_SERVICE = 'durable.dbal.single_resume_lock';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::LOCK_SERVICE)) {
            return;
        }

        // Le service peut avoir été redéfini sans argument par l'application ; on retombe alors sur
        // le nom conventionnel plutôt que d'échouer sur la lecture de l'argument.
        $arguments = $container->getDefinition(self::LOCK_SERVICE)->getArguments();
        $factory = (string) ($arguments[0] ?? 'lock.factory');

        if ($container->has($factory)) {
            return;
        }

        throw new \LogicException(\sprintf(
            'durable: le backend DBAL sérialise les reprises d\'une même exécution avec un verrou, '
            . 'et le service "%s" qui le fournit n\'existe pas. Activez le composant Lock — '
            . '`framework.lock: true` dans config/packages/framework.yaml, ou une entrée `framework.lock.resources` '
            . 'pointant un magasin partagé entre vos processus — ou nommez votre propre fabrique dans '
            . '`durable.dbal.lock_factory`. Sans verrou, deux workers rejouent le même journal en même temps.',
            $factory,
        ));
    }
}
