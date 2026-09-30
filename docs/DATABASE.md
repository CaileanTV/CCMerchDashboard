# Database og datamodel

> Status: **Schema-version 5.** `merchboard_creators` (fase 3), `merchboard_audit_log` (fase 6), `merchboard_creatormeta` (fase 14), `merchboard_creator_categories` (fase 17) samt `merchboard_sales` og `merchboard_sales_cycles` (fase 18, endnu uden registrering) findes, med migrationssystem og transaktioner. Øvrige tabeller er planlagte. Verificeret mod **MariaDB 10.11.19**, **MySQL 8.0.46** (CI) og SQLite (Playground) (se [nedenfor](#mysqlmariadb-vs-playground-sqlite)).

## Placering af data

| Data | Placering | Begrundelse |
| --- | --- | --- |
| Login-identitet, e-mail, password, navn | `wp_users` | WordPress' auth. Genbruges 100 %. |
| Rolle/capabilities | `wp_usermeta` (`wp_capabilities`) via roles API | Standard |
| Brugerpræferencer uden domæneværdi (fx dashboard-tema) | `wp_usermeta` (`merchboard_*`) | Hører til login-kontoen, ikke til creator'en |
| **Creator** (identitet, status, visningsnavn) | `merchboard_creators` (custom) ✅ | Domæneobjekt adskilt fra login-kontoen. Se ADR-005 og ADR-020. |
| Fleksible creator-felter (bio, sociale links; senere avatar-ID) | `merchboard_creatormeta` (custom, WP metadata API) ✅ fase 14, schemaversion 3 | Én række pr. nøgle. Kun whitelistede nøgler (`CreatorMetaStore`) |
| Plugin-settings | `wp_options`: `merchboard_settings` (ét array, autoload) ✅ fase 9. Nøgler: `delete_data_on_uninstall` (bool, default `false`, tæller kun som præcis `true`) og `dashboard_slug` (✅ fase 12, streng, default `creator-dashboard`, ugyldig værdi giver default). ✅ Fase 18, trin 6 (runtime-verificeret i Merch Dashboard Local med HPOS og legacy, ADR-038 statusnote "trin 6 afsluttet"; ADR-038 S1–S14): `counted_order_statuses` (liste af ordrestatus-slugs uden `wc-`, default `processing` og `completed`; core validerer kun formatet: `a-z`, `0-9`, `_` og `-` som WordPress' `sanitize_key()`, ikke tom, uden `wc-`-præfiks og højst 17 tegn, fordi `wp_posts.post_status` og HPOS' `wc_orders.status` begge er `varchar(20)` inkl. `wc-` (ADR-038, statusnote 2026-09-30); dubletter fjernes og rækkefølgen normaliseres; WooCommerce-modulet afgør registrering og afvisningsliste) | Lille og læses på de fleste requests |
| Schemaversion | `wp_options`: `merchboard_db_version` ✅ | Styrer migrationer (ADR-019) |
| Migrationslås | `wp_options`: `merchboard_db_migration_lock` ✅ (midlertidig, ikke autoload) | Forhindrer samtidige migrationer |
| Migrationsfejl | transient `merchboard_db_migration_failed` ✅ (5 min) | Pause mellem automatiske forsøg og admin notice |
| Rolle `merchboard_creator` og pluginets capabilities | WordPress' `wp_user_roles`-option via role-API ✅ (fase 5) | WordPress-nativt. Aldrig direkte manipuleret. |
| Synkroniseret rolle-version | `wp_options`: `merchboard_roles_version` ✅ (autoload) | Styrer rolle-sync ved opdateringer (ADR-022) |
| Secrets (API-nøgler til integrationer) | `wp-config.php`-konstanter (foretrukket) eller option med `autoload = no` | Aldrig i repo, aldrig autoloaded, aldrig sendt til frontend |
| Produkter, varianter, ordrer, refunds og kunder | **WooCommerce** (HPOS) | Source of truth. Ingen kopi af masterdata. |
| Produkt → creator | WooCommerce product meta `_merchboard_creator_id` (fase 17) | **Eneste ejerskabsrelation.** Én creator pr. produkt i v1. Pluginet ejer nøglen: bevares ved deaktivering af pluginet og WooCommerce, og slettes kun ved opt-in-uninstall (ADR-030) |
| Koblingens oprindelse | WooCommerce product meta `_merchboard_creator_link_source` (`admin` | `category`) ✅ fase 17, trin 8 (ADR-033) | Skrives og fjernes sammen med `_merchboard_creator_id`: `admin` ved manuelle koblinger, `category` kun af kategori-afstemningen (`CreatorCategoryReconciler`). Mangler den, eller har den en anden værdi, er koblingen manuel og røres aldrig af afstemningen. Ikke en ejerskabsrelation. Pluginet ejer nøglen og sletter den kun ved opt-in-uninstall |
| Creator ↔ produktkategori (mapping) | `merchboard_creator_categories` (custom) ✅ fase 17, schema-version 4 (ADR-033) | Vedvarende administrativ regel for automatiske koblinger. Aldrig bevis for ejerskab eller adgang |
| Salg pr. creator (historik) | `merchboard_sales` (custom ledger, fase 18) | Hurtige aggregeringer og uforanderlig attribution. Se [WOOCOMMERCE.md](WOOCOMMERCE.md). |
| Tekniske logs | **Ingen tabel.** WooCommerce-logger eller `error_log()` (fase 4, ADR-021) | Tekniske logs er drift, ikke forretningsdata |
| Audit trail (hvem ændrede hvad) | `merchboard_audit_log` (custom) ✅ fase 6 | Forretningsdata, append-only (ADR-023). Read-only visning ✅ fase 11 (Merch Dashboard → Audit log). |
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
- **Storage engine angives ikke** (som i WordPress core). Nye tabeller får serverens default, som er InnoDB på alle understøttede MySQL/MariaDB-versioner. Pluginet kræver en transaktionel engine og nægter at skrive uden (se [Forudsætninger](#forudsætninger-for-mysqlmariadb), ADR-024).
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
| Brugeren slettes | ✅ **Fase 8:** WP-bruger slettes → creator bevares → `user_id = NULL` → `status = inactive` → audit-records (systemhandling, actor NULL). Hook `deleted_user` (efter WordPress har slettet brugeren, uanset kilde). `CreatorService::release_deleted_user()` virker kun for brugere, der ikke længere findes, og frigiver med én betinget `UPDATE … WHERE id = … AND user_id = …`. En samtidig gen-kobling til en anden bruger vinder derfor. Frigivelse og audit (`creator_user_unlinked` og, hvis aktiv, `creator_deactivated`, begge med `reason: user_deleted`) sker i én transaktion (fail closed uden transaktionel engine, ADR-024). Fejler den, bliver WordPress' sletning ikke afbrudt: fejlen logges som `error`, og creator'en beholder et `user_id`, der ikke findes. Det giver ingen adgang og vises som "Unknown user #ID". Roller røres ikke. |
| Relationen fjernes | `user_id = NULL` (admin: "— No WordPress user —"). Creator-recorden, WordPress-brugeren og historikken bevares. Brugeren mister rollen `merchboard_creator` (fase 6). |
| Duplikatkobling | Afvises først af `CreatorService` med en forståelig fejl (`user_already_linked`), dernæst af databasen som sidste sikkerhedsnet (`DatabaseException` uden databasedetaljer). Admin-formularen tilbyder kun brugere, der ikke er koblet til en anden creator. |

#### Statusmodel

`MerchBoard\Modules\Creators\CreatorStatus` (PHP backed enum) er den eneste kilde til gyldige værdier:

| Værdi | Betydning |
| --- | --- |
| `active` | Creator'en er i forretning med virksomheden |
| `inactive` | Ikke aktiv. Data bevares. |

Ingen `NULL`, `0`/`1` eller andre magic strings. Ukendte værdier i databasen afvises ved læsning (`DatabaseException`). Nye tilstande tilføjes som nye enum-cases, når en fase har brug for dem. Kolonnen er `VARCHAR`, så det kræver ingen schemaændring. Adgangsreglen (fase 8) kræver `active`.

### `merchboard_creatormeta` ✅ (schema-version 3, fase 14)

Standard WordPress meta-tabel (`meta_id`, `merchboard_creator_id`, `meta_key` (indeks 191), `meta_value` longtext), så `*_metadata( 'merchboard_creator', … )` og meta-cachen (gruppe `merchboard_creator_meta`) virker. `CreatorMetaStore` registrerer `$wpdb->merchboard_creatormeta` og whitelister nøglerne:

| Nøgle | Værdi |
| --- | --- |
| `bio` | Ren tekst, maks. 1000 tegn. Slettes ved tom værdi |
| `social_links` | Array platform → https-URL, kun satte platforme. Slettes, når alle er tomme. Læses gennem `SocialLinks::from_stored()`, så korrupte værdier ignoreres |

Creator-meta `product_category_id` (planlagt i ADR-031) indføres **ikke**. Kategori-mappingen ligger i `merchboard_creator_categories` (ADR-033).

Skrivninger går gennem `CreatorProfileService` i samme transaktion som eventuel omdøbning og audit (InnoDB, ADR-024) og verificeres ved genlæsning (`update_metadata()` returnerer `false` både ved fejl og ved uændret værdi). Værdier slashes før `update_metadata()`, som ellers ville fjerne backslashes. Tabellen er med i `Tables::all()` (engine-tjek og opt-in-uninstall). Ingen FK.

### `merchboard_creator_categories` ✅ (schema-version 4, fase 17, ADR-033)

Vedvarende administrativ mapping fra en WooCommerce-produktkategori til en creator. Den styrer den automatiske kategori-afstemning, men er **aldrig** bevis for ejerskab eller adgang. Det er kun `_merchboard_creator_id`.

| Kolonne | Type | Noter |
| --- | --- | --- |
| `term_id` | `bigint(20) unsigned NOT NULL` PK | `product_cat`-term. Primærnøglen sikrer én creator pr. kategori |
| `creator_id` | `bigint(20) unsigned NOT NULL`, KEY | Creator-ID. En creator kan teknisk have flere kategorier; fase 17's UI og service tillader én |
| `created_at` | `datetime NOT NULL` | UTC |
| `created_by` | `bigint(20) unsigned DEFAULT NULL` | WordPress-brugeren, der oprettede mappingen |

Skrives kun af `CreatorCategoryService` (`merchboard_manage_creators`) via `CreatorCategoryRepository`, med mapping og audit i samme transaktion. Kun eksisterende `product_cat`-terms, ikke standardkategorien (`default_product_cat`); kun eksisterende, aktive creators; i fase 17 højst én kategori pr. creator. En kategori, der er mappet til en anden creator, afvises (ingen stille flytning). Gentagen oprettelse og fjernelse af en ikke-eksisterende mapping er no-ops uden audit. Servicen læser og skriver aldrig produktmeta og afstemmer ingen produkter. En inaktiv creator beholder sine mappings. Rækken slettes ved fjernelse af mappingen og når kategorien slettes i WooCommerce (`pre_delete_term`). Oprettelse og sletning auditeres (`creator_category_linked`/`creator_category_unlinked`). Tabellen kommer med i `Tables::all()` (engine-tjek og opt-in-uninstall). Produktkategorierne selv røres aldrig. Ingen FK.

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
| `creator_activated` / `creator_deactivated` | `previous_status`. Ved slettet bruger desuden `reason: user_deleted` (fase 8) |
| `creator_user_linked` / `creator_user_unlinked` | `user_id` (hhv. den nye og den tidligere). Ved slettet bruger desuden `reason: user_deleted` (fase 8) |
| `settings_updated` (fase 9) | `setting` (navn) og den nye værdi (fx `enabled: true`). `creator_id` er NULL. |
| `product_creator_linked` / `product_creator_unlinked` (fase 17) | `product_id`, `source` (`admin` | `category`, ADR-033), ved linked `previous_creator_id`, evt. `previous_link: invalid`. For `source: category` desuden `reason` (`category_added`, `category_removed`, `category_moved`, `mapping_created`, `mapping_removed`, `creator_activated`, `term_deleted`, `ambiguous`), `category_ids`, evt. `trigger_user_id` og ved en fuld kørsel `run_id`; actor er NULL (system). En flytning skrives som `product_creator_unlinked` + `product_creator_linked`, begge med `reason: category_moved`. "Keep as manual" skrives som `product_creator_linked` med samme creator, `source: admin`, `previous_source: category` og ved fjernelse af en mapping `term_id` og `choice: keep_as_manual`; actor er administratoren |
| `creator_category_linked` / `creator_category_unlinked` (fase 17, ADR-033) | `term_id`. Ved unlinked desuden `reason`: `admin` med valget `choice` (`remove_links` eller `keep_as_manual`), eller `term_deleted`: kategorien blev slettet i WooCommerce (systemarbejde med actor NULL og evt. `trigger_user_id`), eller en administrator ryddede en mapping op, hvis kategori var slettet (actor administratoren, med `choice`). `creator_id` er creatoren, actor ellers den aktuelle bruger |
| `category_sync_completed` (fase 17, ADR-033) | `run_id`, `dry_run` (altid `false`), `trigger` (anledning, fx `full_sync` eller `mapping_created`), evt. `trigger_user_id`, `scope` (`all`, `category` eller `mapping`) med evt. `term_id`/`creator_id`, optællinger pr. resultat (`linked`, `moved`, `unlinked`, `unchanged`, `skipped_manual`, `ambiguous`, `skipped_inactive`, `errors`) og `total`. Skrives én gang, når alle batches i en kørsel er behandlet (også med fejl i enkelte produkter), aldrig ved en afbrudt eller opgivet kørsel eller en tørkørsel. Kørsler for ét produkt skriver den ikke. `creator_id` og actor er NULL. Kun faktiske ændringer skrives som produkt-records; produkter uden ændring skriver intet |

En redigering, der både omdøber og deaktiverer, skriver `creator_updated` og `creator_deactivated`.

**Privacy:** Display names kan være en persons rigtige navn. Derfor gemmes kun *hvilke* felter der ændredes, ikke gamle eller nye værdier. Det aktuelle navn står i `merchboard_creators`. Aldrig passwords, tokens, cookies, sessioner, e-mail, request bodies, `$_POST` eller `$_SERVER`.

**Append-only:** `AuditRepository` har kun `append()` og `for_creator()` (læsning til tests og den kommende visning). Der er ingen update eller delete, og ingen admin-funktion ændrer records.

**Retention og sletning:** Records bevares ubegrænset. De slettes kun ved opt-in "Slet alle data ved afinstallation" (✅ fase 9, ADR-025), hvor tabellen er med i `Tables::all()`. Uninstall skriver ingen audit-records. **v0.1.0 har bevidst ingen automatisk retention** (ADR-029): ingen cron og ingen sletning efter N år. En retention-politik for produktion skal besluttes før produktionsbrug.

### `merchboard_sales` og `merchboard_sales_cycles` (fase 18, design-låst)

> **Status: design-låst (ADR-037, ADR-038 med supplement 2026-09-28). Tabellerne findes fra schema-version 5 (migration 5) med `SalesRepository` (lagring, lås og transaktion); fra trin 5 skriver `SalesSynchronizer` rækkerne fra WooCommerce-hooks (runtime-verificeret med HPOS og legacy; commit `366714c`, CI grøn inkl. MySQL 8.0.46).** Låsens timeout er 5 sekunder (`SalesRepository::LOCK_TIMEOUT`). Hver INSERT er betinget af `CONNECTION_ID()` = låsens forbindelse, så intet skrives uden for transaktionen, hvis `wpdb` genforbinder. `@@in_transaction` bruges ikke (findes ikke på MySQL 8.0; ADR-038, B4).

**`merchboard_sales`** er en append-only ledger (ADR-037). Historiske rækker ændres og slettes aldrig; afvigelser rettes med nye rækker. Refererer til **creator-ID**. Se [WOOCOMMERCE.md](WOOCOMMERCE.md#sales-ledger).

**Invariant (ADR-038):** for en ordre i en tællende status er ledgerens balance for hver ordrelinje med creator lig WooCommerce' aktuelle netto for linjen efter registrerede refunds; for en ordre, der ikke er tællende, er balancen 0. Balancen er summen af de relevante rækker.

**Salgscyklusser (ADR-038):** gælder hele ordren. Cyklus 1 registreres som brutto-salgsbalancen plus eksisterende refunds som `refund`-rækker (resultat: nettoen); senere cyklusser starter med den resterende netto-balance og åbnes kun ved en positiv ny balance (ADR-038, statusnote 2026-09-29). Asymmetrien er bevidst: ved cyklus 1 står refunds endnu ikke i ledgeren, ved senere cyklusser gør de.

| Kolonne | Type | Noter |
| --- | --- | --- |
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` PK | |
| `event_key` | `VARCHAR(64) NOT NULL` | Deterministisk hændelsesidentitet (se nedenfor). `UNIQUE`. |
| `creator_id` | `BIGINT UNSIGNED NOT NULL` | Snapshot af produktets creator-kobling ved registreringen. Ved backfill den aktuelle kobling (ADR-037 pkt. 6). |
| `order_id` | `BIGINT UNSIGNED NOT NULL` | WooCommerce-ordre |
| `order_item_id` | `BIGINT UNSIGNED NOT NULL` | Den oprindelige ordrelinje (for refunds via refund-linjens `_refunded_item_id`) |
| `cycle` | `INT UNSIGNED NOT NULL` | Ordrens salgscyklus (1, 2, …), jf. `merchboard_sales_cycles` |
| `refund_id` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` | Refund-ID for `refund` og refund-relaterede `correction`, ellers 0 |
| `product_id` | `BIGINT UNSIGNED NOT NULL` | Parent-produkt |
| `variation_id` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` | |
| `item_name` | `VARCHAR(191) NOT NULL DEFAULT ''` | Snapshot af ordrelinjens navn (afkortet til 191 tegn). Privacy: kan i særlige tilfælde indeholde personoplysninger; ingen kundefelter eller anden PII. |
| `type` | `VARCHAR(10) NOT NULL` | `sale` (positiv), `refund` (negativ), `reversal` (negativ) eller `correction` (positiv eller negativ) |
| `correction_reason` | `VARCHAR(32) NULL` | Påkrævet for `correction`, ellers NULL (se nedenfor) |
| `quantity` | `INT NOT NULL` | Fortegn efter typen |
| `net_amount` | `DECIMAL(19,4) NOT NULL` | Ordrelinjens beløb efter rabatter, ekskl. moms, fragt og gebyrer, fra WooCommerce CRUD-data. Fortegn efter typen. |
| `currency` | `CHAR(3) NOT NULL` | Ordrens valuta |
| `occurred_at` | `DATETIME NOT NULL` | UTC. Se fallback-reglerne nedenfor. |
| `created_at` | `DATETIME NOT NULL` | UTC. Indsættelsestidspunkt. |

Indexes: `UNIQUE(event_key)`, `KEY(creator_id, occurred_at)`, `KEY(order_id)`, `KEY(refund_id)` (opslag af ordren ved sletning af en refund).

**Hændelsesidentitet (`event_key`):**

| Hændelse | `type` / `correction_reason` | `event_key` |
| --- | --- | --- |
| Salg i en cyklus | `sale` | `s:{order_item_id}:{cycle}` |
| Reversal, der lukker en cyklus | `reversal` | `v:{order_item_id}:{cycle}` |
| Refund af en ordrelinje | `refund` | `r:{order_item_id}:{refund_id}` |
| Refund registreret på en lukket cyklus (modpost, så cyklussen forbliver 0) | `correction` / `closed_cycle_refund` | `c-ro:{order_item_id}:{refund_id}` |
| Refund slettet i WooCommerce | `correction` / `refund_deleted` | `c-rd:{order_item_id}:{refund_id}` |
| Slettet refund, der lå på en lukket cyklus (modpost) | `correction` / `closed_cycle_refund_deleted` | `c-rdo:{order_item_id}:{refund_id}` |
| Ændret antal eller beløb på en tællende ordrelinje | `correction` / `line_changed` | `c-l:{order_item_id}:{cycle}:{n}` |
| Ordrelinje fjernet fra en tællende ordre | `correction` / `line_removed` | `c-l:{order_item_id}:{cycle}:{n}` |

`n` er det næste løbenummer for linjekorrektioner i cyklussen, beregnet under ordrelåsen. `order_item_id` er globalt unik (auto-increment i `woocommerce_order_items`, også under HPOS), og refund-ID'er genbruges ikke. Refund-nøglerne afhænger bevidst ikke af cyklussen, så en refund aldrig registreres to gange. Den tidligere `UNIQUE(order_item_id, refund_id, type)` er forkastet: den tillader kun én `sale` og én `reversal` pr. ordrelinje og ingen korrektioner.

**`correction_reason`:** `closed_cycle_refund`, `refund_deleted`, `closed_cycle_refund_deleted`, `line_changed` og `line_removed` (tabellen ovenfor).

**`occurred_at`:**

| Hændelse | `occurred_at` |
| --- | --- |
| `sale`, cyklus 1 (live og backfill) | `date_paid`, ellers `date_created`. Aldrig `date_completed` (WooCommerce overskriver den ved hver overgang til `completed`). |
| `sale`, cyklus 2 og senere | Behandlingstidspunktet (WooCommerce gemmer intet tidspunkt pr. statusovergang) |
| `refund` | Refundens `date_created` (altid sat) |
| `reversal`, `correction` og permanent sletning | Behandlingstidspunktet |

Konstateres en reversal eller korrektion først ved en senere genbehandling, er `occurred_at` genbehandlingens tidspunkt (accepteret, ADR-038). `date_modified` bruges ikke som kunstig hændelsestid.

**`merchboard_sales_cycles`** (append-only; ADR-038):

| Kolonne | Type | Noter |
| --- | --- | --- |
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` PK | |
| `order_id` | `BIGINT UNSIGNED NOT NULL` | WooCommerce-ordre |
| `cycle` | `INT UNSIGNED NOT NULL` | 1, 2, … |
| `event` | `VARCHAR(5) NOT NULL` | `open` eller `close` |
| `occurred_at` | `DATETIME NOT NULL` | UTC |
| `created_at` | `DATETIME NOT NULL` | UTC |

Indexes: `UNIQUE(order_id, cycle, event)`; den dækker også opslag på `order_id` (venstre præfiks), så et separat `KEY(order_id)` er unødvendigt. Den aktive cyklus er den højeste `cycle` for ordren uden en `close`-række; den næste er højeste `cycle` + 1. Rækker ændres og slettes aldrig.

**Skrivning (ADR-038):** under en navngiven lås pr. ordre (`GET_LOCK()`) og i én transaktion for ledger- og cyklusrækker; `UNIQUE` er sidste sikkerhedsnet. Begge tabeller kommer med i `Tables::all()` og slettes kun ved opt-in-uninstall (ADR-025). Fejlmarkøren er ordremeta (`_merchboard_ledger_failure`, se WOOCOMMERCE.md), ikke en tabel.

### Fremtidige (ikke designet i detaljer)

- `merchboard_creator_members` – hvis flere brugere (fx en manager) skal have adgang til samme creator. Erstatter `user_id`-kolonnen. Ændringen bliver lokal, fordi al opslag af "nuværende creator" går gennem `CreatorContext`.
- `merchboard_product_creators` – hvis produkter skal kunne deles mellem creators med procentvis split.
- `merchboard_sales_daily` – præ-aggregeret statistik, **kun** hvis målinger viser, at queries på `merchboard_sales` bliver for langsomme.
- `merchboard_payouts`, `merchboard_payout_items` – afregning.
- `merchboard_integrations_*` – synkroniseringsstatus for eksterne tjenester.

## Schemaversion og migrationer ✅ (ADR-019)

| Del | Implementering |
| --- | --- |
| Version | Heltal. Gemt i option `merchboard_db_version` (autoload), `0`/manglende = ikke installeret. Nuværende version: **5** (`merchboard_sales` og `merchboard_sales_cycles`, ADR-037/038). |
| Migrationsliste | `MerchBoard\Core\Database\Schema::migrations()`: `array<int, Migration>` nøglet på den schema-version, migrationen giver. Højeste nøgle = forventet version. |
| Migration | Klasse, der implementerer `MerchBoard\Core\Database\Migration::up( wpdb )`. Kaster `DatabaseException`, hvis ændringen ikke kan verificeres. **Skal være idempotent.** |
| Runner | `MerchBoard\Core\Database\Migrator`: kører manglende migrationer i stigende rækkefølge og gemmer versionen **efter hver succesfuld migration**. Fejler en migration, stopper kørslen, og versionen forbliver på sidste succes. |
| Lås | Række `merchboard_db_migration_lock` i options-tabellen med værdien `<unix-tid>:<token>` (ikke autoload). Oprettes atomisk med `INSERT IGNORE`, så kun én request kan få den. `add_option()` bruges **ikke**: den kører `INSERT … ON DUPLICATE KEY UPDATE`, som overskriver en eksisterende lås og melder succes, når requestens cache er forældet (demonstreret mod MariaDB). Låse ældre end 5 minutter anses for forladte og overtages med en compare-and-swap-`UPDATE` på den forældede værdi, så kun én af flere samtidige requests vinder. Låsen frigives altid (`finally`), men kun hvis værdien stadig er requestens egen token, så en langsom request aldrig sletter en lås, som en anden har overtaget. Ældre låse (kun tidsstempel) læses stadig. (ADR-024) |
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
- `MerchBoard\Core\Audit\AuditRepository` (fase 6): kun `append` og `for_creator`. Fase 11 tilføjer `paginate( AuditFilter, page, per_page )`: nyeste først, filtre (action, creator, periode som UTC `[from, until)`) i faste, prepared SQL-fragmenter, `COUNT(*)` og `LIMIT/OFFSET` (maks. 100 pr. side). Stadig ingen update eller delete.
- **Transaktioner** (`MerchBoard\Core\Database\Transaction`, fase 6): `START TRANSACTION` / `COMMIT` / `ROLLBACK` via `$wpdb`, ikke re-entrant. Bruges af `CreatorService`, så creator-ændring og audit-record committes sammen eller slet ikke (ADR-023). **Fail closed (fase 7, ADR-024):** Før hver transaktion tjekker `TransactionSupport` via `information_schema` (`ENGINES.TRANSACTIONS`), at alle plugin-tabeller ligger på en transaktionel engine. Er en tabel fx MyISAM, skrives intet, og en `DatabaseException` med tabel og engine logges. Et positivt resultat caches pr. request. SQLite (`DB_ENGINE = sqlite`) er altid transaktionel. Rollback er **verificeret på MariaDB 10.11.19 og SQLite**. DDL (`CREATE`/`ALTER TABLE`) må aldrig køre inde i en transaktion (implicit commit på MySQL/MariaDB).
- Audit-context gemmes som **ASCII-JSON** (`\uXXXX` for ikke-ASCII, fase 7). Skrivningen kan derfor ikke fejle på en tabel uden 4-byte-UTF-8 (legacy `utf8mb3`) og dermed rulle en forretningsændring tilbage. Ugyldig UTF-8 og ikke-endelige tal erstattes i stedet for at fejle. Ved læsning giver ugyldig JSON en tom context.
- Al SQL går gennem repositories og `$wpdb->prepare()`. Tabelnavne kommer fra `Tables` og indsættes med `%i` (WP ≥ 6.2). Inserts bruger `$wpdb->insert()` med eksplicitte formater.
- Kolonner vælges eksplicit (ingen `SELECT *`), så nye kolonner aldrig eksponeres ved et uheld.
- ID'er valideres (positive heltal) i repositoriet, *før* der forespørges. Rækker valideres ved læsning (status-enum, tidsstempelformat).
- Databasefejl bliver til `DatabaseException` med generiske beskeder (ingen SQL, `last_error` eller værdier).
- Repositories udfører **ingen authorization**. Se [SECURITY.md](SECURITY.md#datalaget-fase-3).
- `ORDER BY` og kolonner fra input valideres mod en whitelist (når lister tilføjes).
- Lister er altid paginerede (default 20, max 100).
- Aggregeringer til dashboardet caches (transient pr. creator og periode) og invalideres ved nye ledger-rækker.

## MySQL/MariaDB vs. Playground (SQLite)

Produktionsschemaet er skrevet til **MySQL/MariaDB via `$wpdb`**. Playground bruger SQLite via WordPress' SQLite-integration, som oversætter MySQL-SQL. Der er ingen SQLite-specifikke tilpasninger i schemaet. Eneste SQLite-gren i koden er engine-tjekket (`TransactionSupport`), fordi SQLite ikke har storage engines.

Integrationstestene (`npm run test:integration`, fase 7) kører en rigtig WordPress 7.1.2 med pluginet mod en rigtig database. Se [TESTING.md](TESTING.md#integrationstests-fase-7).

| Egenskab | SQLite (Playground) | MariaDB 10.11.19 (lokalt) | MySQL (CI) |
| --- | --- | --- | --- |
| Kolonner, typer, nullability og indexes præcis som dokumenteret | ✅ runtime | ✅ `information_schema` | ✅ |
| Gentaget `dbDelta()` finder ingen ændringer | – | ✅ | ✅ |
| Tabeller og tekstkolonner bruger WordPress' charset/collation (`utf8mb4_unicode_520_ci`), intet hardcodet | – | ✅ | ✅ |
| Tabeller oprettes på en transaktionel engine (server-default InnoDB) | – | ✅ | ✅ |
| Creator-ændring og audit committes sammen | ✅ | ✅ | ✅ |
| Audit-fejl (rigtig databasefejl via trigger) ruller creator-ændringen tilbage, også ved flerfeltsopdatering | ✅ (fremtvunget exception) | ✅ | ✅ |
| Creator-fejl (rigtigt UNIQUE-brud/trigger) efterlader ingen audit-record | unit | ✅ | ✅ |
| MyISAM ignorerer faktisk `ROLLBACK`, og pluginet afviser derfor at skrive (fail closed) | – | ✅ | ✅ |
| `UNIQUE(user_id)` afviser duplikat og tillader flere `NULL` | ✅ | ✅ | ✅ |
| Unicode (dansk, emoji, CJK, kombinerende tegn, 191 tegn à 4 byte) gemmes byte-præcist | – | ✅ | ✅ |
| 4-byte-tegn på en legacy `utf8mb3`-tabel afvises rent (ingen `?` eller trunkering), og ASCII-JSON-context virker | – | ✅ | ✅ |
| UTC uafhængigt af sitets tidszone og databasesessionens `time_zone` | ✅ | ✅ | ✅ |
| Migration 0 → 2, 1 → 2 med data, aktuel schema (0 queries), gentagen kørsel uden datatab | ✅ (0 → 2, 1 → 2) | ✅ | ✅ |
| Fejlet migration: version gemmes ikke, lås frigives, rigtig serverfejl i log-context, Installer pauser og logger | unit | ✅ | ✅ |
| Lås: atomisk `INSERT IGNORE`, compare-and-swap, ejer-baseret sletning | ✅ (SQL-semantik) | ✅ | ✅ |
| 6 samtidige processer: migrationen kører præcis én gang | – | ✅ | ✅ |
| SQL injection-strenge gemmes bogstaveligt, injiceret sortering ignoreres | unit | ✅ | ✅ |

MySQL-kolonnen: CI-jobbet `PHP integration (MySQL)` kører de samme integrationstests mod den MySQL-server, som GitHubs Ubuntu-runner leverer, og udskriver den præcise serverversion. Grøn på MySQL 8.0.46 (senest set 2026-09-28, commit `602cd50`).

**Observeret på MariaDB 10.11.19:** WordPress fjerner strict mode fra sin databasesession (`sql_mode` = `ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION`). Uden WordPress' egen charset-kontrol ville serveren derfor gemme `?` i stedet for at afvise ugyldige tegn. Pluginet går altid gennem `$wpdb->insert()`/`update()`, som afviser værdien, og sætter altid alle `NOT NULL`-kolonner selv.

**Ikke verificeret:** andre MySQL-versioner end CI's, andre MariaDB-versioner end 10.11.19, versionen på dev/staging/produktion (åbent spørgsmål 2), persistent object cache (fx Redis) sammen med låsen, replikering/Galera og en databaseforbindelse, der genoprettes midt i en transaktion (`wpdb` genforbinder og fortsætter så uden transaktion). Autoritativ verifikation af produktionsmiljøet sker på staging (fase 16).

### Forudsætninger for MySQL/MariaDB

| Emne | Forudsætning | Hvis den ikke er opfyldt |
| --- | --- | --- |
| Storage engine | Alle plugin-tabeller på en transaktionel engine (InnoDB). Det er serverens default, og pluginet angiver ingen engine selv. | **Fail closed** (ADR-024): creator-ændringer afvises med en neutral fejl, admin-siden viser en fejl-notice (uden tabelnavne), og hvert afvist forsøg logges som `error` med tabel og engine. Løsning: `ALTER TABLE … ENGINE=InnoDB`. |
| Charset/collation | WordPress' egen (`DB_CHARSET`/`DB_COLLATE` via `$wpdb`). `utf8mb4` anbefales (WordPress' default siden 4.2). | På en legacy `utf8mb3`-tabel afviser `wpdb` display names med 4-byte-tegn (emoji). Admin ser en teknisk fejl, og intet gemmes forkert. Audit-context påvirkes ikke (ASCII-JSON). |
| Rettigheder | Som WordPress i øvrigt: `CREATE`/`ALTER` til `dbDelta()` og læseadgang til `information_schema` for egne tabeller (standard). | Migrationen fejler, versionen gemmes ikke, og der vises en admin notice (fase 3/4). |
| Transaktioner | Ingen DDL inde i `Transaction::run()`, ingen indlejrede transaktioner. | Implicit commit ville bryde garantien. Indlejring afvises af `Transaction`. |

## Afinstallation og datalivscyklus

| Handling | Effekt på data |
| --- | --- |
| **Deaktivering** | Ingen data slettes. Data, tabeller og options bevares. `Deactivator` (✅ fase 12) sletter kun WordPress' gemte `rewrite_rules`, så dashboard-routen forsvinder. De genopbygges ved næste request. |
| **Afinstallation** (`uninstall.php`) | **Ingen, som standard.** Alle tabeller, options, rollen og capabilities bevares. Creator-data er forretningsdata, som fremtidig salgshistorik og payouts refererer til. |
| **Permanent sletning** ✅ fase 9 | Kun med eksplicit opt-in under Merch Dashboard → Settings (se [ADMIN.md](ADMIN.md#settings)). Så sletter `Core\Lifecycle\Uninstaller` pluginets egne data for det aktuelle site, se inventory nedenfor (ADR-025). |
| **Creator "slettes"** | Status `inactive` (soft). Admin-UI'et har bevidst **ingen** "Delete"-funktion (fase 6). Hard delete er ikke understøttet, fordi audit trail og fremtidig salgshistorik refererer til creator-ID'et. Permanent sletning af alle data er opt-in (✅ fase 9, ADR-025). |
| **WordPress-bruger slettes** | Se relationen ovenfor (✅ fase 8). Creator-record, creator-data og audit bevares. Creator'en frakobles og deaktiveres. |

```text
Deaktivering
→ data bevares (tabeller, options, rolle og capabilities uændrede)

Afinstallation (slet plugin) uden eksplicit opt-in
→ data bevares

Afinstallation med eksplicit opt-in ("Delete all Merch Dashboard data when the plugin is deleted")
→ Merch Dashboards egne data slettes, inkl. product meta `_merchboard_creator_id` (fra fase 17, ADR-030) og `_merchboard_creator_link_source` (ADR-033)
→ alle andre WordPress- og WooCommerce-data bevares
```

| Plugin-ejet state (fuld inventory, fase 9) | Slettes ved opt-in-uninstall |
| --- | --- |
| Tabeller `{prefix}merchboard_creators`, `{prefix}merchboard_audit_log`, `{prefix}merchboard_creatormeta`, `{prefix}merchboard_creator_categories`, `{prefix}merchboard_sales` og `{prefix}merchboard_sales_cycles` (`Tables::all()`) | Ja: `DROP TABLE IF EXISTS` med præcise navne for det aktuelle sites prefix (`SchemaEraser`) |
| Ordremeta `_merchboard_ledger_failure` (fejlmarkøren, ADR-038) | Ja: legacy-postmeta via `delete_post_meta_by_key()`; HPOS-ordremeta kun via WooCommerce CRUD (ordrerne findes med `meta_key` + `meta_compare => EXISTS`), mens WooCommerce er aktivt (ellers bliver den liggende, ADR-038 Q3). Ingen direkte SQL mod WooCommerce-tabeller. |
| Options `merchboard_db_version`, `merchboard_db_migration_lock`, `merchboard_roles_version` | Ja |
| Transient `merchboard_db_migration_failed` | Ja |
| Option `merchboard_settings` (inkl. opt-in'en) | Ja, **sidst** |
| Rollen `merchboard_creator` | Ja: fjernes fra brugere (andre roller bevares) og derefter som rolle |
| Capabilities `merchboard_access_dashboard`, `merchboard_manage`, `merchboard_manage_creators`, `merchboard_manage_settings` (`Capabilities::all()`) | Ja: fra alle roller og fra brugere, der har fået dem direkte. Kun disse præcise navne, ikke alt med prefix `merchboard_`. |
| Tekniske log-poster (WooCommerce-log, source `merchboard`, eller `debug.log`) | Nej: ejes af WooCommerce/serveren og ryddes af deres retention |
| Product meta `_merchboard_creator_id` (produkt→creator-koblingen, fra fase 17) og `_merchboard_creator_link_source` (oprindelsen, ADR-033) | Ja: kun disse præcise nøgler, på de produkter og varianter hvor de findes, for det aktuelle site. Via WordPress' meta-API (virker også uden WooCommerce), idempotent og før tabellerne droppes. Når metadata-vagten er indlæst, tillader dens uninstall-vindue kun denne sletning (ADR-036: `WP_UNINSTALL_PLUGIN` er Merch Dashboards basename, og opt-in er aktivt). Selve produkterne bevares (ADR-030) |
| WordPress-brugere, andre roller/capabilities, posts, sider, media, WooCommerce-produkter, -ordrer og -kunder, **al anden** WooCommerce- og produkt-meta end `_merchboard_creator_id` og `_merchboard_creator_link_source`, produktkategorier (`product_cat`-terms og deres tilknytninger), andre plugins' data, themes | **Aldrig** |

**Strategi (ADR-025):** `DROP TABLE` committer implicit og kan ikke indgå i en transaktion, så der er ingen falsk atomicitet. Rækkefølgen er i stedet: brugere → roller → tabeller → options → `merchboard_settings` **sidst**. Fra fase 17 slettes product meta `_merchboard_creator_id` (og fra ADR-033 `_merchboard_creator_link_source`) efter roller og før tabeller (ADR-030). Hvert trin er idempotent (`IF EXISTS`, manglende roller og options ignoreres). Fejler et tabel-drop, logges det, og der slettes ingen options. Opt-in'en bevares derfor, og den næste afinstallation fortsætter. Gentagen afinstallation er sikker. Uninstall skriver ingen audit-records.

**Multisite (verificeret i WordPress 7.1.2's kildekode, ikke officielt understøttet):** `delete_plugins()` kalder `uninstall_plugin()` én gang uden `switch_to_blog()`. Tabelnavne bygges med det aktuelle sites `$wpdb->prefix`, roller ligger i det aktuelle sites `{prefix}user_roles`, og options og brugernes capabilities er pr. site. Afinstallation kan derfor kun slette det aktuelle sites data, og kun hvis opt-in'en er sat på det site. Andre sites' tabeller, roller og options røres ikke (testet med en tabel med lignende navn for et andet site). Data på andre sites i netværket bliver dermed liggende, selv hvis opt-in er sat der. Multisite-oprydning kræver en senere beslutning.
| **GDPR** | Integration med WordPress' personal data exporter/eraser for creator-data (fase efter MVP). |
