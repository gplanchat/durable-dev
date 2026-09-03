# fix/bundle-declare-ce-qu-il-utilise

- **Chantier** : M14, M15 et M19 de l'audit — même thème, « le bundle référence ce qu'il ne déclare
  ni ne vérifie ». M15 : le pool de cache configuré est ignoré en silence s'il n'est pas encore une
  définition, le défaut est `null`, donc le warmer ne réchauffe rien et le résolveur re-réfléchit à
  chaque appel. M19 : `lock.factory` est référencé sans dire à l'exploitant qu'il lui faut
  `framework.lock`. M14 : deux ponts importés en dur, absents même du `suggest`.
- **Entrées** : `DurableExtension`, `ActivityContractResolver`, une passe de compilation, le
  `composer.json` du bundle, les tests. Pas de refonte de l'extension (M20), pas M17.
- **État** : rédaction.
