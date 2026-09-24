# CLAUDE.md

WordPress plugin `ccmerch-dashboard` (creator & merchandise management). Repo root = plugin root.

**Before any work, read and follow [docs/AI-DEVELOPMENT.md](docs/AI-DEVELOPMENT.md)**, including the permanent **Project Isolation** rule: only this repository is in scope; never read, copy from, or modify other local projects or LocalWP sites.

Then read [docs/PROJECT-STATUS.md](docs/PROJECT-STATUS.md) and [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md). Implementation happens phase by phase per [docs/ROADMAP.md](docs/ROADMAP.md); stop at the end of the requested phase.

## Commands

- `npm run lint`: ESLint + PHPCS (WPCS) + PHPStan (level 8). Must pass before committing.
- `node bin/composer.mjs <args>`: Composer (works without php.ini; uses `.tools/composer.phar` if no global composer).
- `npm run wp:start`: ephemeral WordPress + WooCommerce at http://127.0.0.1:9400 (admin / password). Run via npm/PowerShell, not raw Git Bash (path mangling).

## Essentials

- PHP ≥ 8.2, WP ≥ 6.7, namespace `CCMerch\` → `src/`, prefix `ccmerch_`, text domain `ccmerch-dashboard`, REST `ccmerch/v1`.
- No third-party PHP runtime dependencies (ADR-004).
- Creator-facing code resolves the creator only via `CreatorContext`; creator API is `/me`-scoped (ADR-007).
- Docs in Danish; code, comments and commits in English (Conventional Commits). Small commits, push after validation, never force-push.
