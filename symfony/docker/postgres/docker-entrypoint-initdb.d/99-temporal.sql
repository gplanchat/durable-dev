-- Databases and role dedicated to Temporal (same Postgres instance as Doctrine).
-- Run only on the volume's first start (initdb).
CREATE USER temporal WITH PASSWORD 'temporal' CREATEDB;
CREATE DATABASE temporal OWNER temporal;
CREATE DATABASE temporal_visibility OWNER temporal;
