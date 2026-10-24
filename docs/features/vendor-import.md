# Feature: Migrering fra WooCommerce Product Vendors

## Purpose
Projektejerens creators er i dag oprettet som vendors i WooCommerce Product Vendors (PV). Migreringen flytter dem til Merch Dashboard én gang, så de ikke skal oprettes i hånden (ROADMAP fase 26, ADR-074).

## Current status
I gang (fase 26).
- **26.1 tørkørsel:** implementeret 2026-10-24, læser kun. Projektejeren har kontrolleret den på Local med Product Vendors 2.4.9.
- **26.2 migrering:** implementeret 2026-10-24, testet automatisk.
- **26.2.1 produktkategori:** implementeret 2026-10-24, testet automatisk.
- **26.3 fjernelse af PV-roller:** implementeret 2026-10-24, testet automatisk.
- **26.4 review og runtime-test:** udestår.

Udgives sammen med fase 25 som 1.1.0.

## User-facing functionality
Ingen for creators før migreringen. Bagefter har den migrerede bruger en creator med vendorens produkter og adgang til creator-dashboardet (ADR-006/020).

## Admin functionality
Undermenuen **Merch Dashboard → Import fra Product Vendors** vises, mens PV er aktivt, eller når en migrering er kørt.
- **Siden:** forklarer, hvad der flyttes og hvad der ikke gør, og har knappen **Tørkørsel**.
- **Tørkørslen (26.1):** viser pr. vendor:
  - **Bruger:** vendorens godkendte administratorer (Vendor Admin og Vendor Manager), med en liste, når der er flere. Valget sendes som `user[term_id]`, og "Opdatér tørkørslen" viser resultatet.
  - **Hvad der sker:**
    - ny creator;
    - brugerens eksisterende creator bruges;
    - allerede flyttet;
    - creator uden bruger;
    - springes over (venter på godkendelse, eller brugeren er valgt til en anden vendor).
  - **Produktkategori (26.2.1):** kategorien, der kobles til creatoren, så nye produkter og nye salg kobles automatisk. Forslaget er den kategori, flest af vendorens produkter ligger i (aldrig standardkategorien); listen viser først vendorens kategorier med antal produkter, derefter alle andre, og "Ingen". En creator, der har en kategori, beholder den. En kategori hos en anden creator eller valgt til en anden vendor kobles ikke og vises med en forklaring. Valget sendes som `category[term_id]` (0 = ingen).
  - **Produkter:** kobles / allerede koblet / bliver stående, fordi de tilhører en anden creator.
  - **Afregningsstart:** "sættes til migreringens tidspunkt" for nye creators; ellers creatorens nuværende afregningsstart.
  - **Advarsel:** når en creator med samme navn findes. Navne matches aldrig.
- **Tørkørslen skriver intet.**
- **Migreringen (26.2):** under tørkørslen står formularen med de valgte brugere, tørkørslens fingerprint og fluebenet "Jeg har taget en backup af sitet, og Product Vendors er aktivt" og knappen **Flyt vendors**.
  - En frisk tørkørsel med de samme valg skal give samme fingerprint; ellers afvises den ("Data er ændret siden tørkørslen").
  - Pr. vendor oprettes eller genbruges creatoren, nye creators får afregningsstart = migreringens tidspunkt (én gang), og vendorens frie produkter kobles.
  - Siden viser bagefter en besked og en linje om seneste migrering (creators oprettet og brugt, produkter koblet, efterladt hos en anden creator og fejlede).
  - En genkørsel tilføjer kun det, der mangler.
- **Fjern Product Vendors-roller (26.3):** et afsnit på siden, så længe migrerede brugere stadig har en PV-rolle (også efter at PV er deaktiveret).
  - Listen viser bruger, creator og PV-roller, med alle markeret. Administrerer brugeren også en vendor, der ikke er migreret, vises en advarsel: fjernelsen afslutter også den adgang.
  - Fluebenet "Jeg har kontrolleret de migrerede creators og deres produkter" og knappen **Fjern Product Vendors-rollerne**.
  - Kun PV's tre roller fjernes, og kun hos de markerede migrerede brugere. Brugerne beholder deres andre roller og deres creator-adgang.

