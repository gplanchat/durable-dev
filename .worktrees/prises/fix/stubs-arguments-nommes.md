# fix/stubs-arguments-nommes

- **Chantier** : les trois stubs `__call` (`ActivityStub`, `NexusStub`, `ChildWorkflowStub`)
  apparient les arguments **par position** ; PHP passe les arguments nommés dans un tableau à clés
  de chaînes, donc *tous* les paramètres retombent sur leur défaut, sans exception ni trace.
- **Entrées** : `src/Durable/Activity/`, `src/Durable/Nexus/`, `src/Durable/Workflow/`, et leurs
  tests. Ne touche pas les ponts.
- **État** : correction. Trouvé en écrivant la délégation d'agent (`spike/agent-durable-symfony-ai`) :
  un workflow enfant démarrait avec un prompt vide et attendait un message qui ne viendrait jamais.
