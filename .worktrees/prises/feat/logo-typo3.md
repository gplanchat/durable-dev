# feat/logo-typo3

La marque TYPO3, dix-huitième du jeu. TYPO3 entre au sélecteur comme intégration
à part entière : ce n'est ni un kernel Symfony ni une application Laravel, donc
`durable-bundle` ne s'y installe pas tel quel et elle a son propre interrupteur.

Contrainte de backend décidée : **Temporal et in-memory seulement**. Le moteur de
base de données derrière TYPO3 n'est pas vérifié, donc DBAL reste fermé plutôt
que promis à tort.
