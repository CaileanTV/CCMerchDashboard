# Feature: Twitch-gifting

## Purpose
En seer kan købe en af creatorens varer som gave til chatten, mens creatoren streamer på Twitch. Botten trækker en vinder blandt dem, der skriver `!enter`, og vinderen henter gaven med sit Twitch-login. Creatoren får fortjeneste af købet som ved ethvert salg ([ADR-107](../DECISIONS.md), ROADMAP fase 44, Community Features pkt. 2).

## Current status
Udgivet i 2.0.0 (fase 44, tagget på `c2f212c`): reviewet, testet med unit-, Jest- og integrationstests og afprøvet på stg af projektejeren. Påmindelserne og gave-uret kom i 2.0.0; i 2.1.0 gennemføres en betalt gave-ordre automatisk, og i 2.1.1 vælger admin linknavnet (`CommunityLink`). Fase 48 (ADR-111, udgives i 2.2.0 sammen med fase 49): ny gave-boks og 1–10 gaver pr. køb, bygget og runtime-testet i Playground. Lodtrækningen kræver chatbottens webhook og kan kun testes på et site med HTTPS (stg eller devpage).

## User-facing functionality
**Seeren (køberen):**
- Produktsiden viser gave-boksen "Giv som gave på streamen", når creatoren har gifting slået til, ikke har sat på pause, har forbundet Twitch-kanal og er live. Fra fase 48 (ADR-111) er det et kort i Twitch-lilla med Twitch-ikonet og et rødt, pulserende LIVE-mærke (står stille ved "reduceret bevægelse"), "<Creator> er live på Twitch lige nu" (navnet linker til kanalen i en ny fane), en antalsvælger (1–10) og en stor knap.
- Knappen lægger produktet i en tom kurv til den billigste variant med det valgte antal og går til checkout. Kurven skal være tom; en gave købes alene. I kurven kan antallet ændres inden for 1–10.
- Hver enhed er sin egen gave med sin egen lodtrækning, vinder og kode. Prisen pr. gave er linjens betalte beløb delt med antallet.
- Fragten er 50 kr. inkl. moms pr. gave (3 gaver = 150 kr.). Twitch-subscriber-rabatten og rabatkoder gælder ikke for gaver, og Twitch-kortet vises ikke i en gavekurv.
- Gave-ordren gennemføres automatisk, når den er betalt (fra 2.1.0): den betaler for en kode, der gives videre, og vinderens egen ordre er den, der sendes.
- Navnet i annonceringen er alerts' valgfrie brugernavn-felt. Uden navn er gaven anonym ("En anonym seer").
- Køberen får e-mails i WooCommerces design, når vinderen er trukket, når gaven er hentet, og (efter tre lodtrækninger uden afhentning) med gavekoden, når gaven går tilbage til køberen.

**Chatten:**
- Når gave-ordren er betalt, og creatoren er live, annoncerer botten gaven og åbner `!enter`-vinduet (20–180 sekunder, standard 60). Kun én gave ad gangen; den næste venter. Flere gaver fra samme køb trækkes én ad gangen i rækkefølge, og annonceringen siger "(gave 2 af 3)".
- Botten bekræfter deltagerne: den første med det samme, derefter nye deltagere og dem, der skriver `!enter` igen ("Allerede med"), i én besked højst hvert 10. sekund. Creatoren kan slå det fra.
- Når vinduet lukker, skriver botten "Tilmeldingen er lukket! Vinderen af … findes om 10 sekunder …". 10 sekunder senere vinder en tilfældig deltager (`random_int`, hver Twitch-konto én gang, køberen kan også vinde). Botten skriver vinderen og linket til at hente gaven.
- Ingen deltagere: gaven venter. Den åbnes ikke igen af sig selv, men ved næste `!gift start`.
- Creatoren og moderatorer kan skrive `!gift start` (start ventende gaver), `!gift pause` og `!gift resume`. Under pause kan der ikke købes gaver, og der startes ingen lodtrækning.
- Teksterne er faste, på dansk eller engelsk efter creatorens valg.

**Vinderen:**
- Linket åbner en gaveside med præmien, vinderen, fristen og knappen "Log ind med Twitch". Knappen kræver Twitch-login (kundens login fra fase 43) og henter gaven lige efter login. Kun den vindende Twitch-konto får koden; er man logget ind med en anden konto, tilbyder siden at logge ud.
- Koden er en engangskupon, der gælder gave-produktet (alle varianter): ét stk. op til den betalte pris plus op til 50 kr. af fragten. En dyrere variant eller fragt betaler vinderen selv.
- Koden lægges automatisk på, når produktet kommer i kurven. Åbner vinderen linket igen, vises samme kode.
- Fristen er 24 timer. Derefter venter gaven på en ny lodtrækning (højst 3). Efter tredje gang går koden til køberen på e-mail.