**Regler (projektejerens valg 2026-10-24):**
- **Match:** via brugeren.
- **Ny creator:** får vendorens navn og den valgte bruger. Brugeren foreslås i denne rækkefølge: en bruger, der allerede har en creator; ellers Vendor Admin før Vendor Manager; ellers den ældste.
- **Ventende vendors:** springes over.
- **Produkter koblet til en anden creator:** erstattes aldrig.
- **Afregningsstart:** nye creators får den sat til migreringens tidspunkt.
- **Flyttes ikke:** kommission, udbetalinger, PayPal og øvrige PV-data.

## Technical implementation
- **`ProductVendorsSource`:** interface til PV. Adapteren er `ProductVendorsPlugin`, som læser kun mens PV er aktivt:
  - **Vendors:** taxonomien `wcpv_product_vendors`.
  - **Administratorer:** foreningsmængden af `WC_Product_Vendors_Utils::get_vendor_data_by_id()['admins']` og `get_all_vendor_data( $user_id )` for brugere med PV's tre roller. Siden PV 2.1.80 ligger listen et ikke-offentligt sted, som kun PV selv kender.
  - **Admins-værdien:** normaliseres som PV gør, dvs. en liste, kommasepareret tekst eller ét id (`ProductVendorsPlugin::admin_ids()`).
  - **Produkter:** forældreprodukter med vendorens term; ikke variationer, trash eller auto-drafts.
- **`VendorImportPlan::build( $choices )`:**
  - kræver `merchboard_manage_creators` og `merchboard_manage_payouts`;
  - giver en `VendorImportReport` med statusserne `new`, `matched`, `imported`, `no_admin`, `pending` og `user_taken`, produkttal og afregningsstart;
  - har et fingerprint (sha256 af rapporten inkl. de valgte brugere).
- **`VendorImportPage`:** undermenu med prioritet 29, før Audit log.
- **`VendorImporter::run( $fingerprint, $choices )` (26.2):** bygger en frisk tørkørsel med de samme valg og kræver samme fingerprint. Uden ydre transaktion (servicerne har hver deres, og transaktioner kan ikke indlejres), men hvert trin er idempotent:
  1. creator: tidligere kørsels (optionen), den valgte brugers, eller en ny aktiv via `CreatorService::create()` (kobler brugeren og giver rollen `merchboard_creator`); en ny skrives straks i optionen;
  2. afregningsstart: for en creator, migreringen har oprettet, én gang via `PayoutSettings::set_settlement_start()`. En afregningsstart, administratoren ændrer bagefter, sættes aldrig igen;
  3. produktkategori (26.2.1): tørkørslens kategori kobles via `CreatorCategoryService::link()`, som også sætter kategoriens afstemning i gang (ADR-033). Fejler det (fx en deaktiveret creator), tælles det;
  4. produkter: frie produkter kobles via `ProductCreatorLink::link()` (kilde `admin`); produkter hos en anden creator efterlades;
  5. audit `vendor_imported`.

  En vendor, der fejler (fx en deaktiveret creator, hvis produkter ikke kan kobles), tælles, og kørslen fortsætter. Til sidst skrives seneste kørsel og `vendor_import_completed` i én transaktion.
- **`VendorImportActions`:** admin-post `merchboard_vendor_import`: capability → nonce → backup → fingerprintets form → migreringen; Post/Redirect/Get med en resultatkode.
- **`VendorRoleCleanup` (26.3):** `users()` giver de migrerede creators' brugere (optionens `vendors`), der stadig har en PV-rolle, med vendors, der ikke er migreret, som de også administrerer (kun mens PV er aktivt). `remove( $user_ids )` kræver `merchboard_manage_creators` og `promote_users` og ændrer kun bekræftede brugere, der stadig er på listen: `WP_User::remove_role()` for PV-rollerne og audit `vendor_roles_removed` i én transaktion pr. bruger.
- **`VendorRoleActions` (26.3):** admin-post `merchboard_vendor_roles`: capability → nonce → bekræftelse → fjernelsen; Post/Redirect/Get.
- **Wiring:** `WooCommerceModule`.

