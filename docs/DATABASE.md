# Database og datamodel

> Status: **Design.** Ingen tabeller er oprettet endnu. Første migration kommer i roadmap-fase 3.

## Placering af data

| Data | Placering | Begrundelse |
| --- | --- | --- |
| Login-identitet, e-mail, password, navn | `wp_users` | WordPress' auth. Genbruges 100 %. |
| Rolle/capabilities | `wp_usermeta` (`wp_capabilities`) via roles API | Standard |
| Brugerpræferencer uden domæneværdi (fx dashboard-tema) | `wp_usermeta` (`ccmerch_*`) | Hører til login-kontoen, ikke til creator'en |
| **Creator** (status, slug, visningsnavn …) | `ccmerch_creators` (custom) | Domæneobjekt adskilt fra login-kontoen. Se ADR-005. |
| Fleksible creator-felter (bio, sociale links, avatar-ID …) | `ccmerch_creatormeta` (custom, WP metadata API) | Udvidelse uden schemaændring og med gratis meta-cache |
| Plugin-settings | `wp_options`: `ccmerch_settings` (ét array, autoload) | Lille og læses på de fleste requests |
| Schemaversion | `wp_options`: `ccmerch_db_version` | Styrer migrationer |
| Secrets (API-nøgler til integrationer) | `wp-config.php`-konstanter (foretrukket) eller option med `autoload = no` | Aldrig i repo, aldrig autoloaded, aldrig sendt til frontend |
| Produkter, varianter, ordrer, refunds og kunder | **WooCommerce** (HPOS) | Source of truth. Ingen kopi af masterdata. |
| Produkt → creator | WooCommerce product meta `_ccmerch_creator_id` | Én creator pr. produkt i v1. Se nedenfor. |
| Salg pr. creator (historik) | `ccmerch_sales` (custom ledger) | Hurtige aggregeringer og uforanderlig attribution. Se [WOOCOMMERCE.md](WOOCOMMERCE.md). |
| Applikations- og audit-log | `ccmerch_logs` (custom) | Volumen, filtrering og retention |
| Cache af aggregeringer | Transients/object cache | Afledte data, må forsvinde |

**Tommelfingerregel:** WordPress' datamodel bruges, hvor data er *konfiguration* eller *tilhører en eksisterende WP-entitet*. Custom tables bruges, hvor data er *domæneentiteter med relationer, volumen eller behov for effektive queries*.

## Hvorfor ikke …

- **… creators som user meta alene?** Creator'en er en forretningsenhed (brand), som salg, produkter og senere payouts hænger på. Hvis login-kontoen skiftes, deaktiveres eller senere deles af et team, må historikken ikke knække. Med user meta alene bliver "creator" og "login" det samme.
- **… creators som custom post type?** Det er muligt og gratis admin-UI, men post-editor-UX'en passer dårligt, der er ingen unikhedsgaranti på `user_id`, `post_status` skal misbruges til creator-status, og der er risiko for at lække data via generiske post-queries og søgning. Creator-antallet er lille, så volumen er *ikke* argumentet. Klarhed og integritet er.
- **… en `ccmerch_products`-tabel?** WooCommerce ejer produkter. Kopier giver synk-fejl.

## Tabeller (planlagte)

Alle tabeller oprettes med `dbDelta()` via `$wpdb->get_charset_collate()` og prefixes `{$wpdb->prefix}ccmerch_`. Der bruges ingen fremmednøgler (WordPress-konvention, og `dbDelta` understøtter dem dårligt). Integriteten håndhæves i services. Tidspunkter gemmes i **UTC** (`DATETIME`) og konverteres ved visning.

### `ccmerch_creators` (fase 3)

