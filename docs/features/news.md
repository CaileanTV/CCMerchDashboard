# Feature: Nyheder ("Hvad er nyt")

## Purpose
Administratoren kan fortælle creators, hvad der er nyt i Merch Dashboard, fx efter en opdatering, og se hvem der har set og lukket beskeden (ROADMAP 25.11, [ADR-072](../DECISIONS.md)).

## Current status
Færdig (fase 25, trin 25.11; implementeret 2026-10-23, committet som eksperimentel `aa26c25`, CI grøn; projektejeren har testet det i browseren: godkendt). Ikke udgivet i en version endnu.

## User-facing functionality
- **Popup:** næste gang en aktiv creator åbner dashboardet efter en udgivelse, vises den nyeste udgivne besked, creatoren ikke har lukket, som en dialog ("Forstået" eller Escape lukker). Den vises ikke igen efter lukning. Ældre beskeder vises ikke som popup.
- **Siden "Nyheder"** (efter Oversigt i menuen): alle udgivne beskeder fra efter creatorens oprettelse, nyeste først og foldet ud; de andre kan foldes ud.
- Creators, der oprettes senere, ser kun beskeder udgivet efter deres oprettelse.

## Admin functionality
Merch Dashboard → **Nyheder** (`merchboard_manage_creators`):
- **Liste:** titel, status (kladde, udgivet, trukket tilbage), udgivelsesdato og "X set / Y lukket af Z" aktive creators.
- **Skriv/rediger:** titel og WordPress' editor med fed, kursiv, punktliste, nummereret liste og link. "Gem" og "Gem og forhåndsvis" (den rensede tekst som creators ser den). En afvist formular viser teksten igen.
- **Udgiv** kræver afkrydsningen "Vis beskeden for alle aktive creators". En udgivet besked kan rettes (popuppen vises ikke igen) eller trækkes tilbage; kun kladder kan slettes.
- **Læsere:** pr. udgivet besked hver aktiv creator med "Ikke set", "Set {tid}" eller "Lukket {tid}".

## Technical implementation
- **Modul** `NewsModule` (ubetinget): `NewsRepository`, `NewsService` (capability, transaktion, audit), `NewsHtml` (allow-list), `NewsAdminPage` + `NewsAdminActions` (admin-post, nonce, PRG), `MeNewsController` (`/me/news`) og `NewsDashboardSection`.
- **Dashboard:** `components/Dialog.tsx` (modal), `sections/news/WhatsNew.tsx` (monteres i `Layout`, når creatoren er kendt), `NewsSection.tsx`, `NewsContent.tsx` (genopbygger kun de tilladte elementer; ingen indsat HTML) og `api/news.ts`.

## Relevant files
- `src/Modules/News/`, `src/Core/Database/Migrations/CreateNewsTables.php`
- `assets/src/dashboard/components/Dialog.tsx`, `assets/src/dashboard/sections/news/`, `assets/src/dashboard/api/news.ts`
- `tests/Integration/News/NewsTest.php`, `tests/Integration/Database/MigrationTest.php`, `assets/src/dashboard/test/news.test.tsx`

## Database/data used
`merchboard_news` og `merchboard_news_reads` (schema-version 25). Se [DATABASE.md](../DATABASE.md).

## Permissions
Admin: `merchboard_manage_creators` (handler og service), nonce. Creator: `CreatorContext` (ADR-007); kun udgivne beskeder fra efter creatorens oprettelse.

## API endpoints
`GET /merchboard/v1/me/news`, `POST /me/news/{id}/seen`, `POST /me/news/{id}/closed`. Se [API.md](../API.md).

## Hooks
Filteret `merchboard_dashboard_sections` (sektionen "news"); `admin_menu` (prioritet 28), `admin_post_merchboard_news`, `rest_api_init`.

## Dependencies
Creators- og Dashboard-modulet. Ingen WooCommerce, ingen eksterne pakker.

## Tests
Integration: service (kladde, udgiv, ret, træk tilbage, slet, audit uden tekst), REST (synlighed, set/lukket, 404, 403), admin-handlinger, migration 25. Jest: dialog (fokus, Escape, inert), genopbygning af HTML (ingen scripts eller usikre links), popup og side.
