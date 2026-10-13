# fix/start-to-close-default

- **Scope**: refs #1050 (decision 1 of #982): the journal backends bound an activity without `startToClose` at 30 s, as Temporal does.
- **Entries**: src/Durable/Worker/ActivityMessageProcessor.php, src/Durable/Activity/ActivityTimeouts.php, documentation/user/options/_index.md, documentation/user/options/_index.fr.md, tests/.
- **Overlap**: none.
- **State**: in progress.
