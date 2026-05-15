# Deployment Guide

## Purpose

This branch (`feature/hourly-ai-newscaster`) is intended to be deployed again later.
Use this checklist to promote the branch through the custom AzuraCast release flow safely.

## Release Flow

### 1. Merge this branch into the current `dev` release line

If `dev` has moved since the last deployment, start from the latest remote `dev` and merge this branch into a fresh deploy branch.

```bash
git fetch origin --tags
git checkout -b deploy/hourly-ai-newscaster origin/dev
git merge --no-ff feature/hourly-ai-newscaster
```

If there are conflicts, prefer the newer release-line fixes already present on `dev` unless this branch intentionally replaces them.

### 2. Bump the release version

Update `STABLE_VERSION` in `backend/src/Version.php`.

```php
public const STABLE_VERSION = '0.25.1';
```

Version rules:
- patch: bug fixes only, for example `0.25.0` -> `0.25.1`
- minor: new user-facing features, for example `0.25.0` -> `0.26.0`
- major: breaking changes

Then commit the bump:

```bash
git add backend/src/Version.php
git commit -m "chore: bump version to 0.25.1"
```

### 3. Push the deploy branch and update `dev`

```bash
git push -u origin deploy/hourly-ai-newscaster
git push origin deploy/hourly-ai-newscaster:dev
```

### 4. Create and merge the `dev` -> `main` PR

Open a pull request from `dev` to `main`, then merge it.

GitHub CLI example:

```bash
gh pr create --base main --head dev --title "Release 0.25.1"
gh pr merge --merge
```

### 5. Create the GitHub release

Create a release tag from `main`. The tag must match `STABLE_VERSION` with a leading `v`.

```bash
gh release create v0.25.1 --target main --title "v0.25.1"
```

### 6. Wait for GitHub Actions to finish

Do not continue until the release tag workflow is green.

Check recent runs:

```bash
gh run list --limit 5
gh run view <run-id>
```

### 7. Trigger the production web update

After the release workflow is green:
- go to `https://azura.eternityready.com/admin/updates`
- click `Check for Updates`
- click `Update via Web`

## Post-Deploy Verification

### Public/app checks
- confirm the site loads
- confirm admin login works
- confirm the new feature is visible and usable

### Server/container checks

```bash
ssh jeremiah@67.225.188.121
sudo docker ps --format 'table {{.Names}}\t{{.Image}}\t{{.Status}}'
sudo docker compose -f /var/azuracast/docker-compose.yml ps
```

### Verify deployed version inside the container

```bash
ssh jeremiah@67.225.188.121 "sudo docker compose -f /var/azuracast/docker-compose.yml exec -T web php -r 'require \"/var/azuracast/www/vendor/autoload.php\"; require \"/var/azuracast/www/backend/src/Version.php\"; echo App\\Version::STABLE_VERSION, PHP_EOL;'"
```

### Check deployed commit metadata

```bash
ssh jeremiah@67.225.188.121 "sudo docker compose -f /var/azuracast/docker-compose.yml exec -T web sh -lc '[ -f /var/azuracast/www/.version ] && cat /var/azuracast/www/.version'"
```

## Notes From The `0.25.0` Deployment

- production updated successfully to `0.25.0`
- live container version check returned `0.25.0`
- deployed `.version` matched release commit `e83ba97`
- the web updater restarted the `azuracast` container successfully
- some runtime warnings existed in logs, but there was no fatal deployment failure

## Recommended Safety Checks Before Re-Deploying This Branch

- compare this branch with current `origin/dev` before merging
- review `backend/src/Controller/Api/Admin/Updates/GetUpdatesAction.php` for release/update logic drift
- review `util/docker/stations/setup/liquidsoap.sh` if upstream changed again
- confirm the intended next version number is still correct
