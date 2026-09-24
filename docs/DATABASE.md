# Database og datamodel

> Status: **Schema-version 2.** `merchboard_creators` (fase 3) og `merchboard_audit_log` (fase 6) findes, med migrationssystem og transaktioner. Øvrige tabeller er planlagte.

## Placering af data

| Data | Placering | Begrundelse |
| --- | --- | --- |
| Login-identitet, e-mail, password, navn | `wp_users` | WordPress' auth. Genbruges 100 %. |
| Rolle/capabilities | `wp_usermeta` (`wp_capabilities`) via roles API | Standard |
| Brugerpræferencer uden domæneværdi (fx dashboard-tema) | `wp_usermeta` (`merchboard_*`) | Hører til login-kontoen, ikke til creator'en |
| **Creator** (identitet, status, visningsnavn) | `merchboard_creators` (custom) ✅ | Domæneobjekt adskilt fra login-kontoen. Se ADR-005 og ADR-020. |
| Fleksible creator-felter (bio, sociale links, avatar-ID …) | `merchboard_creatormeta` (custom, WP metadata API), **fase 14** | Oprettes, når profilfelterne bygges (ingen tabel "for en sikkerheds skyld") |
| Plugin-settings | `wp_options`: `merchboard_settings` (ét array, autoload), fase 9 | Lille og læses på de fleste requests |
| Schemaversion | `wp_options`: `merchboard_db_version` ✅ | Styrer migrationer (ADR-019) |
| Migrationslås | `wp_options`: `merchboard_db_migration_lock` ✅ (midlertidig, ikke autoload) | Forhindrer samtidige migrationer |
| Migrationsfejl | transient `merchboard_db_migration_failed` ✅ (5 min) | Pause mellem automatiske forsøg og admin notice |
| Rolle `merchboard_creator` og pluginets capabilities | WordPress' `wp_user_roles`-option via role-API ✅ (fase 5) | WordPress-nativt. Aldrig direkte manipuleret. |
| Synkroniseret rolle-version | `wp_options`: `merchboard_roles_version` ✅ (autoload) | Styrer rolle-sync ved opdateringer (ADR-022) |
| Secrets (API-nøgler til integrationer) | `wp-config.php`-konstanter (foretrukket) eller option med `autoload = no` | Aldrig i repo, aldrig autoloaded, aldrig sendt til frontend |
| Produkter, varianter, ordrer, refunds og kunder | **WooCommerce** (HPOS) | Source of truth. Ingen kopi af masterdata. |
| Produkt → creator | WooCommerce product meta `_merchboard_creator_id` (fase 17) | Én creator pr. produkt i v1 |
| Salg pr. creator (historik) | `merchboard_sales` (custom ledger, fase 18) | Hurtige aggregeringer og uforanderlig attribution. Se [WOOCOMMERCE.md](WOOCOMMERCE.md). |
| Tekniske logs | **Ingen tabel.** WooCommerce-logger eller `error_log()` (fase 4, ADR-021) | Tekniske logs er drift, ikke forretningsdata |
| Audit trail (hvem ændrede hvad) | `merchboard_audit_log` (custom) ✅ fase 6 | Forretningsdata, append-only (ADR-023). Visning i fase 11. |
| Cache af aggregeringer | Transients/object cache | Afledte data, må forsvinde |

**Tommelfingerregel:** WordPress' datamodel bruges, hvor data er *konfiguration* eller *tilhører en eksisterende WP-entitet*. Custom tables bruges, hvor data er *domæneentiteter med relationer, volumen eller behov for effektive queries*.

## Data ownership: WordPress user vs. Creator

| | **WordPress user** | **Creator** |
| --- | --- | --- |
| Ejes af | WordPress | Merch Dashboard |
| Tabel | `wp_users` / `wp_usermeta` | `merchboard_creators` |
| Identitet | `wp_users.ID` | `merchboard_creators.id` (**creator-ID**) |
| Bruges til | Login, authentication, capabilities og kontoens livscyklus (password-reset, sletning) | Creator-identitet i systemet, status, samt fremtidige sales, payouts, produkt-relationer og statistik |
| Relation | – | `merchboard_creators.user_id` (nullable, UNIQUE) peger på den konto, der må logge ind som creator'en |

Regler:

