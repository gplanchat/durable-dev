# change/magento-module

Tranche **1.1** — rendre le banc `magento/` suivi, comme `sylius/` l'est.

Ce que la tâche ne disait pas : « sources oui, `vendor/` non » ne suffit pas.
`git add -An magento` met **10 178 fichiers** en scène une fois `vendor/` exclu
— `dev/`, `lib/`, `setup/`, `pub/`, `generated/`, `var/`, tout ce que composer
installe. Les 220 fichiers de `sylius/` sont son squelette applicatif ;
l'équivalent Magento tient en six fichiers d'overlay.

La tranche livre donc un `magento/.gitignore`, comme `sylius/` en a un.
