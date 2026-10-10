# Fase 22 – Creator pricing: arkitektur (trin 1 analyse, trin 2 endeligt design)

**Dato:** 2026-10-08.

**Status:** endeligt design (trin 2). **Opdatering 2026-10-08 (trin 3):** ADR-045–047 er accepteret og flyttet til [DECISIONS.md](../DECISIONS.md) med præciseringer. Fundamentet (migration 6, capability og rent domæne) er implementeret. Teksten nedenfor er bevaret som trin 2's forslag. **Opdatering 2026-10-09:** beregningsmodellen er rettet (ADR-049); grundpris + profit, anvendelse, drift og fast profit nedenfor er trukket tilbage. Se afsnit 16.

**Grundlag:** kun dette repo (kode, docs, ADR-004, 007, 011, 022–025, 030–040) og læsning af WooCommerce 11.1.2's kildekode i Merch Dashboard Local (read-only).

**Historik:** trin 1 (analyse) blev godkendt med 22 bindende beslutninger (afsnit 0). Trin 2 erstatter trin 1's afsnit om database, snapshot og ledger. Ændringer i forhold til trin 1 er markeret **(ændret i trin 2)**.

## 0. Bindende beslutninger fra trin 1

1. Creator-prisen er den faktiske webshoppris, kunden betaler.
2. Creator-pris = grundpris + creator-profit (konceptuelt).
3. Standardprofit er en procentuel markup.
4. Produktspecifik fast profit overrider procenten.
5. Procent og fast profit anvendes aldrig begge på samme pricing-tilstand.
6. Markup 0–100 %.
7. Nye creators starter med 0 %.
8. Separat capability `merchboard_manage_creator_pricing`.
9. WooCommerce-prisen må foreslås som grundpris ved første aktivering, men aldrig overtages uden en eksplicit admin-handling.
10. Pricing anvendes manuelt; WooCommerce-priser låses ikke.
11. Afvigelser vises, og admin vælger selv at anvende pricing igen.
12. Ved creatorskift ændres webshopprisen ikke automatisk.
13. Profit-snapshot implementeres i fase 22.
14. Ingen profit i `/me/sales` endnu.
15. Creators ser ikke grundprisen.
16. Creators ser deres creator-pris og relevant profit; konfiguration er admin-only.
17. Én creator pr. parent-produkt.
18. Fast profit gælder parent-produktet, ingen variantregler.
19. Grundpris pr. faktisk solgt simpelt produkt eller variant.
20. Ingen floats.
21. Ingen valutaomregning.
22. Historiske sales- og ledger-data ændres aldrig af pricing.

## 1. Eksisterende arkitektur (trin 1, uændret)

| Område | I dag | Betydning |
| --- | --- | --- |
| Ejerskab | `_merchboard_creator_id` på parent; varianter arver; én skrivevej `ProductCreatorLink` (ADR-030/032/033), beskyttet meta (ADR-034/035) | Regler følger parent og ejeren. |
| WooCommerce som sandhed | Produkter, priser og ordrer i WooCommerce, kun CRUD, HPOS-sikkert | Salgsprisen bor i WooCommerce; grundpris og regler er pluginets data. |
| Sales ledger | Append-only, `net_amount` = linjens beløb efter rabatter, ekskl. moms, fragt og gebyrer; `currency` og creator-snapshot pr. række; invariant mod WooCommerce' netto (ADR-037/038) | Pricing må ikke ændre ledgeren. |
| Penge | `SalesAmount`: heltal i 1/10 000, half away from zero, overflow-tjek, ingen floats eller `bcmath` | Genbruges. |
| Statistik | Pr. valuta, ingen omregning (ADR-040) | Faste beløb bærer valuta. |
| `/me/products` | Aktiv pris som strenge (`get_price()`/aktive variationspriser), ADR-039 P7 | Udvides med `pricing`. |
| Admin | Creatorens redigeringsside med sektioner via `merchboard_creators_edit_sections` | Ingen ny menu. |
| WooCommerce COGS | `float`-API, slået fra som standard, betyder indkøbspris | Fravalgt som grundpris. |

**WooCommerce' prisadfærd** (verificeret i 11.1.2's kilde: `WC_Product_Data_Store_CPT::handle_updated_props`, som varianternes data store arver):
- `_price` (den aktive pris) sættes ved hver gemning: udsalgsprisen, hvis produktet er på udsalg (inkl. datoer), ellers regular price.
- Er sale price ≥ regular price ved gemning, **sletter WooCommerce selv udsalgsprisen**.

## 2. Endelig pricing-model

### 2.1 Definitioner (præcisering af "grundpris", ikke en ny model)

- **Listepris (creator-pris)** = WooCommerce' **regular price** for den salgbare enhed (simpelt produkt eller variant). Det er den pris, Merch Dashboard publicerer.
- **Grundpris** = virksomhedens andel af **listeprisen** for én enhed, før enhver rabat, i samme momsgrundlag som butikkens priser (`woocommerce_prices_include_tax`).
- **Profit pr. enhed** = listepris − grundpris, bestemt af reglen:

  ```text
  fixed:   profit = fast profit (override for parent)
  default: profit = round_half_away(grundpris × markup_bps / 10000, butikkens prisdecimaler)
  listepris = grundpris + profit
  ```

- **Aktiv pris** = WooCommerce' `_price`: udsalgsprisen, når et udsalg er aktivt, ellers listeprisen. Merch Dashboard beregner den aldrig.
- **Realiseret profit** = profittens andel af det, kunden faktisk betalte netto (se 4). Udsalg og rabatter behandles ens.

### 2.2 Værdier og grænser

| Felt | Lager | Grænser |
| --- | --- | --- |
| Standard-markup | basis points, `INT UNSIGNED`, standard 0 | 0–10 000 (0–100 %), højst 2 decimaler i % |
| Fast profit | `DECIMAL(19,4)` + valuta | ≥ 0, højst butikkens prisdecimaler; 0 er en gyldig override |
| Grundpris | `DECIMAL(19,4)` + valuta | ≥ 0, højst butikkens prisdecimaler |
| Beregning | heltal i 1/10 000 (`SalesAmount`) | overflow-tjek; ingen floats |

Afrundingseksempler (trin 1, uændrede): 99 × 19 % → 18,81 / 117,81; 199,95 × 17,5 % → 34,99 / 234,94; 0,10 × 19 % → 0,02; 0,01 × 19 % → 0,00; 0 % → 0; 100 % → profit = grundpris; 0,05 × 10 % → 0,01. Ændres butikkens prisdecimaler, ændres den forventede pris, og produkterne vises som "konfiguration ændret" (se 5).

## 3. Sale price-model

**Én regel:** Merch Dashboard publicerer kun **listeprisen** (regular price). En WooCommerce-udsalgspris er en rabat på listeprisen, præcis som en kupon. Den ændrer hverken regel, grundpris, profit pr. enhed eller pricing-tilstand. Den nedsætter kun den realiserede profit forholdsmæssigt (afsnit 4). Der findes ingen anden pricing-model for udsalg.

