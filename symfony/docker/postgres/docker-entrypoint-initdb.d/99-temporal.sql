-- Bases et rôle dédiés Temporal (même instance Postgres que Doctrine).
-- Exécuté uniquement au premier démarrage du volume (initdb).
CREATE USER temporal WITH PASSWORD 'temporal' CREATEDB;
CREATE DATABASE temporal OWNER temporal;
CREATE DATABASE temporal_visibility OWNER temporal;
