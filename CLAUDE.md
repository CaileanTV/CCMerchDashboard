# CLAUDE.md

WordPress plugin `merchboard` (creator & merchandise management). Repo root = plugin root.

**Before any work, read and follow [docs/AI-DEVELOPMENT.md](docs/AI-DEVELOPMENT.md)**, including the permanent **Project Isolation** rule: only this repository is in scope; never read, copy from, or modify other local projects or LocalWP sites.

Then read [docs/PROJECT-STATUS.md](docs/PROJECT-STATUS.md) and [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md). Implementation happens phase by phase per [docs/ROADMAP.md](docs/ROADMAP.md); stop at the end of the requested phase.

## Commands

- `npm run lint`: ESLint + stylelint + TypeScript (strict, `tsc`) + PHPCS (WPCS) + PHPStan (level 8). Must pass before committing.
- `npm test`: PHPUnit unit tests (Brain Monkey) + Jest (dashboard). Must pass before committing.
- `npm run build`: builds the creator dashboard (React + TypeScript via `@wordpress/scripts`) into `build/` (gitignored). Needed before Playground shows the dashboard.
- `node bin/composer.mjs <args>`: Composer (works without php.ini; uses `.tools/composer.phar` if no global composer).
- `npm run wp:start`: ephemeral WordPress + WooCommerce at http://127.0.0.1:9400 (admin / password). Run via npm/PowerShell, not raw Git Bash (path mangling).

## Essentials

- PHP ≥ 8.2, WP ≥ 6.7, namespace `MerchBoard\` → `src/`, prefix `merchboard_`, text domain `merchboard`, REST `merchboard/v1`.
- Naming is locked (ADR-018): product `Merch Dashboard`, prefix `merchboard`, namespace `MerchBoard\`. Never use the former temporary `ccmerch` / `CCMerch\` convention. (`CCMerchDashboard` is only the Git repository name.)
- No third-party PHP runtime dependencies (ADR-004). Runtime uses the plugin's own autoloader, never `vendor/autoload.php` (ADR-017).
- New modules: implement `MerchBoard\Core\Module` in `src/Modules/<Name>/` and add them to `Plugin::default_modules()` (see docs/MODULES.md).
- Creator-facing code resolves the creator only via `CreatorContext`; creator API is `/me`-scoped (ADR-007).
- No external services beyond GitHub unless approved via ADR. **Dinero and Google Drive are out of scope**: never use, read, write, back up to, or enable their connectors (ADR-016).
- Docs in Danish; code, comments and commits in English (Conventional Commits). Small commits, push after validation, never force-push.
