# fix/minuteur-deja-annule

- **Chantier** : le pont Temporal réémettait `CANCEL_TIMER` à chaque reprise pour un minuteur qu'il avait déjà annulé — le serveur rejetait la tâche entière (`BadCancelTimerAttributes`), le worker mourait, la tâche était redélivrée, et une seule exécution empoisonnait toute la file `durable-journal`.
- **Entrées** : `src/Bridge/Temporal/Worker/TemporalExecutionHistory.php`, `src/Bridge/Temporal/Worker/TemporalWorkflowCommandBuffer.php`, `tests/unit/Bridge/Temporal/Worker/TimerCancelNotRepeatedTest.php`
- **État** : en relecture. Trouvé pendant un spike d'agent durable — une validation d'outil suspendue sous échéance, réglée par un signal avant l'échéance.
