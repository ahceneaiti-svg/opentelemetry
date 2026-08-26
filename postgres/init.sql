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

CREATE TABLE IF NOT EXISTS orders (
    id SERIAL PRIMARY KEY,
    customer VARCHAR(100) NOT NULL,
    product VARCHAR(100) NOT NULL,
    quantity INTEGER NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

INSERT INTO orders (customer, product, quantity) VALUES
    ('Alice', 'Widget', 2),
    ('Bob', 'Gadget', 1),
    ('Carol', 'Gizmo', 5)
ON CONFLICT DO NOTHING;
