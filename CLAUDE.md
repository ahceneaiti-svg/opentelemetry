# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A demo stack instrumenting a simple PHP app with OpenTelemetry, exporting
all three signals through a single OTel Collector pipeline to Tempo
(traces), Prometheus (metrics), and Loki (logs), visualized in Grafana.

## Commands

```bash
# Start everything (build app image first time / after code changes)
docker compose up -d --build

# Rebuild just the app after editing app/public/index.php or app/composer.json
docker compose build app && docker compose up -d app

# Tail collector logs (the "debug" exporter prints every batch it forwards —
# useful to confirm traces/metrics/logs are actually flowing)
docker logs otel-collector -f

# Validate compose file after editing docker-compose.yml
docker compose config -q

# Tear down
docker compose down
```

There is no test suite or linter in this repo — verification is done by
exercising the running stack (see "Manual verification" below).

### Manual verification

```bash
curl http://localhost:8080/          # normal request, returns a trace_id
curl http://localhost:8080/error     # forces an exception, exercises error path

# confirm each backend actually received data for a given run:
curl "http://localhost:3200/api/search?limit=5"                         # Tempo
curl http://localhost:8889/metrics | grep http_requests                 # Prometheus (raw collector exporter)
curl -sG http://localhost:3100/loki/api/v1/query_range \
  --data-urlencode 'query={service_name="php-app"}'                     # Loki
```

Service UIs: app `:8080`, Grafana `:3000` (anonymous/Admin), Prometheus
`:9090`, Tempo `:3200`, Loki `:3100`.

## Architecture

Everything funnels through **one OTel Collector** (`otel-collector/config.yaml`),
which is the only place that knows about the three backends. The PHP app
never talks to Tempo/Loki/Prometheus directly — it only speaks OTLP to the
collector at `otel-collector:4318`.

```
php-app --OTLP/HTTP(json)--> otel-collector --otlp/tempo (gRPC)--> tempo
                                     |------- prometheus exporter (:8889, scraped)--> prometheus
                                     |------- otlphttp/loki (native OTLP /otlp)-----> loki
```

Three independent pipelines in `otel-collector/config.yaml` (traces/metrics/logs),
each with its own exporter. The `debug` exporter is attached to all three —
that's the first place to look when data isn't showing up in a backend.

Key design choices that matter when modifying this:

- **PHP instrumentation is env-driven, not code-driven.** `OTEL_PHP_AUTOLOAD_ENABLED=true`
  (set in `docker-compose.yml`) makes `open-telemetry/sdk` auto-configure
  `TracerProvider`/`MeterProvider`/`LoggerProvider` from `OTEL_*` env vars.
  `app/public/index.php` just calls `Globals::tracerProvider()` /
  `Globals::meterProvider()` / `Globals::loggerProvider()` — there is no manual
  SDK builder code. To change exporter endpoint/protocol/service name, edit
  the `OTEL_*` environment block in `docker-compose.yml`, not the PHP code.
- **`OTEL_EXPORTER_OTLP_PROTOCOL=http/json`** is deliberate: it avoids needing
  the `protobuf`/`grpc` PHP extensions. If switching to `http/protobuf` or
  gRPC, the app Dockerfile needs those extensions added.
- **Logs get trace correlation automatically.** `open-telemetry/opentelemetry-logger-monolog`'s
  `Handler` is attached to the Monolog logger in `index.php`; every log record
  picks up the active span's `trace_id`/`span_id` without manual wiring.
- **Loki uses its native OTLP ingestion endpoint** (`/otlp`, Loki 3.x+), not
  the older `lokiexporter` with hint-based label attributes. This requires
  `loki/loki-config.yaml` to use `schema: v13` / `store: tsdb` with
  `allow_structured_metadata: true` — don't downgrade the schema without
  keeping that in mind.
- **Prometheus scrapes the collector, it doesn't receive pushes.** The
  collector's `prometheus` exporter serves `:8889/metrics`; `prometheus/prometheus.yml`
  scrapes that endpoint. Metric names get an `otel_` namespace prefix (configured
  in the exporter), so e.g. the app's `http_requests_total` counter shows up as
  `otel_http_requests_total`.
- **Grafana datasources are provisioned, not clicked in.**
  `grafana/provisioning/datasources/datasources.yml` wires Loki→Tempo trace
  correlation via `derivedFields` (matches `trace_id=(\w+)` in log lines) and
  `tracesToLogsV2` on the Tempo datasource. Edit this file rather than the UI
  if correlation needs adjusting — UI changes won't survive `docker compose down -v`.

### Adding a new PHP route/feature

Everything instrumentation-related lives in `app/public/index.php`: span
creation (`$tracer->spanBuilder(...)`), counters/histograms (`$meter->create*`),
and logging (`$logger->info/error(...)` via the Monolog `$logger` already wired
to OTel). Follow the existing pattern of one server span per request with
child spans for sub-steps, and end/detach spans in a `finally` block.
