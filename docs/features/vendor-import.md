# Feature: Migrering fra WooCommerce Product Vendors

## Purpose
Projektejerens creators er i dag oprettet som vendors i WooCommerce Product Vendors (PV). Migreringen flytter dem til Merch Dashboard én gang, så de ikke skal oprettes i hånden (ROADMAP fase 26, ADR-074).

## Current status
Implementeret og runtime-testet (fase 26); udgives med 1.1.0.
- **26.1 tørkørsel:** implementeret 2026-10-24, læser kun. Projektejeren har kontrolleret den på Local med Product Vendors 2.4.9.
- **26.2 migrering:** implementeret 2026-10-24, testet automatisk.
- **26.2.1 produktkategori:** implementeret 2026-10-24, testet automatisk.
- **26.3 fjernelse af PV-roller:** implementeret 2026-10-24, testet automatisk.
- **26.4 review og runtime-test:** reviewet er gennemført 2026-10-25, og rettelserne er implementeret og testet automatisk (projektejerens valg 2026-10-25, se "Regler"). Runtime-testen på Local er bestået 2026-10-25 (41 af 42 punkter OK; backuppen før testen sprunget over).

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
    - springes over: venter på godkendelse; brugeren er valgt til en anden vendor; administratorerne har ingen Product Vendors-rolle (fx en WordPress-administrator); eller en tidligere kørsel blev afbrudt, mens den oprettede vendorens creator uden bruger (kontrollér creators først).
  - **Deaktiveret creator:** en note, når brugerens creator er deaktiveret. Produkter og kategori kan ikke kobles til den, før den aktiveres.
  - **Produktkategori (26.2.1):** kategorien, der kobles til creatoren, så nye produkter og nye salg kobles automatisk. Listen viser først vendorens kategorier med antal produkter, derefter alle andre, og "Ingen". Valget sendes som `category[term_id]` (0 = ingen).
    - **Forslaget:** den ledige kategori, flest af vendorens produkter ligger i, men kun en kategori, der ikke rummer andre produkter end vendorens (aldrig standardkategorien). Findes ingen, foreslås "Ingen" med en forklaring.
    - **Konsekvensen:** kategoriens afstemning kobler også alle andre ukoblede produkter i kategorien til creatoren, og produkter, der er koblet til en anden creator via kategori, bliver tvetydige (ADR-033). Tørkørslen viser begge tal for den valgte kategori.
    - En creator, der har en kategori, beholder den. En kategori hos en anden creator eller valgt til en anden vendor kobles ikke og vises med en forklaring.
  - **Produkter:** kobles / allerede koblet / bliver stående, fordi de tilhører en anden creator eller har en ugyldig kobling.
  - **Afregningsstart:** "sættes til migreringens tidspunkt" for nye creators; ellers creatorens nuværende afregningsstart.
  - **Advarsel:** når en creator med samme navn findes. Navne matches aldrig.
- **Tørkørslen skriver intet.**
- **Migreringen (26.2):** under tørkørslen står formularen med de valgte brugere og kategorier, tørkørslens fingerprint og fluebenet "Jeg har taget en backup af sitet, og Product Vendors er aktivt" og knappen **Flyt vendors**.
  - Ændrer administratoren et valg uden at opdatere tørkørslen, deaktiveres **Flyt vendors** med en note, indtil tørkørslen er opdateret.
  - En frisk tørkørsel med de samme valg skal give samme fingerprint (også de produkter, der kobles); ellers afvises den ("Data er ændret siden tørkørslen").
  - Kun én kørsel ad gangen; en anden samtidig kørsel (fx et dobbeltklik) afvises.
  - Pr. vendor oprettes eller genbruges creatoren, nye creators får afregningsstart = migreringens tidspunkt (én gang), og vendorens frie produkter kobles.
  - Siden viser bagefter en besked og en linje om seneste migrering (creators oprettet og brugt, produkter koblet, efterladt hos en anden creator og fejlede).
  - En genkørsel tilføjer kun det, der er nyt. Produkter og en kategori, som migreringen koblede før, kobles aldrig igen, så det, administratoren har fjernet bagefter, forbliver fjernet.
- **Fjern Product Vendors-roller (26.3):** et afsnit på siden, så længe en bruger, migreringen har koblet til en creator, stadig har en PV-rolle (også efter at PV er deaktiveret). Brugeren vises kun, mens creatoren er aktiv, stadig har netop den bruger, og brugeren har rollen `merchboard_creator`.
  - Listen viser bruger, creator og PV-roller, med alle markeret. Administrerer brugeren også en vendor, der ikke er migreret, vises en advarsel: fjernelsen afslutter også den adgang.
  - Fluebenet "Jeg har kontrolleret de migrerede creators og deres produkter" og knappen **Fjern Product Vendors-rollerne**.
  - Kun PV's tre roller fjernes, og kun hos de markerede brugere, som administratoren selv må ændre roller for i WordPress. Brugerne beholder deres andre roller og deres creator-adgang.

