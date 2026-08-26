# Installation et mise en route

Ce guide décrit pas à pas comment installer, démarrer et vérifier la stack
(application PHP + OpenTelemetry Collector + Tempo + Prometheus + Loki +
Grafana) sur une machine locale.

## 1. Prérequis

- **Docker** (moteur Docker) et **Docker Compose v2** (`docker compose`, sans tiret).
  Vérifier :
  ```bash
  docker --version
  docker compose version
  ```
- Ports disponibles sur la machine hôte : `8080`, `3000`, `9090`, `3200`, `3100`,
  `4317`, `4318`, `8889`, `5432`, `5672`, `15672`. Si l'un de ces ports est déjà
  utilisé, arrêter le service concurrent ou modifier le mapping dans
  `docker-compose.yml` (partie gauche du `ports:`, ex. `"8081:8080"`).
- Aucune dépendance PHP/Composer à installer sur la machine hôte : tout se
  fait dans le conteneur `app` lors du build.

## 2. Récupérer le projet

```bash
cd /home/aia/workspace/claude/docker/opentelemetry
```

(Le projet est déjà présent à cet emplacement.)

## 3. Construire et démarrer la stack

```bash
docker compose up -d --build
```

Cette commande :
- construit l'image de l'application PHP (`app/Dockerfile`), y compris
  `composer install` des dépendances OpenTelemetry ;
- télécharge les images `otel/opentelemetry-collector-contrib`,
  `grafana/tempo`, `grafana/loki`, `prom/prometheus`, `grafana/grafana`,
  `postgres:16-alpine`, `rabbitmq:3.13-management-alpine` ;
- démarre les 8 conteneurs sur le réseau Docker `observability` ; au premier
  démarrage, Postgres exécute `postgres/init.sql` pour créer la base `ot_db`
  et les tables `items`/`orders` (avec quelques lignes seedées).

Vérifier que tout est up :

```bash
docker compose ps
```

Tous les services doivent afficher l'état `Up`. Premier démarrage : compter
quelques dizaines de secondes le temps du téléchargement des images.

## 4. Vérifier que ça fonctionne

### 4.1. Application

```bash
curl http://localhost:8080/
curl http://localhost:8080/error
curl http://localhost:8080/data
curl http://localhost:8080/order
```

Chaque appel renvoie un JSON contenant un `trace_id` :

```json
{"status":"ok","route":"/","trace_id":"..."}
```

`/data` renvoie en plus le résultat du `SELECT` sur la table `items` :

```json
{"status":"ok","route":"/data","trace_id":"...","data":[{"id":1,"name":"alpha","value":10,"created_at":"..."}, ...]}
```

`/order` sélectionne une commande dans `orders`, la publie sur la file
RabbitMQ `orders`, et la renvoie :

```json
{"status":"ok","route":"/order","trace_id":"...","data":{"id":1,"customer":"Alice","product":"Widget","quantity":2,"status":"pending"}}
```

### 4.2. Traces (Tempo)

```bash
curl "http://localhost:3200/api/search?limit=5"
```

Doit lister les traces générées par les appels précédents (`rootServiceName":"php-app"`).

### 4.3. Métriques (Prometheus)

```bash
curl http://localhost:8889/metrics | grep http_requests
```

Doit afficher des compteurs `otel_http_requests_total` avec les labels `route`/`method`.

Vérifier aussi que Prometheus scrape bien le collector :

```bash
curl "http://localhost:9090/api/v1/targets" | grep -A3 otel-collector
```

Le champ `health` doit valoir `up`.

### 4.4. Logs (Loki)

```bash
curl -sG "http://localhost:3100/loki/api/v1/query_range" \
  --data-urlencode 'query={service_name="php-app"}' \
  --data-urlencode 'limit=5'
```

Doit renvoyer des lignes de log (ex. `"Requête reçue"`) avec des labels
`trace_id`/`span_id` correspondant aux traces générées.

### 4.5. Grafana

Ouvrir http://localhost:3000 (authentification anonyme activée, rôle Admin —
aucun identifiant à saisir).

Menu **Explore** (icône boussole dans la barre latérale) :
- sélectionner la datasource **Tempo** et rechercher une trace récente ;
- sélectionner **Prometheus** et exécuter `rate(otel_http_requests_total[1m])` ;
- sélectionner **Loki** et exécuter `{service_name="php-app"}`.

Depuis une ligne de log contenant `trace_id=...`, un lien apparaît pour
sauter directement vers la trace correspondante dans Tempo.

