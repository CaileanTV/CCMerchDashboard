# Roller, capabilities og adgangsmodel

> Status: **Roller og capabilities implementeret (fase 5, ADR-022).** **Adgangspolitikken `CreatorContext` (capability + aktiv creator-record) er implementeret i fase 8.** Login-redirect og wp-admin-blokering kommer i fase 12, meta-capabilities i fase 14.

## Brugertyper

| Brugertype | Hvordan | Adgang |
| --- | --- | --- |
| **Administrator** | WP-rollen `administrator` får pluginets admin-capabilities ved aktivering/opdatering (fase 5) | Hele Merch Dashboard-admin |
| **Shop manager** (valgfri) | Kan tildeles plugin-caps via Settings → Access | Konfigurerbart, default ingen |
| **Creator** | WP-bruger med rollen `merchboard_creator` **og** en aktiv creator-record | Kun Creator Dashboard, kun egne data |
| Øvrige (customer, subscriber …) | – | Ingen adgang til Merch Dashboard |

## Tre forskellige ting

| Begreb | Ejes af | Hvad det er | Hvad det **ikke** er |
| --- | --- | --- | --- |
| **WordPress-bruger** (`wp_users`) | WordPress | Login-identitet og konto | Ikke en creator |
| **Rolle/capability** (`merchboard_creator`, `merchboard_*`) | WordPress' role-system, defineret af pluginet | *Hvad en bruger må* | Giver ikke alene adgang til creator-data |
| **Creator-record** (`merchboard_creators`) | Merch Dashboard | Forretningsenheden med status `active`/`inactive` (ADR-020) | Ikke en login-konto |

Faktisk creator-adgang (fase 8) kræver **både** capability **og** en aktiv creator-record koblet til brugeren. Se adgangsreglen nedenfor.

## Capabilities (implementeret, fase 5)

Definitionerne findes ét sted: `MerchBoard\Core\Security\Capabilities` (konstanter, ingen string literals i øvrig kode). Pluginet ejer alle capabilities med prefix `merchboard_`.

| Capability | Konstant | Tildeles | Giver |
| --- | --- | --- | --- |
| `merchboard_access_dashboard` | `Capabilities::ACCESS_DASHBOARD` | rollen `merchboard_creator` | Forudsætning (ikke tilstrækkelig) for Creator Dashboard |
| `merchboard_manage` | `Capabilities::MANAGE` | `administrator` | Se Merch Dashboard-menuen og Overview (fase 9) |
| `merchboard_manage_creators` | `Capabilities::MANAGE_CREATORS` | `administrator` | Oprette, redigere, aktivere/deaktivere creators og koble brugere til/fra (fase 6/10) |
| `merchboard_manage_settings` | `Capabilities::MANAGE_SETTINGS` | `administrator` | Ændre plugin-settings (fase 9) |

`merchboard_manage_creators` bruges fra fase 6 (creator-administration: admin-side, handlers og `CreatorService`). De øvrige er fundamentet for fase 8-10. Checks skal altid være positive capability-checks: `current_user_can( Capabilities::MANAGE_CREATORS )` / `user_can( $user, … )`. Aldrig "er logget ind", rolle- eller display-navne eller hardcodede user-ID'er.

Administratorer beholder alle deres WordPress-capabilities uændret. Pluginet tilføjer kun sine egne. Pluginets admin-funktioner kræver pluginets capabilities, ikke `manage_options`, så de senere kan gives til fx en shop manager uden fulde admin-rettigheder (fase 9).

### Udskudt (dokumenteret ændring i forhold til den oprindelige fase 5-plan)

| Planlagt i fase 5 | Flyttet til | Begrundelse |
| --- | --- | --- |
| `merchboard_view_logs` | Fase 11 (audit-log viewer) | Audit-visning er ikke en del af fase 5. Capabilities oprettes med den funktion, der bruger dem. |
| Meta-capabilities `merchboard_view_creator`/`merchboard_edit_creator` (`map_meta_cap`) | Fase 14 (REST `/me`), udskudt igen fra fase 8 | Ejerskabsdelen kræver creator-record og status. `CreatorContext` (fase 8) leverer grundlaget, og meta-capabilities tilføjes, når de første ressourcer (`/me`) skal beskyttes. |
| Fjernelse af rolle/caps ved uninstall | Fase 9 (opt-in "Slet alle data") | Uninstall bevarer data (DATABASE.md). Brugere, der har rollen, må ikke miste den implicit. |