## Relevant files
- `src/Modules/WooCommerce/VendorImport/` (`ProductVendorsSource`, `ProductVendorsPlugin`, `VendorImportPlan`, `VendorImportReport`, `VendorImportException`, `VendorImportPage`)
- `tests/Integration/WooCommerce/VendorImportTest.php`, `tests/Unit/Modules/WooCommerce/VendorImport/ProductVendorsPluginTest.php`, `tests/Fixtures/product-vendors.php`

## Database/data used
- **Læses:** `merchboard_creators` og produkternes `_merchboard_creator_id` (ADR-030); creatorens afregningsstart (`merchboard_creators.payout_start_at`, ADR-054); PV's taxonomi, term meta `vendor_data` og roller gennem PV's API.
- **Option:** `merchboard_vendor_import`: `vendors` (vendor-term → creator), `created` (creators, migreringen har oprettet), `settled` (creators, hvis afregningsstart er sat), `completed_at` og `last` (tal). Slettes ved opt-in-afinstallation.
- **Skrives (26.2):** `merchboard_creators` (via `CreatorService`), `merchboard_creator_categories` (via `CreatorCategoryService`, 26.2.1), brugerens ekstra rolle `merchboard_creator`, produkternes kobling (via `ProductCreatorLink`), afregningsstart (via `PayoutSettings`) og audit. Product Vendors' data skrives aldrig.

## Permissions
- **Siden:** `merchboard_manage_creators`.
- **Tørkørslen:** desuden `merchboard_manage_payouts`, fordi migreringen sætter afregningsstart.
- **Brugervalget:** er read-only GET-parametre, der valideres til positive heltal. Et valg, der ikke er vendorens godkendte administrator, ignoreres.

## API endpoints
Ingen.

## Hooks
`admin_menu` (prioritet 29), `admin_post_merchboard_vendor_import` (26.2) og `admin_post_merchboard_vendor_roles` (26.3). De to admin-post-handlere bygges først, når formularen sendes.

## Dependencies
WooCommerce-modulet (`ProductCreatorLink`, `PayoutSettings`) og WooCommerce Product Vendors (aktivt) til migreringen.

## Tests
- **Integration (`VendorImportTest`):** med et fixture af PV's offentlige 2.x-API, inkl. administratorer gemt uden for `vendor_data`:
  - statusserne, forslaget og valget af bruger;
  - én bruger pr. vendor;
  - produkttal og konflikter;
  - afregningsstart;
  - genkendelse af en tidligere migrering;
  - afvisning uden rettigheder eller med PV inaktivt;
  - siden og valideringen af valg;
  - at tørkørslen intet skriver (snapshot af creators, audit, produktkoblinger, roller, PV's data og optionen);
  - rollerne (26.3): kun migrerede brugere med en PV-rolle, advarslen om en anden vendor, kun de bekræftede brugere og kun PV's roller, creator-adgangen bevares, bekræftelse kræves, og uden `promote_users` afvises det;
  - produktkategorien (26.2.1): forslaget (aldrig standardkategorien), valg af en anden eller ingen, en creators egen kategori bevares, en anden creators kategori flyttes aldrig, fingerprintet følger valget, og migreringen kobler kategorien;
  - migreringen: creators, brugerkobling og rolle, produktkobling, afregningsstart kun for nye creators, PV's data uændret, audit, en genkørsel uden dubletter, en afregningsstart administratoren fjernede sættes ikke igen, forældet fingerprint og manglende backup afvises, og en vendor med en deaktiveret creator tælles som fejl, mens resten flyttes.
- **Unit (`ProductVendorsPluginTest`):** admins-værdiens former.

## Known limitations
- PV's datamodel efter 2.0.27 er ikke offentlig. Derfor læses alt gennem PV's eget API, og tørkørslen viser PV's version, så projektejeren kan kontrollere resultatet på Local før migreringen.
- En creator har én bruger (ADR-020). En vendors øvrige administratorer flyttes ikke.
