# Initial projektanalyse – 2026-09-24

Analyse foretaget før fase 1-ændringerne. Den beskriver udgangspunktet og er et fastfrosset øjebliksbillede, som ikke opdateres.

Kun repo'et `CCMerchDashboard` er undersøgt. Der er ikke søgt i, læst fra eller brugt andre lokale projekter eller LocalWP-sites.

## 1. Projektstruktur

Repo'et var **helt tomt**: kun `.git/`, ingen filer og ingen commits. Branchen `main` var oprettet uden commits.

## 2. WordPress

Der er ingen WordPress-installation i repo'et og ingen `wp-config.php`. Repo'et er ikke knyttet til noget LocalWP-site. Projektet er derfor et nyt, selvstændigt plugin-repo.

## 3. WooCommerce

Ikke til stede. Der er ingen WooCommerce-kode eller -data at integrere med endnu.

## 4. Plugins og themes

Ingen eksisterende custom plugins, themes eller anden kode.

## 5-8. Runtime og pakkehåndtering (lokal maskine)

| Værktøj | Fund |
| --- | --- |
| PHP | 8.5.10 CLI (WinGet, ZTS x64). **Ingen php.ini indlæst**, så `openssl`, `mbstring`, `curl`, `zip` og `fileinfo` er inaktive, selvom DLL'erne findes i `ext/`. |
| Composer | Ikke installeret |
| Node.js | 24.14.1 |
| npm | 11.11.0 |
| yarn / pnpm | Ikke installeret |
| `package.json` / `composer.json` | Fandtes ikke |
| WP-CLI | Ikke installeret |
| Docker | Ikke installeret (derfor er `@wordpress/env` udelukket) |
| GitHub CLI (`gh`) | Ikke installeret |

## 9-12. Dependencies, build, test, lint og formatting

Der fandtes ingen dependencies, build tools, frontend-frameworks, tests, linting- eller formatting-konfiguration og ingen coding standards.

## 13-14. Git og GitHub

- Branch: `main` uden commits, og working tree var rent.
- Remote: `origin` → `https://github.com/CaileanTV/CCMerchDashboard.git`. Repo'et var tomt (`git ls-remote` returnerede ingen refs) og er privat (GitHub API svarer 404 uden login).
- Git-bruger: `CaileanTV`.
- `core.autocrlf` var `true` (global Windows-standard). Den er sat til `false` lokalt for repo'et, og `.gitattributes` håndhæver LF.

## 15. Dokumentation

Ingen.

## 16. Tekniske problemer og observationer

1. **PHP uden php.ini:** Composer kan ikke køre uden `openssl`. Løst uden at ændre den globale PHP med wrapperen `bin/composer.mjs`, der aktiverer extensions pr. kørsel.
2. **Ingen Composer:** Hentet projekt-lokalt til `.tools/composer.phar` (gitignored) med verificeret SHA-384-signatur.
3. **Ingen Docker:** Lokal WordPress køres med WordPress Playground CLI (PHP-WASM + SQLite).
4. **Git Bash path-mangling:** Kommandoer med VFS-stier som `/wordpress` omskrives af Git Bash til `C:/Program Files/Git/wordpress`. npm-scripts køres via `cmd.exe` og rammes ikke. Kør Playground via `npm run wp:start`, ikke direkte i Git Bash.
5. **Playground på Windows-host:** Persistent site-data på en host-mount får WooCommerce-installationen til at fejle ("Operation not permitted" ved rename). Løsning: flygtigt site.
6. **PHP-versioner:** Lokal CLI er 8.5, mens pluginets minimum er 8.2. CI og Playground kører 8.2, så kompatibiliteten verificeres mod minimum.

## Konklusion

Greenfield-projekt. Der er intet eksisterende setup at bygge videre på eller at være kompatibel med, så fundamentet etableres fra bunden efter WordPress' egne konventioner.