**Regler (projektejerens valg 2026-10-24):**
- **Match:** via brugeren.
- **Ny creator:** får vendorens navn og den valgte bruger. Brugeren foreslås i denne rækkefølge: en bruger, der allerede har en creator; ellers Vendor Admin før Vendor Manager; ellers den ældste.
- **Ventende vendors:** springes over.
- **Produkter koblet til en anden creator:** erstattes aldrig.
- **Afregningsstart:** nye creators får den sat til migreringens tidspunkt.
- **Flyttes ikke:** kommission, udbetalinger, PayPal og øvrige PV-data.

**Regler (projektejerens valg 2026-10-25, review i 26.4):**
- **Kategoriens konsekvens:** vises i tørkørslen; kun en kategori med vendorens egne produkter foreslås.
- **Genkørsel:** husker, hvad migreringen har koblet, og kobler aldrig det igen, som administratoren har fjernet.
- **Administratorer uden PV-rolle** (fx en WordPress-administrator): vendoren springes over og vises med en forklaring.

## Technical implementation
- **`ProductVendorsSource`:** interface til PV. Adapteren er `ProductVendorsPlugin`, som læser kun mens PV er aktivt:
  - **Vendors:** taxonomien `wcpv_product_vendors`.
  - **Administratorer:** foreningsmængden af `WC_Product_Vendors_Utils::get_vendor_admins()` (PV 2.4.9's eget lager), `get_vendor_data_by_id()['admins']` og `get_all_vendor_data( $user_id )` for brugere med PV's tre roller. Siden PV 2.1.80 ligger listen et ikke-offentligt sted, som kun PV selv kender.
  - **Navne:** vendor-navne afkodes fra HTML-entiteter (fx `&amp;`).
  - **Admins-værdien:** normaliseres som PV gør, dvs. en liste, kommasepareret tekst eller ét id (`ProductVendorsPlugin::admin_ids()`).
  - **Produkter:** forældreprodukter med vendorens term; ikke variationer, trash eller auto-drafts.
- **`VendorImportPlan::build( $choices, $category_choices )`:**
  - kræver `merchboard_manage_creators` og `merchboard_manage_payouts`;
  - giver en `VendorImportReport` med statusserne `new`, `matched`, `imported`, `no_admin` (flyttes) og `pending`, `user_taken`, `no_role`, `interrupted` (springes over), produkttal og de produkter, der kobles, kategori med konsekvens (andre produkter, der kobles, og produkter, der bliver tvetydige), creatorens aktive status og afregningsstart;
  - har et fingerprint (sha256 af hele rapporten: de valgte brugere og kategorier og de produkter, der kobles);
  - læser hver vendors produkter én gang pr. tørkørsel med primede caches.
- **`VendorImportState`:** migreringens tilstand i optionen `merchboard_vendor_import` (se "Database/data used"), læst frisk og normaliseret.
- **`VendorImportPage`:** undermenu med prioritet 29, før Audit log.
- **`VendorImporter::run( $fingerprint, $choices, $category_choices )` (26.2):** tager en navngiven databaselås (`GET_LOCK`; optaget giver `vendors_busy`), bygger en frisk tørkørsel med de samme valg og kræver samme fingerprint. Uden ydre transaktion (servicerne har hver deres, og transaktioner kan ikke indlejres), men hvert trin er idempotent og registreres i `VendorImportState`, så en afbrudt kørsel fortsætter, hvor den stoppede:
  1. creator: tidligere kørsels, den valgte brugers, eller en ny aktiv via `CreatorService::create()` (kobler brugeren og giver rollen `merchboard_creator`). Den valgte bruger skrives som `pending`, før creatoren oprettes, så en creator, en afbrudt kørsel har oprettet, stadig kendes som migreringens egen;
  2. afregningsstart: for en creator, migreringen har oprettet, én gang via `PayoutSettings::set_settlement_start()`. En afregningsstart, administratoren ændrer bagefter, sættes aldrig igen;
  3. produkter: tørkørslens produkter kobles via `ProductCreatorLink::link()` (kilde `admin`) og registreres; produkter hos en anden creator efterlades;
  4. produktkategori (26.2.1), først efter alle vendors produkter: tørkørslens kategori kobles via `CreatorCategoryService::link()`, som også sætter kategoriens afstemning i gang (ADR-033), så afstemningen ikke når en anden vendors produkter før deres egen kørsel. Fejler det (fx en deaktiveret creator), tælles det;
  5. audit `vendor_imported`.

  En vendor, der fejler (fx en deaktiveret creator, hvis produkter ikke kan kobles), tælles, og kørslen fortsætter. Til sidst skrives seneste kørsel og `vendor_import_completed` i én transaktion.
- **`VendorImportActions`:** admin-post `merchboard_vendor_import`: capability → nonce → backup → fingerprintets form → migreringen; Post/Redirect/Get med en resultatkode.
- **`VendorRoleCleanup` (26.3):** `users()` giver kun den bruger, migreringen koblede pr. vendor (tilstandens `users`), og kun mens creatoren er aktiv, stadig har netop den bruger, og brugeren har rollen `merchboard_creator`, når brugeren stadig har en PV-rolle; med vendors, der ikke er migreret, som brugeren også administrerer (kun mens PV er aktivt). `remove( $user_ids )` kræver `merchboard_manage_creators` og `promote_users` (ellers `vendor_roles_not_authorized`) og ændrer kun bekræftede brugere, der stadig er på listen, og som administratoren må ændre (`promote_user` pr. bruger): `WP_User::remove_role()` for PV-rollerne og audit `vendor_roles_removed` i én transaktion pr. bruger.
- **`VendorRoleActions` (26.3):** admin-post `merchboard_vendor_roles`: capability → nonce → bekræftelse → fjernelsen; Post/Redirect/Get.
- **Wiring:** `WooCommerceModule`.

## Relevant files
- `src/Modules/WooCommerce/VendorImport/` (`ProductVendorsSource`, `ProductVendorsPlugin`, `VendorImportPlan`, `VendorImportReport`, `VendorImportState`, `VendorImporter`, `VendorImportActions`, `VendorRoleCleanup`, `VendorRoleActions`, `VendorImportException`, `VendorImportPage`)
- `bin/mb-26-seed-product-vendors.php` og `bin/mb-26-diagnose-product-vendors.php` (kun Local, køres af projektejeren)
- `tests/Integration/WooCommerce/VendorImportTest.php`, `tests/Unit/Modules/WooCommerce/VendorImport/ProductVendorsPluginTest.php`, `tests/Fixtures/product-vendors.php`

## Database/data used
- **Læses:** `merchboard_creators` og produkternes `_merchboard_creator_id` (ADR-030); creatorens afregningsstart (`merchboard_creators.payout_start_at`, ADR-054); PV's taxonomi, term meta `vendor_data` og roller gennem PV's API.
- **Option:** `merchboard_vendor_import` (uden autoload): `vendors` (vendor-term → creator), `users` (vendor-term → den koblede bruger, 0 = ingen), `pending` (vendor-term → valgt bruger, før creatoren oprettes), `created` (creators, migreringen har oprettet), `settled` (creators, hvis afregningsstart er sat), `products` (vendor-term → koblede produkter), `categories` (vendor-term → koblet kategori), `completed_at` og `last` (tal). Slettes ved opt-in-afinstallation.
- **Skrives (26.2):** `merchboard_creators` (via `CreatorService`), `merchboard_creator_categories` (via `CreatorCategoryService`, 26.2.1), brugerens ekstra rolle `merchboard_creator`, produkternes kobling (via `ProductCreatorLink`), afregningsstart (via `PayoutSettings`) og audit. Product Vendors' data skrives aldrig.

## Permissions
- **Siden:** `merchboard_manage_creators`.
- **Tørkørslen:** desuden `merchboard_manage_payouts`, fordi migreringen sætter afregningsstart.
- **Bruger- og kategorivalget:** er read-only GET-parametre, der valideres til positive heltal (kategori: også 0). Et valg, der ikke er vendorens godkendte administrator, eller en kategori, der ikke findes eller er standardkategorien, ignoreres.
- **Rollefjernelsen:** desuden `promote_users` og `promote_user` for hver bruger.

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
  - migreringen: creators, brugerkobling og rolle, produktkobling, afregningsstart kun for nye creators, PV's data uændret, audit, en genkørsel uden dubletter, en afregningsstart administratoren fjernede sættes ikke igen, forældet fingerprint og manglende backup afvises, og en vendor med en deaktiveret creator tælles som fejl, mens resten flyttes;
  - reviewet (26.4): en genkørsel kobler aldrig igen, hvad administratoren har fjernet (produkt og kategori), men kobler nye produkter; en afbrudt kørsel genoptages, og dens creator får afregningsstart (`interrupted` for en creator uden bruger); en samtidig kørsel afvises (lås fra en anden databaseforbindelse); et produkt tilføjet efter tørkørslen ændrer fingerprintet; `no_role`; kategoriens konsekvens, forslaget kun med egne produkter og en kategori valgt til en tidligere vendor; den deaktiverede creator vises; rollefjernelsen kun for den koblede bruger, mens creatoren er aktiv og har brugeren og rollen.
- **Unit (`ProductVendorsPluginTest`):** admins-værdiens former.

## Known limitations
- PV's datamodel efter 2.0.27 er ikke offentlig. Derfor læses alt gennem PV's eget API, og tørkørslen viser PV's version, så projektejeren kan kontrollere resultatet på Local før migreringen.
- En creator har én bruger (ADR-020). En vendors øvrige administratorer flyttes ikke.
