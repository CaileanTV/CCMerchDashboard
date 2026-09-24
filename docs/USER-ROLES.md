# Roller, capabilities og adgangsmodel

> Status: **Design.** Roller og capabilities implementeres i fase 5, adgangsflow i fase 8.

## Brugertyper

| Brugertype | Hvordan | Adgang |
| --- | --- | --- |
| **Administrator** | WP-rollen `administrator` får plugin-capabilities ved aktivering | Hele Merch Dashboard-admin |
| **Shop manager** (valgfri) | Kan tildeles plugin-caps via Settings → Access | Konfigurerbart, default ingen |
| **Creator** | WP-bruger med rollen `merchboard_creator` **og** en aktiv creator-record | Kun Creator Dashboard, kun egne data |
| Øvrige (customer, subscriber …) | – | Ingen adgang til Merch Dashboard |

## Capabilities

Få og meningsfulde. Nye capabilities tilføjes, når et modul har et reelt behov for at adskille rettigheder.

| Capability | Tildeles | Giver |
| --- | --- | --- |
| `merchboard_manage` | administrator | Se Merch Dashboard-menuen og Overview |
| `merchboard_manage_creators` | administrator | Oprette, redigere, aktivere/deaktivere creators og koble brugere til/fra |
| `merchboard_manage_settings` | administrator | Ændre plugin-settings |
| `merchboard_view_logs` | administrator | Se logs |
| `merchboard_access_dashboard` | rollen `merchboard_creator` | Forudsætning for at bruge Creator Dashboard (se adgangsregel nedenfor) |

**Meta-capabilities** (via `map_meta_cap`, fase 5):

| Meta-cap | Kaldes som | Mappes til |
| --- | --- | --- |
| `merchboard_view_creator` | `current_user_can( 'merchboard_view_creator', $creator_id )` | `merchboard_manage_creators` **eller** (brugeren ejer creator'en, creator'en er aktiv og brugeren har `merchboard_access_dashboard`) |
| `merchboard_edit_creator` | `current_user_can( 'merchboard_edit_creator', $creator_id )` | `merchboard_manage_creators` **eller** (ejer og aktiv) for de profilfelter creator'en selv må redigere |

Meta-caps er defense-in-depth og gør det muligt senere at tilføje ID-baserede ressourcer uden at genopfinde ejerskabs-tjek.

Fremtidige capabilities (tilføjes med deres modul): `merchboard_manage_payouts`, `merchboard_view_reports` og `merchboard_manage_integrations`.

## Rollen `merchboard_creator`

- Label: "Creator".
- Capabilities: `read` og `merchboard_access_dashboard`. **Intet andet.** Ingen `upload_files`, `edit_posts` osv.
- Tildeles som **ekstra rolle** (`$user->add_role()`), så en eksisterende rolle (fx WooCommerce `customer`) bevares.
- Rolle og caps synkroniseres ved aktivering og når plugin-versionen ændres (`Upgrader`), så nye caps rulles ud ved opdatering.
- Fjernes ved afinstallation, kun hvis "slet data" er slået til.

## Adgangsregel for Creator Dashboard

En request får creator-adgang, **kun hvis alle** fire er opfyldt:

1. Brugeren er logget ind (WordPress-session).
2. `current_user_can( 'merchboard_access_dashboard' )`.
3. Der findes en creator-record med `user_id = get_current_user_id()`.
4. Creator-recorden har `status = active`.

Det implementeres **ét sted**: `CreatorContext::current(): ?Creator`. Alle creator-facing controllers, REST-routes og services bruger den. Ingen kode må selv slå "nuværende creator" op.

Hvorfor både rolle **og** record? Rollen gør adgang synlig og håndterbar med standard-WP-værktøjer, fx brugerlisten. Recorden er domænet og bærer status. Kravet om begge betyder, at en fejlagtigt tildelt rolle alene ikke giver adgang, og at suspension virker øjeblikkeligt uden at ændre roller.

## Livscyklus

| Handling (admin) | Effekt |
| --- | --- |
| Opret creator for bruger | Record (`active`) oprettes og rolle `merchboard_creator` tilføjes. Audit-log. |
| Deaktivér | `status = inactive`. Rollen **bevares**, så genaktivering er nem, men adgangen forsvinder øjeblikkeligt pga. regel 4. Aktive sessioner afvises på næste request. Valgfrit: `WP_Session_Tokens::destroy_all()` for brugeren. Audit-log. |
| Genaktivér | `status = active`. Audit-log. |
| Frakobl bruger | `user_id = NULL`, og rollen fjernes. Creator-recorden og historikken bevares på creator-ID'et. Audit-log. |
| WP-bruger slettes | Hook `deleted_user` (fase 6): creator'ens `user_id = NULL` og `status = inactive`. Creator-data bevares. Audit-log. |

Statusmodellen er bevidst minimal: `active` og `inactive` (ADR-020, [DATABASE.md](DATABASE.md#statusmodel)). Et separat "arkiveret"-tilstand tilføjes kun, hvis en fase får et konkret behov.

## Login-flow

- Creators logger ind via den normale WordPress-login (`wp-login.php`) eller WooCommerce "Min konto". Der bygges **intet** eget auth-system. Password-reset, rate-limit-plugins og 2FA-plugins virker dermed uændret.
- `login_redirect`-filter: Brugere med creator-adgang og **uden** `merchboard_manage` sendes til `/creator-dashboard/`. En eksplicit, valideret `redirect_to` til dashboardet respekteres.
- Creators uden admin-caps, der tilgår `/wp-admin/`, redirectes til dashboardet. `admin-ajax.php`, `admin-post.php` og `async-upload.php` undtages, fordi de kan bruges legitimt af frontend.
- Admin bar skjules for creators (`show_admin_bar` filter).
- Login-siden kan senere brandes via `login_enqueue_scripts`/`login_headerurl`. Det er styling og ikke et nyt system.

## Administratorer, der også er creators

Tilladt, fx til test. En administrator med en aktiv creator-record kan åbne dashboardet og ser *sine egne* creator-data. Der er ingen "se som anden creator"-funktion i v1. Impersonation kræver separat design med audit-log (fremtid).
