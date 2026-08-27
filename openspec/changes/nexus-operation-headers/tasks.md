## 1. Probe before encoding any rule

- [x] 1.1 Probe what the server accepts as a Nexus header — **le serveur est permissif sur tout sauf la casse** : clé vide, valeur vide, blancs en bord, saut de ligne, espace dans la clé, 1000 caractères, tous acceptés tels quels. Verdicts dans `design.md`, épinglés par `NexusHeaderRulesTest`
- [x] 1.2 Probe whether the server rewrites or drops anything silently — **oui, une seule fois** : la clé est minusculée en silence, et deux clés qui ne diffèrent que par la casse entrent en collision — deux en-têtes entrent, un seul sort, sans erreur ni trace. C'est la seule chose que §2.1 ait à empêcher

## 2. Domain

- [x] 2.1 A header value object built on the probed rules — `NexusOperationHeaders` : minuscule la clé à la construction (coercition, pas refus : `X-Correlation` *est* `x-correlation`) et refuse une collision de casse en nommant **les deux graphies**. Rien d'autre — clé vide, valeur vide, blancs, saut de ligne et mille caractères sont acceptés parce que le serveur les accepte
- [x] 2.2 Unit tests asserting the probed verdicts, one case per observation — 13 cas, un par ligne du tableau de `design.md`, plus la collision et son message

## 3. Port and backends

- [x] 3.1 `WorkflowCommandBufferInterface::scheduleNexusOperation()` carries the headers — **BREAKING**, as DUR031 was : septième paramètre **requis**, pas optionnel, pour qu'aucune implémentation ne l'oublie en silence
- [x] 3.2 `TemporalWorkflowCommandBuffer` writes them into the command — livrée avec 3.1 : élargir le port sans que le pont n'écrive rien aurait laissé un paramètre accepté puis ignoré, pire que pas de paramètre du tout. Le champ n'est écrit que s'il y a quelque chose à porter — une map vide n'est pas une map absente pour qui relit un historique
- [ ] 3.3 `TemporalExecutionHistory` reads them back, if anything needs them on replay

## 4. Integration

- [ ] 4.1 A header sent through the bridge comes back unchanged in `NEXUS_OPERATION_SCHEDULED`, against a real server

## 5. Documentation

- [ ] 5.1 Update the `nexus-operations` spec: headers move from `MAY` to a capability actually offered
