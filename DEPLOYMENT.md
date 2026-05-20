# Deployment Guide

## Purpose

Use this checklist to promote any release branch through the custom AzuraCast release flow safely.

Before you start, choose these values for the deployment:
- source branch: the branch you want to release, for example `feature/my-change`
- deploy branch: a temporary branch created from `origin/dev`, for example `deploy/my-change`
- release version: the next valid `STABLE_VERSION`, for example `0.25.2`

## Release Flow

### 1. Merge the source branch into the current `dev` release line

If `dev` has moved since the last deployment, start from the latest remote `dev` and merge the source branch into a fresh deploy branch.

```bash
git fetch origin --tags
git checkout -b <deploy-branch> origin/dev
git merge --no-ff <source-branch>
```

If there are conflicts, prefer the newer release-line fixes already present on `dev` unless the source branch intentionally replaces them.

### 2. Bump the release version

Update `STABLE_VERSION` in `backend/src/Version.php`.

```php
public const STABLE_VERSION = '<release-version>';
```

Version rules:
- patch: bug fixes only, for example `0.25.1` -> `0.25.2`
- minor: new user-facing features, for example `0.25.0` -> `0.26.0`
- major: breaking changes

Then commit the bump:

```bash
git add backend/src/Version.php
git commit -m "chore: bump version to <release-version>"
```

### 3. Push the deploy branch and update `dev`

```bash
git push -u origin <deploy-branch>
git push origin <deploy-branch>:dev
```

### 4. Create and merge the `dev` -> `main` PR

Open a pull request from `dev` to `main`, then merge it.

GitHub CLI example:

```bash
gh pr create --base main --head dev --title "Release <release-version>"
gh pr merge --merge
```

### 5. Create the GitHub release

Create a release tag from `main`. The tag must match `STABLE_VERSION` with a leading `v`.

```bash
gh release create v<release-version> --target main --title "v<release-version>"
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

## Test Server Deployment

Use this flow when you want to deploy the current `dev` line to the test server without publishing a GitHub release.

### Target

- host: `root@23.95.254.206`
- domain: `https://test.eternityready.com`
- source checkout on server: `/root/Azura-Cast-Custom`
- live compose directory on server: `/var/azuracast`
- live container name: `azuracast`

### 1. Merge your feature branch into `dev`

Do this locally first, then push `dev`.

```bash
git fetch origin
git checkout -B dev origin/dev
git merge --no-ff <source-branch>
git push origin dev
```

### 2. Update the server checkout to `dev`

The live stack is built from the source checkout in `/root/Azura-Cast-Custom`, then run from `/var/azuracast`.

```bash
ssh root@23.95.254.206
cd /root/Azura-Cast-Custom
git fetch origin
git checkout dev
git pull --ff-only origin dev
```

### 3. Build the local Docker image on the server

Build the final AzuraCast image locally on the test server.

```bash
cd /root/Azura-Cast-Custom
docker build --target final -t azuracast:local .
```

### 4. Point the live stack at the local image

The runtime compose bundle lives in `/var/azuracast` and normally points at GHCR. Override only the `web` image so the test server runs the freshly built local image.

```bash
cat > /var/azuracast/docker-compose.override.yml <<'EOF'
services:
  web:
    image: azuracast:local
EOF
```

### 5. Recreate the live stack

```bash
cd /var/azuracast
docker compose up -d
```

### 6. Verify the deployment

Check that the container is running the local image:

```bash
docker ps --format 'table {{.Names}}\t{{.Image}}\t{{.Status}}'
docker inspect azuracast --format '{{.Config.Image}}'
```

Check that the site is reachable:

```bash
curl -I https://test.eternityready.com
curl -I https://test.eternityready.com/login
```

Then verify in the browser:
- confirm the login page loads at `https://test.eternityready.com`
- log in with a valid test-server account
- confirm the target page loads, for example `/station/2/schedule`
- confirm the new feature is visible and usable

### Notes

- `/var/azuracast` is not a git checkout; it is only the live runtime compose/env bundle.
- `/root/Azura-Cast-Custom` is the server-side source checkout used for local builds.
- If you want to switch back to GHCR-based images later, remove `/var/azuracast/docker-compose.override.yml` and recreate the stack.

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

## Recommended Safety Checks Before Re-Deploying A Branch

- compare the source branch with current `origin/dev` before merging
- review `backend/src/Controller/Api/Admin/Updates/GetUpdatesAction.php` for release/update logic drift
- review `util/docker/stations/setup/liquidsoap.sh` if upstream changed again
- confirm the intended next version number is still correct
