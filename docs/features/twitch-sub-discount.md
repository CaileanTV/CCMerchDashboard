# Feature: Rabat til Twitch-subscribers

## Purpose
Creatorens Twitch-subscribers får automatisk rabat på creatorens produkter i butikken. Det er et af "Community Features" i MitCCMerch, inspireret af Fourthwall. Rabatten betales af creatorens fortjeneste, så virksomhedens del og andre creatores fortjeneste aldrig påvirkes.

## Current status
I gang (fase 43, ADR-106). Udgives i næste version efter review og runtime-test.

## User-facing functionality
**Creatoren** (MitCCMerch → Community Features → Twitch subscriber discount):
- forbinder sin Twitch-kanal (samme forbindelse som chatbotten; chatbeskederne kan stadig være slået fra);
- vælger, om abonnementer, nogen har givet i gave, tæller (gælder alle kampagner);
- opretter op til 20 kampagner. Hver har et navn, til/fra, procent pr. tier med op til to decimaler (1, 2, 3; Prime = tier 1), alle egne produkter eller udvalgte (produktvælger med søgning) og en valgfri periode;
- ser "Højst X %", som er den laveste fortjeneste-% blandt kampagnens produkter, med decimaler, når den ikke er hel (fx "Højst 16,67 %"). Serveren afviser højere procenter.

Regler for kampagner, der er slået til, og hvis perioder mødes:
- et produkt kan kun være i én kampagne med udvalgte produkter;
- der kan kun være én kampagne for alle produkter;
- på et produkt går en kampagne med udvalgte produkter forud for "alle".

**Kunden** (kurv og checkout, klassisk og blokke):
- Et kort vises **kun**, når kurven har creator-merchandise med en aktiv kampagne hos en creator, der har forbundet Twitch.
- Ikke logget ind: "Log ind med Twitch" og den højeste procent, der tilbydes. Har kunden en husket konto, står der "Tjek mit abonnement som …".
- Logget ind: Twitch-navnet, om rabatten gælder, og "Log ud fra Twitch".
- Rabatten vises som "Twitch-subscriber-rabat" uden fjern-link. Den lægges ikke oven i andre rabatkoder; kunden får den største pr. linje.
- Lige før ordren oprettes, tjekkes abonnementet igen. Er noget ændret, stopper checkout med en besked, og kurven viser den nye pris.

## Admin functionality
- Ordresiden viser kundens Twitch-konto og tier og procent pr. creator.
- Ordrehistorikken (Merch Dashboard → ordrehistorik) viser "Twitch subscriber discount X, paid from the profit" på rækken.
- Audit-loggen viser "Twitch subscriber discount changed" med feltnavne.
- Twitch-appen sættes op som for chatbotten (ADR-066). Kundens scope `user:read:subscriptions` kræver ikke noget ekstra.

## Technical implementation
- **Login** (Alerts-modulet): `TwitchCustomerLogin` står for OAuth via appens eksisterende callback, hvor `TwitchOAuth` sender kundens states videre. `state` er bundet til en HttpOnly-cookie og til WordPress-brugeren. `TwitchCustomerSession` holder et krypteret token i WooCommerce-sessionen, gemmer svar i 10 minutter og husker kontoen i user meta. `TwitchApi::user_subscription()` slår abonnementet op.
- **Rabat** (Community-modulet): `TwitchSubDiscount` vælger kampagne og tier pr. linje og fejler lukket. `TwitchSubCoupon` er en virtuel kupon af egen type, der beregnes sidst og kun fylder op til subscriber-rabatten, med loft ved creatorens fortjeneste pr. enhed (`CreatorProfitDiscount::per_unit()`). `TwitchSubCheckout` tjekker abonnementet igen før ordren.
- **Regnskab** (WooCommerce-modulet): migration 49 (`sub_discount`). En salgsrække får fortjenesten `max(0, f(betalt + S) − S)`, refunderinger tages tilbage forholdsmæssigt, og PHP og SQL læser de gemte beløb (`SubDiscountAmounts`, `SalesRepository`, `LedgerCreatorProfits`/`LedgerCreatorPayouts`).

## Relevant files
- `src/Modules/Community/`
- `src/Modules/Alerts/Chatbot/TwitchCustomerLogin.php`, `TwitchCustomerSession.php`, `src/Modules/Alerts/Rest/ShopTwitchController.php`
- `src/Modules/WooCommerce/Pricing/SubDiscountAmounts.php`, `src/Modules/WooCommerce/SubDiscount/SubDiscountMeta.php`
- `assets/src/dashboard/sections/community/TwitchSubDiscountSection.tsx`, `assets/src/dashboard/api/community.ts`
- `tests/Integration/Community/`, `tests/Integration/Alerts/TwitchCustomerLoginTest.php`, `tests/Integration/WooCommerce/SubDiscountLedgerTest.php`, `tests/Unit/Modules/Community/`, `assets/src/dashboard/test/twitch-sub-discount.test.tsx`

## Database/data used
Se DATABASE.md: creator-meta `twitch_sub_discount`, kolonnen `merchboard_sales_tax.sub_discount` (migration 49), ordre-meta `_merchboard_twitch_sub`, linje-meta `_merchboard_sub_*`, WooCommerce-sessionen `merchboard_twitch` og user meta `merchboard_twitch_customer`.

## Permissions
- `/me`-ruten bruger `CreatorContext` (ADR-007). Kun egne produkter tillades (`CreatorProducts::owns()`). Gem blokeres under "Se som creator".
- Kundens login- og log ud-links kræver sidens nonce (`merchboard_twitch_shop`).
- Rabatten beregnes kun på serveren ud fra Twitchs svar.

## API endpoints
- `GET`/`POST /merchboard/v1/me/community/twitch-sub-discount` (creator)
- `GET /merchboard/v1/shop/twitch/login` og `/logout` (kunden, nonce)
- Callback: `GET /merchboard/v1/alerts/twitch/callback` (fælles med chatbotten)

## Hooks
WooCommerce:
- kuponer: `woocommerce_get_shop_coupon_data`, `woocommerce_coupon_sort`, `woocommerce_coupon_get_items_to_apply`, `woocommerce_coupon_get_discount_amount`, `woocommerce_coupon_is_valid_for_product`, `woocommerce_apply_individual_use_coupon`, `woocommerce_apply_with_individual_use_coupon`;
- kurv og ordre: `woocommerce_before_calculate_totals`, `woocommerce_checkout_create_order_line_item`, `woocommerce_order_recalculate_coupons_coupon_object`, `woocommerce_order_after_calculate_totals`;
- checkout: `woocommerce_after_checkout_validation`, `woocommerce_store_api_checkout_update_order_from_request`;
- visning: `woocommerce_before_cart`, `woocommerce_before_checkout_form`, `render_block`, `woocommerce_admin_order_data_after_billing_address`.

WordPress: `wp_privacy_personal_data_exporters` og `_erasers`.

## Dependencies
WooCommerce, Alerts-modulet (Twitch-appen, creatorens kanal) og WooCommerce-modulets ledger. Ekstern tjeneste: Twitch (ADR-066, ADR-106).

## Tests
Unit-, integrations- og Jest-tests (se Relevant files). Kuponens forløb med rigtig WooCommerce testes i runtime-testen (43.9), fordi testmiljøet ikke indlæser WooCommerce.
