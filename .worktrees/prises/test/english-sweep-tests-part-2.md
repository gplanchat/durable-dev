# test/english-sweep-tests-part-2

- **Scope**: #400 part 2 (part 1 merged in #528). The remaining French test data (kind C: exception
  messages, return values, termination reasons, header keys, names) in `tests/` and
  `src/*/tests/`, translated per WA006 with each value keeping the property its test relies on;
  then, once the tree is clean, a guard beside `TheRootDocumentsSpeakEnglishTest` scanning
  `tests/` and `src/*/tests/`, with an explicit allowlist for kind D (deliberate accented inputs,
  the guards' own regexes, the plugin's French-translation assertions), each entry with its reason.
- **Entries**: the ~30 test files the inventory lists (string literals only, no logic, no method
  names), `tests/unit/` for the new guard. Overlap: #325's prise names "their tests" for the history
  source; any shared file is a line-level merge.
- **State**: in progress — sabrina, reviewer bob.