Fremtidige capabilities (tilføjes med deres modul): `merchboard_view_logs` (11), `merchboard_manage_payouts`, `merchboard_view_reports` og `merchboard_manage_integrations`.

## Rollen `merchboard_creator` (implementeret, fase 5)

- Display name: **"Merch Dashboard Creator"** (`Capabilities::CREATOR_ROLE_NAME`).
- Capabilities: **præcis** `read` og `merchboard_access_dashboard`.
  - `read` er WordPress' baseline for indloggede brugere (Subscriber har kun `read`). Den giver adgang til egen profil i wp-admin som en Subscriber. Blokering af wp-admin for creators kommer i fase 12.
- Rollen **må ikke** have: `manage_options`, `activate_plugins`, `edit_users`, `delete_users`, `create_users`, `list_users`, `promote_users`, `edit_posts`, `upload_files`, `unfiltered_html`, `manage_woocommerce` eller andre brede capabilities, og heller ingen af pluginets admin-capabilities. Det håndhæves ved hver synkronisering (se nedenfor).
- Tildeles som **ekstra rolle**, når en bruger tilknyttes en creator i admin (fase 6, `WordPressUserAccounts::grant_creator_role()`), så en eksisterende rolle (fx WooCommerce `customer`) bevares. Fjernes igen ved frakobling. Verificeret i Playground.

## Installation og synkronisering (ADR-022)

`MerchBoard\Core\Security\RoleManager` bringer WordPress' lagrede roller i overensstemmelse med `Capabilities` og bruger kun role-API'et (`get_role`, `add_role`, `add_cap`, `remove_cap`):

| Rolle | Adfærd |
| --- | --- |
| `merchboard_creator` | Oprettes, hvis den mangler. Holdes derefter på **præcis** de definerede capabilities: manglende tilføjes, og alt andet (fx tilføjet med en rolle-editor) fjernes og logges som `warning`. |
| `administrator` | Pluginets admin-capabilities tilføjes. Forældede `merchboard_*`-capabilities fjernes. WordPress' og andre plugins' capabilities røres aldrig. Mangler rollen, logges en `warning`, og creator-rollen synkroniseres alligevel. |
| Alle andre roller | Røres aldrig. |
| Brugere | Ændres aldrig. |

- **Hvornår:** ved aktivering (altid) og ved normal request, når `merchboard_roles_version` < `RoleManager::VERSION` (plugin-opdateringer udløser ikke aktivering). Koster én autoloaded option-læsning. Synkroniseringen er idempotent.
- **Ændring af definitionerne:** Opdatér `Capabilities` og hæv `RoleManager::VERSION`.
- **Fejl:** Kan creator-rollen ikke oprettes, logges `error`, og versionen registreres ikke (forsøges igen næste request).
- **Deaktivering:** Rolle og capabilities bevares. Uden pluginet er de inaktive, fordi intet tjekker dem, og brugere bevarer deres roller.
- **Uninstall:** Rolle og capabilities bevares (data-retention). Fjernelse sker kun med opt-in-sletning i fase 9 og kun for plugin-ejede roller/capabilities.

## Grænsen for fase 5

Fase 5 leverer **kun** permission-grundlaget: rolle, capabilities, synkronisering og konstanter. Den leverer **ikke** adgangskontrol til creator-data, frontend, REST, `/me`, dashboard-routing, login-redirect eller admin-UI. Senere kode kan spørge "har brugeren capability X?", men "er brugeren en aktiv creator?" besvares først af `CreatorContext` i fase 8.

## Adgangsregel for Creator Dashboard

En request får creator-adgang, **kun hvis alle** fire er opfyldt:

1. Brugeren er logget ind (WordPress-session).
2. `current_user_can( 'merchboard_access_dashboard' )`.
3. Der findes en creator-record med `user_id = get_current_user_id()`.
4. Creator-recorden har `status = active`.

Det implementeres **ét sted**: `MerchBoard\Modules\Access\CreatorContext` (✅ fase 8). Alle creator-facing controllers, REST-routes og services bruger den. Ingen kode må selv kombinere `current_user_can()`, et repository-opslag og et statustjek.

```text
creator-adgang = WP-capability merchboard_access_dashboard
               + creator-record med user_id = brugerens ID
               + status = active
```

| Metode | Svar |
| --- | --- |
| `current(): ?Creator` | Den aktive creator for den indloggede bruger, ellers `null` |
| `current_user_has_access(): bool` | Om den indloggede bruger har creator-adgang |
| `for_user( int $user_id ): ?Creator` / `user_has_access( int $user_id ): bool` | Samme regel for et kendt bruger-ID, fx server-side opgaver. Aldrig med et ID fra en request. |

