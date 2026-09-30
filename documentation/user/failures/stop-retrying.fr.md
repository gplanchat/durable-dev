---
title: Arrêter les réessais pour une exception
weight: 20
---

# Arrêter les réessais pour une exception

Ce guide montre comment empêcher Durable de réessayer une activité quand une exception donnée est
levée. Une activité est une unité d'effet de bord dans un workflow : un appel HTTP, une écriture en
base, un e-mail. Voyez le [glossaire](../../glossary/).

Il s'applique aux erreurs qu'aucune tentative suivante ne peut corriger. Une carte refusée reste
refusée à la troisième tentative.

## Déclarer l'exception non réessayable

Ajoutez l'exception à `nonRetryableExceptions` quand vous construisez les options de l'activité :

```php
use Gplanchat\Durable\Activity\ActivityOptions;

ActivityOptions::of(5, nonRetryableExceptions: [PaymentRefusedException::class]);
```

Quand l'activité lève `PaymentRefusedException`, la tentative échoue et aucune autre tentative
n'a lieu. L'événement `ActivityFailed` enregistre `retryState` = `NonRetryableFailure`.

Sur le backend Temporal, cette liste devient le `nonRetryableErrorTypes` de la politique de
réessai. Le serveur Temporal cesse alors de réessayer, en plus du worker PHP.

Avec Symfony Messenger, l'échec est écrit dans le journal, puis levé en
`UnrecoverableMessageHandlingException`. Messenger ne le réessaie pas, et l'envoie au
`failure_transport` si vous en avez configuré un. Voyez
[Les réessais avec Symfony Messenger](../messenger/).

## Limiter les autres réessais

`nonRetryableExceptions` ne couvre que les exceptions que vous listez. Toute autre exception reste
réessayable, et sans limite explicite, les tentatives sont **illimitées**. Pour en fixer une, voyez [Options](../../options/#retrylimit).
`RetryLimit::ofAttempts(3)` autorise trois exécutions au total, la première comprise.

## Pages liées

- [Événements et états d'échec](../events/)
- [Diagnostiquer une activité ou un workflow en échec](../diagnose/)
