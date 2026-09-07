# test/interprocess-crash-bench

- **Chantier** : the discriminant bench of line 1.2 in the Durable/LangGraph parity note — the one
  measurement the product's central promise has never had. A workflow with two activities runs on a
  shared on-disk journal; the worker process is killed between the two, and a second process picks
  the execution up. **No agent, no model, no Symfony AI**: that is the whole point. The agent
  maquette runs the same shape afterwards, and the pair of verdicts says where a failure lives —
  green bare / red with the agent means a leak in the maquette's ~4 100 lines; red both ways means
  the core has a defect on the worker/journal path, and the parity plan stops there.
- **Entrées** : `tests/integration/Durable/` — a new bench plus its support workflow, and whatever
  small runner it needs to spawn and kill a second process. DBAL backend on a file-backed SQLite
  journal, because two processes need a journal that outlives either of them. No production code is
  expected to change; if any does, that *is* the finding and it gets its own slice.
- **Ne touche pas** : `src/Durable/` behaviour, the agent maquette, and PR #282's guard — the bench
  assumes that guard merged, since without it a two-process run can go green while diverging.
- **État** : en revue — PR #283. Bench green: the core survives process death, so the discriminant says a
  later failure with the agent would live in the maquette, not the core.