**Creatoren** (Community Features → Twitch gifting):
- slå gifting til og fra (kræver forbundet Twitch-kanal), vinduets længde, sproget og "Bekræft deltagere i chatten";
- se om man er live, sæt på pause eller genoptag (chatten får besked), "Start ventende gaver";
- listen over de seneste 50 gaver: købt, produkt, fra (navn eller anonym), status, vinder og antal lodtrækninger;
- klokken: en gave er købt (én gang pr. køb, med antallet, fx "Mads købte 3 × Hoodie som gaver i din stream"), en gave er hentet.

## Admin functionality
- Ordresiden for en gave-ordre viser hver gaves status, vinder og antal lodtrækninger ("Gave 2 af 3"). Når der er trukket en vinder, advarer den mod refundering: en refundering ændrer ikke gaver med vinder. Annullering eller refundering af hele ordren annullerer alle gaver uden vinder; en delvis refundering ændrer intet.
- Ordrenoter for hvert trin (aldrig koden).
- E-mail "Twitch-gave givet tilbage til køberen" (Notifikationer → Til administratoren).
- **Påmindelser i chatten:** botten minder chatten om `!enter`, når halvdelen og en fjerdedel af vinduet er tilbage, og 10 sekunder før tilmeldingen lukker; over 75 sekunder også ved tre fjerdedele. Et "ur" (`GiftTicker`, `POST /merchboard/v1/gifts/{id}/tick` med HMAC-token) holder påmindelser, lukning og vinder til tiden, også i en stille chat; jobbet `merchboard_gift_remind` og chatbeskeder er reserve. Gifting-siden viser, at vinduet kan være 20–180 sekunder. Gifting-siden siger det, og at botten skal være moderator eller undtaget, hvis kanalen fjerner links i chatten.
- **Global knap** under Indstillinger → Community (fase 45, ADR-108), slået fra som standard. Slået fra er en pause: siden, REST-ruterne og gaveknappen forsvinder, og der åbnes ingen nye vinduer (heller ikke med `!gift start`). Et åbent vindue trækkes færdigt, vindere kan hente gaven, og ventende gaver venter.
- **Testtilstand** på creatorens redigeringsside (Creators → Rediger, kræver `merchboard_manage_creators`): creatoren regnes som live, så gaver kan købes og trækkes uden en stream, fx på stg. Siden advarer, mens den er slået til, og creatorens dashboard viser "Testtilstand: du regnes som live." Ændringen auditeres.

## Technical implementation
- `GiftPurchase`: gave-boksen (skabelonen `templates/community/gift-box.php`, GET-formular med nonce og `merchboard_gift_quantity`), gave-kurven (`merchboard_gift` på kurv-linjen, kun gaven, 1–10 stk., `GiftPurchase::MAX_GIFTS`), fragten (`woocommerce_package_rates` → én sats `merchboard_gift` på 50 kr. × antal), linje-meta `_merchboard_gift` (creatoren) og gaverne oprettet ved `processing`/`completed` (én pr. enhed med `order_unit`, `INSERT IGNORE`, så en ny statusændring ikke laver flere). `cancelled`/`refunded` annullerer alle gaver i ordren uden vinder.
- `GiftDraws`: lodtrækningerne. Chatbeskeder kommer fra webhooken som action `merchboard_twitch_chat_message`. Jobs via Action Scheduler (`merchboard_gift_draw`, `merchboard_gift_expire`) og som fallback ved hver chatbesked. Hver statusændring er en betinget `UPDATE` i `GiftRepository`, så job, webhook og klik aldrig flytter en gave to gange. Deltagere tilføjes under rækkelås.
- `GiftCodes`: kupontypen `merchboard_twitch_gift` (kupon-meta `_merchboard_gift_id`), rabat pr. linje og et negativt gebyr for fragten. Koden gælder kun, mens gaven er `claimed` eller `returned` med samme kupon.
- `GiftClaim`: gavesiden `?merchboard_gift_claim=<token>` (`template_redirect`, skabelon `templates/community/gift-claim.php`) og knappen `&merchboard_gift_redeem=1`, Twitch-login via `ShopTwitchController::link( 'login', 'redeem:<token>' )`.
- Bekræftelser og ventetid: `GiftDraws::confirm()` under `GiftRepository::lock()` (`GET_LOCK`), job `merchboard_gift_confirm`; `GiftDraws::closing()` sender "Tilmeldingen er lukket" og planlægger vinderen 10 sekunder senere.
- `GiftNotices`: lytter på `merchboard_gift_event` (`bought`, `won`, `claimed`, `returned`) og sender notifikationer, køber-mails og ordrenoter. `bought` sendes kun for gave 1 af ordren, med `count`; mails og noter for flere gaver nævner "gave n af N" (`GiftTexts::unit()`).
- `TwitchLiveStatus` (Alerts): `GET helix/streams`, svaret gemmes i 60 sekunder. `CommunityModule::live()` svarer "live" uden at spørge Twitch, når creatorens testtilstand er slået til (kun med forbundet kanal).
- `Admin/GiftingTestModeSection`: testtilstanden på creatorens redigeringsside (`merchboard_creators_edit_sections`, admin-post `merchboard_save_gifting_test_mode`, nonce pr. creator).
- Chatbeskeder sendes med `ChatSender::send_now()`, også når creatorens ordrebeskeder er slået fra.

