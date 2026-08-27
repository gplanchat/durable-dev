# feat/logo-typo3

La marque TYPO3, dix-huitième du jeu. TYPO3 entre au sélecteur comme intégration
à part entière : ce n'est ni un kernel Symfony ni une application Laravel, donc
`durable-bundle` ne s'y installe pas tel quel et elle a son propre interrupteur.

Backends : in-memory, Temporal **et Doctrine DBAL**. TYPO3 utilise DBAL comme
couche d'abstraction de base de données depuis la v9, la connexion est déjà dans
l'install — même argument que Symfony. Seul Illuminate reste fermé.

> DBAL avait d'abord été fermé faute d'avoir vérifié le moteur de base. La
> vérification a rendu la question sans objet ; la contrainte est levée et le
> message du commit `a20d99e` la décrit encore à l'ancien état.
