<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Monolog\Logger;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Contrib\Logs\Monolog\Handler as OtelMonologHandler;
use Psr\Log\LogLevel;

// Le SDK OpenTelemetry est déjà initialisé automatiquement grâce à
// OTEL_PHP_AUTOLOAD_ENABLED=true (voir docker-compose.yml).
// On récupère simplement les providers globaux.
$tracer = Globals::tracerProvider()->getTracer('php-app');
$meter = Globals::meterProvider()->getMeter('php-app');

// --- Logs : Monolog relié à OpenTelemetry, exportés vers Loki via le Collector ---
$otelHandler = new OtelMonologHandler(Globals::loggerProvider(), LogLevel::DEBUG);
$logger = new Logger('php-app');
$logger->pushHandler($otelHandler);

// --- Métriques ---
$requestCounter = $meter->createCounter(
    'http_requests_total',
    unit: '1',
    description: 'Nombre total de requêtes HTTP reçues'
);
$errorCounter = $meter->createCounter(
    'http_requests_errors_total',
    unit: '1',
    description: 'Nombre de requêtes ayant échoué'
);
$durationHistogram = $meter->createHistogram(
    'http_request_duration_seconds',
    unit: 's',
    description: 'Durée de traitement des requêtes HTTP'
);

$route = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$start = microtime(true);

$span = $tracer->spanBuilder("$method $route")
    ->setSpanKind(SpanKind::KIND_SERVER)
    ->startSpan();
$scope = $span->activate();

$span->setAttribute('http.method', $method);
$span->setAttribute('http.target', $route);

header('Content-Type: application/json');

try {
    $logger->info('Requête reçue', ['route' => $route, 'method' => $method]);

    $extra = match ($route) {
        '/error' => simulateError($tracer, $logger),
        '/data' => fetchData($tracer, $logger),
        default => simulateWork($tracer, $logger),
    };

    $traceId = $span->getContext()->getTraceId();
    $span->setStatus(StatusCode::STATUS_OK);

    $payload = [
        'status' => 'ok',
        'route' => $route,
        'trace_id' => $traceId,
    ];
    if ($extra !== null) {
        $payload['data'] = $extra;
    }
    echo json_encode($payload);
} catch (\Throwable $e) {
    $errorCounter->add(1, ['route' => $route]);
    $span->recordException($e);
    $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
    $logger->error('Erreur lors du traitement de la requête', [
        'route' => $route,
        'exception' => $e->getMessage(),
    ]);

    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'trace_id' => $span->getContext()->getTraceId(),
    ]);
} finally {
    $requestCounter->add(1, ['route' => $route, 'method' => $method]);
    $durationHistogram->record(microtime(true) - $start, ['route' => $route]);
    $span->end();
    $scope->detach();
}

/**
 * Simule un traitement métier normal, avec une sous-span dédiée.
 */
function simulateWork($tracer, Logger $logger): void
{
    $child = $tracer->spanBuilder('process-request')->startSpan();
    $childScope = $child->activate();

    try {
        $delayMs = random_int(10, 150);
        usleep($delayMs * 1000);
        $child->setAttribute('work.delay_ms', $delayMs);
        $child->addEvent('traitement terminé');
        $logger->debug('Traitement effectué', ['delay_ms' => $delayMs]);
    } finally {
        $child->end();
        $childScope->detach();
    }
}

/**
 * Simule une erreur applicative pour tester traces/logs/metrics d'échec.
 */
function simulateError($tracer, Logger $logger): void
{
    $child = $tracer->spanBuilder('process-request-with-error')->startSpan();
    $childScope = $child->activate();

    try {
        throw new \RuntimeException('Échec simulé du traitement');
    } finally {
        $child->end();
        $childScope->detach();
    }
}

/**
 * Route /data : SELECT simple sur la table items (Postgres), avec une
 * span client dédiée portant les attributs sémantiques db.*.
 */
function fetchData($tracer, Logger $logger): array
{
    $span = $tracer->spanBuilder('pg.select items')
        ->setSpanKind(SpanKind::KIND_CLIENT)
        ->startSpan();
    $scope = $span->activate();

    try {
        $statement = 'SELECT id, name, value, created_at FROM items ORDER BY id';
        $span->setAttribute('db.system', 'postgresql');
        $span->setAttribute('db.name', getenv('POSTGRES_DB') ?: 'ot_db');
        $span->setAttribute('db.statement', $statement);

        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            getenv('POSTGRES_HOST') ?: 'postgres',
            getenv('POSTGRES_PORT') ?: '5432',
            getenv('POSTGRES_DB') ?: 'ot_db',
        );
        $pdo = new \PDO($dsn, getenv('POSTGRES_USER') ?: 'otel', getenv('POSTGRES_PASSWORD') ?: 'otel', [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);

        $rows = $pdo->query($statement)->fetchAll(\PDO::FETCH_ASSOC);
        $logger->info('Lecture table items', ['count' => count($rows)]);

        return $rows;
    } finally {
        $span->end();
        $scope->detach();
    }
}
