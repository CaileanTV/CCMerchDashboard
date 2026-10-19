# Database og datamodel

> Status: **Schema-version 19 i koden (17 i 1.0.1).** Version 19 (ADR-065) tilføjer alert-overlays og fjerner kundens fornavn fra alerts (se [Alert-overlays](#alert-overlays--schema-version-19-adr-065)). Version 18 (ADR-065) tilføjer købs-alerts (se [Købs-alerts](#købs-alerts--schema-version-18-adr-065)). Version 17 (ADR-068) tilføjer creator-rabatkoder (se [Creator-rabatkoder](#creator-rabatkoder--schema-version-17-adr-068)). Version 16 (ADR-062 K06) tilføjer historikken over creatorens momsstatus (se [Historik over momsstatus](#historik-over-momsstatus--schema-version-16-adr-062-k06)). Version 15 (ADR-062 punkt 9) tilføjer eksporttabellen for genberegninger, og version 14 (ADR-063) genberegninger og reguleringer af payouts (se [Genberegning og regulering](#genberegning-og-regulering--schema-version-14-og-15-adr-063)); versionerne 12 og 13 (G1-tabellerne og `merchboard_operations`) er ikke beskrevet i denne fil. Version 11 (fase 23, ADR-060) tilføjer afregningsbilag (`merchboard_settlement_documents`, `merchboard_settlement_document_rows`, `merchboard_settlement_counters`); version 10 (ADR-057) gavekortkolonnerne på `merchboard_payouts`. Version 9 (fase 23, ADR-054–056) tilføjer `merchboard_payouts`, `merchboard_payout_secrets` og `merchboard_creators.payout_start_at` (se [Payouts](#payouts--schema-version-9-fase-23-adr-054056)). `merchboard_creators` (fase 3), `merchboard_audit_log` (fase 6), `merchboard_creatormeta` (fase 14), `merchboard_creator_categories` (fase 17) samt `merchboard_sales` og `merchboard_sales_cycles` (fase 18, endnu uden registrering) findes, med migrationssystem og transaktioner. Fra fase 22 findes også creator pricing (ADR-049): `merchboard_creators.default_markup_bps`, `merchboard_pricing_overrides` (produkt-markup) og `merchboard_sales_line_pricing`; `merchboard_item_pricing` blev oprettet i version 6 og droppet i version 8. Øvrige tabeller er planlagte. Schema-version 1–5 er verificeret mod **MariaDB 10.11.19**, **MySQL 8.0.46** (CI) og SQLite (Playground) (se [nedenfor](#mysqlmariadb-vs-playground-sqlite)). Fra version 7 (fase 22, ADR-048) også `merchboard_creators.vat_registered`, `merchboard_sales_line_pricing.vat_registered` og `merchboard_sales_tax`. Version 8 (ADR-049) tilføjer andelen i snapshottet, genopretter produkt-markup-tabellen og dropper `merchboard_item_pricing`. Schema-version 6–8 er indtil videre kun verificeret lokalt mod MariaDB 10.11.19 (integrationstests); CI mod MySQL følger ved næste push.

## Placering af data

| Data | Placering | Begrundelse |
| --- | --- | --- |
| Login-identitet, e-mail, password, navn | `wp_users` | WordPress' auth. Genbruges 100 %. |
| Rolle/capabilities | `wp_usermeta` (`wp_capabilities`) via roles API | Standard |
| Brugerpræferencer uden domæneværdi (fx dashboard-tema) | `wp_usermeta` (`merchboard_*`). ✅ Fase 21, trin 5 (ADR-044): `merchboard_dashboard_theme` med præcis én af `light`, `dark-black`, `dark-green`, `dark-blue`, `dark-red`, `dark-pink`; ingen række = følg systemet. Skrives kun af `ThemePreference` via `PATCH /me/preferences` og verificeres ved genlæsning; en ugyldig gemt værdi tæller som intet valg. Slettes kun ved opt-in-uninstall (`Uninstaller::USER_META_KEYS`, præcis nøgle, ADR-025) | Hører til login-kontoen, ikke til creator'en. Ingen migration |
| **Creator** (identitet, status, visningsnavn) | `merchboard_creators` (custom) ✅ | Domæneobjekt adskilt fra login-kontoen. Se ADR-005 og ADR-020. |
| Fleksible creator-felter (bio, sociale links; senere avatar-ID) | `merchboard_creatormeta` (custom, WP metadata API) ✅ fase 14, schemaversion 3 | Én række pr. nøgle. Kun whitelistede nøgler (`CreatorMetaStore`) |
| Plugin-settings | `wp_options`: `merchboard_settings` (ét array, autoload) ✅ fase 9. Nøgler: `delete_data_on_uninstall` (bool, default `false`, tæller kun som præcis `true`) og `dashboard_slug` (✅ fase 12, streng, default `creator-dashboard`, ugyldig værdi giver default). ✅ Fase 18, trin 6 (runtime-verificeret i Merch Dashboard Local med HPOS og legacy, ADR-038 statusnote "trin 6 afsluttet"; ADR-038 S1–S14): `counted_order_statuses` (liste af ordrestatus-slugs uden `wc-`, default `processing` og `completed`; core validerer kun formatet: `a-z`, `0-9`, `_` og `-` som WordPress' `sanitize_key()`, ikke tom, uden `wc-`-præfiks og højst 17 tegn, fordi `wp_posts.post_status` og HPOS' `wc_orders.status` begge er `varchar(20)` inkl. `wc-` (ADR-038, statusnote 2026-09-30); dubletter fjernes og rækkefølgen normaliseres; WooCommerce-modulet afgør registrering og afvisningsliste). ✅ Fase 21, trin 6 (ADR-042): `logo_attachment_id` (heltal ≥ 0, 0 = default-logoet; kun attachment-ID'et gemmes, aldrig en URL; alt andet end et positivt heltal læses som 0; valideres mod mediebiblioteket ved gem og ved hver visning; attachmentet ejes af mediebiblioteket og slettes aldrig af pluginet). ADR-062 K09 (2026-10-17): `company_cvr` (den udstedende virksomheds CVR-nummer på nye afregningsbilag, otte cifre eller "" = ikke registreret; alt andet læses som ""). ADR-065 (fase 25, trin 25.2): `alerts_enabled` (kill switch for købs-alerts, default `false`, tæller kun som præcis `true`) | Lille og læses på de fleste requests |
| Schemaversion | `wp_options`: `merchboard_db_version` ✅ | Styrer migrationer (ADR-019) |
| Migrationslås | `wp_options`: `merchboard_db_migration_lock` ✅ (midlertidig, ikke autoload) | Forhindrer samtidige migrationer |
| Migrationsfejl | transient `merchboard_db_migration_failed` ✅ (5 min) | Pause mellem automatiske forsøg og admin notice |
| Rolle `merchboard_creator` og pluginets capabilities | WordPress' `wp_user_roles`-option via role-API ✅ (fase 5) | WordPress-nativt. Aldrig direkte manipuleret. |
| Synkroniseret rolle-version | `wp_options`: `merchboard_roles_version` ✅ (autoload) | Styrer rolle-sync ved opdateringer (ADR-022) |
| Alert-lyde (ADR-065, trin 25.6) | Vedhæftninger i WordPress' mediebibliotek; post meta `_merchboard_alert_sound_creator` (creator-ID) markerer ejeren. Hvilken fil der hører til hvilket slot, står i overlayets `settings` (`audio_default_sound`, `audio_category_map`) ✅ | Kun ejerens filer afspilles, vises eller slettes. Filerne er almindelige medier og bevares ved opt-in-afinstallation |
| Alert-lydbibliotek (trin 25.6.3) | Vedhæftninger med post meta `_merchboard_alert_sound_library` = 1 (højst 50) ✅ | Kun administratoren tilføjer og fjerner dem; overlays henviser til dem via `audio_default_sound`/`audio_category_map`. Bevares ved opt-in-afinstallation som almindelige medier |
| Alert-fontbibliotek (ADR-067) | `wp_options` uden autoload: `merchboard_alert_fonts_key` (Google Fonts API-nøgle) og `merchboard_alert_fonts_approved` (godkendte fonte: `family`, `category`, `weights`); transient `merchboard_alert_fonts_catalog` (Google Fonts-kataloget, 30 dage) ✅ fase 25, trin 25.5 | Erstatter den planlagte tabel `merchboard_alert_fonts` (ADR-065, statusnote 2026-10-19): lille og læses altid samlet. Skrives kun af `AlertFonts` med audit i samme transaktion. Nøglen sendes aldrig til frontend |
| Secrets (API-nøgler til integrationer) | `wp-config.php`-konstanter (foretrukket) eller option med `autoload = no` | Aldrig i repo, aldrig autoloaded, aldrig sendt til frontend |
| Produkter, varianter, ordrer, refunds og kunder | **WooCommerce** (HPOS) | Source of truth. Ingen kopi af masterdata. |
| Produkt → creator | WooCommerce product meta `_merchboard_creator_id` (fase 17) | **Eneste ejerskabsrelation.** Én creator pr. produkt i v1. Pluginet ejer nøglen: bevares ved deaktivering af pluginet og WooCommerce, og slettes kun ved opt-in-uninstall (ADR-030) |
| Koblingens oprindelse | WooCommerce product meta `_merchboard_creator_link_source` (`admin` | `category`) ✅ fase 17, trin 8 (ADR-033) | Skrives og fjernes sammen med `_merchboard_creator_id`: `admin` ved manuelle koblinger, `category` kun af kategori-afstemningen (`CreatorCategoryReconciler`). Mangler den, eller har den en anden værdi, er koblingen manuel og røres aldrig af afstemningen. Ikke en ejerskabsrelation. Pluginet ejer nøglen og sletter den kun ved opt-in-uninstall |
| Creator ↔ produktkategori (mapping) | `merchboard_creator_categories` (custom) ✅ fase 17, schema-version 4 (ADR-033) | Vedvarende administrativ regel for automatiske koblinger. Aldrig bevis for ejerskab eller adgang |
| Salg pr. creator (historik) | `merchboard_sales` (custom ledger, fase 18) | Hurtige aggregeringer og uforanderlig attribution. Se [WOOCOMMERCE.md](WOOCOMMERCE.md). |
| Creator pricing: creatorens markup (andel af salgsprisen) og momsstatus, produkt-markup | `merchboard_creators.default_markup_bps`/`vat_registered`, `merchboard_pricing_overrides` (custom) ✅ fase 22, schema-version 6–8 (ADR-048/049) | Pluginets egne forretningsdata. Alle WooCommerce-priser forbliver WooCommerce' data og skrives aldrig af Merch Dashboard |
| Pricing-snapshot pr. ordrelinje (historik) | `merchboard_sales_line_pricing` (custom, append-only) ✅ fase 22, schema-version 6 (ADR-047) | Uforanderlig kilde til realiseret profit. Ledgeren `merchboard_sales` er uændret |
| Tekniske logs | **Ingen tabel.** WooCommerce-logger eller `error_log()` (fase 4, ADR-021) | Tekniske logs er drift, ikke forretningsdata |
| Audit trail (hvem ændrede hvad) | `merchboard_audit_log` (custom) ✅ fase 6 | Forretningsdata, append-only (ADR-023). Read-only visning ✅ fase 11 (Merch Dashboard → Audit log). |
| Cache af aggregeringer | Object cache (gruppe `merchboard_sales`, ADR-040), aldrig transients | Afledte data, må forsvinde |

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
| `vat_registered` | `tinyint(1) unsigned NOT NULL DEFAULT 0` | ✅ Schema-version 7 (ADR-048). 1 = momsregistreret, 0 = ikke (standard for eksisterende og nye creators). Skrives kun af `CreatorVatRepository` via `CreatorPricingSettings` (audit `creator_vat_status_changed`); fra schema-version 16 kun som den status, historikken giver for i dag ([Historik over momsstatus](#historik-over-momsstatus--schema-version-16-adr-062-k06), ADR-062 K06) |
| `payout_start_at` | `datetime DEFAULT NULL` | ✅ Schema-version 9 (fase 23, D6, ADR-054). Creatorens afregningsstart i UTC; NULL = hele historikken. Afgrænser kun vinduet for optjent payout; ingen ledger-række ændres eller udfyldes. Skrives kun af `PayoutRepository` via `PayoutSettings` under creatorens payout-lås (audit `payout_start_changed`) |
| `default_markup_bps` | `int(10) unsigned NOT NULL DEFAULT 0` | ✅ Schema-version 6 (fase 22, ADR-049). Creatorens markup = andel af WooCommerce-salgsprisen i basis points, 0–10 000 (10 000 = 100 %). Eksisterende og nye creators får 0. Intervallet håndhæves af `MarkupBps` ved skrivning og læsning (ingen CHECK) |

Indexes: `PRIMARY KEY (id)`, `UNIQUE KEY user_id (user_id)`, `KEY status (status)`.

Kilde: `src/Core/Database/Migrations/CreateCreatorsTable.php`; kolonnen `default_markup_bps` tilføjes af `CreateCreatorPricingTables` (migration 6) med `ALTER TABLE … ADD COLUMN`, kun hvis den mangler. Den skrives og læses kun af `CreatorMarkupRepository`, ikke af `CreatorRepository`.

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
| `billing_details` | Stamdata til afregningsbilag (ADR-062 K09, ingen migration): array med de registrerede af `legal_name` (≤ 191), `address_1` (≤ 191), `address_2` (≤ 191), `postcode` (≤ 20), `city` (≤ 100), `country` (≤ 100) og `cvr` (otte cifre). Slettes, når alle er tomme. Skrives kun af `PayoutSettings::set_billing_details()` (`merchboard_manage_payouts`, under lås af creatorens række, audit `creator_billing_details_changed` med feltnavne uden værdier) og læses gennem `CreatorBillingDetails::from_stored()`, så korrupte værdier ignoreres. Ikke en del af creatorens profil eller `/me/profile` |

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
| `sales_backfill_completed` (fase 18, trin 7, ADR-038 statusnote "ROADMAP trin 7") | `run_id`, evt. `trigger_user_id` (WP-CLI-brugeren), `scanned`, optællinger pr. udfald (`registered`, `skipped_history`, `skipped_not_counted`, `skipped_no_creator`, `skipped_missing`, `skipped_busy`, `failed`), `rows` (rækker pr. type: `sale`, `refund`, `reversal`, `correction`) og `failures` (fejlede ordrer pr. fejlkode). Ingen ordre-ID'er og ingen beløb. Skrives én gang, når sidste side af en kørsel er behandlet, aldrig ved en tørkørsel eller en stoppet kørsel. `creator_id` og actor er NULL |

En redigering, der både omdøber og deaktiverer, skriver `creator_updated` og `creator_deactivated`.

**Privacy:** Display names kan være en persons rigtige navn. Derfor gemmes kun *hvilke* felter der ændredes, ikke gamle eller nye værdier. Det aktuelle navn står i `merchboard_creators`. Aldrig passwords, tokens, cookies, sessioner, e-mail, request bodies, `$_POST` eller `$_SERVER`.

**Append-only:** `AuditRepository` har kun `append()` og `for_creator()` (læsning til tests og den kommende visning). Der er ingen update eller delete, og ingen admin-funktion ændrer records.

**Retention og sletning:** Records bevares ubegrænset. Fra ADR-059 (B2) bevares de også ved opt-in-afinstallation som historisk dokumentation (`Tables::retained_on_uninstall()`). Uninstall skriver ingen audit-records. **v0.1.0 har bevidst ingen automatisk retention** (ADR-029): ingen cron og ingen sletning efter N år. En retention-politik for produktion skal besluttes før produktionsbrug.

### `merchboard_sales` og `merchboard_sales_cycles` (fase 18, design-låst)

> **Status: design-låst (ADR-037, ADR-038 med supplement 2026-09-28). Tabellerne findes fra schema-version 5 (migration 5) med `SalesRepository` (lagring, lås og transaktion); fra trin 5 skriver `SalesSynchronizer` rækkerne fra WooCommerce-hooks (runtime-verificeret med HPOS og legacy; commit `366714c`, CI grøn inkl. MySQL 8.0.46); fra trin 7 registrerer backfillen historiske ordrer uden ledger-historik (commit `00fb066`). Fase 18 er afsluttet 2026-10-01.** Låsens timeout er 5 sekunder (`SalesRepository::LOCK_TIMEOUT`). Hver INSERT er betinget af `CONNECTION_ID()` = låsens forbindelse, så intet skrives uden for transaktionen, hvis `wpdb` genforbinder. `@@in_transaction` bruges ikke (findes ikke på MySQL 8.0; ADR-038, B4).

**`merchboard_sales`** er en append-only ledger (ADR-037). Historiske rækker ændres og slettes aldrig; afvigelser rettes med nye rækker. **Eneste undtagelse (ADR-052):** en administrator kan slette alle rækker for én ordre i `merchboard_sales`, `merchboard_sales_cycles`, `merchboard_sales_tax` og `merchboard_sales_line_pricing`, når WooCommerce-ordren ligger i papirkurven eller er slettet (fx en testordre), i én transaktion med audit (`SalesRepository::erase_order()`). Refererer til **creator-ID**. Se [WOOCOMMERCE.md](WOOCOMMERCE.md#sales-ledger).

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

### Creator pricing ✅ (schema-version 6–8, fase 22, ADR-049)

Oprettet af `CreateCreatorPricingTables` (migration 6) og ændret af `AddCreatorVatAndSalesTax` (migration 7) og `AddCreatorShareModel` (migration 8), plus kolonnerne `merchboard_creators.default_markup_bps` og `vat_registered`. Modellen er ADR-049: creatorens profit er en andel af WooCommerce-salgsprisen, og Merch Dashboard skriver aldrig priser. Fælles regler:

- **Procenter og beløb:** andele i basis points (0–10 000); beløb som `DECIMAL(19,4)`, læst og skrevet som heltal i 1/10 000-enheder (`SalesAmount`); aldrig floats og ingen valutaomregning.
- **Ingen fremmednøgler** (ADR-019): snapshottet skal overleve ordrer, ordrelinjer, produkter, creators og regler.
- **Ingen CHECK-constraints:** værdiintervaller og sammenhænge håndhæves af domænet før skrivning og igen ved læsning (en ugyldig række giver `DatabaseException`).
- **dbDelta og versionerede `ALTER`/`DROP`** i migrationerne, ingen `CURRENT_TIMESTAMP`; tider er UTC.
- **Adgang:** kun via repositories i `src/Modules/WooCommerce/Pricing/`. Autorisation og audit ligger i `CreatorPricingSettings`.

#### `merchboard_pricing_overrides` (current state, ADR-049)

Højst én produkt-markup pr. parent-produkt, bundet til ejeren. Den erstatter creatorens markup for produktet og alle varianter; de summeres aldrig. Genoprettet af migration 8 (før: fast profit pr. enhed, ADR-046, som ikke kan omregnes).

| Kolonne | Type | Noter |
| --- | --- | --- |
| `product_id` | `bigint(20) unsigned NOT NULL` PK | Parent-produkt (WooCommerce-ID) |
| `creator_id` | `bigint(20) unsigned NOT NULL` | Ejeren, da markup'en blev sat. Gælder kun, mens det er den nuværende ejer |
| `markup_bps` | `int(10) unsigned NOT NULL` | Andel af salgsprisen, 0–10 000 |
| `created_at`, `updated_at` | `datetime NOT NULL` | UTC. `created_at` bevares, når markup'en erstattes |

Indexes: `PRIMARY KEY (product_id)`, `KEY creator_id (creator_id)`. Repository: `ProductMarkupRepository` (`find`, `for_creator`, `save` (indsæt eller erstat, verificeret ved genlæsning), `delete`).

#### `merchboard_item_pricing` (udgået)

Grundpriser og sidst anvendte priser for den tilbagetrukne pris-skrivning (ADR-045). Oprettet af migration 6 og droppet af migration 8. `Tables::ITEM_PRICING` findes kun som "retired" konstant (`Tables::retired()`), så migration 6 og opt-in-uninstall kan nævne tabellen.

#### `merchboard_sales_line_pricing` (append-only snapshot, ADR-047, ADR-049)

Ét snapshot pr. ordrelinje med creator, taget når linjen oprettes (`woocommerce_new_order_item`; se WOOCOMMERCE.md). Det gemmer den andel og momsstatus, der gjaldt da, aldrig nuværende regler, aldrig priser og aldrig kundedata.

| Kolonne | Type | Noter |
| --- | --- | --- |
| `order_item_id` | `bigint(20) unsigned NOT NULL` PK | WooCommerce-ordrelinje |
| `order_id`, `creator_id`, `product_id` | `bigint(20) unsigned NOT NULL` | Ejer på salgstidspunktet; parent-produkt |
| `variation_id` | `bigint(20) unsigned NOT NULL DEFAULT 0` | 0 for et simpelt produkt |
| `status` | `varchar(8) NOT NULL` | `share` (fra migration 8); `priced`/`unpriced` kun i ældre rækker |
| `reason` | `varchar(20) DEFAULT NULL` | Kun ældre `unpriced`-rækker |
| `currency` | `char(3) NOT NULL` | Ordrens valuta |
| `rule`, `markup_bps`, `base_unit`, `profit_unit`, `price_unit` | `DEFAULT NULL` | Den tilbagetrukne model (ADR-045/047): kun udfyldt i ældre `priced`-rækker, aldrig genfortolket |
| `captured_at` | `datetime NOT NULL` | UTC |
| `vat_registered` | `tinyint(1) unsigned DEFAULT NULL` | Migration 7 (ADR-048): ejerens momsstatus ved oprettelsen; NULL = ukendt (rækker fra før version 7) |
| `share_bps` | `int(10) unsigned DEFAULT NULL` | Migration 8: creatorens andel af salgsprisen; NULL = ukendt (rækker fra før version 8) |
| `share_source` | `varchar(8) DEFAULT NULL` | Migration 8: `creator` eller `product` |
| `price_decimals` | `tinyint(3) unsigned DEFAULT NULL` | Migration 8: butikkens prisdecimaler (0–4) ved oprettelsen, til afrunding |

`share_bps`, `share_source` og `price_decimals` er enten alle kendte (status `share`) eller alle NULL. Indexes: `PRIMARY KEY (order_item_id)`, `KEY order_id (order_id)`, `KEY creator_id (creator_id)`.

**Uforanderlig:** `SalesLinePricingRepository` har kun `insert` og `find`. En ekstra INSERT for samme ordrelinje (også samtidig) beholder det første snapshot. Kun opt-in-uninstall sletter tabellen.

**Realiseret profit og udbetaling** for en ledger-række (`RealizedProfit`, `RealizedPayout`):

- salgspris = `net_amount` + rækkens moms fra `merchboard_sales_tax` (det betalte beløb inkl. moms);
- profit = `round_half_away(salgspris × share_bps / 10 000)` til `price_decimals`;
- udbetaling = profit for rækker, hvor creatoren var momsregistreret ved salget; ellers creatorens andel af beløbet ekskl. rækkens moms (`net_amount` × andel, ADR-064, 2026-10-18; ADR-063 havde fjernet 20 %-fradraget). En række, der indgår i et afregningsbilag, tæller i saldoen med den udbetaling, der er gemt i `merchboard_settlement_document_rows.payout_amount` (ADR-060).

De beregnes kun med et matchende snapshot med andel og rækkens gemte moms; ellers er de ukendte (NULL). Der er ingen profit- eller udbetalingskolonne i `merchboard_sales`.

#### `merchboard_sales_tax` (append-only, schema-version 7, ADR-048)

Den faktiske WooCommerce-moms pr. sales ledger-række. Den er en følgetabel til `merchboard_sales`, som ikke er ændret.

| Kolonne | Type | Noter |
| --- | --- | --- |
| `event_key` | `varchar(64) NOT NULL` PK | Ledger-rækkens `event_key` |
| `order_id`, `order_item_id` | `bigint(20) unsigned NOT NULL` | Som rækken |
| `tax_amount` | `decimal(19,4) NOT NULL` | Rækkens moms med fortegn |
| `currency` | `char(3) NOT NULL` | Rækkens valuta |
| `created_at` | `datetime NOT NULL` | UTC |

Indexes: `PRIMARY KEY (event_key)`, `KEY order_id (order_id)`.

**Skrivning:** `SalesRepository::write()` skriver i samme transaktion og med samme `CONNECTION_ID()`-vagt som rækken, kun for rækker, der faktisk indsættes. Planneren beregner momsen efter samme regel som beløbet:
- salg: linjens `get_total_tax`;
- refund: refund-linjens egen moms;
- korrektioner og reversal: spejlet gemt moms eller momsbalance.

**Ukendt moms:** rækker fra før version 7, og værdier der ikke er rene decimaler, giver ingen momsrække, aldrig et gæt.

**Læsning:** `SalesTaxRepository` (payout).

### Payouts ✅ (schema-version 9 og 10, fase 23, ADR-054–057)

#### `merchboard_payouts`

Én række pr. payout-anmodning (ADR-055). Beløb, metode og creator ændres aldrig; status ændres kun fra `pending` med en betinget UPDATE. Rækker slettes aldrig.

| Kolonne | Type | Noter |
| --- | --- | --- |
| `id` | `bigint(20) unsigned NOT NULL AUTO_INCREMENT` PK | Payout-ID |
| `creator_id` | `bigint(20) unsigned NOT NULL` | Creatoren (fra `CreatorContext`) |
| `currency`, `amount`, `price_decimals` | `char(3)`, `decimal(19,4)`, `tinyint(3) unsigned`, alle `NOT NULL` | Beløb med højst butikkens decimaler på anmodningstidspunktet |
| `method` | `varchar(20) NOT NULL` | `bank`, `paypal` eller `gift_card` (ADR-057; ingen række i `merchboard_payout_secrets`) |
| `status` | `varchar(20) NOT NULL` | `pending` (= reservation), `completed`, `rejected`, `cancelled` |
| `destination_hint` | `varchar(191) NOT NULL DEFAULT ''` | Maskeret: "•••• 1234" (aldrig reg.nr.) eller "j•••@g•••.com" |
| `idempotency_key` | `varchar(64) NOT NULL` | Klientens nøgle; `UNIQUE(creator_id, idempotency_key)` |
| `requested_by`, `requested_at` | `bigint(20) unsigned NULL`, `datetime NOT NULL` | Bruger og tid (UTC) |
| `decided_by`, `decided_at` | `bigint(20) unsigned NULL`, `datetime NULL` | Hvem der gennemførte, afviste eller annullerede |
| `decision_reason` | `varchar(500) NULL` | Afvisningens begrundelse (vises for creatoren, aldrig i audit) |
| `reference` | `varchar(191) NULL` | Valgfri bank-/PayPal-reference ved `completed` (aldrig i audit) |
| `updated_at` | `datetime NOT NULL` | UTC |
| `gift_card_provider`, `gift_card_external_id` | `varchar(32) NULL`, `varchar(64) NULL` | Schema-version 10 (`AddPayoutGiftCards`, ADR-057): udbyder-ID (fx `yith-wcgc`) og kortets ID i pluginet, sat når et valideret gavekort registreres; aldrig koden. NULL for bank og PayPal |

Indexes: `PRIMARY KEY (id)`, `UNIQUE KEY creator_idempotency (creator_id, idempotency_key)`, `KEY creator_status (creator_id, status, currency)`, `KEY status_requested (status, requested_at)`, `UNIQUE KEY gift_card (gift_card_provider, gift_card_external_id)` (ét kort på højst én payout, også ved samtidige requests; NULL-par tæller ikke).

#### `merchboard_payout_secrets`

Krypterede bank- og PayPal-oplysninger (ADR-056), adskilt fra `merchboard_payouts`, så en læsning af anmodninger aldrig læser dem.

| Kolonne | Type | Noter |
| --- | --- | --- |
| `payout_id` | `bigint(20) unsigned NOT NULL` PK | Payouten |
| `kind` | `varchar(20) NOT NULL` | Metoden |
| `ciphertext` | `text NULL` | base64(nonce ‖ sodium `secretbox`) med `MERCHBOARD_PAYOUT_KEY`; NULL, når payouten ikke længere venter |
| `key_id` | `varchar(32) NOT NULL` | Nøglens ID (hash-præfiks, ikke hemmeligt) til senere nøgleskift |
| `created_at`, `purged_at` | `datetime NOT NULL`, `datetime NULL` | UTC; `purged_at` sættes, når ciphertext fjernes |

**Skrivning og samtidighed:** kun `PayoutRepository`, under en `GET_LOCK` pr. creator og i projektets `Transaction`, med `CONNECTION_ID()`-vagt på hver skrivning og forbindelseskontrol før COMMIT (samme mønster som ledgeren, ADR-038 pkt. 18). Audit skrives i samme transaktion.

**Uninstall (ADR-059, B2):** `merchboard_payouts` bevares ved opt-in-uninstall som historisk dokumentation; `merchboard_payout_secrets` (de krypterede betalingsoplysninger) slettes altid. Findes der en ventende payout, eller kan de ikke tælles, sletter afinstallationen intet og afbryder sletningen (se [Afinstallation og datalivscyklus](#afinstallation-og-datalivscyklus)).

### Afregningsbilag ✅ (schema-version 11, fase 23, ADR-060)

> **Foreløbig:** omvendt fakturering efter brugeroplyst mundtlig vejledning fra SKAT; intet er juridisk eller regnskabsmæssigt godkendt (R1–R4). Opbevaring ved opt-in-afinstallation: bevares efter ADR-059 (B2); opbevaringsperioden er uafklaret (R3).

#### `merchboard_settlement_documents`  (kun indsættelse)

| Kolonne | Type | Noter |
| --- | --- | --- |
| `id` | `bigint(20) unsigned` PK | Internt bilags-ID |
| `document_number`, `document_series` | `varchar(32)`, `varchar(10)` | `AFR-000001` (D9: én fortløbende serie `AFR` for afregningsbilag og, indtil ADR-062 K10, korrigerende bilag); fra skabelonversion 5 nummereres et rettelsesbilag ud fra originalen, `AFR-000001-K1` (K10, ingen migration, intet nummer af serien); `UNIQUE` |
| `document_type` | `varchar(12)` | `settlement` eller `correction` |
| `creator_id`, `payout_id` | `bigint(20) unsigned` | Creator og payout |
| `settlement_payout_id` | `bigint(20) unsigned NULL`, `UNIQUE` | Payouten for et afregningsbilag (D8: ét pr. payout); NULL for korrektioner |
| `corrects_document_id` | `bigint(20) unsigned NULL`, `UNIQUE` | Det bilag, en korrektion retter (D11: én korrektion pr. bilag, kæde; et rettelsesbilag retter altid det seneste i kæden, ADR-062 K15) |
| `currency`, `period_from`, `period_to` | `char(3)`, `datetime NULL`, `datetime` | Payoutens valuta; perioden fra forrige bilag (eller afregningsstarten; NULL = hele historikken) til udstedelsen (UTC) |
| `profit_amount`, `commission_amount`, `opening_balance`, `payout_amount`, `closing_balance` | `decimal(19,4)` | Profit og kommission (Σ payout, ADR-049) for de afregnede rækker, overført saldo, udbetalt beløb og saldo efter bilaget (= overført + kommission − udbetalt); 0 for korrektioner, også rettelsesbilag med beløb (de står i snapshottet), så ingen saldo eller sum tæller dem |
| `adjustment_amount` | `decimal(19,4) NOT NULL DEFAULT 0` | Schema-version 14 (ADR-063): summen af de reguleringer, bilaget afregnede; referencerne står i snapshottet (`adjustments`) |
| `vat_registered` | `tinyint(1) unsigned NULL` | Creatorens momsstatus ved udstedelsen (NULL = ukendt) |
| `calculation_version`, `template_version` | `varchar(32)`, `smallint(5) unsigned` | Beregnings- og skabelonversion (1 = foreløbig mærkning, 2 = oplysning om skat og indberetning, 3 = også moms af kommissionen, ADR-062 K05/K12, 4 = også stamdata, ADR-062 K09; 5 = rettelsesbilag med beløb, ADR-062 K10/K15, kun for `correction`; 6 = også moms fratrukket udbetalingen, ADR-064; nye afregningsbilag udstedes med 6 og `calculation_version` `adr064-adr054-v1`) |
| `snapshot` | `longtext` (JSON) | Creatorens ID, visningsnavn og momsstatus, butikkens navn og WooCommerce-adresse, payoutens metode og maskerede modtager eller gavekortets udbyder og ID, karens og afregningsstart; for korrektioner også begrundelse og rettet bilagsnummer (D10: kun eksisterende data). Fra skabelonversion 3 også `commission_vat` (`version`, `rate_bps`, `incl`, `vat`, `excl`, `untaxed`): kommissionens opdeling efter rækkernes momsstatus på salgstidspunktet (`CommissionVat`, ingen migration). Fra skabelonversion 4 også stamdata som ved udstedelsen (ADR-062 K09, ingen migration): `creator.legal_name`, `creator.address` (liste af linjer), `creator.cvr` fra creatormeta `billing_details` og `company.cvr` fra `merchboard_settings['company_cvr']`; "" eller [] = ikke registreret. Fra skabelonversion 6 også `vat_deduction` (ADR-064, ingen migration): `version` (1), `gross`, `deduction` og `net` for rækkerne uden momsregistrering ved salget (Σ profit, Σ profit − Σ payout og Σ payout; `VatDeduction`). Fra skabelonversion 5 har et rettelsesbilag også `correction` (ADR-062 K10/K15, ingen migration): `version` (1), `original` og `corrects` (bilagsnumre), `sequence` (K-løbenummeret), `rate_bps`, `decimals` (butikkens præcision), `before`, `after` og `difference` (hver `commission`, `excl`, `vat`, `incl`, `untaxed`; forskel = efter − før) og `payout` (originalens udbetalte beløb, uændret); kontrolleres, før det vises (`SettlementCorrection`). Aldrig fulde konti, e-mails eller gavekortkoder |
| `issued_by`, `issued_at` | `bigint(20) unsigned NULL`, `datetime` | Bruger og tid (UTC) |

Indexes: `PRIMARY`, `UNIQUE document_number`, `UNIQUE settlement_payout`, `UNIQUE corrects_document`, `KEY creator_currency (creator_id, currency, id)`, `KEY payout_id`.

#### `merchboard_settlement_document_rows` (kun indsættelse)

Én række pr. afregnet ledger-række som snapshot: `event_key` (PK = `UNIQUE`, så en række højst afregnes én gang), `document_id`, `order_id`, `order_item_id`, `product_id`, `variation_id`, `item_name`, `type`, `quantity`, `net_amount`, `tax_amount`, `share_bps`, `vat_registered`, `price_decimals`, `profit_amount`, `payout_amount`, `occurred_at`. Indexes: `KEY document_id`, `KEY order_id` (D5: ADR-052-sletning afvises for ordrer med afregnede rækker).

#### `merchboard_settlement_counters`

`series` (PK) og `next_value`: tællerrækken låses (`FOR UPDATE`) og øges i samme transaktion som udstedelsen; en rollback giver nummeret tilbage, og samtidige udstedelser kan ikke få samme nummer.

**Skrivning:** kun `SettlementRepository` via `SettlementDocuments::issue()`, i samme transaktion og under samme creator-lås som payoutens overgang til `completed` (manuel overførsel og gavekortregistrering), med `CONNECTION_ID()`-vagt. Rækkerne læses med `LOCK IN SHARE MODE` (`LedgerCreatorPayouts::unsettled_rows()`, samme D1/D2-regler som saldoen; en ukendt række afviser gennemførelsen). Ingen bilag med tilbagevirkende kraft (060-8). **Uninstall:** bevares ved opt-in-uninstall som historisk dokumentation (ADR-059, B2; erstatter 060-7 a), så nummerserien fortsætter efter en geninstallation.

### Genberegning og regulering ✅ (schema-version 14 og 15, ADR-063)

Migration 14 (`CreatePayoutRecalculationTables`) tilføjer fire tabeller, og migration 15 (`CreatePayoutRecalculationExportsTable`) eksporttabellen. Migration 15 er en selvstændig migration, fordi version 14 allerede var kørt på Merch Dashboard Local uden eksporttabellen; et miljø på version 14 får tabellen ved opgraderingen. Migration 14 tilføjer også kolonnen `merchboard_settlement_documents.adjustment_amount` (`decimal(19,4) NOT NULL DEFAULT 0`, efter `commission_amount`; 0 for alle eksisterende bilag). For et afregningsbilag gælder: saldo efter bilaget = overført + kommission + regulering − udbetalt. Intet eksisterende beløb, bilag eller payout ændres. Tabellerne bevares ved opt-in-afinstallation (som payouts og bilag).

| Tabel | Indhold | Nøgler |
| --- | --- | --- |
| `merchboard_payout_recalculations` | Én række pr. genberegning: regel (`adr063-remove-20`), beregningsversion, status (`preview`, `approved`, `rejected`, `partially_executed`, `executed`, `closed`), fingeraftryk, `reopens_id`, hvem og hvornår den blev oprettet, afgjort (med note) og afsluttet (med note). Slettes aldrig. | `PRIMARY`, `KEY status` |
| `merchboard_payout_recalculation_items` | Én post pr. genberegning, creator, valuta og gruppe (`active`, `former`, `manual`): årsag, oprindeligt beløb, genberegnet beløb og forskel (NULL ved manuel vurdering), antal rækker, detaljer (JSON: bilag og rækker med gemte input), fingeraftryk, status (`proposed`, `executed`, `failed`, `reported`, `manual`), fejlkode, hvem og hvornår den blev udført. | `UNIQUE (recalculation_id, creator_id, currency, category)` |
| `merchboard_payout_adjustments` | En udført regulering: creator, valuta, beløb med fortegn, regel, genberegning og post, hvem og hvornår, og det bilag, der afregnede den (`settled_document_id`, NULL indtil da). Indsættes kun; ændres kun ved afregningen. | `UNIQUE (recalculation_item_id)`, `KEY creator_currency (creator_id, currency, settled_document_id)` |
| `merchboard_payout_adjustment_rows` | De afregnede rækker, en regulering regulerede, med oprindelig og genberegnet payout. | `PRIMARY (rule, event_key)`: en række reguleres højst én gang pr. regel |
| `merchboard_payout_recalculation_exports` (migration 15) | En eksport af en genberegning (ADR-062 punkt 9): filtre (JSON), filerne (JSON: `items.csv`, `rows.csv`, `timeline.csv`, `manifest.json`, `SHA256SUMS`), SHA-256 af manifest og `SHA256SUMS`, hvem og hvornår. Skrives én gang i den oprettende transaktion; ændres og slettes aldrig. | `PRIMARY`, `KEY recalculation (recalculation_id, id)` |

**Læsning og skrivning:** `PayoutRecalculationRepository` (genberegninger og poster), `PayoutAdjustmentRepository` (reguleringer, i saldoen via `PayoutBalances` og afregnet af `SettlementDocuments::issue()` under creatorens lås). Udførelsen skriver post, regulering og rækker i én transaktion under creatorens lås.

### Historik over momsstatus ✅ (schema-version 16, ADR-062 K06)

Migration 16 (`CreateCreatorVatHistoryTable`) tilføjer `merchboard_creator_vat_history`: én række pr. registrering af en creators momsstatus. Registreringer indsættes kun og ændres eller slettes aldrig; en rettelse er en ny registrering. Der oprettes ingen historik for eksisterende creators: deres nuværende status blev sat før historikken, og tidligere ændringer står i audit-loggen (`creator_vat_status_changed`). Tabellen bevares ved opt-in-afinstallation (som payouts og bilag).

| Kolonne | Type | Noter |
| --- | --- | --- |
| `id` | `bigint(20) unsigned` PK | Registreringens rækkefølge |
| `creator_id` | `bigint(20) unsigned` | Creator |
| `vat_registered` | `tinyint(1) unsigned` | 1 = momsregistreret, 0 = ikke |
| `effective_from` | `date` | Datoen, status gælder fra (sitets kalender); aldrig i fremtiden |
| `checked_on` | `date` | Datoen, status blev kontrolleret (fx i CVR-registret); aldrig i fremtiden |
| `documentation_reference` | `varchar(191)` | Dokumentationsreference (1–191 tegn) |
| `reason` | `varchar(500)` | Begrundelse (1–500 tegn, linjeskift tilladt) |
| `recorded_by`, `recorded_at` | `bigint(20) unsigned NULL`, `datetime` | Bruger og tidspunkt for registreringen (UTC) |

Indexes: `PRIMARY`, `KEY creator_effective (creator_id, effective_from, id)`.

**Opslag:** status på en dato er registreringen med den seneste `effective_from` på eller før datoen og, ved samme dato, den senest registrerede (`CreatorVatHistoryRepository::at()`). **Skrivning:** kun `CreatorVatHistoryRepository::insert()` via `CreatorPricingSettings::record_vat_status()` (`merchboard_manage_creator_pricing`) i én transaktion under en lås af creatorens række: registreringen, `merchboard_creators.vat_registered` sat til den status, historikken giver for i dag, og audit (`creator_vat_history_recorded`, og `creator_vat_status_changed`, når den nuværende status ændres). Ordrelinjernes snapshot (`merchboard_sales_line_pricing.vat_registered`), afregningsbilag (også `commission_vat`), payouts og reguleringer genberegnes aldrig.

### Creator-rabatkoder ✅ (schema-version 17, ADR-068)

Migration 17 (`CreateCreatorCodeTables`) tilføjer to tabeller til creatorens egen rabatkode. Der ændres intet eksisterende, og der dannes ingen rækker for eksisterende ordrer: en kode gælder fra sin registrering og frem (K1). Begge tabeller bevares ved opt-in-afinstallation (ADR-059), fordi de forklarer, hvorfor en ordre mangler i salg og bilag.

#### `merchboard_creator_codes` (registreringer, bevares permanent)

| Kolonne | Type | Noter |
| --- | --- | --- |
| `id` | `bigint(20) unsigned` PK | Registreringen |
| `creator_id` | `bigint(20) unsigned` | Creator |
| `code` | `varchar(191)` | Normaliseret kuponkode (`wc_format_coupon_code()` + `wc_strtolower()`) |
| `coupon_id` | `bigint(20) unsigned` | WooCommerce-kuponens ID ved registreringen |
| `valid_from`, `valid_to` | `datetime`, `datetime NULL` | Perioden [fra, til) i UTC; `valid_to` NULL = aktiv |
| `active_creator_id`, `active_code` | `bigint(20) unsigned NULL`, `varchar(191) NULL` | Creator og kode, kun mens registreringen er aktiv (ellers NULL) |
| `created_by`, `ended_by` | `bigint(20) unsigned NULL` | Brugerne, der registrerede og afsluttede |

Indexes: `PRIMARY`, `UNIQUE active_creator (active_creator_id)` og `UNIQUE active_code (active_code)` (højst én aktiv kode pr. creator og én aktiv creator pr. kode; flere NULL er tilladt), `KEY code_period (code, valid_from)`, `KEY creator (creator_id, id)`.

**Skrivning:** kun `CreatorCodeRepository::insert()` og `end()` via `CreatorCodeService`, under navngivne låse (creator, derefter kode) og i en transaktion med audit. En registrering ændres kun ved at blive afsluttet og slettes aldrig.

#### `merchboard_order_exclusions` (én afgørelse pr. ordre og creator)

| Kolonne | Type | Noter |
| --- | --- | --- |
| `order_id` | `bigint(20) unsigned` PK | WooCommerce-ordren |
| `status` | `varchar(20)` | `excluded`, eller `open` (creatorens rækker på ordren lå i et udstedt bilag, K2) |
| `reason` | `varchar(20)` | `creator_code` |
| `creator_id` | `bigint(20) unsigned` PK | Kodens creator, hvis linjer i ordren er udelukket |
| `code_id`, `code` | `bigint(20) unsigned`, `varchar(191)` | Den matchede registrering og dens kode |
| `coupon_deviation` | `tinyint(1) unsigned` | 1 = kuponen afveg fra K5 ved afgørelsen |
| `order_created_at`, `assessed_at` | `datetime` | Ordrens WooCommerce-oprettelse og afgørelsen (UTC) |

Indexes: `PRIMARY (order_id, creator_id)`, `KEY status_assessed (status, assessed_at)`, `KEY creator (creator_id)`.

**Skrivning:** kun `OrderExclusionRepository::insert_on_connection()` fra synkroniseringen, under ordrens lås, på låsens forbindelse og i samme transaktion som audit-posten. **Læsning (M2):** alle læsemodeller af `merchboard_sales` udelader rækker, hvis ordre og creator har en afgørelse, med `OrderExclusionRepository::JOIN`/`COUNTS` eller `SALES_NOT_EXCLUDED`. Andre creators' rækker i samme ordre tæller normalt. Afregnede rækker tæller fortsat i payout-saldoen. Ledger-rækker ændres eller slettes aldrig.

### Købs-alerts ✅ (schema-version 18, ADR-065)

Migration 18 (`CreateAlertEventsTable`) tilføjer `merchboard_alert_events`: én række pr. betalt ordrelinje og creator, med det, alerten viser, som det var, da ordren blev betalt. Der oprettes ingen alerts for ordrer fra før migrationen. Tabellen slettes ved opt-in-afinstallation (ADR-025); den bevares ikke som historik.

| Kolonne | Type | Noter |
| --- | --- | --- |
| `id` | `bigint(20) unsigned` PK | Rækkefølge |
| `creator_id` | `bigint(20) unsigned` | Den creator, produktet var tilknyttet (`ProductCreatorLink`) |
| `order_id`, `order_number` | `bigint(20) unsigned`, `varchar(64)` | Ordren og ordrenummeret, som kunden ser det |
| `order_item_id` | `bigint(20) unsigned` | Ordrelinjen |
| `product_id`, `variation_id` | `bigint(20) unsigned` | Parent-produkt og variation (0 = ingen) |
| `product_name`, `variation` | `varchar(255)` | Linjens navn ved salget og variationens attributter som tekst ("" = ingen) |
| `quantity` | `int(10) unsigned` | Antal (mindst 1) |
| `image_id` | `bigint(20) unsigned` | Variationens billede, ellers produktets (0 = intet) |
| `category_ids` | `varchar(1000)` | JSON-liste over produktets direkte `product_cat`-termer (højst 40) |
| `customer_username` | `varchar(191)` | Kundens frivillige brugernavn fra checkout ("" = intet). **Personoplysning** |
| `created_at` | `datetime` | Hvornår alerten blev registreret (UTC) |

Migration 18 oprettede også `customer_first_name` (kundens fornavn); migration 19 fjerner kolonnen igen, fordi alerts kun må vise brugernavnet (projektejerens beslutning 2026-10-19, ADR-065, statusnote).

Indexes: `PRIMARY`, `UNIQUE order_item_creator (order_item_id, creator_id)` (højst én alert pr. linje og creator, også når ordren går fra processing til completed), `KEY creator_created (creator_id, created_at, id)` og `KEY order_id`. Tekst afkortes til kolonnelængden før indsættelse. **Skrivning:** kun `AlertEventRepository::insert()` via `AlertRouter` (`woocommerce_order_status_processing`/`_completed`), og kun når `alerts_enabled` er slået til, og MerchAlert ikke er aktivt. Brugernavnet læses fra ordre-meta `_merchboard_alert_username` med MerchAlerts `_ccmerch_username` som fallback (ADR-065 pkt. 5 og 9). **Læsning:** overlay-feedet læser en creators alerts efter en cursor (`id > after`), så ingen alert springes over eller leveres to gange.

### Alert-overlays ✅ (schema-version 19, ADR-065)

Migration 19 (`CreateAlertOverlaysTable`) tilføjer `merchboard_alert_overlays` og fjerner `customer_first_name` fra `merchboard_alert_events` (se ovenfor). Én række pr. creator. Tabellen slettes ved opt-in-afinstallation (ADR-025).

| Kolonne | Type | Noter |
| --- | --- | --- |
| `id` | `bigint(20) unsigned` PK | |
| `creator_id` | `bigint(20) unsigned` UNIQUE | Creator |
| `overlay_id` | `char(32)` UNIQUE | Hemmeligt ID i OBS-URL'en: 128 tilfældige bit som 32 hex-tegn (MerchAlerts format, så importerede URL'er virker). Sammenlignes også med `hash_equals()` |
| `settings` | `longtext` (JSON) | Overlay-indstillinger med MerchAlerts nøgler (`OverlaySettings`); valideres ved hver læsning |
| `created_at`, `updated_at` | `datetime` | UTC; `updated_at` indgår i overlayets version |

**Skrivning:** `AlertOverlayRepository::create()` (fra trin 25.4 via creatorens dashboard). Fra trin 25.5 gemmer `settings` også creatorens font (`font_family`: `inherit` eller en godkendt familie) og designerens `layout` (bokse for billede, hovedtekst og sekundær tekst på et 1920 × 1080-lærred og snap-to-grid); ingen migration. **Læsning:** overlay-siden og feedet slår overlayet op på `overlay_id`.

### Fremtidige (ikke designet i detaljer)

- `merchboard_creator_members` – hvis flere brugere (fx en manager) skal have adgang til samme creator. Erstatter `user_id`-kolonnen. Ændringen bliver lokal, fordi al opslag af "nuværende creator" går gennem `CreatorContext`.
- `merchboard_product_creators` – hvis produkter skal kunne deles mellem creators med procentvis split.
- `merchboard_sales_daily` – præ-aggregeret statistik, **kun** hvis målinger viser, at queries på `merchboard_sales` bliver for langsomme.
- Afregningsbilag (fase 23, trin 23.8, ADR-060).
- `merchboard_integrations_*` – synkroniseringsstatus for eksterne tjenester.

## Schemaversion og migrationer ✅ (ADR-019)

| Del | Implementering |
| --- | --- |
| Version | Heltal. Gemt i option `merchboard_db_version` (autoload), `0`/manglende = ikke installeret. Nuværende version: **8** (creator-andel i snapshottet, produkt-markup og droppet `merchboard_item_pricing`, ADR-049; version 7: creator-momsstatus og moms pr. ledger-række, ADR-048; version 6: creator pricing, ADR-045–047; version 5: `merchboard_sales` og `merchboard_sales_cycles`, ADR-037/038). |
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
- Aggregeringer til dashboardet caches i object cachen (gruppe `merchboard_sales`, en generation pr. creator, 15 minutter; ingen transients, ADR-040 pkt. 10) og invalideres efter hver committet `SalesRepository::write()` med nye rækker for creatoren (fase 20, trin 4). Cachen efterlader intet i databasen, så uninstall (ADR-025) skal ikke rydde op efter den.

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
| **Permanent sletning** ✅ fase 9 | Kun med eksplicit opt-in under Merch Dashboard → Settings (se [ADMIN.md](ADMIN.md#settings)). Så sletter `Core\Lifecycle\Uninstaller` pluginets egne data for det aktuelle site, se inventory nedenfor (ADR-025), undtagen den historik, ADR-059 bevarer. Er en payout ventende, slettes intet, og sletningen afbrydes (ADR-059). |
| **Creator "slettes"** | Status `inactive` (soft). Admin-UI'et har bevidst **ingen** "Delete"-funktion (fase 6). Hard delete er ikke understøttet, fordi audit trail og fremtidig salgshistorik refererer til creator-ID'et. Permanent sletning af alle data er opt-in (✅ fase 9, ADR-025). |
| **WordPress-bruger slettes** | Se relationen ovenfor (✅ fase 8). Creator-record, creator-data og audit bevares. Creator'en frakobles og deaktiveres. |

```text
Deaktivering
→ data bevares (tabeller, options, rolle og capabilities uændrede)

Afinstallation (slet plugin) uden eksplicit opt-in
→ data bevares

Afinstallation med eksplicit opt-in ("Delete Merch Dashboard data when the plugin is deleted")
→ ventende payout (enhver metode) eller fejl ved optællingen: intet slettes, og sletningen af pluginet afbrydes (ADR-059)
→ ellers slettes Merch Dashboards egne data, inkl. product meta `_merchboard_creator_id` (fra fase 17, ADR-030) og `_merchboard_creator_link_source` (ADR-033)
→ bevares som historisk dokumentation (ADR-059): creators (arkiveret), audit-loggen, payouts, afregningsbilag, bilagsrækker og bilagstællere
→ alle andre WordPress- og WooCommerce-data bevares
```

| Plugin-ejet state (fuld inventory, fase 9; ADR-059) | Slettes ved opt-in-uninstall |
| --- | --- |
| Tabeller `{prefix}merchboard_creatormeta`, `{prefix}merchboard_creator_categories`, `{prefix}merchboard_sales`, `{prefix}merchboard_sales_cycles`, `{prefix}merchboard_pricing_overrides`, `{prefix}merchboard_sales_line_pricing`, `{prefix}merchboard_sales_tax`, `{prefix}merchboard_payout_secrets` (de krypterede betalingsoplysninger) `{prefix}merchboard_alert_events` (købs-alerts) og `{prefix}merchboard_alert_overlays` (alert-overlays), samt den udgåede `{prefix}merchboard_item_pricing`, hvis den stadig findes (`Tables::retired()`) | Ja: `DROP TABLE IF EXISTS` med præcise navne for det aktuelle sites prefix (`SchemaEraser`) |
| Tabeller `{prefix}merchboard_creators`, `{prefix}merchboard_audit_log`, `{prefix}merchboard_payouts`, `{prefix}merchboard_settlement_documents`, `{prefix}merchboard_settlement_document_rows` og `{prefix}merchboard_settlement_counters` (`Tables::retained_on_uninstall()`) | **Nej** (ADR-059, B2): historisk dokumentation. Før sletningen arkiveres alle creators (`status = inactive`, `user_id = NULL`), så ingen bevaret record kan give adgang. Betalingsoplysninger findes kun maskeret (fx `•••• 1234`). Ingen automatisk sletning; opbevaringsperioden afventer revisor (R3) |
| Option `merchboard_restored_history` (`archived_at`, `max_creator_id`) | **Nej**: skrives efter arkiveringen og før tabellerne droppes. Creators med ID til og med `max_creator_id` er arkiverede og vises med advarsel efter en geninstallation. `Installer` udleder den aldrig; ved frisk installation sletter den den kun, hvis creator-tabellen mangler |
| Ordremeta `_merchboard_ledger_failure` (fejlmarkøren, ADR-038) | Ja: legacy-postmeta via `delete_post_meta_by_key()`; HPOS-ordremeta kun via WooCommerce CRUD (ordrerne findes med `meta_key` + `meta_compare => EXISTS`), mens WooCommerce er aktivt (ellers bliver den liggende, ADR-038 Q3). Ingen direkte SQL mod WooCommerce-tabeller. |
| Options `merchboard_db_version`, `merchboard_db_migration_lock`, `merchboard_roles_version` | Ja (også når tabeller bevares: en geninstallation kører alle migrationer igen hen over de bevarede tabeller; de skriver ingen data og ændrer ingen bevarede rækker; testet på MariaDB 10.11 i `ReinstallAfterUninstallTest`, MySQL 8 afventer CI) |
| Transient `merchboard_db_migration_failed` | Ja |
| Alert-lyde: vedhæftninger med post meta `_merchboard_alert_sound_creator` (fase 25, trin 25.6) eller `_merchboard_alert_sound_library` (trin 25.6.3) | **Nej**: lydfilerne er creatorens uploads og bevares som almindelige medier (sammen med meta-nøglen); de kan slettes i mediebiblioteket |
| Options `merchboard_alert_fonts_key` (Google Fonts API-nøglen) og `merchboard_alert_fonts_approved`, transient `merchboard_alert_fonts_catalog` (fase 25, trin 25.5, ADR-067) | Ja, sammen med de øvrige options |
| Option `merchboard_settings` (inkl. opt-in'en) | Ja, **sidst** |
| Rollen `merchboard_creator` | Ja: fjernes fra brugere (andre roller bevares) og derefter som rolle |
| Capabilities `merchboard_access_dashboard`, `merchboard_manage`, `merchboard_manage_creators`, `merchboard_manage_settings`, `merchboard_manage_creator_pricing` og `merchboard_delete_order_history` (fase 22) (`Capabilities::all()`) | Ja: fra alle roller og fra brugere, der har fået dem direkte. Kun disse præcise navne, ikke alt med prefix `merchboard_`. |
| Tekniske log-poster (WooCommerce-log, source `merchboard`, eller `debug.log`) | Nej: ejes af WooCommerce/serveren og ryddes af deres retention |
| Product meta `_merchboard_creator_id` (produkt→creator-koblingen, fra fase 17) og `_merchboard_creator_link_source` (oprindelsen, ADR-033) | Ja: kun disse præcise nøgler, på de produkter og varianter hvor de findes, for det aktuelle site. Via WordPress' meta-API (virker også uden WooCommerce), idempotent og før tabellerne droppes. Når metadata-vagten er indlæst, tillader dens uninstall-vindue kun denne sletning (ADR-036: `WP_UNINSTALL_PLUGIN` er Merch Dashboards basename, og opt-in er aktivt). Selve produkterne bevares (ADR-030) |
| WordPress-brugere, andre roller/capabilities, posts, sider, media, WooCommerce-produkter, -ordrer og -kunder, **al anden** WooCommerce- og produkt-meta end `_merchboard_creator_id` og `_merchboard_creator_link_source`, produktkategorier (`product_cat`-terms og deres tilknytninger), andre plugins' data, themes | **Aldrig** |
| WooCommerce-priser (regular price, sale price, `_price`) | **Aldrig**: Merch Dashboard skriver dem ikke (ADR-049), og de nulstilles og slettes ikke. Kun pluginets egne pricing-tabeller slettes (testet i `UninstallTest`) |

**Strategi (ADR-025):** `DROP TABLE` committer implicit og kan ikke indgå i en transaktion, så der er ingen falsk atomicitet. Rækkefølgen er i stedet: brugere → roller → tabeller → options → `merchboard_settings` **sidst**. Fra fase 17 slettes product meta `_merchboard_creator_id` (og fra ADR-033 `_merchboard_creator_link_source`) efter roller og før tabeller (ADR-030). Hvert trin er idempotent (`IF EXISTS`, manglende roller og options ignoreres). Fejler et tabel-drop, logges det, og der slettes ingen options. Opt-in'en bevares derfor, og den næste afinstallation fortsætter. Gentagen afinstallation er sikker. Uninstall skriver ingen audit-records.

**ADR-059 (B2):** Med opt-in tælles de ventende payouts først (alle metoder). Er der én, eller fejler optællingen, ændres intet (heller ikke backfill-jobs), og `uninstall.php` afslutter slette-requesten, så WordPress beholder pluginets filer: `wp_send_json_error()` på Plugins-skærmen (et AJAX-request pr. plugin), `WP_CLI::error()` i WP-CLI og `wp_die()` ellers. **Sideeffekt:** ved sletning uden JavaScript og i WP-CLI med flere plugins i samme request behandles plugins efter Merch Dashboard ikke. Ellers arkiveres creators efter meta-trinnene og før tabellerne (fejler arkiveringen, droppes intet, og opt-in'en bevares). En geninstallation understøtter kun historisk dokumentation: genaktivering af arkiverede creators og normal payout-drift for dem er ikke understøttet (ledgeren genopbygges ud fra de nuværende produktkoblinger, så ordrer kan blive tilskrevet en anden creator end i de bevarede bilag). Det er dokumenteret og advaret, ikke blokeret.

**ADR-059, kendte fejltilstande og forudsætninger:**
- **Fejlet arkivering:** fejler arkiveringen af creators, droppes ingen tabeller, og ingen options slettes, inklusive opt-in'en og schema-versionen. Afinstallationen afbrydes ikke af den grund: `uninstall.php` returnerer normalt, og WordPress kan stadig slette pluginets filer. Alle data bliver så liggende, inklusive `payout_secrets` og ledgeren, mens roller, capabilities og meta allerede kan være fjernet (de trin kører før arkiveringen). En senere geninstallation og afinstallation fortsætter forløbet. Det svarer til en fejlet `DROP`.
- **Fejlet skrivning af markøren (sjælden):** fejler skrivningen af `merchboard_restored_history` efter en vellykket arkivering, droppes ingen tabeller, men creators er allerede arkiveret uden markør. Efter en geninstallation vises advarslen om arkiverede creators så ikke, før en ny afinstallation har skrevet markøren.
- **Afbrydelsen ved ventende payouts** forudsætter, at WordPress' afslutningshandlere (`wp_send_json_error()`, `wp_die()` og `WP_CLI::error()`) faktisk afslutter requesten, som WordPress' og WP-CLI's egne handlere gør. En handler fra tredjepart, der ikke afslutter, ville lade WordPress slette filerne. Data er stadig uberørte, fordi blokeringen sker før enhver ændring.

**Multisite (verificeret i WordPress 7.1.2's kildekode, ikke officielt understøttet):** `delete_plugins()` kalder `uninstall_plugin()` én gang uden `switch_to_blog()`. Tabelnavne bygges med det aktuelle sites `$wpdb->prefix`, roller ligger i det aktuelle sites `{prefix}user_roles`, og options og brugernes capabilities er pr. site. Afinstallation kan derfor kun slette det aktuelle sites data, og kun hvis opt-in'en er sat på det site. Andre sites' tabeller, roller og options røres ikke (testet med en tabel med lignende navn for et andet site). Data på andre sites i netværket bliver dermed liggende, selv hvis opt-in er sat der. Multisite-oprydning kræver en senere beslutning.
| **GDPR** | Integration med WordPress' personal data exporter/eraser for creator-data (fase efter MVP). |
