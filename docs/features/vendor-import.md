# Feature: Migrering fra WooCommerce Product Vendors

## Purpose
Projektejerens creators er i dag oprettet som vendors i WooCommerce Product Vendors (PV). Migreringen flytter dem til Merch Dashboard én gang, så de ikke skal oprettes i hånden (ROADMAP fase 26, ADR-074).

## Current status
I gang (fase 26).
- **26.1 tørkørsel:** implementeret 2026-10-24, læser kun.
- **26.2 migrering:** udestår.
- **26.3 fjernelse af PV-roller:** udestår.
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
  - **Produkter:** kobles / allerede koblet / bliver stående, fordi de tilhører en anden creator.
  - **Afregningsstart:** "sættes til migreringens tidspunkt" for nye creators; ellers creatorens nuværende afregningsstart.
  - **Advarsel:** når en creator med samme navn findes. Navne matches aldrig.
- **Skriver intet.** Selve migreringen kommer i 26.2.

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
- **Wiring:** `WooCommerceModule`.

## Relevant files
- `src/Modules/WooCommerce/VendorImport/` (`ProductVendorsSource`, `ProductVendorsPlugin`, `VendorImportPlan`, `VendorImportReport`, `VendorImportException`, `VendorImportPage`)
- `tests/Integration/WooCommerce/VendorImportTest.php`, `tests/Unit/Modules/WooCommerce/VendorImport/ProductVendorsPluginTest.php`, `tests/Fixtures/product-vendors.php`

## Database/data used
- **Læses:** `merchboard_creators` og produkternes `_merchboard_creator_id` (ADR-030); creatorens afregningsstart (`merchboard_creators.payout_start_at`, ADR-054); PV's taxonomi, term meta `vendor_data` og roller gennem PV's API.
- **Option:** `merchboard_vendor_import` (vendor-term → creator) læses af tørkørslen. Den skrives først af migreringen i 26.2.

## Permissions
- **Siden:** `merchboard_manage_creators`.
- **Tørkørslen:** desuden `merchboard_manage_payouts`, fordi migreringen sætter afregningsstart.
- **Brugervalget:** er read-only GET-parametre, der valideres til positive heltal. Et valg, der ikke er vendorens godkendte administrator, ignoreres.

## API endpoints
Ingen.

## Hooks
`admin_menu` (prioritet 29).

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
  - at tørkørslen intet skriver (snapshot af creators, audit, produktkoblinger, roller, PV's data og optionen).
- **Unit (`ProductVendorsPluginTest`):** admins-værdiens former.

## Known limitations
- PV's datamodel efter 2.0.27 er ikke offentlig. Derfor læses alt gennem PV's eget API, og tørkørslen viser PV's version, så projektejeren kan kontrollere resultatet på Local før migreringen.
- En creator har én bruger (ADR-020). En vendors øvrige administratorer flyttes ikke.
