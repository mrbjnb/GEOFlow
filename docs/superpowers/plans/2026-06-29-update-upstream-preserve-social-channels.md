# Update GEOFlow from Upstream While Preserving Social Media Distribution Channels

> **Status:** All assumptions empirically verified against live repo. Ready for execution.
> 
> **Verification methods used:** `git merge-tree` simulation, intersection analysis of uncommitted vs upstream changes, `git ls-tree` for upstream file existence.

---

## Goal

Safely merge the latest upstream GEOFlow (`yaojingang/GEOFlow` main) into your local fork while preserving your custom Blogger and Facebook Page distribution channel features. Then rebuild and deploy the Docker production image.

## Architecture

Commit plan docs → stash uncommitted changes → merge upstream (will be CLEAN — verified by `git merge-tree`) → pop stash → resolve conflicts on 8 files → verify code in Docker → rebuild and deploy.

## Tech Stack

Git, Docker Compose, PHP 8.4 / Laravel, PostgreSQL 16 + pgvector, Redis 7, Nginx, PowerShell 5.1

---

## Verified Context (All Empirically Confirmed)

| Item | Value |
|------|-------|
| Local branch | `main` |
| Local HEAD | `650a852` |
| Upstream HEAD | `7a8f6ff` |
| Merge base | `b865cec` |
| Merge type | Real merge (both sides have unique commits) |
| Merge will be clean? | ✅ YES (verified by `git merge-tree` — exit 0, no conflicts) |
| PHP on host? | ❌ NOT installed |
| `.env.prod` exists? | ❌ No |
| Docker available? | ✅ Yes (v29.5.3) |

## Task 1: Pre-Update Safety Checks

- [ ] 1.1 Verify Git remotes: `git remote -v`
- [ ] 1.2 Verify branch is `main`: `git branch --show-current`
- [ ] 1.3 Verify HEAD: `git rev-parse --short HEAD`
- [ ] 1.4 Commit plan documents so they aren't stashed
- [ ] 1.5 Create MANDATORY backup branch
- [ ] 1.6 Check production containers if on server

## Task 2: Stash Local Changes

- [ ] 2.1 Stash all changes: `git stash push -u -m "social-media-distribution-channels"`
- [ ] 2.2 Verify clean working directory: `git status`
- [ ] 2.3 Verify stash saved: `git stash list`

## Task 3: Merge Upstream (CLEAN)

- [ ] 3.1 Fetch upstream: `git fetch upstream main`
- [ ] 3.2 Merge: `git merge upstream/main --no-edit`
- [ ] 3.3 Verify merge succeeded: `git log --oneline -3` + `git status`
- [ ] 3.4 Review new env variables from upstream

## Task 4: Pop Stash (Conflicts Expected on 8 Files)

- [ ] 4.1 Pop stash: `git stash pop`
- [ ] 4.2 Check conflicted files: `git status`

## Task 5: Resolve 8 Merge Conflicts

- [ ] 5.1 Resolve DistributionController.php
- [ ] 5.2 Resolve DistributionChannel.php
- [ ] 5.3 Resolve config/geoflow.php
- [ ] 5.4 Resolve lang/en/admin.php
- [ ] 5.5 Resolve lang/zh_CN/admin.php
- [ ] 5.6 Resolve create.blade.php
- [ ] 5.7 Resolve edit.blade.php
- [ ] 5.8 Resolve show.blade.php
- [ ] 5.9 Verify all markers removed: `git diff --check`
- [ ] 5.10 Stage all: `git add -A`

## Task 6: Verify Code Inside Docker

- [ ] 6.1 Build dev image: `docker compose -f docker-compose.yml build app`
- [ ] 6.2 Start dev containers: `docker compose -f docker-compose.yml up -d`
- [ ] 6.3 Wait for healthy containers
- [ ] 6.4 Check PHP syntax in container
- [ ] 6.5 Run distribution tests
- [ ] 6.6 Run social channel tests
- [ ] 6.7 Run full test suite (optional)
- [ ] 6.8 Stop dev containers

## Task 7: Commit and Push

- [ ] 7.1 Commit: `git commit -m "feat: preserve social media distribution channels after upstream merge"`
- [ ] 7.2 Drop stash if retained: `git stash drop stash@{0}`
- [ ] 7.3 Push: `git push origin main`

## Task 8: Rebuild and Deploy Docker (Production)

- [ ] 8.1 Create `.env.prod` with real secrets (if not present)
- [ ] 8.2 Review new env variables from upstream
- [ ] 8.3 Backup production database
- [ ] 8.4 Build production image
- [ ] 8.5 Stop old containers: `docker compose down`
- [ ] 8.6 Start containers: `docker compose up -d`
- [ ] 8.7 Monitor init container
- [ ] 8.8 Verify containers are running
- [ ] 8.9 Verify application accessible

## Task 9: Cleanup

- [ ] 9.1 Check application logs
- [ ] 9.2 Verify git history
- [ ] 9.3 Final smoke test
- [ ] 9.4 Remove backup branch (ONLY after confirming everything works)

## Rollback Plan

### If merge conflicts too complex:
```powershell
git stash push -u -m "recovery"
git reset --hard backup-main-before-upstream-merge
```

### If deployment breaks:
```powershell
git reset --hard backup-main-before-upstream-merge
docker compose build
docker compose up -d
```

### If stash lost:
```powershell
git reflog show stash
git stash apply <commit-hash>
```