1. **Creator-ID er den primære reference** for al fremtidig domænedata (`merchboard_sales.creator_id`, `_merchboard_creator_id` osv.). User-ID bruges aldrig som creator-reference.
2. Ændringer af WordPress-kontoen (e-mail, navn, password, sletning) ændrer **aldrig** creator'ens historiske domænedata.
3. Creator-data slettes **aldrig automatisk**: hverken ved sletning af brugeren, ved fjernelse af relationen, ved deaktivering af pluginet eller ved afinstallation (se nedenfor).

## Hvorfor ikke …

- **… creators som user meta alene?** Creator'en er en forretningsenhed (brand), som salg, produkter og senere payouts hænger på. Hvis login-kontoen skiftes, deaktiveres eller senere deles af et team, må historikken ikke knække. Med user meta alene bliver "creator" og "login" det samme.
- **… creators som custom post type?** Det er muligt og gratis admin-UI, men post-editor-UX'en passer dårligt, der er ingen unikhedsgaranti på `user_id`, `post_status` skal misbruges til creator-status, og der er risiko for at lække data via generiske post-queries og søgning. Creator-antallet er lille, så volumen er *ikke* argumentet. Klarhed og integritet er.
- **… en `merchboard_products`-tabel?** WooCommerce ejer produkter. Kopier giver synk-fejl.

## Fælles konventioner for tabeller

- Oprettes med `dbDelta()`. Charset/collation fra `$wpdb->get_charset_collate()` (samme som resten af WordPress-databasen, typisk `utf8mb4`). Intet hardcodet.
- Navn: `{$wpdb->prefix}merchboard_<navn>`. Prefix fra `$wpdb->prefix`, aldrig `wp_` hardcodet. Navne bygges **kun** af `MerchBoard\Core\Database\Tables::name()`, som afviser alt andet end registrerede tabeller.
- `VARCHAR(191)` for strenge, der kan blive indekseret (utf8mb4-indexgrænse i ældre MySQL).
- **Ingen fremmednøgler** (WordPress-konvention; `dbDelta` understøtter dem dårligt; sletning af WP-data må aldrig kaskadere ind i domænedata). Integritet håndhæves i PHP.
- **Tidsstempler** (ADR-019):
  - Kolonnetype `DATETIME`, format `Y-m-d H:i:s`, altid **UTC**.
  - Sættes af applikationen (repository) med `current_time( 'mysql', true )`, **ikke** med MySQL-defaults som `CURRENT_TIMESTAMP` / `ON UPDATE`. De afhænger af databasesessionens tidszone, fungerer forskelligt i SQLite og håndteres dårligt af `dbDelta()`.
  - `created_at` sættes én gang ved oprettelse. `updated_at` sættes ved oprettelse og skal sættes eksplicit af enhver repository-metode, der ændrer rækken (fase 6).
  - Læses som `DateTimeImmutable` i tidszonen `UTC`. Konvertering til sitets tidszone sker kun ved visning (`wp_date()`).
  - Uafhængigt af PHP-serverens tidszone: WordPress sætter `date_default_timezone_set( 'UTC' )`, og `current_time( …, true )` bruger GMT.

## Tabeller

### `merchboard_creators` ✅ (schema-version 1)

Creator som forretningsenhed. Indeholder **ingen** kundedata og er **ikke** en kopi af `wp_users`.

| Kolonne | Type | Noter |
| --- | --- | --- |
| `id` | `bigint(20) unsigned NOT NULL AUTO_INCREMENT` PK | Creator-ID. Uafhængigt af user-ID. |
| `user_id` | `bigint(20) unsigned DEFAULT NULL` | Relation til WordPress-login. **Nullable, UNIQUE.** |
| `display_name` | `varchar(191) NOT NULL` | Creator-navn i systemet. 1-191 tegn, trimmet. Gemmes rå og escapes ved output. |
| `status` | `varchar(20) NOT NULL` | `active` eller `inactive` (se statusmodel) |
| `created_at` | `datetime NOT NULL` | UTC |
| `updated_at` | `datetime NOT NULL` | UTC |

Indexes: `PRIMARY KEY (id)`, `UNIQUE KEY user_id (user_id)`, `KEY status (status)`.

Kilde: `src/Core/Database/Migrations/CreateCreatorsTable.php`.

