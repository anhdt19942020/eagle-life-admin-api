# Deploy scripts

Production: `root@180.93.1.160:1994`

| Target | URL | Server path |
|--------|-----|-------------|
| API | https://api.eaglelife.info.vn | `/var/www/eagle-life-admin-api` |
| FE | https://admin.eaglelife.info.vn | `/var/www/admin.eaglelife.info.vn` |

## Requirements

- Git Bash, WSL, or macOS/Linux shell
- SSH key access to the VPS
- Sibling folders: `eagle-life-admin-api` + `eagle-life-admin-fe` (for `--all`)
- FE: Node/npm; API: `rsync` preferred (falls back to `scp`)

## Usage

From **either** repo:

```bash
chmod +x scripts/deploy.sh

./scripts/deploy.sh           # this repo only
./scripts/deploy.sh --all     # API + FE
./scripts/deploy.sh --api
./scripts/deploy.sh --fe
./scripts/deploy.sh --api --migrate
```

Optional env overrides: `DEPLOY_HOST`, `DEPLOY_PORT`, `DEPLOY_API_ROOT`, `DEPLOY_FE_ROOT`, `DEPLOY_API_DIR`, `DEPLOY_FE_DIR`.

**Never** overwrites server `.env` / `vendor` / `storage`.