### 4.6. Base de données (Postgres)

```bash
docker exec -it postgres psql -U otel -d ot_db -c "SELECT * FROM items;"
docker exec -it postgres psql -U otel -d ot_db -c "SELECT * FROM orders;"
```

Doit afficher les lignes seedées par `postgres/init.sql` (`alpha`, `beta`,
`gamma` pour `items` ; `Alice`, `Bob`, `Carol` pour `orders`).

### 4.7. File de messages (RabbitMQ)

```bash
docker exec rabbitmq rabbitmqctl list_queues name messages
```

Après un ou plusieurs `curl http://localhost:8080/order`, la file `orders`
doit afficher un nombre de messages > 0. UI de management :
http://localhost:15672 (utilisateur/mot de passe `otel`/`otel`).

## 5. Régénérer des données

Pour observer davantage de trafic (utile en Explore Grafana) :

```bash
for i in $(seq 1 20); do curl -s http://localhost:8080/ > /dev/null; done
curl -s http://localhost:8080/error > /dev/null
curl -s http://localhost:8080/data > /dev/null
curl -s http://localhost:8080/order > /dev/null
```

## 6. Arrêter / redémarrer

```bash
# Arrêter les conteneurs (les volumes de données sont conservés)
docker compose stop

# Redémarrer
docker compose start

# Arrêter et supprimer les conteneurs (les volumes sont conservés)
docker compose down

# Tout supprimer, y compris les données stockées (Tempo/Loki/Prometheus/Grafana)
docker compose down -v
```

## 7. Mettre à jour le code de l'application

Après une modification de `app/public/index.php` ou `app/composer.json` :

```bash
docker compose build app
docker compose up -d app
```

Après une modification d'un fichier de configuration (`otel-collector/config.yaml`,
`prometheus/prometheus.yml`, `tempo/tempo.yaml`, `loki/loki-config.yaml`,
`grafana/provisioning/**`), redémarrer uniquement le service concerné :

```bash
docker compose restart otel-collector   # exemple
```

## 8. Dépannage

- **`docker compose ps` montre un service en `Restarting`** : consulter ses
  logs, ex. `docker logs tempo` ou `docker logs loki`, pour voir l'erreur de
  configuration.
- **Aucune donnée dans Tempo/Loki/Prometheus alors que l'app répond** :
  regarder les logs du collector, il possède un exportateur `debug` qui
  affiche chaque lot envoyé :
  ```bash
  docker logs otel-collector -f
  ```
  Si rien n'apparaît après un `curl` sur l'app, le problème vient de
  l'application (variables `OTEL_*` dans `docker-compose.yml`) ; si les
  logs du collector montrent l'envoi mais rien n'arrive côté backend,
  le problème vient de l'exportateur concerné (`otlp/tempo`, `prometheus`
  ou `otlphttp/loki`) dans `otel-collector/config.yaml`.
- **Port déjà utilisé au démarrage** : erreur du type
  `Bind for 0.0.0.0:XXXX failed: port is already allocated`. Modifier le
  mapping de port dans `docker-compose.yml` ou libérer le port occupé.
- **Erreur `composer install` lors du build de `app`** : vérifier la
  connectivité réseau du démon Docker (accès à `packagist.org`) ; relancer
  `docker compose build app --no-cache` en dernier recours.
- **`curl http://localhost:8080/data` (ou `/order`) renvoie `SQLSTATE[08006] ... Connection refused`** :
  Postgres n'a pas encore fini son démarrage (première initialisation un peu
  plus longue). Attendre quelques secondes et vérifier
  `docker exec postgres pg_isready -U otel -d ot_db`, puis réessayer.
- **`SQLSTATE[42P01]: Undefined table: ... relation "orders" does not exist`** :
  le volume `postgres-data` existait déjà avant l'ajout de la table `orders` à
  `postgres/init.sql` — ce script ne rejoue que sur un volume vide. Recréer le
  volume : `docker compose down` puis `docker volume rm opentelemetry_postgres-data`
  (préfixe = nom du dossier projet) et relancer `docker compose up -d`.
- **`curl http://localhost:8080/order` renvoie une erreur de connexion AMQP** :
  RabbitMQ met quelques secondes à être prêt. Vérifier
  `docker exec rabbitmq rabbitmq-diagnostics -q ping`, puis réessayer.

Pour plus de détails sur l'architecture et le fonctionnement interne, voir
[README.md](README.md).