- Creator'en findes **altid** server-side ud fra WordPress-brugerens ID (`merchboard_creators.user_id`), aldrig ud fra et creator-ID fra klienten (`?creator_id=`, `/me?creator_id=` osv.). Services, der modtager et creator-ID, skal stadig selv autorisere det mod `current()`.
- Capability-tjekket bruger WordPress' egen `user_can()` og dermed rolle-modellen. Brugere, der ikke findes, har ingen adgang.
- Almindelig "ingen adgang" giver `null`/`false`, aldrig en exception. En databasefejl logges som `error` og betyder "ingen adgang" (fail closed).
- Ikke memoiseret: hvert kald afspejler den aktuelle record, så en deaktivering virker på næste kald.
- Creator-adgang giver **ingen** administrative rettigheder. Creator-rollen har kun `read` + `merchboard_access_dashboard`. Administration kræver fortsat `merchboard_manage_creators`, og en administrator har ikke creator-adgang, medmindre vedkommende også har capability'en og en aktiv record.

Adgangsmatrix (testet med WordPress' rigtige rolle-model og MariaDB):

| WP-capability | Creator-record | Status | Adgang |
| --- | --- | --- | --- |
| nej | ingen | – | nej |
| ja | ingen | – | nej |
| nej | findes | active | nej |
| ja | findes | inactive | nej |
| ja | findes | active | **ja** |

Hvorfor både rolle **og** record? Rollen gør adgang synlig og håndterbar med standard-WP-værktøjer, fx brugerlisten. Recorden er domænet og bærer status. Kravet om begge betyder, at en fejlagtigt tildelt rolle alene ikke giver adgang, og at suspension virker øjeblikkeligt uden at ændre roller.

## Livscyklus

| Handling (admin) | Effekt |
| --- | --- |
| Opret creator (med eller uden bruger) | Record oprettes (default `inactive`). Med bruger tilføjes rollen `merchboard_creator` som ekstra rolle. Audit: `creator_created`. ✅ fase 6 |
| Deaktivér ✅ fase 6 | `status = inactive`. Rollen **bevares**, så genaktivering er nem, men adgangen forsvinder øjeblikkeligt pga. regel 4. Aktive sessioner afvises på næste request. Valgfrit: `WP_Session_Tokens::destroy_all()` for brugeren. Audit-log. |
| Genaktivér ✅ fase 6 | `status = active`. Audit: `creator_activated`. |
| Frakobl bruger ✅ fase 6 | `user_id = NULL`, og rollen fjernes (brugeren slettes ikke). Creator-recorden og historikken bevares på creator-ID'et. Audit-log. |
| WP-bruger slettes ✅ fase 8 | WP-bruger slettes → creator bevares → `user_id = NULL` → `status = inactive` → audit-records (systemhandling, actor NULL). Uanset hvorfra brugeren slettes (wp-admin, WP-CLI, REST, andre plugins). Detaljer i [DATABASE.md](DATABASE.md#relationen-til-wordpress-brugeren). |

Statusmodellen er bevidst minimal: `active` og `inactive` (ADR-020, [DATABASE.md](DATABASE.md#statusmodel)). Et separat "arkiveret"-tilstand tilføjes kun, hvis en fase får et konkret behov.

## Login-flow

- Creators logger ind via den normale WordPress-login (`wp-login.php`) eller WooCommerce "Min konto". Der bygges **intet** eget auth-system. Password-reset, rate-limit-plugins og 2FA-plugins virker dermed uændret.
- `login_redirect`-filter: Brugere med creator-adgang og **uden** `merchboard_manage` sendes til `/creator-dashboard/`. En eksplicit, valideret `redirect_to` til dashboardet respekteres.
- Creators uden admin-caps, der tilgår `/wp-admin/`, redirectes til dashboardet. `admin-ajax.php`, `admin-post.php` og `async-upload.php` undtages, fordi de kan bruges legitimt af frontend.
- Admin bar skjules for creators (`show_admin_bar` filter).
- Login-siden kan senere brandes via `login_enqueue_scripts`/`login_headerurl`. Det er styling og ikke et nyt system.

## Administratorer, der også er creators

Tilladt, fx til test. En administrator med en aktiv creator-record kan åbne dashboardet og ser *sine egne* creator-data. Der er ingen "se som anden creator"-funktion i v1. Impersonation kræver separat design med audit-log (fremtid).
