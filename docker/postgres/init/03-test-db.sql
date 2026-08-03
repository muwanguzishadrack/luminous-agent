-- Test database: owned by the app role so Pest can migrate it directly.
-- FORCE ROW LEVEL SECURITY keeps RLS enforced even against the table owner,
-- so the isolation tests stay meaningful (docs/06 §2).
CREATE DATABASE luminous_test OWNER luminous_app;
\connect luminous_test
CREATE EXTENSION IF NOT EXISTS "pgcrypto";
CREATE EXTENSION IF NOT EXISTS "pg_trgm";
CREATE EXTENSION IF NOT EXISTS "btree_gin";
GRANT ALL ON SCHEMA public TO luminous_app;

-- Tests migrate as luminous_migrator (BYPASSRLS) exactly like dev/prod, so
-- SECURITY DEFINER helpers owned by the migrator genuinely bypass RLS and
-- FORCE RLS stays meaningful against the runtime role.
GRANT CONNECT ON DATABASE luminous_test TO luminous_migrator;
GRANT ALL ON SCHEMA public TO luminous_migrator;
ALTER DEFAULT PRIVILEGES FOR ROLE luminous_migrator IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO luminous_app;
ALTER DEFAULT PRIVILEGES FOR ROLE luminous_migrator IN SCHEMA public
  GRANT USAGE, SELECT ON SEQUENCES TO luminous_app;
