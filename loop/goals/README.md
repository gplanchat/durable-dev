# Standing goals

One file per goal. The core is `predicate`: a single shell command, run from the repository root,
that exits 0 while the finished work is still true and non-zero the moment it is not.

Three rules decide whether a goal is worth having:

- **Test the predicate against both states.** It must exit 0 on the fixed code and non-zero on the
  broken code — check out the pre-fix commit and confirm. A predicate that cannot fail is
  decoration.
- **Keep it cheap.** The whole set runs daily. `composer test` is the entire 1246-test suite and
  has no place in a predicate; use `vendor/bin/phpunit --filter` or a single `--testsuite`. If the
  honest check is expensive, write a cheap proxy and say so in the goal file.
- **If a shell script cannot check it, it is not a goal.** "The bundle is cleaner" is a wish.
  "`vendor/bin/phpunit --filter AsWorkflowAutoconfigureTest` passes" is a goal.

Retirement is a human decision, and it is logged: set `status: retired` and say why.