| Situation | Kunden ser og betaler | Pricing-tilstand | Profit |
| --- | --- | --- | --- |
| Intet udsalg | Listeprisen (= creator-pris) | uændret | listepris × andel |
| Udsalg aktivt | Udsalgsprisen (WooCommerce' aktive pris) | uændret ("anvendt") | forholdsmæssigt af det betalte |
| Udsalg oprettet efter anvendt pricing | Udsalgsprisen | uændret, ingen afvigelse | forholdsmæssigt |
| Udsalg fjernet eller udløbet | Listeprisen | uændret | fuld andel |
| Regular price ændret direkte i WooCommerce | Den nye pris | **afviger** (`price_drift`) | ukendt, indtil pricing anvendes igen |
| Pricing anvendt igen | Den forventede listepris (+ evt. eksisterende udsalg) | anvendt | som ovenfor |

- **Anvendelse** skriver kun `set_regular_price()` via CRUD. Merch Dashboard skriver aldrig sale price eller `_price`.
- Er en eksisterende udsalgspris ≥ den nye listepris, sletter WooCommerce selv udsalget ved gemningen. Det vises i forhåndsvisningen før anvendelse ("WooCommerce fjerner udsalgsprisen X, fordi den ikke er lavere end den nye pris"), så intet sker skjult.
- **Eksempel** (butik med priser inkl. 25 % moms):
  - Grundpris 100, markup 20 % → listepris 120.
  - Udsalg 96 → kunden betaler 96; netto ekskl. moms 76,80.
  - Realiseret profit 76,80 × 20/120 = 12,80 (ekskl. moms); virksomheden 64,00.

## 4. Rabatmodel

Al reduktion af prisen bæres forholdsmæssigt: udsalg, kuponer, manuel rabat på ordren og delvise refunds. Profit er aldrig et uændret fast beløb efter rabat; heller ikke en fast profit, som kun er fast pr. enhed *af listeprisen*.

```text
realiseret_profit(ledger-række) = round_half_away( net_amount × profit_unit / price_unit , 4 decimaler )
```

| Del | Betydning |
| --- | --- |
| `net_amount` | Ledger-rækkens beløb, med fortegn; efter alle rabatter, ekskl. moms (ADR-037) |
| `profit_unit`, `price_unit` | Snapshottets profit og listepris pr. enhed, i samme momsgrundlag; forholdet er derfor momsuafhængigt |
| `price_unit = 0` | Profit 0 (så er også `profit_unit` 0) |

- **Beregning:** i heltal (1/10 000-enheder). Multiplikation og division er overflow-sikker uden floats eller `bcmath` (fx opdeling i mindre heltal med kontrolleret mellemresultat). Kvotienten afrundes half away from zero til 4 decimaler, som ledgerens `DECIMAL(19,4)`.
- **Eksempel:** listepris 117,81 (profit 18,81), kupon, netto 33,33 → 33,33 × 18,81 / 117,81 = 5,32159… → **5,3216**.
- **Afrunding pr. række:** hver ledger-række afrundes for sig. Summer over rækker afrundes ikke igen; afrunding til udbetalingsvaluta er et senere Payouts-spørgsmål.

## 5. Pricing-tilstand pr. salgbar enhed

**Input:**
- produktets nuværende ejer `C`;
- `C`'s standard-markup;
- parent'ens override (gælder kun, hvis `override.creator_id = C`);
- enhedens pricing-række (grundpris og "anvendt"-snapshot);
- butikkens valuta og prisdecimaler;
- WooCommerce' aktuelle regular price `R` og produktets status og type.

**Forventet pris `E`** beregnes altid deterministisk (gemmes ikke):

```text
E = grundpris + profit(regel)
```

Tilstande (evalueres i denne rækkefølge; første match vinder):

| # | Tilstand | Gruppe (krav 1–4) | Betingelse |
| --- | --- | --- | --- |
| 1 | `unavailable` / `no_creator` | 4: kan ikke anvendes | Parent har ingen creator |
| 2 | `unavailable` / `unsupported` | 4 | Produktet er i papirkurven, er ekstern/grupperet, eller er en variabel parent (kun varianterne prissættes) |
| 3 | `unavailable` / `currency_mismatch` | 4 | Grundprisens eller den gyldige overrides valuta ≠ butikkens valuta |
| 4 | `not_applied` / `no_base_price` | 1: ikke anvendt | Enheden har ingen grundpris |
| 5 | `not_applied` / `never_applied` | 1 | Der er aldrig anvendt pricing på enheden |
| 6 | `drift` | 3: afviger | `R` ≠ den sidst anvendte pris (WooCommerce-prisen er ændret uden om Merch Dashboard) |
| 7 | `not_applied` / `creator_changed` | 1 | Sidst anvendt for en anden creator end `C` |
| 8 | `not_applied` / `config_changed` | 1 | `R` = anvendt pris, men `E` ≠ anvendt pris (regel, grundpris, markup eller decimaler er ændret siden) |
| 9 | `applied` | 2: anvendt | `R` = anvendt pris = `E`, anvendt for `C` |

- Udsalg påvirker aldrig tilstanden (afsnit 3).
- Et variabelt produkts samlede tilstand er den "værste" blandt varianterne, vist som et sammendrag: "3 af 5 anvendt, 1 afviger".
- "Anvend pricing" er kun muligt i tilstand 5–9. I tilstand 6 viser forhåndsvisningen den aktuelle WooCommerce-pris, den forventede pris og en evt. udsalgsfjernelse. En inaktiv creators produkter kan prissættes (inaktiv betyder kun ingen login).

## 6. Creatorskift

`ProductCreatorLink` er den eneste vej til ejerskift, både manuelt og via kategori-afstemning. Pricing-reglerne kobles på samme transaktion:

| Hændelse | Override | Grundpriser | WooCommerce-pris | Tilstand efter |
| --- | --- | --- | --- | --- |
| Creator fjernes fra produktet | A's override slettes (audit `pricing_override_removed`, årsag `ownership_changed`) | Bevares (virksomhedens grundpris er creator-uafhængig) | **Uændret** | `unavailable/no_creator` |
| A → B, B har ingen override | A's override slettes | Bevares | **Uændret** (indeholder stadig A's profit) | `not_applied/creator_changed`; forventet pris efter B's standard |
| A → B, "B har override" | Kan ikke forekomme: en override kan kun oprettes for produktets nuværende ejer og slettes ved ejerskift. Har B tidligere ejet produktet, blev B's override slettet, da det skiftede | – | – | – |
| A → B → A | A's tidligere override er væk; A får sin standard, indtil en ny override oprettes | Bevares | Uændret | `not_applied/creator_changed` |

