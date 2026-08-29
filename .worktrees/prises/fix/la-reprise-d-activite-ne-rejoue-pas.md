# fix/la-reprise-d-activite-ne-rejoue-pas

- **Chantier** : issue #218 — sur Temporal, une activité en échec consomme ses tentatives sans que son code soit rappelé
- **Entrées** : `src/Durable/Store/ActivityEventJournal.php`, `src/Bridge/Temporal/Worker/TemporalActivityWorker.php`, `tests/unit/Bridge/Temporal/`
- **État** : en cours
