# Feature: Twitch-gifting

## Purpose
En seer kan købe en af creatorens varer som gave til chatten, mens creatoren streamer på Twitch. Botten trækker en vinder blandt dem, der skriver `!enter`, og vinderen henter gaven med sit Twitch-login. Creatoren får fortjeneste af købet som ved ethvert salg ([ADR-107](../DECISIONS.md), ROADMAP fase 44, Community Features pkt. 2).

## Current status
I gang (fase 44, testes sammen med fase 43 på stg og udgives som 2.0.0). 44.1–44.8 er bygget og testet automatisk. Mangler: review, CI, testpakke og runtime-test på stg (44.9). Lodtrækningen kræver chatbottens webhook og kan kun testes på et site med HTTPS (stg eller devpage).

## User-facing functionality
**Seeren (køberen):**
- Produktsiden viser "Giv som gave i streamen", når creatoren har gifting slået til, ikke har sat på pause, har forbundet Twitch-kanal og er live.
- Knappen lægger produktet i en tom kurv til den billigste variant (antal 1) og går til checkout. Kurven skal være tom; en gave købes alene.
- Fragten er fast 50 kr. inkl. moms.
- Navnet i annonceringen er alerts' valgfrie brugernavn-felt. Uden navn er gaven anonym ("En anonym seer").
- Køberen får e-mails i WooCommerces design, når vinderen er trukket, når gaven er hentet, og (efter tre lodtrækninger uden afhentning) med gavekoden, når gaven går tilbage til køberen.

**Chatten:**
- Når gave-ordren er betalt, og creatoren er live, annoncerer botten gaven og åbner `!enter`-vinduet (20–180 sekunder, standard 60). Kun én gave ad gangen; den næste venter.
- Når vinduet lukker, vinder en tilfældig deltager (`random_int`, hver Twitch-konto én gang, køberen kan også vinde). Botten skriver vinderen og linket til at hente gaven.
- Ingen deltagere: gaven venter. Den åbnes ikke igen af sig selv, men ved næste `!gift start`.
- Creatoren og moderatorer kan skrive `!gift start` (start ventende gaver), `!gift pause` og `!gift resume`. Under pause kan der ikke købes gaver, og der startes ingen lodtrækning.
- Teksterne er faste, på dansk eller engelsk efter creatorens valg.

**Vinderen:**
- Linket kræver Twitch-login (kundens login fra fase 43). Kun den vindende Twitch-konto får koden.
- Koden er en engangskupon, der gælder gave-produktet (alle varianter): ét stk. op til den betalte pris plus op til 50 kr. af fragten. En dyrere variant eller fragt betaler vinderen selv.
- Koden lægges automatisk på, når produktet kommer i kurven. Åbner vinderen linket igen, vises samme kode.
- Fristen er 24 timer. Derefter venter gaven på en ny lodtrækning (højst 3). Efter tredje gang går koden til køberen på e-mail.

**Creatoren** (Community Features → Twitch gifting):
- slå gifting til og fra (kræver forbundet Twitch-kanal), vinduets længde og sproget;
- se om man er live, sæt på pause eller genoptag (chatten får besked), "Start ventende gaver";
- listen over de seneste 50 gaver: købt, produkt, fra (navn eller anonym), status, vinder og antal lodtrækninger;
- klokken: en gave er købt, en gave er hentet.

## Admin functionality
- Ordresiden for en gave-ordre viser gavens status, vinder og antal lodtrækninger. Når der er trukket en vinder, advarer den mod refundering: en refundering ændrer ikke gaven.
- Ordrenoter for hvert trin (aldrig koden).
- E-mail "Twitch-gave givet tilbage til køberen" (Notifikationer → Til administratoren).

## Technical implementation
- `GiftPurchase`: knappen, gave-kurven (`merchboard_gift` på kurv-linjen, kun gaven, ét stk.), fragten (`woocommerce_package_rates` → én sats `merchboard_gift`), linje-meta `_merchboard_gift` (creatoren) og gaven oprettet ved `processing`/`completed` (én pr. ordre, `INSERT IGNORE`). `cancelled`/`refunded` annullerer kun en gave uden vinder.
- `GiftDraws`: lodtrækningerne. Chatbeskeder kommer fra webhooken som action `merchboard_twitch_chat_message`. Jobs via Action Scheduler (`merchboard_gift_draw`, `merchboard_gift_expire`) og som fallback ved hver chatbesked. Hver statusændring er en betinget `UPDATE` i `GiftRepository`, så job, webhook og klik aldrig flytter en gave to gange. Deltagere tilføjes under rækkelås.
- `GiftCodes`: kupontypen `merchboard_twitch_gift` (kupon-meta `_merchboard_gift_id`), rabat pr. linje og et negativt gebyr for fragten. Koden gælder kun, mens gaven er `claimed` eller `returned` med samme kupon.
- `GiftClaim`: `?merchboard_gift_claim=<token>` (`template_redirect`), Twitch-login via `ShopTwitchController::link( 'login', 'claim:<token>' )`.
- `GiftNotices`: lytter på `merchboard_gift_event` (`bought`, `won`, `claimed`, `returned`) og sender notifikationer, køber-mails og ordrenoter.
- `TwitchLiveStatus` (Alerts): `GET helix/streams`, svaret gemmes i 60 sekunder.
- Chatbeskeder sendes med `ChatSender::send_now()`, også når creatorens ordrebeskeder er slået fra.