Ordrer i mellemtiden (efter skift, før ny anvendelse) får snapshot `unpriced/creator_changed`: prisen indeholder den tidligere creators profit, så den nye creators profit gættes ikke (afsnit 8).

## 7. Endelig database-model (ingen migration nu)

**Kritisk gennemgang af trin 1's fire forslag:**

| Trin 1 | Trin 2 | Begrundelse |
| --- | --- | --- |
| `merchboard_creators.default_markup_bps` | **Beholdes**, nu `NOT NULL DEFAULT 0` | Forretningsattribut på creator-entiteten (beslutning 7) |
| `merchboard_pricing_overrides` | **Beholdes** | Eneste sted for parent-reglen; strukturel eksklusivitet |
| `merchboard_base_prices` | **Erstattes** af `merchboard_item_pricing` **(ændret i trin 2)** | Grundprisen kan ikke undværes: efter anvendelse er WooCommerce-prisen grundpris + profit, så grundprisen findes ingen andre steder. Den forventede pris kan beregnes, men tilstandene "afviger", "creator skiftet" og "konfiguration ændret" kræver at vide, *hvad der sidst blev publiceret og for hvem*. Det er et domænefaktum (ikke cache), som også er grundlaget for snapshottet (afsnit 8). |
| `merchboard_sales_line_pricing` | **Beholdes**, renset for redundans | Immutable historik |
| `merchboard_sales.creator_profit` | **Fravalgt (ændret i trin 2)** | Realiseret profit er en ren funktion af immutable data (ledger-række + snapshot), så en kolonne ville være cache |
| Ordrelinje-meta `_merchboard_pricing` | **Fravalgt (ændret i trin 2)** | Én kilde: snapshottet skrives direkte i pluginets tabel |

**Current state:** `default_markup_bps`, `merchboard_pricing_overrides` og `merchboard_item_pricing`.
**Immutable:** `merchboard_sales_line_pricing` (og den eksisterende ledger).

### 7.1 `merchboard_creators.default_markup_bps`

| Felt | Indhold |
| --- | --- |
| Type | `INT UNSIGNED NOT NULL DEFAULT 0` |
| Constraint | `CHECK (default_markup_bps <= 10000)` |
| Ændres af | Kun af `CreatorPricingService` (capability `merchboard_manage_creator_pricing`, audit) |
| Eksisterende creators | Får 0 ved migrationen (beslutning 7) |
| Uninstall | Følger creators-tabellen |

### 7.2 `merchboard_pricing_overrides` (current state, én pr. parent)

| Kolonne | Type | Noter |
| --- | --- | --- |
| `product_id` | `BIGINT UNSIGNED` PK | Parent-produkt |
| `creator_id` | `BIGINT UNSIGNED NOT NULL` | Ejeren ved oprettelse; KEY |
| `fixed_profit` | `DECIMAL(19,4) NOT NULL` | `CHECK (fixed_profit >= 0)` |
| `currency` | `CHAR(3) NOT NULL` | Butikkens valuta ved oprettelse |
| `created_at`, `updated_at` | `DATETIME NOT NULL` | UTC |

