#!/bin/sh
# Creates the five Dashflow database roles on first start of an empty data directory
# (mounted into /docker-entrypoint-initdb.d, so it runs as the bootstrap superuser).
#
#   migrator     owns every object, runs migrations; no BYPASSRLS
#   app          the runtime login of every service; SELECT, INSERT, UPDATE only; no BYPASSRLS
#   maintenance  the only role with DELETE (retention sweeps); no BYPASSRLS
#   system       dispatcher and outbox relay; its column grants and policy come with the tables (migrations)
#   operator     operator commands; grants arrive with the tables of later stories
#
# No role except the bootstrap superuser has BYPASSRLS. Passwords come from the environment;
# the defaults in compose.yaml are development values only.
set -eu

: "${POSTGRES_MIGRATOR_PASSWORD:?POSTGRES_MIGRATOR_PASSWORD is required}"
: "${POSTGRES_APP_PASSWORD:?POSTGRES_APP_PASSWORD is required}"
: "${POSTGRES_MAINTENANCE_PASSWORD:?POSTGRES_MAINTENANCE_PASSWORD is required}"
: "${POSTGRES_SYSTEM_PASSWORD:?POSTGRES_SYSTEM_PASSWORD is required}"
: "${POSTGRES_OPERATOR_PASSWORD:?POSTGRES_OPERATOR_PASSWORD is required}"

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
    -v migrator_pw="$POSTGRES_MIGRATOR_PASSWORD" \
    -v app_pw="$POSTGRES_APP_PASSWORD" \
    -v maintenance_pw="$POSTGRES_MAINTENANCE_PASSWORD" \
    -v system_pw="$POSTGRES_SYSTEM_PASSWORD" \
    -v operator_pw="$POSTGRES_OPERATOR_PASSWORD" <<'SQL'
CREATE ROLE migrator    LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS PASSWORD :'migrator_pw';
CREATE ROLE app         LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS PASSWORD :'app_pw';
CREATE ROLE maintenance LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS PASSWORD :'maintenance_pw';
CREATE ROLE system      LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS PASSWORD :'system_pw';
CREATE ROLE operator    LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS PASSWORD :'operator_pw';

-- migrator owns the database and the public schema, so it owns every table it migrates.
SELECT format('ALTER DATABASE %I OWNER TO migrator', current_database()) \gexec
ALTER SCHEMA public OWNER TO migrator;
REVOKE CREATE ON SCHEMA public FROM PUBLIC;

-- Tables and sequences created by migrator reach the other roles only through these defaults.
-- app never gets DELETE on these tenant tables (a migration grants it on the framework's global tables only);
-- maintenance is the only role with DELETE on them. system and operator get nothing yet.
ALTER DEFAULT PRIVILEGES FOR ROLE migrator IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE ON TABLES TO app;
ALTER DEFAULT PRIVILEGES FOR ROLE migrator IN SCHEMA public
    GRANT SELECT, DELETE ON TABLES TO maintenance;
ALTER DEFAULT PRIVILEGES FOR ROLE migrator IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO app;
-- Functions are executable by PUBLIC by default; each migration grants EXECUTE explicitly.
ALTER DEFAULT PRIVILEGES FOR ROLE migrator IN SCHEMA public
    REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC;
SQL
