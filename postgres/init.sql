-- Exécuté automatiquement au premier démarrage du conteneur postgres
-- (docker-entrypoint-initdb.d), sur la base ot_db créée via POSTGRES_DB.

CREATE TABLE IF NOT EXISTS items (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    value INTEGER NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

INSERT INTO items (name, value) VALUES
    ('alpha', 10),
    ('beta', 20),
    ('gamma', 30)
ON CONFLICT DO NOTHING;
