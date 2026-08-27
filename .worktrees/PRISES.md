# Prises en cours

Plusieurs sessions partagent cette copie. Une tranche se prend en écrivant sa ligne ici **avant**
de commencer, et la ligne se retire à la fusion. Son absence est ce qui a permis à deux sessions
de se croiser sur `workflow-conditions-and-handler-dispatch` le 2026-08-26.

**Une prise se pousse sur `main` avant de commencer, et s'y retire en refermant.** Une ligne écrite
dans un worktree n'existe que là : personne ne la voit avant la fusion, c'est-à-dire une fois le
travail fait. Le 2026-08-26, neuf tranches ont été construites en double pour cette raison — le
bloc 4 entier de `temporal-nexus-support` deux fois, la même classe sous le même nom, la même garde
écrite à l'identique. Le registre n'empêche une collision que s'il est lu **et écrit** sur `main`.

Et une ligne qu'on ne retire pas ment aussi longtemps qu'elle reste. Le 2026-08-27, le registre
annonçait encore quatre tranches « en cours » ou « en relecture » sur un chantier **archivé**, dont
les quatre branches avaient disparu du distant depuis longtemps. Un registre périmé est pire qu'un
registre vide : il fait renoncer à une tranche libre. **Retirer sa ligne fait partie de la
fusion**, au même titre que supprimer sa branche et démonter son worktree.

Une ligne : `<branche> — <chantier> <entrées> — <état>`

| Branche | Chantier | Entrées | État |
|---|---|---|---|
| `feat/nexus-headers-through-the-port` | nexus-operation-headers | 3.1 (port elargi, RUPTURE) | en cours |
