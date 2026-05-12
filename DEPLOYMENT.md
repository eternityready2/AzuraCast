# Deployment Guide

## Before Every Release

Update `STABLE_VERSION` in [backend/src/Version.php](backend/src/Version.php):

```php
public const STABLE_VERSION = '0.24.1'; // match the release tag without "v"
```

---

## Release Process

### 1. Commit and push to `dev`
```bash
git add backend/src/Version.php
git commit -m "chore: bump version to 0.24.1"
git push origin dev
```

### 2. Create PR from `dev` → `main` on GitHub
- Go to GitHub → Pull requests → New pull request
- Base: `main` ← Compare: `dev`
- Merge the PR

### 3. Create a new Release on GitHub
- Go to GitHub → Releases → Draft a new release
- Tag: `v0.24.1` (must match `'v' . STABLE_VERSION`)
- Target: `main`
- Publish release

The GitHub Action will automatically build and push the Docker image to GHCR tagged as `:v0.24.1`, `:stable`, and `:latest`.

### 4. Update the production server
Once the GitHub Action finishes, go to the AzuraCast Update page:

`https://azura.eternityready.com/admin/updates`

Click **"Check for Updates"** and then **"Update via Web"**.

---

## Rollback

### Quick rollback (image only)
Before any update, tag the current image as backup:
```bash
sudo docker tag azuracast-web:custom azuracast-web:backup
```

To rollback:
```bash
sudo docker tag azuracast-web:backup azuracast-web:custom
sudo docker compose up -d
```

### Full rollback (data + code)
Restore from AzuraCast backup:
```bash
sudo docker compose exec web azuracast_cli azuracast:restore /var/azuracast/backups/backup-pre-vX.X.X.zip
```

---

## Version Naming

Follow semver format `vMAJOR.MINOR.PATCH`:
- Bug fixes → increment PATCH (`v0.24.0` → `v0.24.1`)
- New features → increment MINOR (`v0.24.0` → `v0.25.0`)
- Breaking changes → increment MAJOR (`v0.24.0` → `v1.0.0`)
