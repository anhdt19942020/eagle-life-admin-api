#!/usr/bin/env bash
# Deploy Eagle Life Admin (API and/or FE) to production VPS.
#
# Usage (Git Bash / WSL / macOS / Linux):
#   ./scripts/deploy.sh              # deploy this repo only (auto-detect api|fe)
#   ./scripts/deploy.sh --all        # API + FE (sibling folder required)
#   ./scripts/deploy.sh --api
#   ./scripts/deploy.sh --fe
#   ./scripts/deploy.sh --api --migrate
#
# Env overrides (optional):
#   DEPLOY_HOST=root@180.93.1.160
#   DEPLOY_PORT=1994
#   DEPLOY_API_ROOT=/var/www/eagle-life-admin-api
#   DEPLOY_FE_ROOT=/var/www/admin.eaglelife.info.vn
#   DEPLOY_FE_DIR=/path/to/eagle-life-admin-fe
#   DEPLOY_API_DIR=/path/to/eagle-life-admin-api

set -euo pipefail

DEPLOY_HOST="${DEPLOY_HOST:-root@180.93.1.160}"
DEPLOY_PORT="${DEPLOY_PORT:-1994}"
DEPLOY_API_ROOT="${DEPLOY_API_ROOT:-/var/www/eagle-life-admin-api}"
DEPLOY_FE_ROOT="${DEPLOY_FE_ROOT:-/var/www/admin.eaglelife.info.vn}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
REPO_NAME="$(basename "${REPO_ROOT}")"

ssh_cmd() { ssh -p "${DEPLOY_PORT}" -o StrictHostKeyChecking=accept-new "${DEPLOY_HOST}" "$@"; }
scp_cmd() { scp -P "${DEPLOY_PORT}" -o StrictHostKeyChecking=accept-new "$@"; }

die() { echo "ERROR: $*" >&2; exit 1; }
info() { echo "==> $*"; }

detect_kind() {
  if [[ -f "${REPO_ROOT}/artisan" ]]; then
    echo api
  elif [[ -f "${REPO_ROOT}/package.json" ]] && [[ -f "${REPO_ROOT}/vite.config.js" || -f "${REPO_ROOT}/vite.config.ts" ]]; then
    echo fe
  else
    die "Unknown repo at ${REPO_ROOT} (expected Laravel API or Vite FE)"
  fi
}

resolve_sibling() {
  local want="$1"
  local sibling
  if [[ "${want}" == api ]]; then
    sibling="${DEPLOY_API_DIR:-$(cd "${REPO_ROOT}/../eagle-life-admin-api" 2>/dev/null && pwd || true)}"
    [[ -n "${sibling}" && -f "${sibling}/artisan" ]] || die "API sibling not found. Set DEPLOY_API_DIR."
  else
    sibling="${DEPLOY_FE_DIR:-$(cd "${REPO_ROOT}/../eagle-life-admin-fe" 2>/dev/null && pwd || true)}"
    [[ -n "${sibling}" && -f "${sibling}/package.json" ]] || die "FE sibling not found. Set DEPLOY_FE_DIR."
  fi
  echo "${sibling}"
}

has_rsync() { command -v rsync >/dev/null 2>&1; }

