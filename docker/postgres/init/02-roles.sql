-- Runtime role: subject to RLS. This is what the app connects as.
CREATE ROLE luminous_app LOGIN PASSWORD 'secret' NOSUPERUSER NOCREATEDB NOCREATEROLE;
GRANT CONNECT ON DATABASE luminous TO luminous_app;
GRANT USAGE ON SCHEMA public TO luminous_app;
ALTER DEFAULT PRIVILEGES IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO luminous_app;
ALTER DEFAULT PRIVILEGES IN SCHEMA public
  GRANT USAGE, SELECT ON SEQUENCES TO luminous_app;

-- Migration / system-job role: bypasses RLS (docs/05 §1 layer 2).
CREATE ROLE luminous_migrator LOGIN PASSWORD 'secret' NOSUPERUSER BYPASSRLS;
GRANT CONNECT ON DATABASE luminous TO luminous_migrator;
GRANT ALL ON SCHEMA public TO luminous_migrator;

-- Default privileges must also apply to objects created BY the migrator,
-- since the migrator creates every table.
ALTER DEFAULT PRIVILEGES FOR ROLE luminous_migrator IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO luminous_app;
ALTER DEFAULT PRIVILEGES FOR ROLE luminous_migrator IN SCHEMA public
  GRANT USAGE, SELECT ON SEQUENCES TO luminous_app;
