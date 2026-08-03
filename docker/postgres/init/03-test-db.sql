-- Test database: owned by the app role so Pest can migrate it directly.
-- FORCE ROW LEVEL SECURITY keeps RLS enforced even against the table owner,
-- so the isolation tests stay meaningful (docs/06 §2).
CREATE DATABASE luminous_test OWNER luminous_app;
\connect luminous_test
CREATE EXTENSION IF NOT EXISTS "pgcrypto";
CREATE EXTENSION IF NOT EXISTS "pg_trgm";
CREATE EXTENSION IF NOT EXISTS "btree_gin";
GRANT ALL ON SCHEMA public TO luminous_app;
