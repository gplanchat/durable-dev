---
title: Introduction
weight: 1
---

Bienvenue dans la documentation **Durable**. Le fil le plus pédagogique est le **[parcours guidé — installation et premier workflow]({{< relref "/docs/parcours-premier-workflow/" >}})** : vous y enchaînez les commandes réelles (monorepo ou projet Composer) jusqu’à un premier **Hello** fonctionnel, puis vous approfondissez avec les pages ci-dessous.

{{< card >}}
### Commencer par le parcours (recommandé)

1. **[Parcours : installer et exécuter un premier workflow]({{< relref "/docs/parcours-premier-workflow/" >}})** — prérequis, `composer install`, `durable:schema:init`, `durable:sample`, option workers.
2. Ensuite : [Workflows et activités]({{< relref "/docs/workflows-et-activites/" >}}) pour comprendre **pourquoi** le code du workflow est contraint (déterminisme, `await`, replay).
3. Puis : [Installation du bundle Symfony]({{< relref "/docs/installation-bundle/" >}}) pour **tout** configurer (YAML, Messenger, tags) dans votre projet.
{{< /card >}}

{{% columns %}}
- {{< card >}}
  ### En complément
  - [DBAL et MySQL]({{< relref "/docs/dbal-et-mysql/" >}}) — connexion dédiée, non bufferisé.
  - [Temporal avec Durable]({{< relref "/docs/temporal/" >}}) — journal gRPC.
  - [README sur GitHub](https://github.com/gplanchat/durable-dev#readme) — vue d’ensemble du dépôt.

  **Prérequis** : PHP 8.2+, Symfony 6.4+ ou 7.4+ pour le bundle.
  {{< /card >}}

- {{< card >}}
  ### Architecture & contribuer
  Les **ADR**, **WA**, **OST** et **PRD** sont répertoriés ici (liens vers les sources GitHub) :

  [**→ Architecture documentée**]({{< relref "/docs/architecture/" >}})

  [**→ Contribuer**]({{< relref "/docs/contribuer/" >}})
  {{< /card >}}
{{% /columns %}}

{{< card >}}
### Guides (référence)

| Sujet | Page |
|-------|------|
| Parcours pas à pas (installation + premier exemple) | [Parcours : premier workflow]({{< relref "/docs/parcours-premier-workflow/" >}}) |
| Workflows, activités, déterminisme, async durable | [Workflows et activités]({{< relref "/docs/workflows-et-activites/" >}}) |
| Bundle, Messenger, schéma, workers | [Installation du bundle Symfony]({{< relref "/docs/installation-bundle/" >}}) |
| Connexion DBAL dédiée, lectures non bufferisées (MySQL) | [DBAL : connexion et MySQL]({{< relref "/docs/dbal-et-mysql/" >}}) |
| Bridge Temporal (journal d’événements) | [Temporal avec Durable]({{< relref "/docs/temporal/" >}}) |
{{< /card >}}

---

[← Retour à l’accueil du site]({{< relref "/" >}})
