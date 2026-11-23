#!/usr/bin/env bash
# Runs the browser suites against a freshly seeded throwaway database, the way CI does.
#
#   E2E_DB=shvet_crm_e2e bash e2e/run.sh smoke crm
#
# Needs: MySQL reachable with the DB_* / MYSQL_* settings below, built assets (npm run build), Playwright + Chromium.
# The server runs with a NON-local APP_ENV so the strict CSP is active, and with the log mailer so reset links are readable.
set -euo pipefail
cd "$(dirname "$0")/.."

DB="${E2E_DB:-shvet_crm_e2e}"
PORT="${E2E_PORT:-8766}"
MOCK_PORT="${E2E_MOCK_PORT:-8799}"
SUITES=("${@:-smoke crm}")
export MYSQL_HOST="${MYSQL_HOST:-127.0.0.1}" MYSQL_PWD="${MYSQL_PWD:-${DB_PASSWORD:-}}"

suite_db_reset() {
    mysql -uroot -e "DROP DATABASE IF EXISTS \`$DB\`; CREATE DATABASE \`$DB\`"
    DB_DATABASE="$DB" APP_ENV=local php artisan migrate --seed --force --no-interaction >/dev/null
}

start_server() {
    : > storage/logs/mail.log
    if curl -fsS -o /dev/null "http://127.0.0.1:$PORT/login" 2>/dev/null; then
        echo "port $PORT is already in use (a leftover server?)"; exit 1
    fi
    # Integration suites talk to a MOCK Graph server (never Meta) and import synchronously.
    local extra=()
    if [[ "$1" == "meta" || "$1" == "whatsapp" ]]; then
        extra=(QUEUE_CONNECTION=sync META_APP_ID=123 META_APP_SECRET=e2e-secret META_WEBHOOK_VERIFY_TOKEN=e2e-verify-token-0123456789
            WHATSAPP_WEBHOOK_VERIFY_TOKEN=e2e-wa-verify-token META_GRAPH_VERSION=v99.0
            META_GRAPH_BASE_URL="http://127.0.0.1:$MOCK_PORT" META_OAUTH_DIALOG_BASE_URL="http://127.0.0.1:$MOCK_PORT")
    fi
    (cd public && exec env "${extra[@]}" APP_ENV=staging APP_DEBUG=false APP_URL="http://127.0.0.1:$PORT" DB_DATABASE="$DB" \
        MAIL_MAILER=log MAIL_LOG_CHANNEL=maillog SESSION_SECURE_COOKIE=false CRM_ENFORCE_HOST=false \
        php -S "127.0.0.1:$PORT" ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php \
        > ../storage/logs/e2e-server.log 2>&1) &
    SERVER_PID=$!
    for _ in $(seq 1 50); do curl -fsS -o /dev/null "http://127.0.0.1:$PORT/login" && return 0; sleep 0.2; done
    echo "server did not start"; cat storage/logs/e2e-server.log; exit 1
}

status=0
for suite in ${SUITES[@]}; do
    echo "=== e2e: $suite ==="
    suite_db_reset
    MOCK_PID=""
    if [[ "$suite" == "meta" || "$suite" == "whatsapp" ]]; then
        node e2e/mock-graph-server.cjs "$MOCK_PORT" > storage/logs/e2e-mock.log 2>&1 &
        MOCK_PID=$!
    fi
    start_server "$suite"
    BASE_URL="http://127.0.0.1:$PORT" MOCK_URL="http://127.0.0.1:$MOCK_PORT" META_APP_SECRET=e2e-secret MAIL_LOG=storage/logs/mail.log E2E_DB="$DB" \
        node "e2e/$suite.cjs" || status=1
    kill "$SERVER_PID" ${MOCK_PID:+"$MOCK_PID"} 2>/dev/null || true
    wait 2>/dev/null || true
done
exit $status
