# feat/logos-integrations

Les cinq logos qui manquent à `hugo-docs/assets/logos/` et qui bloquent
`import-design.py` : `akeneo`, `sulu`, `api-platform`, `shopware`, `illuminate`.
Plus la correction du tiret dans les motifs du script (`logo-api-platform.svg`
n'était reconnu par aucun des trois, y compris la garde des orphelins).

Ne touche pas au texte de `layouts/index.html` : la page et le canevas ont
divergé (roadmap corrigée au dépôt, pas dans le canevas), le ré-import est une
décision séparée.