## Relevant files
- `src/Modules/Community/Gifting/` (`Gift`, `GiftRepository`, `GiftingSettings`, `GiftingSettingsRepository`, `GiftPurchase`, `GiftDraws`, `GiftTexts`, `GiftCodes`, `GiftClaim`, `GiftNotices`, `GiftOrderInfo`, `GiftPrivacy`, `GiftCreatorCleanup`)
- `src/Modules/Community/Rest/MeTwitchGiftingController.php`, `src/Modules/Community/Admin/GiftingTestModeSection.php`, `src/Modules/Community/CommunityModule.php`
- `src/Modules/Alerts/Chatbot/TwitchLiveStatus.php`, `TwitchCustomerLogin.php`, `src/Modules/Alerts/Rest/ShopTwitchController.php`, `TwitchController.php` (chat-action)
- `src/Core/Database/Migrations/CreateGiftsTable.php`, `AddGiftOrderUnits.php` (migration 52)
- `templates/community/gift-box.php`
- `assets/src/dashboard/api/gifting.ts`, `sections/community/TwitchGiftingSection.tsx`
- `tests/Integration/Community/TwitchGiftingTest.php`, `tests/Unit/Modules/Community/GiftingTest.php`, `assets/src/dashboard/test/twitch-gifting.test.tsx`

## Database/data used
- Tabellen `merchboard_gifts` (schema-version 50; `order_unit` fra 52), se [DATABASE.md](../DATABASE.md#twitch-gaver-schema-version-50-adr-107).
- Creator-meta `twitch_gifting` (`enabled`, `paused`, `window`, `language`, `test_mode`, `confirm`).
- Linje-meta `_merchboard_gift` på gave-ordren, kupon-meta `_merchboard_gift_id` på koden.
- Transienter `merchboard_twitch_live_<md5>` (60 sekunder), `merchboard_gift_run_<creator>` og `merchboard_gift_tried_<creator>` (6 timer), `merchboard_gift_confirm_<gave>` og `merchboard_gift_reveal_<gave>` (1 time).

## Permissions
- Dashboardet: creator med dashboard-adgang via `CreatorContext` (ADR-007). Skrivninger er blokeret under "Se som creator" (`ImpersonationGuard`), fordi de taler i creatorens chat.
- Chatten: `!gift`-kommandoer kun fra badges `broadcaster` og `moderator` i den signerede webhook.
- Indløsning: claim-tokenet (32 hex, `random_bytes`) plus Twitch-login med state og cookie (ADR-106). Kun vinderens Twitch-id får koden.

## API endpoints
- `GET`/`POST /merchboard/v1/me/community/twitch-gifting` (alle ruterne svarer 404 `merchboard_community_disabled`, når gifting er slået fra for butikken)
- `POST /merchboard/v1/me/community/twitch-gifting/pause` (`{paused}`)
- `POST /merchboard/v1/me/community/twitch-gifting/start`
- `GET /merchboard/v1/shop/twitch/login?target=claim:<token>` (kundens login, fase 43)

## Hooks
- Lytter: `merchboard_twitch_chat_message`, `merchboard_gift_draw`, `merchboard_gift_expire`, `woocommerce_order_status_processing|completed|cancelled|refunded`, kurv-, kupon- og fragt-filtre.
- Udløser: `merchboard_gift_event` (event, Gift, data) og `merchboard_notify` (`gift_bought`, `gift_claimed`, `admin_gift_returned`).

## Dependencies
WooCommerce, Alerts-modulet (Twitch-app, chatbot og webhook) og kundens Twitch-login (fase 43). Action Scheduler (WooCommerce) til jobs.

## Tests
- Integration: flere gaver i ét køb (én række pr. enhed, idempotent, trukket én ad gangen med "gave 1 af 3", "købt" én gang med antal, annullering kun uden vinder), indstillinger og audit, bekræftelser i chatten og ventetiden før vinderen, gavesiden og knappen (login, anden konto, vinderen, igen), testtilstanden (admin-formularen, audit, creatorens formular bevarer den), én gave pr. ordre, annoncering og lodtrækning, ingen deltagere uden spam, gaver betalt offline og `!gift start`, pause, ikke hentet, refundering kun uden vinder, privacy og creator-sletning.
- Unit: indstillinger, tekster og gave-rækken.
- Jest: parseren og siden (gem, pause, start, feltfejl, testtilstand).
- Impersonation: skrivningerne er blokeret.
- Kupon, kurv og claim-flow testes runtime på stg (WooCommerce er ikke indlæst i testmiljøet).

## Known limitations
- Kun Twitch.
- Lodtrækningen kræver chatbottens webhook (HTTPS).
- Gave-kurven tillader ikke andre varer samtidig.

## Future improvements
- Ingen planlagte.
