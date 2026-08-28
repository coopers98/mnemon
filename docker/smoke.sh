#!/bin/sh
# Boots the stack, asserts it works, restarts it, and asserts the restart
# changed nothing it should not have — including the persisted APP_KEY,
# whose silent rotation would invalidate every session and anything
# encrypted under the old key. Run from the repository root.
#
# Uses a dedicated Compose project name (-p mnemon-smoke) rather than the
# default, which Compose derives from the directory name ("mnemon") — the
# same namespace a self-hoster's own `docker compose up` uses from this
# same checkout. Without an explicit project name this script would adopt
# a running self-hosted stack's containers, assert against its live data,
# and destroy every mnemon_* volume in the cleanup trap below. See the
# `$COMPOSE down -v` right before `up` for the other half of this fix.
set -eu

ENVFILE=.env.smoke
cp .env.docker.example "$ENVFILE"

# --env-file drives interpolation only; this is what points the services'
# env_file at the template instead of a developer's real .env.
export MNEMON_ENV_FILE="$ENVFILE"

PROJECT=mnemon-smoke
COMPOSE="docker compose -p $PROJECT --env-file $ENVFILE"

# A DOMAIN here would make every CI run order real certificates.
sed -i 's/^DOMAIN=.*/DOMAIN=/' "$ENVFILE"

# The hardcoded http://127.0.0.1:8080 check below only matches the template's
# defaults; force them so a developer's real .env.docker.example edits (or a
# future change to the template) can't silently desync the two.
sed -i 's/^HTTP_BIND=.*/HTTP_BIND=127.0.0.1:8080/' "$ENVFILE"
sed -i 's/^HTTPS_BIND=.*/HTTPS_BIND=127.0.0.1:8443/' "$ENVFILE"

cleanup() {
    $COMPOSE down -v >/dev/null 2>&1 || true
    rm -f "$ENVFILE"
}
trap cleanup EXIT

# Tear down before starting, not just after: a project-scoped volume left
# over from an interrupted previous run (trap didn't fire, CI runner
# reused) would let the first-boot assertions below — USERS1 = 1, the
# admin-password read — pass against that leftover state instead of
# genuinely exercising the code this script exists to test. Every run
# must provably start from nothing.
$COMPOSE down -v >/dev/null 2>&1 || true

$COMPOSE up -d --build

echo "waiting for app health…"
i=0
until [ "$($COMPOSE ps app --format '{{.Health}}')" = "healthy" ]; do
    i=$((i + 1))
    [ "$i" -gt 60 ] && { echo "app never became healthy"; $COMPOSE logs app; exit 1; }
    sleep 5
done

curl -fsS -o /dev/null http://127.0.0.1:8080/up
echo "health OK"

echo "checking built assets…"
$COMPOSE exec -T app sh -c '
    set -e
    test -f public/build/manifest.json || { echo "FAIL: no Vite manifest — the asset stage did not run"; exit 1; }
    php -r "
        \$m = json_decode(file_get_contents(\"public/build/manifest.json\"), true);
        if (!\$m) { fwrite(STDERR, \"FAIL: manifest is empty or unparseable\n\"); exit(1); }
        \$checked = 0;
        foreach (\$m as \$key => \$entry) {
            \$f = \"public/build/\" . \$entry[\"file\"];
            if (!file_exists(\$f)) { fwrite(STDERR, \"FAIL: manifest references missing asset {\$f}\n\"); exit(1); }
            \$checked++;
            foreach (\$entry[\"css\"] ?? [] as \$css) {
                \$cf = \"public/build/\" . \$css;
                if (!file_exists(\$cf)) { fwrite(STDERR, \"FAIL: manifest references missing asset {\$cf}\n\"); exit(1); }
                \$checked++;
            }
        }
        echo \$checked . \" asset(s) present\n\";
    "
'
echo "assets OK"

PW1=$($COMPOSE exec -T app sh -c 'cat storage/admin-password.txt')
KEY1=$($COMPOSE exec -T app sh -c 'cat storage/app_key')
USERS1=$($COMPOSE exec -T app php artisan tinker --execute='echo App\Models\User::count();' | tail -1)
[ "$USERS1" = "1" ] || { echo "FAIL: expected 1 user, got $USERS1"; exit 1; }

echo "restarting to prove the bootstrap is idempotent…"
$COMPOSE restart app
i=0
until [ "$($COMPOSE ps app --format '{{.Health}}')" = "healthy" ]; do
    i=$((i + 1))
    [ "$i" -gt 60 ] && { echo "app never became healthy after restart"; $COMPOSE logs app; exit 1; }
    sleep 5
done

PW2=$($COMPOSE exec -T app sh -c 'cat storage/admin-password.txt')
KEY2=$($COMPOSE exec -T app sh -c 'cat storage/app_key')
USERS2=$($COMPOSE exec -T app php artisan tinker --execute='echo App\Models\User::count();' | tail -1)

[ "$PW1" = "$PW2" ] || { echo "FAIL: admin password rotated on restart"; exit 1; }
[ "$USERS2" = "1" ] || { echo "FAIL: user count changed to $USERS2 on restart"; exit 1; }
[ -n "$KEY1" ] || { echo "FAIL: no persisted APP_KEY after first boot"; exit 1; }
[ "$KEY1" = "$KEY2" ] || { echo "FAIL: APP_KEY rotated on restart — every session and anything encrypted under the old key is now unrecoverable"; exit 1; }

echo "OK: stack boots, serves, and restarts without rotating credentials or the persisted APP_KEY"
