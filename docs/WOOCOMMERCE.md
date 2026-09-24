# WooCommerce-integration

> Status: **Design.** Implementeres i fase 17-20. Ingen WooCommerce-kode i tidligere faser.

## Principper

1. **WooCommerce er source of truth** for produkter, varianter, ordrer, refunds og kunder. Vi kopierer ikke masterdata.
2. **Valgfrit modul:** `Modules/WooCommerce` indlæses kun, hvis WooCommerce er aktivt. Resten af pluginet (creators, adgang og dashboard-shell) virker uden.
3. **HPOS-kompatibel fra dag ét:** kun CRUD-API'er (`wc_get_order()`, `wc_get_orders()`, `$order->get_items()`, `wc_get_product()`, `$product->get_meta()`). **Aldrig** direkte SQL mod `wp_posts`/`wp_postmeta` for ordrer. Kompatibilitet erklæres via `FeaturesUtil::declare_compatibility( 'custom_order_tables', CCMERCH_FILE, true )`.
4. Tungt arbejde (backfill og genberegning) køres asynkront via **Action Scheduler**, som følger med WooCommerce.
5. Minimumsversion af WooCommerce fastlægges i fase 17 (header `WC requires at least`).

## Kobling: produkt → creator

- Product meta **`_ccmerch_creator_id`** på parent-produktet. Varianter arver fra parent.
- Sættes af admin i produktets data-panel (se [ADMIN.md](ADMIN.md#woocommerce-skærme-fase-17)). Ændringer audit-logges.
- Kun **aktive** creators kan vælges. Hvis en creator suspenderes, beholder produktet koblingen (historik), men creator'en kan ikke logge ind.
- **Én creator pr. produkt i v1.** Collab-produkter med revenue split kræver en `ccmerch_product_creators`-tabel. Det er et åbent spørgsmål, om det er nødvendigt.

## Sales ledger

**Hvorfor en ledger i stedet for at forespørge WooCommerce live?**

- Performance: dashboard-aggregater over måneder og mange ordrer fra `wc_get_orders()` pr. request skalerer ikke. `wc_order_product_lookup` er en intern analytics-tabel uden stabil kontrakt.
- Uforanderlig attribution: Hvis et produkt senere flyttes til en anden creator, må historisk salg ikke skifte ejer. Ledger-rækken er et snapshot.
- Grundlag for fremtidige payouts: Payouts skal kunne revideres række for række.

**Hvordan (fase 18):**

| Hændelse | Hook | Ledger-effekt |
| --- | --- | --- |
| Ordre bliver "tællende" (default: `processing` eller `completed`; kan konfigureres) | `woocommerce_order_status_changed` | Én `sale`-række pr. ordrelinje med creator |
| Ordre forlader tællende status (fx → `cancelled`, `failed`) | `woocommerce_order_status_changed` | `reversal`-rækker, der modposterer |
| Refund oprettet | `woocommerce_order_refunded` / `woocommerce_refund_created` | `refund`-række pr. refunderet linje (negativt beløb/antal) |
| Ordre slettet permanent | `woocommerce_before_delete_order` (HPOS) / `before_delete_post` | `reversal` hvis der er poster |

- **Idempotens:** `UNIQUE(order_item_id, refund_id, type)`. Gentagne hooks giver ingen dubletter (`INSERT IGNORE`). Et salg, der er reverseret og bliver tællende igen, håndteres med en løbende saldo pr. linje, som skal specificeres i fase 18.
- **Beløb:** `net_amount` = linjetotal efter rabatter (`$item->get_total()`), **ekskl. moms og fragt**. Valuta pr. ordre gemmes. Om creator-omsætning skal opgøres før eller efter rabat/moms er en forretningsbeslutning (åbent spørgsmål).
- **Backfill:** WP-CLI-kommando og/eller admin-knap, der via Action Scheduler gennemløber historiske ordrer i batches.
- **Reconciliation:** en senere job-type, der sammenligner ledger med WooCommerce for en periode og logger afvigelser.

## Dashboard-data og privacy

- `/me/products`: læses fra WooCommerce (`wc_get_products( [ 'meta_key' => '_ccmerch_creator_id', 'meta_value' => $creator_id ] )`) med paginering.
- `/me/orders`: bygges fra ledger og ordrelinjer. Creators ser **ikke** kundens navn, e-mail, telefon eller adresse (GDPR: creator'en er ikke databehandler for virksomhedens kunder). Højst ordrenummer, dato, status, produkt, antal og evt. land. Beslutningen skal bekræftes af virksomheden.
- `/me/sales`: aggregeret fra `ccmerch_sales` med caching.

## Øvrige hensyn

- **Multi-currency:** Hvis shoppen sælger i flere valutaer, aggregeres pr. valuta. Der sker ingen omregning i v1.
- **Produkter slettet:** Ledger-rækker bevares. Visning falder tilbage til snapshot-navn, så produktnavn bør overvejes tilføjet til ledgeren i fase 18.
- **WooCommerce deaktiveret:** Modulet indlæses ikke. Dashboard-sektioner, der kræver WooCommerce, registreres ikke. Eksisterende ledger-data bevares.
