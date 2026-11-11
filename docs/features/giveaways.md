# Feature: Giveaways

## Purpose
Creatoren kan give et af sine egne produkter væk til en seer. Vinderen findes enten af vores bot i Twitch-chatten eller af creatorens eget giveaway-system, hvor creatoren selv giver et link videre. Creatoren betaler kun, hvad varen og fragten koster virksomheden ([ADR-110](../DECISIONS.md), ROADMAP fase 47, Community Features pkt. 1).

En giveaway er creatorens egen gave. Den må aldrig forveksles med Twitch gifting ([twitch-gifting.md](twitch-gifting.md)), hvor seerne køber gaver til chatten.

## Current status
Bygget i fase 47 (47.2–47.8, `fa199ba`) og testet automatisk. Mangler: CI med integrationstests, runtime-test og testpakke 2.1.0 på stg (47.9). Udgives i 2.1.0.

## User-facing functionality
**Creatoren (MitCCMerch → Community Features → Giveaways):**
- Vælger et af sine produkter i shoppen. Listen viser prisen: grundprisen for den billigste variant plus 50 kr. i fragt.
- Vælger, hvordan vinderen findes:
  - **Mit eget giveaway-system:** creatoren får et link med kopiknap og giver det selv videre.
  - **Vores bot i Twitch-chatten:** creatoren starter giveawayen med "Start i chatten", mens creatoren er live, og vælger, hvor mange sekunder chatten kan skrive `!enter` (20–180).
- Vælger betaling:
  - **Fra saldoen, når koden bruges:** kun når den tilgængelige saldo dækker prisen. Prisen reserveres med det samme og vises under Udbetalinger som "Reserveret til giveaways". Den trækkes først, når vinderen bruger koden; bruges koden ikke, trækkes intet.
  - **Betal nu i shoppen:** creatoren sendes til kassen, hvor kun grundprisen og 50 kr. i fragt betales. Giveawayen er klar, når ordren er betalt.
- Listen over giveaways viser produkt, tilstand, pris, betaling og status, linket med kopiknap og knapperne "Betal i shoppen", "Start i chatten" og "Annullér".
- Annullering: en giveaway, der venter på betaling, eller en fra saldoen, hvis gave venter på en lodtrækning eller hvis link ingen har indløst. Reservationen frigives. En giveaway, der er betalt i shoppen, kan kun annulleres ved at annullere ordren.
- Klokken (og e-mail): linket er klar efter tre lodtrækninger uden afhentning, koden er brugt, og giveawayen er udløbet uden at være brugt.
- Højst 20 åbne giveaways ad gangen.

**Seeren med linket (eget giveaway-system):**
- Linket åbner gavesiden med produktet og knappen "Indløs" – uden Twitch-login. Den, der har linket, får gavekoden og vælger størrelse og farve i shoppen.
- Linket kan indløses i 30 dage. Den indløste kode gælder i 30 dage, én gang, kun for produktet. Den dækker den billigste variant og op til 50 kr. af fragten; en dyrere variant eller fragt betaler vinderen selv.

**Chatten (bot-tilstand):**
- Samme forløb som Twitch gifting: "🎁 Nova giver Stream tee væk! Skriv !enter inden for 60 sekunder …", bekræftelser, påmindelser, "Tilmeldingen er lukket" og vinderen 10 sekunder senere med et link.
- Vinderen henter gaven med Twitch-login (kun den vindende konto).
- Ingen deltagere: giveawayen venter og kan startes igen. Hentes gaven ikke inden 24 timer, venter den på en ny lodtrækning, højst 3 gange. Derefter får creatoren et link (som i eget system).
- `!gift start` og den automatiske start i Twitch gifting starter aldrig en giveaway; kun "Start i chatten".

## Admin functionality
- Indstillinger → Community: knappen "Giveaways", slået fra som standard. Slået fra forsvinder siden og REST-ruterne (404), og der kan ikke oprettes eller startes giveaways. Links og koder, der er givet videre, virker videre.
- Ordresiden: en giveaway-ordre har boksen "Creator-giveaway" med status og en note om, at den ikke er en Twitch-gave fra en seer.
- Udbetalinger: kolonnen "Reserveret" medregner reserverede giveaways. Trækket står på afregningsbilaget med referencen `GIV-000123`.
- Audit: `giveaway_created`, `giveaway_charged` og `giveaway_cancelled`.

## Data model
- `merchboard_giveaways` (migration 51): creator, produkt, tilstand (`bot`/`manual`), betaling (`balance`/`shop`), status (`awaiting_payment`, `ready`, `used`, `expired`, `cancelled`), dækket pris, fragtkredit, creatorens pris, valuta, `!enter`-vindue, butiksordre, gave og træk.
- Gaven (lodtrækning, link og kode) er en række i `merchboard_gifts` med `giveaway_id` og uden ordre.
- Trækket er en negativ række i `merchboard_payout_adjustments` med reglen `adr110-giveaway` og `giveaway_id` (unik).
- Butiksordren: ordre-meta `_merchboard_giveaway` og linje-meta `_merchboard_giveaway` (giveawayens id) og `_merchboard_sub_discount` (creatorens fortjeneste, som creatoren selv betaler).
- Se [DATABASE.md](../DATABASE.md).

## API endpoints
- `GET /merchboard/v1/me/community/giveaways`: giveaways, produkter med pris, tilgængelig saldo, grænser, Twitch-kanal og live-status.
- `POST /merchboard/v1/me/community/giveaways`: `product_id`, `mode`, `funding`, `window`.
- `POST /merchboard/v1/me/community/giveaways/{id}/start` og `/{id}/cancel`.
- Se [API.md](../API.md).

## Permissions
- Kun creatoren selv via `CreatorContext` (ADR-007). Under "Se som creator" (ADR-075) afvises oprettelse, start og annullering, og "Betal i shoppen" åbner ikke kassen.
- Kun creatorens egne produkter. Betaling i shoppen kræver creatorens egen bruger og en nonce bundet til giveawayen.

## Security considerations
- Reservation og træk sker under creatorens payout-lås, så de ikke kan kollidere med en udbetaling. En giveaway trækkes højst én gang (`UNIQUE(giveaway_id)`).
- Statusændringer er betingede, så to requests aldrig flytter en giveaway to gange.
- Linket er et tilfældigt token (32 hex-tegn). Koden kan bruges én gang og kun til produktet.
- Trækket sker i et job, aldrig midt i en WooCommerce-ordregemning (ADR-086).

## Edge cases
- En kode, der bruges i en ordre, som først betales efter udløbet, trækkes alligevel; saldoen kan da gå i minus og modregnes som andre udestående beløb.
- En butiksordre, der annulleres eller refunderes, efter vinderen har fået gaven, ændrer ikke gaven.
- En slettet creator: giveaways, ingen kan få endnu, annulleres; færdige slettes. En kode, der er givet videre, virker videre.

## Tests
- Integration: `tests/Integration/Community/GiveawaysTest.php` (reservation og træk, udløb, bot-forløb, annullering, betaling i shoppen, slået fra).
- Unit: faste lister (tabeller, audit, hooks, skemaversion) og `WooCommercePaidOrderReaderTest` (ingen alert for en giveaway).
- Jest: `assets/src/dashboard/test/giveaways.test.tsx`.

## Open questions
Ingen.
