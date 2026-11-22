# Feature: Samlinger af designs

## Purpose
En creator kan bede om at få flere af sine designs solgt fra én produktside, hvor kunden vælger design. Siden laves af virksomhedens plugin "CCMerch Design-samling". Beslutningerne står i ADR-120.

## Current status
I gang (fase 55, version 2.4.0). Testet automatisk mod vores egen efterligning af pluginets data. Det rigtige plugin, kurv, kasse og tema er ikke afprøvet; det sker i projektejerens test på stg.

## User-facing functionality
- **"Dine produkter":** kortet "Samlinger" med "Anmod om samling" og anmodningernes status. Et samlet produkt viser samlingens navn.
- **En ny samling:** et navn og 2–50 af creatorens udgivne, synlige produkter.
- **En tilføjelse:** 1–50 produkter til en samling, hvor alle designs er creatorens. Samlinger lavet i hånden tæller med.
- **Besked:** når samlingen er klar, eller anmodningen er afvist (klokke og mail).
- **Frit valg (trin 55.8a, under opbygning):** koster alle designs det samme (samme creator, kundepris og grundpris i alle varianter), kan giveren give hele samlingen, og vinderen vælger design.
- **Giveaway:** samlede designs står under samlingens navn. Intet er forvalgt.
- **Kunden:**
  - en samlings gaveboks kræver valg af design;
  - indløsningen åbner samlingen med gavens design valgt.

## Admin functionality
Merch Dashboard → Forslag → **Samlinger**:

| Tilstand | Handling | Virkning i shoppen |
| --- | --- | --- |
| Ny samling, venter | Godkend | Én kladde med navn, designs, kategorier, tags og første billede |
| Kladde | Udgiv i WooCommerce | Pluginet skjuler designs, og anmodningen bliver klar |
| Tilføjelse til en kladde, venter | Godkend | Designs tilføjes kladden |
| Tilføjelse til en udgivet samling, venter | Godkend | Ingen |
| Samme, godkendt | Tilføj og skjul nu | Designs tilføjes og skjules |
| Godkendt, fejlede undervejs | Prøv igen | Fortsætter uden at lave to kladder |
| Godkendt | Tjek igen | Ingen. Lukker anmodningen, hvis shoppen er klar |
| Åben | Afvis med begrundelse | Ingen. En kladde bliver liggende |

Fanen viser også åbne gaver og giveaways, der peger på en samling i stedet for et design. De rettes aldrig automatisk.

Fjernelse og opløsning sker i samlings-pluginet.

## Technical implementation
- **`DesignCollections`:** den eneste læser af pluginets data. Den virker også, når pluginet er slået fra.
- **`CollectionWriter`:** den eneste skriver. Den kræver, at pluginet er aktivt, og kører aldrig i vores transaktioner (HPOS committer ydre transaktioner).
- **`CollectionRequests`:** reglerne, som tjekkes igen ved godkendelse og udførelse. En navngiven lås pr. anmodning.
- **`CollectionFulfilment`:** udfører en godkendt anmodning. Kladden bærer anmodningens mærke, til anmodningen har registreret den.
- **`CollectionWatcher`:** lukker en anmodning som klar, når samlingen er udgivet og designs peger på den, også når det er gjort i hånden.
- **`CollectionCartGuard`:** en samling er aldrig selv en varelinje.

Eksisterende creator-tilknytninger på samlinger fjernes og flyttes aldrig automatisk, fordi en ordrelinje uden historik i ledgeren får creator fra den.

## Relevant files
- `src/Modules/WooCommerce/Collections/`
- `src/Modules/WooCommerce/Admin/CollectionRequestsPage.php`, `CollectionRequestActions.php`
- `src/Modules/WooCommerce/Rest/MeCollectionsController.php`
- `src/Modules/Community/Gifting/GiftDesigns.php`, `templates/community/gift-box.php`
- `assets/src/dashboard/sections/products/CollectionsCard.tsx`, `CollectionDialog.tsx`

## Database/data used
- Tabellerne `merchboard_collection_requests` og `merchboard_collection_request_items` (schema 53, se [DATABASE.md](../DATABASE.md)).
- Vores meta: `_merchboard_collection_token`, kun til kladden er registreret.
- Pluginets data: produkttypen `ccm_design_group` og metaen `_ccmds_members`, `_ccmds_group` og `_ccmds_prev_visibility`. Afinstallation rører dem ikke.

## Permissions
- **Creatoren:** kun via `CreatorContext`. Ejerskab tjekkes pr. produkt og samling.
- **Admin:** `merchboard_manage_creators` og en nonce pr. handling og anmodning.

## API endpoints
- `GET` og `POST /me/collections`
- `collection` i `GET /me/products` og i giveaway-produkterne

Se [API.md](../API.md).

## Hooks
- **Lytter:** `transition_post_status`, `added_post_meta`, `updated_post_meta`, kurvens validering, antal og tjek samt `woocommerce_ccm_design_group_add_to_cart`.
- **Udløser:** `merchboard_notify` med `admin_collection_requested`, `collection_ready` og `collection_declined`.

## Dependencies
CCMerch Design-samling (testet mod formatet i 1.0.2, læst i koden) og WooCommerce. Intet kopieres fra pluginet.

## Tests
- Unit: `DesignCollectionsTest`, `CollectionCartGuardTest`, `GiftDesignsTest` og tests af tilknytning og Meta.
- Integration: `CollectionRequestsTest`, `CollectionFulfilmentTest`, `MeCollectionsEndpointTest` og produktlistens SQL i `CreatorProductsTest`.
- Jest: `collections.test.tsx` og giveaway-vælgeren.

## Known limitations
- Integrationssuiten indlæser hverken WooCommerce eller pluginet, så rigtige produktgem, kurv, kasse og tema er ikke dækket.
- Ændrer pluginet sit dataformat, skal læseren og skriveren rettes.
- Er et produkt skiftet fra samling til enkelt produkt med designlisten i behold, regnes det stadig som samling.

## Future improvements
Anmodning om fjernelse og opløsning. Det er fravalgt i fase 55.