deploy_api() {
  local api_dir="$1"
  local do_migrate="${2:-0}"
  [[ -d "${api_dir}" ]] || die "API dir missing: ${api_dir}"
  info "Deploy API from ${api_dir} → ${DEPLOY_HOST}:${DEPLOY_API_ROOT}"

  if has_rsync; then
    rsync -az --delete \
      -e "ssh -p ${DEPLOY_PORT} -o StrictHostKeyChecking=accept-new" \
      --exclude='.env' \
      --exclude='.env.*' \
      --exclude='vendor/' \
      --exclude='storage/' \
      --exclude='.git/' \
      --exclude='node_modules/' \
      --exclude='tests/' \
      --exclude='.phpunit*' \
      --exclude='docs/deployment.md' \
      --exclude='_*.php' \
      --exclude='bootstrap/cache/' \
      "${api_dir}/" "${DEPLOY_HOST}:${DEPLOY_API_ROOT}/"
  else
    info "rsync not found — scp core paths"
    local paths=(
      app artisan composer.json composer.lock config database public routes resources
      API_DOCS.md .env.example
    )
    for p in "${paths[@]}"; do
      [[ -e "${api_dir}/${p}" ]] || continue
      scp_cmd -r "${api_dir}/${p}" "${DEPLOY_HOST}:${DEPLOY_API_ROOT}/"
    done
    # bootstrap without local cache (dev packages.php breaks prod --no-dev)
    if [[ -d "${api_dir}/bootstrap" ]]; then
      [[ -f "${api_dir}/bootstrap/app.php" ]] && scp_cmd "${api_dir}/bootstrap/app.php" "${DEPLOY_HOST}:${DEPLOY_API_ROOT}/bootstrap/"
      [[ -f "${api_dir}/bootstrap/providers.php" ]] && scp_cmd "${api_dir}/bootstrap/providers.php" "${DEPLOY_HOST}:${DEPLOY_API_ROOT}/bootstrap/"
    fi
  fi

  local remote_cmds="cd ${DEPLOY_API_ROOT} && rm -f bootstrap/cache/packages.php bootstrap/cache/services.php bootstrap/cache/config.php && php artisan optimize:clear"
  if [[ "${do_migrate}" == "1" ]]; then
    remote_cmds+=" && php artisan migrate --force"
  fi
  remote_cmds+=" && curl -s -o /dev/null -w 'api_up=%{http_code}\n' https://api.eaglelife.info.vn/up"
  ssh_cmd "${remote_cmds}"
  info "API deploy done"
}

deploy_fe() {
  local fe_dir="$1"
  [[ -d "${fe_dir}" ]] || die "FE dir missing: ${fe_dir}"
  info "Build FE in ${fe_dir}"
  (
    cd "${fe_dir}"
    if [[ -f package-lock.json ]]; then
      npm ci --prefer-offline --no-audit --no-fund 2>/dev/null || npm install --no-audit --no-fund
    else
      npm install --no-audit --no-fund
    fi
    npm run build
    [[ -d dist ]] || die "FE build produced no dist/"
  )

  info "Upload FE dist → ${DEPLOY_HOST}:${DEPLOY_FE_ROOT}"
  scp_cmd -r "${fe_dir}/dist/"* "${DEPLOY_HOST}:${DEPLOY_FE_ROOT}/"
  scp_cmd -r "${fe_dir}/dist/assets" "${DEPLOY_HOST}:/tmp/admin-fe-assets-new"
  ssh_cmd "rm -rf ${DEPLOY_FE_ROOT}/assets; mv /tmp/admin-fe-assets-new ${DEPLOY_FE_ROOT}/assets; chown -R www-data:www-data ${DEPLOY_FE_ROOT}; curl -s -o /dev/null -w 'admin_up=%{http_code}\n' https://admin.eaglelife.info.vn/"
  info "FE deploy done"
}

DO_API=0
DO_FE=0
DO_MIGRATE=0
DO_ALL=0

if [[ $# -eq 0 ]]; then
  kind="$(detect_kind)"
  if [[ "${kind}" == api ]]; then DO_API=1; else DO_FE=1; fi
else
  while [[ $# -gt 0 ]]; do
    case "$1" in
      --all) DO_ALL=1 ;;
      --api) DO_API=1 ;;
      --fe) DO_FE=1 ;;
      --migrate) DO_MIGRATE=1 ;;
      -h|--help)
        sed -n '2,20p' "$0"
        exit 0
        ;;
      *) die "Unknown arg: $1 (try --help)" ;;
    esac
    shift
  done
fi

if [[ "${DO_ALL}" == "1" ]]; then
  DO_API=1
  DO_FE=1
fi

[[ "${DO_API}" == "1" || "${DO_FE}" == "1" ]] || die "Nothing to deploy"

kind="$(detect_kind)"
API_DIR="${REPO_ROOT}"
FE_DIR="${REPO_ROOT}"
if [[ "${kind}" == api ]]; then
  API_DIR="${REPO_ROOT}"
  if [[ "${DO_FE}" == "1" ]]; then FE_DIR="$(resolve_sibling fe)"; fi
else
  FE_DIR="${REPO_ROOT}"
  if [[ "${DO_API}" == "1" ]]; then API_DIR="$(resolve_sibling api)"; fi
fi

info "Host ${DEPLOY_HOST} port ${DEPLOY_PORT}"
[[ "${DO_API}" == "1" ]] && deploy_api "${API_DIR}" "${DO_MIGRATE}"
[[ "${DO_FE}" == "1" ]] && deploy_fe "${FE_DIR}"
info "All requested deploys finished"
