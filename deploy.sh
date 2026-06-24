#!/usr/bin/env bash
set -euo pipefail

# ── Configuration (override via environment variables) ─────────────────────────
DEPLOY_HOST="${DEPLOY_HOST:-aiinf.gnf.dk}"
DEPLOY_USER="${DEPLOY_USER:-www-data}"
DEPLOY_PATH="${DEPLOY_PATH:-/var/www/aiinf.gnf.dk}"
SSH_KEY="${SSH_KEY:-}"          # leave empty to use SSH agent / default key

# ── Helpers ────────────────────────────────────────────────────────────────────
info() { printf '\033[34m→\033[0m %s\n' "$*"; }
ok()   { printf '\033[32m✓\033[0m %s\n' "$*"; }
die()  { printf '\033[31m✗\033[0m %s\n' "$*" >&2; exit 1; }

# ── 1. Guard: clean working tree ──────────────────────────────────────────────
if ! git diff --quiet || ! git diff --cached --quiet; then
    die "Uncommitted changes present. Commit or stash first."
fi

# ── 2. Sync main with origin ──────────────────────────────────────────────────
CURRENT=$(git rev-parse --abbrev-ref HEAD)

if [ -d "$DEPLOY_PATH" ]; then
    # Running on the server — pull from origin rather than push
    info "On server — pulling main from origin..."
    if [ "$CURRENT" != "main" ]; then
        git checkout main
    fi
    git pull origin main
    ok "main pulled"
else
    # Running from a dev machine — push local changes first
    if [ "$CURRENT" = "main" ]; then
        info "On main — pushing..."
        git push origin main
    else
        info "Merging $CURRENT into main..."
        git checkout main
        git pull origin main --ff-only
        git merge --no-ff "$CURRENT" -m "Deploy: merge $CURRENT"
        git push origin main
        git checkout "$CURRENT"
    fi
    ok "main pushed"
fi

# ── 3. Deploy (local or remote) ───────────────────────────────────────────────
deploy_local() {
    local path="$1"
    info "Deploying locally to $path ..."
    cd "$path"
    echo "  git pull origin main"
    git pull origin main
    echo "  running DB migrations..."
    for f in scripts/migrate_*.php; do
        [ -f "$f" ] || continue
        echo "    php $f"
        php "$f"
    done
    # Uncomment if PHP-FPM opcache needs a flush after deploy:
    # echo "  reloading php-fpm..."
    # systemctl reload php8.2-fpm 2>/dev/null || true
    echo "  done."
}

deploy_remote() {
    local path="$1"
    SSH_OPTS=(-o BatchMode=yes -o StrictHostKeyChecking=accept-new)
    [ -n "$SSH_KEY" ] && SSH_OPTS+=(-i "$SSH_KEY")
    info "Deploying to $DEPLOY_USER@$DEPLOY_HOST:$path ..."
    ssh "${SSH_OPTS[@]}" "$DEPLOY_USER@$DEPLOY_HOST" bash -s "$path" <<'REMOTE'
set -euo pipefail
cd "$1"
echo "  git pull origin main"
git pull origin main
echo "  running DB migrations..."
for f in scripts/migrate_*.php; do
    [ -f "$f" ] || continue
    echo "    php $f"
    php "$f"
done
echo "  done."
REMOTE
}

# Run locally if the deploy path exists on this machine, otherwise SSH.
if [ -d "$DEPLOY_PATH" ]; then
    deploy_local "$DEPLOY_PATH"
else
    deploy_remote "$DEPLOY_PATH"
fi

ok "Deploy complete."