| Kolonne | Type | Noter |
| --- | --- | --- |
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` PK | Creator-ID (ikke = user-ID) |
| `user_id` | `BIGINT UNSIGNED NOT NULL` | Login-konto. **UNIQUE** (én creator pr. bruger i v1) |
| `slug` | `VARCHAR(191) NOT NULL` | **UNIQUE**. Bruges i interne referencer og evt. offentlige URL'er senere. |
| `display_name` | `VARCHAR(191) NOT NULL` | |
| `status` | `VARCHAR(20) NOT NULL` | `active`, `suspended` eller `archived` (PHP backed enum `CreatorStatus`) |
| `created_at` | `DATETIME NOT NULL` | UTC |
| `updated_at` | `DATETIME NOT NULL` | UTC |
| `created_by` | `BIGINT UNSIGNED NULL` | Admin-bruger (audit) |

Indexes: `UNIQUE(user_id)`, `UNIQUE(slug)`, `KEY(status)`.

> **Teams:** Hvis flere brugere (fx en manager) senere skal have adgang til samme creator, erstattes `user_id`-koblingen af en `ccmerch_creator_members(creator_id, user_id, role)`-tabel. Fordi al opslag af "nuværende creator" går gennem `CreatorContext`, bliver ændringen lokal.

### `ccmerch_creatormeta` (fase 3)

Standard WordPress meta-tabel (`meta_id`, `ccmerch_creator_id`, `meta_key`, `meta_value`), så `add_metadata( 'ccmerch_creator', … )`, `get_metadata()` og meta-cachen virker. Tabellen registreres som `$wpdb->ccmerch_creatormeta`. Meta-keys whitelistes i `CreatorService`.

### `ccmerch_logs` (fase 4)

| Kolonne | Type | Noter |
| --- | --- | --- |
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` PK | |
| `created_at` | `DATETIME NOT NULL` | UTC |
| `level` | `VARCHAR(10) NOT NULL` | debug…critical |
| `channel` | `VARCHAR(50) NOT NULL` | fx `audit`, `access`, `woocommerce`, `rest` |
| `message` | `VARCHAR(255) NOT NULL` | Kort, uden PII |
| `context` | `LONGTEXT NULL` | JSON. Aldrig secrets. |
| `user_id` | `BIGINT UNSIGNED NULL` | Actor |
| `creator_id` | `BIGINT UNSIGNED NULL` | Target (hvis relevant) |

Indexes: `KEY(created_at)`, `KEY(channel, level)`, `KEY(creator_id)`. IP-adresser logges **ikke** som standard (GDPR/dataminimering).

### `ccmerch_sales` (fase 18)

Uforanderlig ledger. Én række pr. (ordrelinje × hændelse). Se [WOOCOMMERCE.md](WOOCOMMERCE.md#sales-ledger).

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

- `ccmerch_product_creators` – hvis produkter skal kunne deles mellem creators med procentvis split.
- `ccmerch_sales_daily` – præ-aggregeret statistik, **kun** hvis målinger viser, at queries på `ccmerch_sales` bliver for langsomme.
- `ccmerch_payouts`, `ccmerch_payout_items` – afregning.
- `ccmerch_integrations_*` – synkroniseringsstatus for eksterne tjenester.

## Migrationer

- Klasser i `src/Core/Database/Migrations/` navngivet `Migration_0001_CreateCreatorsTables` osv. Hver har `up()` og en unik stigende version.
- `Migrator` kører alle migrationer med højere version end `ccmerch_db_version`, i rækkefølge, og opdaterer optionen efter hver succesfuld migration.
- Den køres ved aktivering og ved `plugins_loaded`, når kodeversionen er nyere (opdatering uden reaktivering). Den beskyttes med en transient-lås mod samtidige kørsler.
- Migrationer er **fremadrettede og idempotente** (`dbDelta` og "if not exists"-tjek). Der er ingen `down()`. Rollback sker via backup.
- Enhver schemaændring kræver opdatering af denne fil og en linje i [CHANGELOG.md](CHANGELOG.md).

## Queries og performance

- Al SQL går gennem repositories og `$wpdb->prepare()`. Tabelnavne kommer fra en central `Tables`-klasse (aldrig fra input) og indsættes med `%i` (WP ≥ 6.2).
- `ORDER BY` og kolonner fra input valideres mod en whitelist.
- Lister er altid paginerede (default 20, max 100).
- Aggregeringer til dashboardet caches (transient pr. creator og periode) og invalideres ved nye ledger-rækker.

## Afinstallation og datalivscyklus

- **Deaktivering:** fjerner cron-jobs og flusher rewrite rules. Data bevares.
- **Afinstallation (`uninstall.php`):** sletter tabeller, options, meta og rollen **kun** hvis setting'en "Slet alle data ved afinstallation" er slået til (default: fra). Salgshistorik er forretningskritisk.
- **Creator slettes:** v1 bruger status `archived` (soft delete). Hard delete af en creator med salgshistorik er ikke tilladt.
- **WordPress-bruger slettes:** hook på `delete_user` sætter creator'en til `archived` og nulstiller ikke historik. Adgang forsvinder automatisk, fordi login-kontoen ikke findes.
- **GDPR:** Integrer med WordPress' personal data exporter/eraser for creator-data (fase efter MVP).
