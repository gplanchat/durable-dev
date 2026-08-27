## 1. Probe before encoding any rule

- [x] 1.1 Probe what the server accepts as a Nexus header — **le serveur est permissif sur tout sauf la casse** : clé vide, valeur vide, blancs en bord, saut de ligne, espace dans la clé, 1000 caractères, tous acceptés tels quels. Verdicts dans `design.md`, épinglés par `NexusHeaderRulesTest`
- [x] 1.2 Probe whether the server rewrites or drops anything silently — **oui, une seule fois** : la clé est minusculée en silence, et deux clés qui ne diffèrent que par la casse entrent en collision — deux en-têtes entrent, un seul sort, sans erreur ni trace. C'est la seule chose que §2.1 ait à empêcher

## 2. Domain

- [x] 2.1 A header value object built on the probed rules — `NexusOperationHeaders` : minuscule la clé à la construction (coercition, pas refus : `X-Correlation` *est* `x-correlation`) et refuse une collision de casse en nommant **les deux graphies**. Rien d'autre — clé vide, valeur vide, blancs, saut de ligne et mille caractères sont acceptés parce que le serveur les accepte
- [x] 2.2 Unit tests asserting the probed verdicts, one case per observation — 13 cas, un par ligne du tableau de `design.md`, plus la collision et son message

## 3. Port and backends

- [x] 3.1 `WorkflowCommandBufferInterface::scheduleNexusOperation()` carries the headers — **BREAKING**, as DUR031 was : septième paramètre **requis**, pas optionnel, pour qu'aucune implémentation ne l'oublie en silence
- [x] 3.2 `TemporalWorkflowCommandBuffer` writes them into the command — livrée avec 3.1 : élargir le port sans que le pont n'écrive rien aurait laissé un paramètre accepté puis ignoré, pire que pas de paramètre du tout. Le champ n'est écrit que s'il y a quelque chose à porter — une map vide n'est pas une map absente pour qui relit un historique
- [x] 3.3 `TemporalExecutionHistory` reads them back, if anything needs them on replay — **rien n'en a besoin, et c'est le verdict de la tâche** : un en-tête voyage vers le handler, il ne revient pas au workflow. Aucun code de relecture n'est donc écrit. Le test d'aller-retour de §4.1 les lit tout de même depuis `NEXUS_OPERATION_SCHEDULED`, ce qui établit qu'ils y sont si cela devait changer

## 4. Integration

- [x] 4.1 A header sent through the bridge comes back unchanged in `NEXUS_OPERATION_SCHEDULED`, against a real server — trois cas ajoutés au test d'aller-retour existant plutôt qu'un second fichier : deux en-têtes reviennent inchangés, une clé donnée en majuscules revient identique à ce que l'appelant **tenait** (et non à ce qu'il avait tapé), et l'absence d'en-tête reste une absence

## 5. Documentation

- [x] 5.1 Update the `nexus-operations` spec: headers move from `MAY` to a capability actually offered — l'exigence dit désormais que les en-têtes atteignent l'opération **inchangés**, et que la validation des clés a lieu là où on les écrit plutôt que par une réécriture serveur
