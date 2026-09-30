---
title: Échecs et réessais
weight: 26
---

# Échecs et réessais

Quand une activité échoue, Durable peut la réessayer. Chaque tentative et le résultat final sont
enregistrés dans le journal. Une activité est une unité d'effet de bord dans un workflow : un appel
HTTP, une écriture en base, un e-mail. Le journal est l'enregistrement, en ajout seul, de tout ce
qu'une exécution a décidé et reçu. Les deux termes sont dans le [glossaire](../glossary/).

Choisissez la page qui correspond à votre besoin. Pour annuler les effets d'un processus en échec,
voyez [Compenser](../cancellation/#compenser).

## Diagnostiquer une activité ou un workflow en échec {#pourquoi-une-activité-a-cessé-de-réessayer}

[Diagnostiquer une activité ou un workflow en échec](diagnose/) : une activité qui cesse de
réessayer trop tôt ou ne s'arrête jamais, un `messenger:failed:retry` qui ne relance rien, une
ligne *early resume* dans les logs, un workflow terminé par `WorkflowExecutionFailed`.

## Arrêter les réessais pour une exception {#déclarer-une-exception-non-réessayable}

[Arrêter les réessais pour une exception](stop-retrying/) : déclarer une exception non
réessayable, pour qu'une erreur qu'aucune tentative suivante ne peut corriger, comme une carte
refusée, ne soit jamais retentée.

## Événements et états d'échec {#ce-que-le-journal-enregistre-pour-une-activité}

<span id="le-décompte-des-tentatives"></span><span id="quand-cest-le-workflow-lui-même-qui-échoue"></span>
[Événements et états d'échec](events/) : les événements du journal pour une activité, les valeurs
de `retryState`, le décompte des tentatives par `RetryLimit`, et les valeurs de `kind` de
`WorkflowExecutionFailed`.

## Les réessais avec Symfony Messenger {#quel-réglage-de-réessai-vit-où-symfony-messenger}

[Les réessais avec Symfony Messenger](messenger/) : quels réglages de réessai relèvent de Durable
et lesquels de Messenger, pourquoi il n'existe qu'un seul compteur de réessais, et les deux
messages qui passent outre `max_retries`.