**Livscyklus:** rækken oprettes og ændres med "Sæt fast profit" og slettes ved:
- "Brug standard";
- ejerskift eller frakobling (i `ProductCreatorLink`'s transaktion);
- permanent sletning af produktet.

Kun gyldig, når `creator_id` = nuværende ejer (forsvar i dybden).

### 7.3 `merchboard_item_pricing` (current state, én pr. salgbar enhed) **(ændret i trin 2)**

| Kolonne | Type | Noter |
| --- | --- | --- |
| `item_id` | `BIGINT UNSIGNED` PK | Simpelt produkt eller variant |
| `product_id` | `BIGINT UNSIGNED NOT NULL` | Parent (= `item_id` for simple); KEY |
| `base_price` | `DECIMAL(19,4) NOT NULL` | `CHECK (base_price >= 0)` |
| `base_currency` | `CHAR(3) NOT NULL` | |
| `base_updated_at` | `DATETIME NOT NULL` | |
| `applied_at` | `DATETIME NULL` | Sidste anvendelse; resten af `applied_*` sat præcis når denne er sat |
| `applied_creator_id` | `BIGINT UNSIGNED NULL` | |
| `applied_rule` | `VARCHAR(7) NULL` | `default` eller `fixed` |
| `applied_markup_bps` | `INT UNSIGNED NULL` | Kun ved `default` |
| `applied_base` | `DECIMAL(19,4) NULL` | Grundprisen, der blev publiceret med |
| `applied_profit` | `DECIMAL(19,4) NULL` | |
| `applied_price` | `DECIMAL(19,4) NULL` | Den regular price, Merch Dashboard skrev |
| `applied_currency` | `CHAR(3) NULL` | |

**Constraints:**
- alt-eller-intet for `applied_*`;
- `applied_price = applied_base + applied_profit`;
- `applied_rule = 'default'` ⇔ `applied_markup_bps IS NOT NULL`;
- `applied_markup_bps <= 10000`.

Domænevalidering håndhæver det samme før skrivning; CHECK er forsvar i dybden (MySQL 8.0.16+ og MariaDB 10.2+ håndhæver CHECK; Playground/SQLite verificeres ved migrationen).

**Livscyklus:**
- Grundprisen sættes eksplicit. Første gang foreslås WooCommerce' nuværende regular price i formularen (beslutning 9), men intet gemmes uden "Gem".
- `applied_*` skrives kun af "Anvend pricing" i samme transaktion som audit, og først efter vellykket CRUD-`save()`.
- Rækken slettes ved permanent sletning af enheden.
- Variantens række følger varianten. En ny variant har ingen række (tilstand `no_base_price`).
- **Hvad der ikke gemmes:** den forventede pris (beregnes) og WooCommerce' aktuelle pris (læses via CRUD). Drift opdages ved at sammenligne `R` med `applied_price`.

### 7.4 `merchboard_sales_line_pricing` (immutable snapshot)

Se afsnit 8.

### 7.5 Fælles for alle tabeller

- **Ejerskab:** pluginet (ADR-025); i `Tables::all()` (engine-tjek, InnoDB, utf8mb4).
- **Uninstall:** droppes kun ved opt-in. WooCommerce-priserne, som Merch Dashboard har skrevet, bevares, fordi pluginet aldrig sletter WooCommerce-data.
- **Migration:** migration 6 (ALTER af `merchboard_creators` + 3 CREATE) med den eksisterende migrator, fail-closed, verificeret på MariaDB, MySQL 8 og Playground.
- **Capability:** ny capability i `Capabilities` med rolleversion-bump (ADR-022), kun til administrator.

## 8. Endeligt historisk snapshot

### 8.1 Tidspunkt og kilde **(ændret i trin 2)**

Snapshottet skrives, når WooCommerce **opretter en produktlinje i en ordre**: `woocommerce_new_order_item` med `item_id`. Det dækker checkout (klassisk og blok), admin-oprettede linjer og REST. Hook-dækningen verificeres ved implementering.

- Snapshottet skrives direkte i pluginets tabel og kun for linjer, hvis produkt har en creator.
- Det beskriver, hvad der var publiceret på det tidspunkt; nuværende regler bruges aldrig.

### 8.2 Schema

| Kolonne | Type | Noter |
| --- | --- | --- |
| `order_item_id` | `BIGINT UNSIGNED` PK | Én pr. linje; INSERT kun, aldrig UPDATE (dublet-hooks ignoreres) |
| `order_id` | `BIGINT UNSIGNED NOT NULL` | KEY |
| `creator_id` | `BIGINT UNSIGNED NOT NULL` | Ejeren ved snapshot; KEY |
| `product_id` | `BIGINT UNSIGNED NOT NULL` | Parent |
| `variation_id` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` | |
| `status` | `VARCHAR(8) NOT NULL` | `priced` eller `unpriced` |
| `reason` | `VARCHAR(20) NULL` | Ved `unpriced`: `no_base_price`, `never_applied`, `creator_changed`, `price_drift`, `currency_mismatch` eller `unavailable` |
| `currency` | `CHAR(3) NOT NULL` | Ordrens valuta |
| `rule` | `VARCHAR(7) NULL` | `default` eller `fixed` (kun `priced`) |
| `markup_bps` | `INT UNSIGNED NULL` | Kun `default` |
| `base_unit` | `DECIMAL(19,4) NULL` | Grundpris pr. enhed (admin- og revisionsformål; sendes aldrig til creators) |
| `profit_unit` | `DECIMAL(19,4) NULL` | Effektiv profit pr. enhed; ved `fixed` = den faste profit |
| `price_unit` | `DECIMAL(19,4) NULL` | Listepris (creator-pris) pr. enhed |
| `captured_at` | `DATETIME NOT NULL` | UTC |

**Constraints:**
- `status = 'priced'` ⇔ `rule`, `base_unit`, `profit_unit` og `price_unit` er sat og `reason` er NULL;
- `price_unit = base_unit + profit_unit`;
- `rule = 'default'` ⇔ `markup_bps IS NOT NULL`.

**Bevidst udeladt:**
- `fixed_profit` (= `profit_unit` ved `fixed`);
- antal (står med fortegn i hver ledger-række);
- realiseret profit (beregnes, afsnit 4);
- `created_at` (= `captured_at`).

### 8.3 Status ved snapshot

| Enhedens tilstand (afsnit 5) | Snapshot |
| --- | --- |
| `applied` eller `config_changed` med `R` = anvendt pris og anvendt for nuværende ejer, ordrens valuta = `applied_currency` | `priced` med de **anvendte** værdier (det, kunden faktisk så som listepris) |
| `creator_changed` | `unpriced/creator_changed` |
| `drift` | `unpriced/price_drift` |
| `no_base_price` eller `never_applied` | `unpriced/…` |
| `unavailable` | `unpriced/unavailable` eller `currency_mismatch` |
| Ordrens valuta ≠ anvendt valuta (fx multi-currency-plugin) | `unpriced/currency_mismatch` (ingen omregning, beslutning 21) |

### 8.4 Holdbarhed

Snapshottet ligger i pluginets egen tabel og refererer kun ved ID. Det overlever derfor:
- sletning af ordren (som ledgeren, ADR-037 pkt. 8);
- sletning af produkt eller variant;
- deaktivering af creatoren (creators slettes ikke);
- alle senere ændringer af standard, override, grundpris og WooCommerce-pris.

**Ledger-rækker uden snapshot** (ordrer før fase 22 eller linjer uden creator ved oprettelse): profit er **ukendt (NULL)**. Den rekonstrueres aldrig. "Ingen række" (ikke fanget) og `unpriced` (fanget, men uden pris) kan skelnes.

## 9. Integration med sales ledgeren

- Ledgeren (`merchboard_sales`, `merchboard_sales_cycles`), dens invariant og dens skrivevej ændres **ikke**. Ingen ny kolonne.
- Realiseret profit for en ledger-række (fremtidig læsning, fx Payouts) er:

  ```text
  hvis snapshot(order_item_id) findes og status = 'priced'
     og snapshot.creator_id = række.creator_id og snapshot.currency = række.currency:
         round_half_away(række.net_amount × profit_unit / price_unit, 4)
  ellers:
         NULL (ukendt)
  ```

| Rækketype | Profit |
| --- | --- |
| `sale` (cyklus 1 og senere) | Positiv andel af nettoen |
| Delvis refund | Negativ andel af refund-beløbet; samme snapshot, aldrig nuværende regler |
| Fuld refund | Negativ andel; summen over linjen bliver 0 (± afrunding pr. række) |
| Rabat | Allerede i `net_amount` (afsnit 4) |
| `reversal` | Modpost af cyklussens netto → modpost af profitten |
| `correction`/`line_changed` | Andel af deltaet; samme snapshot (linjen er den samme ordrelinje) |
| `correction`/`line_removed` og `refund_deleted` | Andel af korrektionen |
| Permanent slettet ordre | Ledgerens modposter + snapshottet, som overlever |

- **Ejerskift mellem snapshot og ledger-registrering:** ledgeren tager ejeren ved registrering (ADR-037), snapshottet ved linjens oprettelse. Er de forskellige, er profit `NULL` for rækken.
- **Ordrelinje, hvis produkt ændres efter oprettelse:** WooCommerce opretter normalt en ny linje. Ændres produktet på samme linje, gælder snapshottet fortsat den oprindelige linje (dokumenteret begrænsning).
- **Fase 22 leverer:** snapshottet og en ren, unit-testet beregningsfunktion. Aggregering og visning af profit (`/me/sales`, Payouts) kommer senere (beslutning 14). Når de kommer, skal SQL-aggregeringen give samme resultat som PHP-funktionen (testkrav).

## 10. Admin-UX

**Capability:** `merchboard_manage_creator_pricing` (ny, kun administrator). Uden den vises ingen pricing-sektion eller -kolonner.

**Skrivninger:**
- Capability før nonce, egen admin-post-action pr. handling, Post/Redirect/Get, audit i samme transaktion.
- Anvendelse til WooCommerce kræver desuden `edit_product` på produktet.

**Audit-actions:**
- `pricing_default_updated`;
- `pricing_override_set` / `pricing_override_removed` (med årsag);
- `base_price_set`;
- `creator_price_applied` (enhed, gammel og ny regular price, og evt. at WooCommerce fjernede et udsalg).

### 10.1 Creatorens redigeringsside → sektion "Pricing" (før Products)

- **Standard-markup:** felt i % (2 decimaler, 0–100), vist med regneeksempel.
- **Optælling:** antal produkter på standard / med fast profit / pr. tilstand.
- **"Gem markup"** ændrer kun konfigurationen. Produkterne går til `config_changed`; WooCommerce-priser ændres ikke.
- **"Anvend pricing på …"** (valg: alle standardprissatte, alle med afvigelse osv.) med forhåndsvisning:
  - pr. enhed: nuværende WooCommerce regular price → forventet pris;
  - udsalg, som WooCommerce vil fjerne;
  - enheder, der springes over (tilstand 1–4);
  - derefter bekræftelse.

### 10.2 Sektionen "Products" → pricing-kolonner pr. produkt (variant-rækker foldes ud)

| Kolonne | Indhold |
| --- | --- |
| Grundpris | Pr. enhed, med valuta; tom → "Angiv grundpris" (forslag = WooCommerce' regular price, kræver Gem) |
| Regel | "Standard 20,00 %" eller "Fast 35,00 DKK" |
| Forventet pris | `E` (og profit) |
| Anvendt | Anvendt pris, tidspunkt og for hvilken creator |
| WooCommerce | Aktuel regular price og evt. aktivt udsalg (kun info) |
| Status | Anvendt / Ikke anvendt (årsag) / Afviger / Kan ikke anvendes (årsag) |
| Handlinger | Angiv eller ret grundpris; Sæt fast profit; Brug standard; Anvend pricing (med forhåndsvisning) |

## 11. Creator-API og UX (kun læsning)

Ingen nye endpoints. Creator kun fra `CreatorContext` (ADR-007). Ingen grundpris, forventet pris, afvigelse, tidspunkter eller audit i creator-API'et.

**`GET /me`** får:

```json
"pricing": { "default_markup_percent": "20.00" }
```

**`GET /me/products`** får pr. produkt (`price` = aktiv WooCommerce-pris, uændret):

```json
"pricing": {
  "status": "priced" | "partial" | "pending",
  "rule": "default" | "fixed" | null,
  "markup_percent": "20.00" | null,
  "creator_price": { "min": "120.00", "max": "124.00" } | null,
  "profit": { "min": "20.00", "max": "20.00" } | null,
  "currency": "DKK" | null
}
```

- **Grundlag:** kun de **anvendte** værdier (det, der er publiceret) for enheder i tilstand `applied` eller `config_changed`, anvendt for creatoren selv.
  - `priced`: alle enheder.
  - `partial`: nogle enheder; intervallerne dækker kun dem.
  - `pending`: ingen enheder; `creator_price` og `profit` er `null`.
- `creator_price` er listeprisen (WooCommerce regular price); udsalg ses i `price`.
- **Dashboard:**
  - Products viser "Din pris" og "Din profit pr. stk." med en note om, at rabatter og udsalg reducerer profitten forholdsmæssigt.
  - Profile viser standardreglen som tekst.
  - Intet kan redigeres.
- **`/me/sales`:** uændret (beslutning 14).
- **Bemærk:** grundprisen sendes og vises aldrig, men den kan beregnes som creator-pris − profit. Det følger af beslutning 16 og kan ikke undgås, når både pris og profit vises.

## 12. ADR-forslag (PROPOSED — NOT YET ACCEPTED)

De flyttes ikke til DECISIONS.md, før de er accepteret.

> **2026-10-08 (trin 3):** accepteret og flyttet til [DECISIONS.md](../DECISIONS.md). Den gældende tekst står dér, inklusive præciseringer (bl.a. at `unavailable` hedder `cannot_apply`, ingen CHECK-constraints og den præcise `priced`-regel). Teksten herunder er historik.

### ADR-045: Creator pricing-model

#### Date
2026-10-08

#### Status
PROPOSED — NOT YET ACCEPTED (fase 22, trin 2). Bygger på projektejerens 22 beslutninger (rapportens afsnit 0). Supplerer ADR-030 (ejerskab), ADR-037/038 (ledgeren uændret) og ADR-039 P7 (`/me/products`).

#### Context
Creators skal tjene en profit oven på virksomhedens grundpris, og creator-prisen er den pris, kunden betaler. WooCommerce er sandheden for salgsprisen; grundpris og regler findes ikke i WooCommerce. Penge regnes uden floats (ADR-038 B1). WooCommerce sletter selv en udsalgspris ≥ regular price ved gemning.

#### Decision
1. **Listepris** (creator-pris) = WooCommerce' regular price for den salgbare enhed = grundpris + profit pr. enhed. Grundprisen er virksomhedens andel af listeprisen før rabatter, i butikkens momsgrundlag.
2. **Profit:** fast override (ADR-046), ellers `round_half_away(grundpris × markup_bps / 10000)` til butikkens prisdecimaler. Markup pr. creator i basis points 0–10 000, standard 0.
3. **Data og valuta:** grundpris pr. salgbar enhed og creatorens markup er Merch Dashboard-data (`merchboard_item_pricing`, `merchboard_creators.default_markup_bps`) med valuta; ingen omregning. Regler i en anden valuta end butikkens anvendes ikke.
4. **Anvendelse:** creator-prisen publiceres kun ved en eksplicit, auditeret handling (`merchboard_manage_creator_pricing` + `edit_product`). Den skriver kun regular price via CRUD og gemmer den anvendte tilstand (`applied_*`). Ingen runtime-filtre; sale price og `_price` skrives aldrig. En forhåndsvisning viser udsalg, som WooCommerce fjerner.
5. **Udsalg og rabatter** er rabatter på listeprisen og ændrer hverken regel, tilstand eller profit pr. enhed; den realiserede profit reduceres forholdsmæssigt (ADR-047).
6. **Tilstand pr. enhed** beregnes deterministisk (rapportens afsnit 5): `unavailable`, `not_applied` (`no_base_price`, `never_applied`, `creator_changed`, `config_changed`), `drift` og `applied`. Afvigelser overskrives aldrig automatisk, og WooCommerce-priser låses ikke.
7. **Første aktivering:** WooCommerce' regular price må foreslås som grundpris, men kun en eksplicit "Gem" gemmer den.
8. **Creators** ser deres standardregel, deres anvendte creator-pris og profit (`/me`, `/me/products`), aldrig grundprisen eller konfigurationen.

#### Alternatives considered
- Runtime-prisfiltre: risiko for salg til grundpris ved deaktivering eller oversete prisveje. Fravalgt.
- Creator-pris kun i dashboardet: kunden betaler ikke creator-prisen (strider mod beslutning 1). Fravalgt.
- WooCommerce COGS: `float`, valgfri feature, anden betydning. Fravalgt.
- Grundpris som produkt-meta: kræver flere beskyttede WooCommerce-meta-nøgler og spredt validering. Fravalgt.
- Udsalgspris som separat pricing (fx profit-fradrag først): en skjult anden model. Fravalgt.

#### Reason
Kunden betaler altid det, WooCommerce viser. Pluginet kan deaktiveres uden tab. Én forklarlig model for listepris, udsalg og rabat, og deterministiske tilstande.

#### Consequences
- **Migration:** kolonnen og `merchboard_item_pricing`; ny capability (rolleversion-bump); nye audit-actions.
- **Brugerflader:** admin-sektioner og felter i `/me` og `/me/products`.
- **Dokumentation:** WOOCOMMERCE.md præciseres (grundpris og regler er pluginets data; listeprisen publiceres via CRUD).

### ADR-046: Produktspecifik fast profit som eksklusiv override

#### Date
2026-10-08

#### Status
PROPOSED — NOT YET ACCEPTED (fase 22, trin 2). Bruges af ADR-045.

#### Context
Admin skal kunne give et creator-produkt en fast profit, der erstatter procenten. Ejerskab ligger på parent (ADR-030), og grundpriser kan variere pr. variant.

#### Decision
1. **Én override pr. parent-produkt** i `merchboard_pricing_overrides` (`product_id` PK), bundet til ejeren (`creator_id`) og valutaen. Den gælder pr. enhed for alle varianter. Ingen variantregler i v1.
2. **Gyldighed:** en override gælder kun, når `creator_id` = produktets nuværende ejer, og valutaen = butikkens. Så er profit = den faste profit, ellers creatorens standard. Procent og fast profit summeres aldrig, og override-tabellen har ingen procentkolonne.
3. **Nulstilling og 0:** "Brug standard" sletter rækken (auditeret). En fast profit på 0 er en gyldig override.
4. **Ejerskift:** en override kan kun oprettes for den nuværende ejer. Ved ejerskift eller frakobling slettes den i samme transaktion som koblingen i `ProductCreatorLink` (manuelt og via kategori-afstemning) og auditeres med årsag `ownership_changed`. WooCommerce-prisen ændres ikke; enhederne får tilstand `creator_changed` eller `unavailable`.
5. **Permanent sletning** af produktet sletter overriden.

#### Alternatives considered
- Override pr. variant eller begge niveauer: overlap og uklar forrang. Fravalgt.
- Procent og fast profit i samme række: muliggør ugyldige kombinationer. Fravalgt.
- At beholde overriden inaktiv ved ejerskift: den ville vågne skjult op, hvis produktet vender tilbage. Fravalgt.

#### Reason
Strukturel eksklusivitet, ingen cross-creator-lækage og ingen skjult genaktivering.

#### Consequences
- **Validering:** domænevalidering og CHECK (`fixed_profit >= 0`).
- **Integration:** `ProductCreatorLink` får et pricing-trin i sin transaktion.
- **Tests:** forrang, nulstilling, 0-override, ejerskift (A→B, A→B→A), kategori-afstemning og varianter med forskellig grundpris.

### ADR-047: Historiske creator-pricing-snapshots

#### Date
2026-10-08

#### Status
PROPOSED — NOT YET ACCEPTED (fase 22, trin 2). Supplerer ADR-037/038 uden at ændre dem.

#### Context
Historisk creator-profit skal kunne garanteres (beslutning 13), men vises ikke endnu (beslutning 14). `merchboard_sales` er append-only, med `net_amount` efter rabatter, ekskl. moms, og overlever ordresletning. Pricing-regler og WooCommerce-priser ændrer sig over tid.

#### Decision
1. **Snapshot:** når WooCommerce opretter en produktlinje med creator (`woocommerce_new_order_item`), skrives et snapshot i den immutable tabel `merchboard_sales_line_pricing`. Feltet er `order_item_id` PK, med linjens creator, produkt, variant, ordrevaluta, status `priced`/`unpriced` + årsag og, ved `priced`, de **anvendte** værdier (regel, markup, grundpris, profit og listepris pr. enhed). Kun INSERT; aldrig UPDATE eller DELETE (undtagen opt-in-uninstall).
2. **Status:** `priced` kun når WooCommerce' regular price = den anvendte pris, anvendt for den nuværende ejer, og ordrens valuta = den anvendte valuta. Ellers `unpriced` med årsag: `no_base_price`, `never_applied`, `creator_changed`, `price_drift`, `currency_mismatch` eller `unavailable`.
3. **Realiseret profit** for en ledger-række = `round_half_away(net_amount × profit_unit / price_unit, 4)`, kun når snapshottet er `priced` og creator og valuta matcher rækken; ellers NULL. Beregnet overflow-sikkert i heltal uden floats. Det gælder alle rækketyper (sale, refund, reversal, correction). Udsalg, kuponer og delvise refunds reducerer profitten forholdsmæssigt.
4. **Ledgeren:** ledgeren, dens invariant og dens rækker ændres ikke; ingen profitkolonne. Profit rekonstrueres aldrig ud fra nuværende regler. Linjer uden snapshot (før fase 22) har ukendt profit.
5. **Scope i fase 22:** snapshot og beregningsfunktion (unit-testet). Aggregering og visning kommer med Payouts/statistik, og SQL-aggregering skal give samme resultat som funktionen.

#### Alternatives considered
- Profitkolonne i ledgeren: cache af en ren funktion af immutable data. Fravalgt.
- Ordrelinje-meta som kilde: tabes ved permanent ordresletning; to kilder. Fravalgt.
- Fast profit pr. enhed uanset rabat: strider mod den godkendte rabatregel. Fravalgt.
- Beregning ud fra nuværende regler: ændrer historikken. Fravalgt.

#### Reason
Hver ordrelinje bærer den pricing, kunden faktisk så. Ledgeren forbliver uændret og append-only, og profit er deterministisk og reviderbar.

#### Consequences
- **Migration:** tabellen.
- **Implementering:** hook ved oprettelse af ordrelinjer; beregningsfunktion.
- **Tests:** udsalg, kupon, delvis og fuld refund, reversal, korrektioner, ordre- og produktsletning, ejerskift, afvigelse, valuta og ordrer før fase 22.

## 13. Forslag til trin i fase 22

| Trin | Indhold |
| --- | --- |
| 1 | Analyse ✅ |
| 2 | Endeligt design (denne rapport) ✅ |
| 3 | ADR-045–047 accepteret og flyttet til DECISIONS.md |
| 4 | Migration 6, capability, domæne (`CreatorPricing`: beregning, tilstande, rene funktioner; afrundingstabellen; konfliktreglen) |
| 5 | Admin: Pricing-sektion, grundpriser, overrides, forhåndsvisning, anvendelse via CRUD, audit |
| 6 | Ejerskift og livscyklus (`ProductCreatorLink`, papirkurv, sletning, nye varianter) |
| 7 | Snapshot på ordrelinjer + beregningsfunktion for realiseret profit |
| 8 | Creator-API og dashboard (`/me`, `/me/products`) |
| 9 | Runtime-verifikation i Merch Dashboard Local |
| 10 | Docs, commit, push og CI |

## 14. Resterende beslutninger

Alle har en anbefaling; ingen blokerer ADR-teksterne, men de bør bekræftes ved accept.

1. **Ordrer efter creatorskift, før ny anvendelse:** snapshot `unpriced/creator_changed` (profit ukendt). Anbefalet; alternativet er at tilskrive den tidligere creators profit, hvilket strider mod ledgerens ejer-snapshot.
2. **Ordre under afvigelse:** profit ukendt (`price_drift`) frem for at gætte. Anbefalet.
3. **Multi-currency-ordrer:** profit ukendt (`currency_mismatch`). Anbefalet.
4. **Inaktive creators:** deres produkter kan prissættes. Anbefalet.
5. **Grundprisen kan udledes** som creator-pris − profit, selv om den aldrig sendes. Accepteres som en følge af beslutning 16.
6. **Ændrede prisdecimaler** i WooCommerce giver tilstand `config_changed`. Anbefalet.
7. **Kladder:** produkter med status kladde kan prissættes (forberedelse); kun papirkurven blokerer. Anbefalet.

## 15. Fase 21-dokumentation

Uændret i dette trin: oprydningen (ROADMAP, PROJECT-STATUS og CHANGELOG for fase 21) er en separat opgave.

## 16. Rettelse af beregningsmodellen (2026-10-09, ADR-049)

**Låst forretningsregel:** WooCommerce-salgsprisen er den endelige kundepris inkl. creator-profit og moms. Creator-profit = salgspris × markup %. Udbetaling = profit (momsregistreret) eller profit × 0,80 (ikke momsregistreret). Merch Dashboard lægger aldrig noget oven i prisen og skriver aldrig priser.

**Den tidligere model og hvorfor den var forkert:** trin 3–6 byggede regular price = grundpris + profit (`round(grundpris × markup)` eller fast profit) og skrev den til WooCommerce ved "Anvend". Realiseret profit var `net × profit_unit / price_unit` (ekskl. moms), og payout for momsregistrerede `(net + moms) × profit_unit / price_unit` (ADR-048). Markup'en blev altså lagt oven i en grundpris, og prisen blev skrevet, hvilket den låste regel forbyder.

**Projektejerens afklaringer (2026-10-09):**
1. Pris-skrivningen fjernes helt (anvendelse, drift, grundpris, `merchboard_item_pricing` og UI'et).
2. Fast profit erstattes af en produktspecifik procent (eksklusiv, bundet til ejeren).
3. Grundlaget pr. ledger-række er det faktisk betalte beløb inkl. moms: `net_amount` + rækkens gemte moms.
4. Ny migration 8; gamle snapshots har ukendt andel.

**Migrations- og kompatibilitetsstrategi:** de gamle snapshots (`priced`/`unpriced`) indeholder en markup på grundprisen, ikke en andel af salgsprisen, så de kan ikke omregnes uden at gætte. Migration 8 tilføjer `share_bps`, `share_source` og `price_decimals` som NULL og ændrer ingen eksisterende række; deres profit og udbetaling er ukendt. Produktoverrides med fast beløb kan heller ikke omregnes og slettes, mens tabellen genoprettes med `markup_bps`. `merchboard_item_pricing` droppes. På Merch Dashboard Local var alle tre tabeller tomme før migrationen, og fase 22 er ikke frigivet.

**Runtime (2026-10-09, Merch Dashboard Local, WordPress 7.1.3, WooCommerce 11.1.2, HPOS, dansk site, syntetiske MB-T22F-data):** 41/41 checks via WP-CLI (`--user=1`) med rigtige ordrer (`wc_create_order()`, `add_product()`, `wc_create_refund()`):

- migration 8 kørte (schema-version 8, `merchboard_item_pricing` droppet, nye kolonner), og creator 1's markup på 16,67 % var uændret;
- de fem låste eksempler (100/20 %/Nej → 20/16; 100/20 %/Ja → 20/20; 200/10 %/Nej → 20/16; 200/10 %/Ja → 20/20; 100/0 %/Nej → 0/0), hver linje med den markup og momsstatus, der gjaldt ved oprettelsen;
- snapshottets indhold (`share`, 2000, `creator`, 2 decimaler, momsstatus), afrunding 99,99 × 16,67 % = 16,67 → 13,34;
- produktmarkup 50 % på et variabelt produkt for begge variationer (kilde `product`, egen variation-ID);
- salg 3 × 100 (60/48), refund (−20/−16), slettet refund (korrektion +20/+16) og annullering (reversal, sum 0);
- WooCommerces regular-, udsalgs- og aktive priser uændrede, ingen Merch Dashboard-callback på prisfiltrene og ingen apply/drift/fast profit-audit;
- historikken og snapshot-rækkerne uændrede efter ændret markup, momsstatus, produktmarkup og WooCommerce-pris;
- med midlertidig moms (priser inkl. moms, syntetisk 25 %-sats, som godkendt for ADR-048): WooCommerce deler 100 i 80 + 20, profit 20, udbetaling 16 (Nej) eller 20 (Ja), og en fuld refund går i 0;
- admin-sektionen (engelsk og dansk) med forklaringen, Ja/Nej-teksterne og eksemplet 20,00/20,00 og uden Anvend, drift og grundpris;
- ingen kundedata-kolonner og ingen nye ledger-kolonner.

Oprydning: ordrer, produkter, koblinger, produktmarkups og de syntetiske ordrers ledger-, cyklus-, snapshot- og momsrækker er fjernet; ledger 6/2, ingen snapshot-, moms- eller produktmarkup-rækker, ingen momssatser og momsindstillingerne er tilbage. Creator 4 er gendannet (inaktiv, 0 %, ikke momsregistreret). Creator 1 (16,67 %), logo 198 og bruger 1's `dark-pink` er bevaret. Append-only audit-records fra kørslen er bevaret. Ingen browser.

**Åbent spørgsmål:** 20 %-fradraget svarer til momsandelen ved 25 % moms (20 / 1,25 = 16), men ikke ved andre satser eller 0 % (fx momsfritaget salg eller eksport). Reglen er implementeret som besluttet og skal afklares, før udbetalingen bruges til afregning (ADR-049).

## 17. Creator-profit i dashboardet (2026-10-09, ADR-050)

Creators ser deres historiske profit (ADR-049) i Products (pr. produkt inkl. variationer, al tid), Orders (pr. ordrelinje og ordre i alt) og Sales (for den valgte periode). Én aggregering (`LedgerCreatorProfits`) på pluginets egne tabeller bruges af `/me/products`, `/me/orders` og `/me/sales`; integrationstests sikrer, at hver sum er lig summen af `RealizedProfit` pr. række. Ufuldstændig og ukendt profit har intet beløb og vises som "Ufuldstændig" eller "Ukendt", aldrig 0.

**Runtime (2026-10-09, Merch Dashboard Local, WooCommerce 11.1.2, HPOS, syntetiske MB-T22P-data, midlertidig moms inkl. 25 %):** 20/20 checks via REST som en syntetisk creator-bruger:

- `/me/products`: tee 20 + 20 − 10 (refund) = 30 komplet, variabelt produkt 40 under parent, produkt uden salg `null`, produkt med en linje uden snapshot `unknown`;
- `/me/orders`: linjeprofit 20 og ordretotal 60 over 2 linjer, linje med refund 10, linje uden snapshot `unknown`; ingen salgsbeløb eller kundedata;
- `/me/sales`: profit adskilt fra uændrede salgstal og `incomplete` uden beløb; cachet svar identisk; `creator_id` fra klienten ignoreres;
- historikken uændret efter ændret markup og momsstatus; WooCommerce-priserne uændrede.

Oprydning: ordrer, produkter, koblinger, den syntetiske bruger og de syntetiske ordrers ledger-, cyklus-, snapshot- og momsrækker er fjernet (ledger 6/2), momssats og -indstillinger er tilbage, creator 4 er gendannet (inaktiv, 0 %, ikke momsregistreret, tidligere brugerkobling). Creator 1 (16,67 %), logo 198 og bruger 1's `dark-pink` er bevaret. Append-only audit-records er bevaret. Ingen browser.

## 18. Admin-ordrehistorik (2026-10-09, ADR-051)

"Merch Dashboard → Order history" viser sales ledgeren pr. WooCommerce-ordre for `merchboard_manage_creators`, kun læsning. Listen er server-side pagineret (én grupperet SQL med samme profitbetingelse som `LedgerCreatorProfits`), har filtre på ordre-ID, creator og profitstatus og viser ingen kundedata. Detaljen viser cyklusser, ordrelinjer med snapshot og alle ledger-hændelser med gemt moms og profit eller årsagen til, at den er ukendt (`ProfitDiagnosis`).

**Sletning pr. ordre er ikke implementeret.** Den ville slette rækker i `merchboard_sales`, `merchboard_sales_cycles`, `merchboard_sales_tax` og `merchboard_sales_line_pricing` og strider dermed mod ADR-037 pkt. 2 og 8, ADR-038 pkt. 5, ADR-047 og ADR-048. Findes WooCommerce-ordren stadig, ville næste synkronisering desuden registrere den igen uden snapshot. Beslutningen og et forslag står i ADR-051 pkt. 7.

**Runtime (2026-10-09, Merch Dashboard Local, WooCommerce 11.1.2, HPOS, syntetiske MB-T22H-data, midlertidig 25 % moms):** 31/31 checks via WP-CLI som bruger 1:

- en komplet ordre med flere hændelser (2 salg + refund på 2 linjer, netto 160, profit 40), en ukendt (linje uden snapshot) og en ufuldstændig (1 kendt, 1 ukendt);
- filtre (ordre-ID, creator, de tre statusser, kombineret), paginering (1 pr. side gennem alle 8 ordrer, tom side efter den sidste) og antal;
- detaljens årsager; de rigtige ordrer 177, 201 og 217 vises med "Intet profit-snapshot" på alle rækker (kun læst);
- renderet liste og detalje uden kundedata (syntetiske faktureringsdata fandtes ikke i HTML'en), ugyldige parametre med notice, 403 uden capability;
- visningen skrev ingen rækker, og WooCommerce-ordrerne (status, ændringstid, total, linjer, refunds) var uændrede.

Oprydning: ordrer, produkter, koblinger, den syntetiske bruger og de syntetiske ordrers ledger-, cyklus-, snapshot- og momsrækker er fjernet (tabellerne tilbage på 14/5/6/8 rækker), momssats og -indstillinger er tilbage, creator 4 er gendannet. Creator 1 (16,67 %), logo 198 og bruger 1's `dark-pink` er bevaret. Ingen browser.

**Beslutning 2026-10-09 (kun læsning):** projektejeren har godkendt siden som ren visning. Fysisk sletning af ordrehistorik er ikke en del af fase 22. Der indføres ingen undtagelse fra append-only-reglerne og ingen "registrér ikke igen"-markering (ADR-051, beslutningsnote). Siden har ingen slettehandling, og teksterne siger nu, at historikken aldrig ændres eller slettes. Runtime gentaget efter ændringen (2026-10-09, syntetiske MB-T22H-data): 33/33 checks, herunder at ingen side har POST-formular, nonce, `admin-post`-handling eller sletteknap, og at forklaringen (dansk locale) siger, at historikken aldrig slettes. Oprydning til 14/5/6/8 rækker; creator 1, logo 198 og `dark-pink` bevaret.

**Rettelse 2026-10-10: siden manglede i menuen.** Undermenuen blev registreret inde fra `admin_init`, men WordPress kører `admin_menu` før `admin_init` (`wp-admin/admin.php`), så "Order history" blev aldrig tilføjet til menuen. Testene og runtime-kørslerne kaldte `add_menu()` og `render()` direkte og fangede det derfor ikke. Nu hookes `admin_menu` direkte i `WooCommerceModule` (prioritet 25), og en unit-test sikrer det. Verificeret på Merch Dashboard Local ved at bygge wp-admin-menuen som WordPress gør (kun læsning): Overview, Creators, Settings, Order history, Audit log. Ikke set i en browser.

**Skjulning af gamle ordrer (2026-10-10, ADR-051, beslutningsnote):** listen viser som standard kun ordrer med profitdata. Ordrer helt uden profitdata skjules med en notits ("N ordrer uden profitdata er skjult … Intet er slettet." og "Vis alle ordrer"). Kun visningen ændres; ledgeren, Sales, Overview og creator-profitten er uændrede.

**Sletning af en testordres historik (2026-10-10, ADR-052):** projektejeren ændrede beslutningen fra 2026-10-09. En administrator med `merchboard_delete_order_history` kan på ordrens side slette Merch Dashboards gemte historik for én ordre (ledger, cyklusser, gemt moms og snapshots), når WooCommerce-ordren ligger i papirkurven eller er slettet. Nonce pr. ordre, indtastet ordre-ID, én transaktion under ledgerens ordrelås med audit `order_history_deleted`; WooCommerce ændres aldrig.

Runtime (2026-10-10, Merch Dashboard Local, syntetisk MB-T22D-testordre, midlertidig 25 % moms): 24/24 checks. Rollerne blev synkroniseret til version 4 ved første request; den aktive ordre blev afvist og viste kun forklaringen; i papirkurven blev formularen vist, et forkert ordre-ID og en bruger uden capability slettede intet; sletningen fjernede ordrens 4 rækker (1 ledger, 1 cyklus, 1 moms, 1 snapshot) og intet andet (ordrerne 177, 201, 217, 330, 333 og 350 uændrede), WooCommerce-ordren var uændret i papirkurven, audit-posten havde kun antal og ID'er, og en ny gemning af den kasserede ordre registrerede den ikke igen. Ordre 333 blev kun læst. Oprydning til 27/10/15/7 rækker; creator 4, momsindstillinger, creator 1 (16,67 %), logo 198 og `dark-pink` bevaret. Audit-posten fra testen er bevaret (append-only). Ikke set i en browser.