**Bevidst udeladt** (tilføjes med ny migration, når en fase har brug for det): `slug` (offentlige URL'er), `created_by` (audit håndteres af logs i fase 4), profilfelter (creatormeta i fase 14).

#### Relationen til WordPress-brugeren

| Spørgsmål | Beslutning |
| --- | --- |
| Nullable? | **Ja.** En creator kan eksistere uden login (fx oprettet før kontoen, eller efter kontoen er slettet/frakoblet). |
| Højst én creator pr. bruger? | **Ja, håndhævet i databasen** med `UNIQUE(user_id)`. MySQL/MariaDB (og SQLite) tillader mange `NULL` i et UNIQUE-index, så mange creators kan være ukoblede. |
| "Én aktiv creator pr. bruger" | UNIQUE er strengere: en bruger kan højst være koblet til én creator-record, uanset status. Skal en bruger kobles til en anden creator, frakobles den gamle først (`user_id = NULL`). Dens historik bevares på dens creator-ID. Det er enklere og databasegaranteret frem for en betinget unikhed, som MySQL ikke understøtter direkte. |
| Brugeren slettes | **Fase 8** (flyttet fra fase 6): hook på `deleted_user` sætter `user_id = NULL` og `status = inactive` for den tilknyttede creator. Creator-recorden og al historik bevares. **Indtil da** peger `user_id` på en ikke-eksisterende bruger, hvis en tilknyttet bruger slettes. Admin-listen viser "Unknown user #ID", og creator-data påvirkes ikke. Administratoren kan frakoble manuelt. |
| Relationen fjernes | `user_id = NULL` (admin: "— No WordPress user —"). Creator-recorden, WordPress-brugeren og historikken bevares. Brugeren mister rollen `merchboard_creator` (fase 6). |
| Duplikatkobling | Afvises først af `CreatorService` med en forståelig fejl (`user_already_linked`), dernæst af databasen som sidste sikkerhedsnet (`DatabaseException` uden databasedetaljer). Admin-formularen tilbyder kun brugere, der ikke er koblet til en anden creator. |

#### Statusmodel

`MerchBoard\Modules\Creators\CreatorStatus` (PHP backed enum) er den eneste kilde til gyldige værdier:

| Værdi | Betydning |
| --- | --- |
| `active` | Creator'en er i forretning med virksomheden |
| `inactive` | Ikke aktiv. Data bevares. |

Ingen `NULL`, `0`/`1` eller andre magic strings. Ukendte værdier i databasen afvises ved læsning (`DatabaseException`). Nye tilstande tilføjes som nye enum-cases, når en fase har brug for dem. Kolonnen er `VARCHAR`, så det kræver ingen schemaændring. Adgangsreglen (fase 8) kræver `active`.

### `merchboard_creatormeta` (fase 14)

Standard WordPress meta-tabel (`meta_id`, `merchboard_creator_id`, `meta_key`, `meta_value`), så `add_metadata( 'merchboard_creator', … )`, `get_metadata()` og meta-cachen virker. Registreres som `$wpdb->merchboard_creatormeta`. Meta-keys whitelistes i `CreatorService`. Flyttet fra fase 3 til fase 14, hvor profilfelterne bygges.

### `merchboard_audit_log` ✅ (schema-version 2, ADR-023)

Append-only audit trail for **forretningsændringer**: hvem ændrede hvad, hvornår og på hvilken creator. Det er ikke tekniske logs (de går via `Logger`, ADR-021).

| Kolonne | Type | Noter |
| --- | --- | --- |
| `id` | `bigint(20) unsigned NOT NULL AUTO_INCREMENT` PK | |
| `created_at` | `datetime NOT NULL` | UTC, sat af applikationen |
| `action` | `varchar(50) NOT NULL` | En `AuditAction`-værdi (lukket sæt, se nedenfor) |
| `actor_user_id` | `bigint(20) unsigned DEFAULT NULL` | WordPress-brugeren, der udførte handlingen. **NULL = systemhandling** (intet opfundet bruger-ID) |
| `creator_id` | `bigint(20) unsigned DEFAULT NULL` | Berørt creator. NULL for hændelser uden creator (fx settings, senere) |
| `context` | `longtext DEFAULT NULL` | Lille JSON-objekt, renset via `LogContext`. NULL når tomt. |

Indexes: `KEY(created_at)`, `KEY(action)`, `KEY(creator_id)`. Ingen fremmednøgler. IP-adresser og user agent gemmes **ikke**.

**Actions** (`MerchBoard\Core\Audit\AuditAction`, én record pr. semantisk ændring):

| Action | Context |
| --- | --- |
| `creator_created` | `status` og `user_id` (eller null) |
| `creator_updated` | `changed_fields` (feltnavne, **ingen værdier**) |
| `creator_activated` / `creator_deactivated` | `previous_status` |
| `creator_user_linked` / `creator_user_unlinked` | `user_id` (hhv. den nye og den tidligere) |

En redigering, der både omdøber og deaktiverer, skriver `creator_updated` og `creator_deactivated`.

**Privacy:** Display names kan være en persons rigtige navn. Derfor gemmes kun *hvilke* felter der ændredes, ikke gamle eller nye værdier. Det aktuelle navn står i `merchboard_creators`. Aldrig passwords, tokens, cookies, sessioner, e-mail, request bodies, `$_POST` eller `$_SERVER`.

**Append-only:** `AuditRepository` har kun `append()` og `for_creator()` (læsning til tests og den kommende visning). Der er ingen update eller delete, og ingen admin-funktion ændrer records.

**Retention og sletning:** Records bevares ubegrænset. De slettes kun ved opt-in "Slet alle data ved afinstallation" (fase 9), hvor tabellen er med i `Tables::all()`. Retention-politik (fx automatisk sletning efter N år) er et åbent spørgsmål.

### `merchboard_sales` (fase 18)

Uforanderlig ledger. Én række pr. (ordrelinje × hændelse). Refererer til **creator-ID**. Se [WOOCOMMERCE.md](WOOCOMMERCE.md#sales-ledger).

| Kolonne | Type | Noter |
| --- | --- | --- |
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` PK | |
| `creator_id` | `BIGINT UNSIGNED NOT NULL` | Snapshot ved salgstidspunkt |
| `order_id` | `BIGINT UNSIGNED NOT NULL` | WooCommerce-ordre |
| `order_item_id` | `BIGINT UNSIGNED NOT NULL` | |
| `refund_id` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` | 0 for salg, ellers refund-ID |
| `product_id` | `BIGINT UNSIGNED NOT NULL` | Parent-produkt |
| `variation_id` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` | |
| `type` | `VARCHAR(10) NOT NULL` | `sale`, `refund` eller `reversal` |
| `quantity` | `INT NOT NULL` | Negativ ved refund/reversal |
| `net_amount` | `DECIMAL(19,4) NOT NULL` | Linjetotal efter rabat, ekskl. moms og fragt. Negativ ved refund. |
| `currency` | `CHAR(3) NOT NULL` | |
| `occurred_at` | `DATETIME NOT NULL` | UTC. Tidspunkt for salg/refund. |
| `created_at` | `DATETIME NOT NULL` | UTC. Indsættelsestidspunkt. |

Indexes: `UNIQUE(order_item_id, refund_id, type)` (idempotens), `KEY(creator_id, occurred_at)`, `KEY(order_id)`.

### Fremtidige (ikke designet i detaljer)

- `merchboard_creator_members` – hvis flere brugere (fx en manager) skal have adgang til samme creator. Erstatter `user_id`-kolonnen. Ændringen bliver lokal, fordi al opslag af "nuværende creator" går gennem `CreatorContext`.
- `merchboard_product_creators` – hvis produkter skal kunne deles mellem creators med procentvis split.
- `merchboard_sales_daily` – præ-aggregeret statistik, **kun** hvis målinger viser, at queries på `merchboard_sales` bliver for langsomme.
- `merchboard_payouts`, `merchboard_payout_items` – afregning.
- `merchboard_integrations_*` – synkroniseringsstatus for eksterne tjenester.

## Schemaversion og migrationer ✅ (ADR-019)

| Del | Implementering |
| --- | --- |
| Version | Heltal. Gemt i option `merchboard_db_version` (autoload), `0`/manglende = ikke installeret. Nuværende version: **1**. |
| Migrationsliste | `MerchBoard\Core\Database\Schema::migrations()`: `array<int, Migration>` nøglet på den schema-version, migrationen giver. Højeste nøgle = forventet version. |
| Migration | Klasse, der implementerer `MerchBoard\Core\Database\Migration::up( wpdb )`. Kaster `DatabaseException`, hvis ændringen ikke kan verificeres. **Skal være idempotent.** |
| Runner | `MerchBoard\Core\Database\Migrator`: kører manglende migrationer i stigende rækkefølge og gemmer versionen **efter hver succesfuld migration**. Fejler en migration, stopper kørslen, og versionen forbliver på sidste succes. |
| Lås | Option `merchboard_db_migration_lock` oprettes atomisk med `add_option()` (fejler, hvis den findes). Låse ældre end 5 minutter anses for forladte og overtages. Låsen frigives altid (`finally`). |
| `dbDelta()` | Bruges *inde i* migrationer til at oprette og ændre tabeller. Migrationssystemet tilføjer kun rækkefølge, versionering og lås, fordi `dbDelta()` ikke kan omdøbe eller droppe kolonner, flytte data eller køre engangsopgaver. |
| Ingen `down()` | Migrationer er fremadrettede. Rollback sker via database-backup. |

**Sådan ændres schemaet:** Tilføj en ny migrationsklasse i `src/Core/Database/Migrations/`, registrér den med næste heltal i `Schema::migrations()`, og opdatér denne fil samt CHANGELOG. En released migration ændres eller omnummereres aldrig.

### Hvornår migrerer pluginet?

| Situation | Trigger | Kode |
| --- | --- | --- |
| Aktivering (også gentagen) | `register_activation_hook` → `Plugin::activate()` → `Installer::install()` | Kører altid Migrator. Idempotent. |
| Plugin-opdatering (ingen aktivering) | `plugins_loaded` → `Plugin::boot()` → `Installer::maybe_upgrade()`, **før** modulerne registreres | Koster én autoloaded option-læsning, når schemaet er aktuelt |
| Efter en fejl | `maybe_upgrade()` springer over i 5 minutter (transient) for ikke at gentage en fejlende schemaændring på hver request. Aktivering forsøger altid. | Administratorer (`activate_plugins`) ser en admin notice uden databasedetaljer. Fejlen logges én gang som `error` via `Logger` (fase 4) med schemaversion, migrationsklasse, generisk årsag og en renset, afkortet DDL-fejl. |
| Multisite | Netværksaktivering installerer kun det aktuelle site. Øvrige sites installeres dovent ved første request (`maybe_upgrade`), fordi tabellerne bruger det enkelte sites `$wpdb->prefix`. Multisite er ikke officielt understøttet. | |

## Databaseadgang (repositories)

- `MerchBoard\Modules\Creators\CreatorRepository`: `find`, `find_by_user_id` og `create` (fase 3), samt `rename`, `set_status`, `link_user`, `unlink_user`, `paginate` (whitelisted sortering, max 100 pr. side) og `linked_user_ids` (fase 6). Hver skriveoperation opdaterer `updated_at`. Der er ingen delete.
- `MerchBoard\Core\Audit\AuditRepository` (fase 6): kun `append` og `for_creator`.
- **Transaktioner** (`MerchBoard\Core\Database\Transaction`, fase 6): `START TRANSACTION` / `COMMIT` / `ROLLBACK` via `$wpdb`, ikke re-entrant. Bruges af `CreatorService`, så creator-ændring og audit-record committes sammen eller slet ikke (ADR-023). Rollback af begge skrivninger er **verificeret i Playground/SQLite**. På MySQL/MariaDB forudsætter det InnoDB (default for nye tabeller siden MySQL 5.5 og WordPress' default). Med MyISAM ignoreres transaktioner. Derfor logges en fejlet audit-skrivning altid, så intet skjules. **Ikke verificeret på MySQL/MariaDB.**
- Al SQL går gennem repositories og `$wpdb->prepare()`. Tabelnavne kommer fra `Tables` og indsættes med `%i` (WP ≥ 6.2). Inserts bruger `$wpdb->insert()` med eksplicitte formater.
- Kolonner vælges eksplicit (ingen `SELECT *`), så nye kolonner aldrig eksponeres ved et uheld.
- ID'er valideres (positive heltal) i repositoriet, *før* der forespørges. Rækker valideres ved læsning (status-enum, tidsstempelformat).
- Databasefejl bliver til `DatabaseException` med generiske beskeder (ingen SQL, `last_error` eller værdier).
- Repositories udfører **ingen authorization**. Se [SECURITY.md](SECURITY.md#datalaget-fase-3).
- `ORDER BY` og kolonner fra input valideres mod en whitelist (når lister tilføjes).
- Lister er altid paginerede (default 20, max 100).
- Aggregeringer til dashboardet caches (transient pr. creator og periode) og invalideres ved nye ledger-rækker.

## MySQL/MariaDB vs. Playground (SQLite)

Produktionsschemaet er skrevet til **MySQL/MariaDB via `$wpdb`**. Playground bruger SQLite via WordPress' SQLite-integration, som oversætter MySQL-SQL. Der er ingen SQLite-specifikke tilpasninger i schemaet.

| Egenskab | Verificeret hvordan |
| --- | --- |
| Tabel, kolonner og indexes oprettes via `dbDelta()` | **Playground/SQLite (runtime)**: begge tabeller findes med alle kolonner. Indexes `user_id`/`status` (creators) og `created_at`/`action`/`creator_id` (audit) findes. Migration 1 → 2 kørte ved aktivering. |
| Transaktion ruller creator- og audit-skrivning tilbage | **Playground/SQLite (runtime)**, probe med fremtvunget fejl efter begge inserts |
| Transaktioner på MySQL/MariaDB (InnoDB-krav, isolation) | **Ikke verificeret** |
| `UNIQUE(user_id)` afviser duplikat og tillader flere `NULL` | **Playground/SQLite (runtime)** |
| Idempotent genkørsel af migration 1 og opgradering fra version 0 → 1 uden datatab | **Playground/SQLite (runtime)** og unit-tests |
| Data bevares ved deaktivering og genaktivering | **Playground/SQLite (runtime)** |
| Prepared queries (`%i`, `%d`, `%s`) og UTC-tidsstempler | Unit-tests og Playground |
| `dbDelta()`-adfærd på MySQL/MariaDB (typer, `bigint(20)`-displaybredde på MySQL 8, index-sammenligning ved fremtidige ændringer) | **Ikke verificeret.** Kun statisk (schemaet følger WordPress core-konventioner). |
| MySQL strict mode (`NOT NULL` uden default på `datetime`) | **Ikke verificeret.** Repositoriet sætter altid værdierne. |
| Charset/collation fra en rigtig MySQL-installation | **Ikke verificeret.** |
| Samtidige migrationer (låsens atomicitet under reel samtidighed) | **Ikke verificeret.** Kun unit-testet. |

**Autoritativ MySQL-verifikation** sker i CI (integrationstests med MySQL-service, fase 7) og på staging (fase 16). Indtil da må MySQL-kompatibilitet ikke betragtes som bevist.

## Afinstallation og datalivscyklus

| Handling | Effekt på data |
| --- | --- |
| **Deaktivering** | Ingen. Data, tabeller og options bevares. (Fase 4 og 12 tilføjer oprydning af cron og rewrite rules, men aldrig data.) |
| **Afinstallation** (`uninstall.php`) | **Ingen, som standard.** `merchboard_creators`, `merchboard_audit_log`, `merchboard_db_version`, `merchboard_roles_version`, rollen `merchboard_creator` og administratorens `merchboard_*`-capabilities bevares. Creator-data er forretningsdata, som fremtidig salgshistorik og payouts refererer til. |
| **Permanent sletning** | Bliver en eksplicit opt-in (setting "Slet alle data ved afinstallation", default fra) i **fase 9**. Selve sletterutinen implementeres sammen med settingen, ikke før. `Tables::all()` giver den komplette liste over plugin-tabeller til den rutine. |
| **Creator "slettes"** | Status `inactive` (soft). Admin-UI'et har bevidst **ingen** "Delete"-funktion (fase 6). Hard delete er ikke understøttet, fordi audit trail og fremtidig salgshistorik refererer til creator-ID'et. Permanent sletning af alle data er opt-in i fase 9. |
| **WordPress-bruger slettes** | Se relationen ovenfor (hook i fase 8). Creator- og audit-data bevares. |
| **GDPR** | Integration med WordPress' personal data exporter/eraser for creator-data (fase efter MVP). |
