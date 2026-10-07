# Backup and restore

The full backup and restore drill (a scheduled backup, a restore into a clean environment, measured
recovery point and recovery time against the targets, and a written runbook) is delivered in **Epic 9**.
Nothing in Epic 1 claims it.

## What Epic 1 verifies (AR-56)

After a fresh database restore, Epic 1 only checks that the security setup survives:

`bin/test-restore` runs in `bin/tools` after the Database suite (`composer ci:check`, `composer test:database`,
`composer test:security`). It dumps the migrated test database with `pg_dump`, restores it into a fresh
database with `pg_restore`, and fails the command if any of these drift through SQL assertions:

- every tenant table (every table with `workspace_id` that is not a listed global table) still has row-level
  security enabled and forced, with its policy;
- the five roles (`migrator`, `app`, `maintenance`, `system`, `operator`) exist, none with BYPASSRLS or
  superuser (roles are cluster-global: `pg_dump` does not carry them and the restore runs in the same
  cluster, so this confirms the roles, not that a dump recreates them; Epic 9 covers a restore into a
  clean cluster);
- `app` has no DELETE on tenant tables, and the policies (names, commands, roles, USING and WITH CHECK
  expressions), table grants, default privileges and function EXECUTE grants equal those of the source;
- the `SECURITY DEFINER` functions are owned by `migrator`.

It does not restore production-shaped data, time the restore, test point-in-time recovery or replace a
runbook. Those are Epic 9.
