# Démo OpenTelemetry avec PHP, Loki, Prometheus et Tempo

Ce projet montre comment instrumenter une application PHP simple avec
OpenTelemetry et exporter les trois piliers de l'observabilité :

- **Traces** → OpenTelemetry Collector → **Tempo**
- **Métriques** → OpenTelemetry Collector → **Prometheus**
- **Logs** → OpenTelemetry Collector → **Loki**

Le tout est visualisable dans **Grafana**, avec corrélation logs ↔ traces.

## Architecture

```
┌──────────┐   OTLP/HTTP    ┌────────────────────┐
│ php-app  │ ─────────────► │  OTel Collector     │
└────┬─────┘                │  (traces/metrics/   │
     │ SQL (PDO)            │   logs)             │
     ▼                      └──────┬──────┬───────┘
┌──────────┐        OTLP gRPC │  │      │ OTLP HTTP (/otlp)
│ postgres │                  ▼  │      ▼
│ (ot_db)  │             ┌───────┴──┐ ┌──────┐
└──────────┘             │  Tempo   │ │ Loki │
                          └──────────┘ └──────┘
                                        ▲
                        Prometheus scrape (:8889)
                                        │
                                 ┌─────────────┐
                                 │ Prometheus  │
                                 └─────────────┘
                                        │
                                 ┌─────────────┐
                                 │  Grafana    │
                                 └─────────────┘
```

## Structure du projet

```
.
├── docker-compose.yml
├── app/                        # Application PHP
│   ├── Dockerfile
│   ├── composer.json
│   └── public/index.php
├── otel-collector/
│   └── config.yaml             # Pipelines traces/metrics/logs
├── postgres/
│   └── init.sql                # Création + seed de la table items (base ot_db)
├── tempo/
│   └── tempo.yaml
├── loki/
│   └── loki-config.yaml
├── prometheus/
│   └── prometheus.yml
└── grafana/
    └── provisioning/datasources/datasources.yml
```

## Démarrage

```bash
docker compose up -d --build
```

Services exposés :

| Service     | URL                          |
|-------------|-------------------------------|
| Application | http://localhost:8080         |
| Grafana     | http://localhost:3000 (anonyme, rôle Admin) |
| Prometheus  | http://localhost:9090         |
| Tempo       | http://localhost:3200          |
| Loki        | http://localhost:3100          |
| Postgres    | localhost:5432 (db `ot_db`, user/pass `otel`/`otel`) |

## Tester l'application

```bash
curl http://localhost:8080/
curl http://localhost:8080/error
curl http://localhost:8080/data
```

Chaque appel :
- crée une trace avec des spans (`process-request`, etc.) envoyée à Tempo ;
- incrémente des compteurs (`http_requests_total`, `http_requests_errors_total`)
  et alimente un histogramme de durée, visibles dans Prometheus ;
- écrit des logs structurés (via Monolog) corrélés au `trace_id`, envoyés à Loki.

`/data` fait en plus un `SELECT` sur la table `items` de Postgres (créée et
seedée par `postgres/init.sql`), dans une span client dédiée (`pg.select items`,
attributs `db.system`/`db.name`/`db.statement`) ; le résultat est renvoyé dans
le champ `data` de la réponse JSON.

La réponse JSON contient le `trace_id` de la requête, pratique pour la
retrouver directement dans Tempo ou dans les logs Loki.

## Explorer les données dans Grafana

1. Ouvrir http://localhost:3000
2. Menu **Explore** :
   - Datasource **Tempo** : rechercher par `trace_id` ou parcourir les dernières traces.
   - Datasource **Prometheus** : requêter `rate(otel_http_requests_total[1m])`.
   - Datasource **Loki** : requêter `{service_name="php-app"}`.
3. Depuis une ligne de log contenant `trace_id=...`, Grafana propose un lien
   direct vers la trace correspondante dans Tempo (champ dérivé configuré
   dans `grafana/provisioning/datasources/datasources.yml`).

## Détails d'implémentation côté PHP

L'instrumentation utilise le SDK officiel `open-telemetry/sdk` avec
l'auto-configuration par variables d'environnement
(`OTEL_PHP_AUTOLOAD_ENABLED=true`), ce qui évite d'écrire à la main la
configuration des `TracerProvider` / `MeterProvider` / `LoggerProvider`.
Ces variables sont définies dans `docker-compose.yml` :

- `OTEL_EXPORTER_OTLP_ENDPOINT` : adresse du Collector (`http://otel-collector:4318`)
- `OTEL_EXPORTER_OTLP_PROTOCOL` : `http/json` (évite la dépendance à l'extension `protobuf`)
- `OTEL_SERVICE_NAME` : nom du service tel qu'il apparaît dans Grafana

Le code (`app/public/index.php`) :
- démarre une span serveur par requête, avec une sous-span pour le traitement métier ;
- enregistre compteurs et histogramme via l'API Metrics ;
- journalise via Monolog, relié à OpenTelemetry par le handler du package
  `open-telemetry/opentelemetry-logger-monolog`, ce qui propage automatiquement
  le `trace_id`/`span_id` courant dans chaque log.

## Aller plus loin

- Ajouter `open-telemetry/opentelemetry-auto-*` (PDO, Guzzle, Slim, Laravel…)
  pour de l'instrumentation automatique de bibliothèques tierces.
- Passer en mode microservices (plusieurs services PHP) pour voir des traces
  distribuées multi-services dans Tempo.
- Activer l'authentification sur Grafana/Loki/Tempo avant tout usage en dehors
  d'un poste de développement local.
