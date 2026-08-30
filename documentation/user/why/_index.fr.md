---
title: Pourquoi Durable
weight: 1
---

# Pourquoi Durable

Certains traitements ne tiennent pas dans une requête. Débiter une carte, réserver le stock et
envoyer un reçu est **une** opération métier, mais elle touche trois systèmes, dure plus longtemps
qu'une connexion ne reste ouverte, et peut être interrompue entre deux étapes quelconques par un
déploiement, un plantage ou un OOM kill.

PHP n'apporte pas de réponse à ça, donc chaque projet invente la sienne. Durable est cette réponse,
écrite une fois.

## Vous avez déjà le problème si

Cherchez ceci dans votre propre code. Chacun de ces éléments est un morceau d'exécution durable,
construit à la main :

- une **colonne d'état qui veut dire *peut-être*** — `pending`, `processing`, `in_progress` — et
  personne ne sait quelles lignes sont bloquées ;
- une **clé d'idempotence** que vous avez écrite vous-même, parce qu'un réessai a débité un client
  deux fois, une fois ;
- un **travail de réconciliation** qui tourne la nuit pour retrouver les opérations arrêtées en
  chemin ;
- un **script de réparation**, lancé à la main en production, quand un lot meurt au milieu ;
- un **compteur de réessais et une table de rebut**, avec la procédure qui dit quoi en faire ;
- aucun moyen de répondre à *pourquoi la commande 4242 s'est arrêtée il y a trois jours*, sinon
  relire des journaux applicatifs.

Ces six choses existent pour qu'un traitement survive à une interruption. Durable fait survivre le
traitement directement : le runtime enregistre chaque étape terminée dans un **journal**, et après
un redémarrage il rejoue la méthode en rendant les résultats enregistrés au lieu de refaire ces
étapes. Le traitement reprend sur la ligne où il en était.

Un worker peut être redéployé en plein traitement. Rien n'est débité deux fois, rien n'est perdu, et
aucun cron n'intervient.

## Ce que ça remplace

Une méthode, et le journal derrière elle, au lieu de :

| Ce que vous maintenez aujourd'hui | Ce qui y répond à la place |
|---|---|
| Une colonne d'état, et la migration qui ajoute l'état suivant | La ligne où la méthode en est — le journal tient la position |
| Un planificateur qui interroge ce qui est dû | L'instruction suivante ; minuteries et signaux réveillent l'exécution |
| Un compteur de réessais et une table de rebut | `RetryLimit::ofAttempts(3)`, une option sur le stub d'activité |
| Des clés d'idempotence, pour qu'un réessai ne débite pas deux fois | Une étape enregistrée rend son résultat enregistré — elle ne peut pas s'exécuter deux fois |
| Relire les journaux pour savoir pourquoi une exécution s'est arrêtée | Rejouer son journal : chaque étape, chaque résultat, chaque tentative |

La [page d'accueil](/fr/) déroule la même commande, étape par étape, en montrant ce qui se passe
avec et sans. C'est le chemin le plus rapide pour voir le mécanisme si vous avez cinq minutes et pas
de code sous les yeux.

## Quand vous n'en avez pas besoin

Durable n'est pas gratuit : ça ajoute un journal à écrire, des workers à faire tourner, et une règle
de déterminisme que le code de workflow doit respecter. Passez votre chemin quand :

- le traitement **tient dans une requête** et n'a aucun effet de bord qui vaille d'être rattrapé —
  rendre une page, une requête de recherche, un rapport que vous pouvez relancer ;
- le traitement **peut repartir de zéro sans dommage**. Un export nocturne qui réécrit tout le
  fichier ne perd rien à être relancé depuis le début ; un débit partiel, si ;
- vos consommateurs de file sont **déjà idempotents et déjà observables**, et vous savez répondre à
  *qu'est-il arrivé à ce travail* sans ouvrir un fichier de journal. Vous avez déjà construit la
  chose ; inutile de l'avoir deux fois ;
- il y a **exactement un effet de bord**. Un seul `INSERT` dans une transaction est déjà atomique.
  Le problème commence à la deuxième étape, quand la première a déjà eu lieu et ne peut plus être
  annulée.

Une bonne règle : si perdre sa place en cours de traitement coûte de l'argent, du stock ou la
confiance d'un client, le traitement veut un journal. Si ça coûte une relance, non.

## Où aller ensuite

| | |
|---|---|
| [Concepts](../concepts/) | le vocabulaire — workflow, activité, journal, rejeu — avant les guides |
| [Premiers pas](../getting-started/) | installer, configurer, et écrire un premier workflow |
| [Paquets](../packages/) | quoi installer pour votre framework, et quel backend |
| [Durable et le SDK PHP de Temporal](../comparison/) | si l'exécution durable est décidée et que vous choisissez entre les deux |

La dernière suppose que la décision dont parle cette page est déjà prise. À lire en second.