## Relevant files
- `src/Modules/Community/Gifting/` (`Gift`, `GiftRepository`, `GiftingSettings`, `GiftingSettingsRepository`, `GiftPurchase`, `GiftDraws`, `GiftTexts`, `GiftCodes`, `GiftClaim`, `GiftNotices`, `GiftOrderInfo`, `GiftPrivacy`, `GiftCreatorCleanup`)
- `src/Modules/Community/Rest/MeTwitchGiftingController.php`, `src/Modules/Community/CommunityModule.php`
- `src/Modules/Alerts/Chatbot/TwitchLiveStatus.php`, `TwitchCustomerLogin.php`, `src/Modules/Alerts/Rest/ShopTwitchController.php`, `TwitchController.php` (chat-action)
- `src/Core/Database/Migrations/CreateGiftsTable.php`
- `assets/src/dashboard/api/gifting.ts`, `sections/community/TwitchGiftingSection.tsx`
- `tests/Integration/Community/TwitchGiftingTest.php`, `tests/Unit/Modules/Community/GiftingTest.php`, `assets/src/dashboard/test/twitch-gifting.test.tsx`

## Database/data used
- Tabellen `merchboard_gifts` (schema-version 50), se [DATABASE.md](../DATABASE.md#twitch-gaver-schema-version-50-adr-107).
- Creator-meta `twitch_gifting` (`enabled`, `paused`, `window`, `language`).
- Linje-meta `_merchboard_gift` på gave-ordren, kupon-meta `_merchboard_gift_id` på koden.
- Transienter `merchboard_twitch_live_<md5>` (60 sekunder), `merchboard_gift_run_<creator>` og `merchboard_gift_tried_<creator>` (6 timer).

## Permissions
- Dashboardet: creator med dashboard-adgang via `CreatorContext` (ADR-007). Skrivninger er blokeret under "Se som creator" (`ImpersonationGuard`), fordi de taler i creatorens chat.
- Chatten: `!gift`-kommandoer kun fra badges `broadcaster` og `moderator` i den signerede webhook.
- Indløsning: claim-tokenet (32 hex, `random_bytes`) plus Twitch-login med state og cookie (ADR-106). Kun vinderens Twitch-id får koden.

## API endpoints
- `GET`/`POST /merchboard/v1/me/community/twitch-gifting`
- `POST /merchboard/v1/me/community/twitch-gifting/pause` (`{paused}`)
- `POST /merchboard/v1/me/community/twitch-gifting/start`
- `GET /merchboard/v1/shop/twitch/login?target=claim:<token>` (kundens login, fase 43)

## Hooks
- Lytter: `merchboard_twitch_chat_message`, `merchboard_gift_draw`, `merchboard_gift_expire`, `woocommerce_order_status_processing|completed|cancelled|refunded`, kurv-, kupon- og fragt-filtre.
- Udløser: `merchboard_gift_event` (event, Gift, data) og `merchboard_notify` (`gift_bought`, `gift_claimed`, `admin_gift_returned`).

## Dependencies
WooCommerce, Alerts-modulet (Twitch-app, chatbot og webhook) og kundens Twitch-login (fase 43). Action Scheduler (WooCommerce) til jobs.

## Tests
- Integration: indstillinger og audit, én gave pr. ordre, annoncering og lodtrækning, ingen deltagere uden spam, gaver betalt offline og `!gift start`, pause, ikke hentet, refundering kun uden vinder, privacy og creator-sletning.
- Unit: indstillinger, tekster og gave-rækken.
- Jest: parseren og siden (gem, pause, start, feltfejl).
- Impersonation: skrivningerne er blokeret.
- Kupon, kurv og claim-flow testes runtime på stg (WooCommerce er ikke indlæst i testmiljøet).

## Known limitations
- Kun Twitch.
- Lodtrækningen kræver chatbottens webhook (HTTPS).
- Gave-kurven tillader ikke andre varer samtidig.

## Future improvements
- Flere gaver på én gang (antal > 1).
