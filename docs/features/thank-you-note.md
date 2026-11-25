# Feature: Takkebesked på følgesedlen

## Purpose
Creatoren kan skrive en kort hilsen til sine kunder. Hilsenen kommer på følgesedlen i pakken, når kunden har købt creatorens produkter.

Merch Dashboard laver ikke selv følgesedler. Projektejerens eget følgeseddel-plugin henter hilsnerne gennem et offentligt filter (ADR-123).

## Current status
Bygget i fase 58 (version 2.6.0).

## User-facing functionality
Kortet "Takkebesked på følgesedlen" ligger i Profil under rabatkoden:
- creatoren slår beskeden til og fra og skriver teksten;
- teksten er højst 300 tegn og 6 linjer;
- en tæller viser, hvor mange tegn der er brugt;
- teksten er ren tekst med linjeskift. HTML fjernes, og links afvises, fordi beskeden trykkes på papir;
- beskeden bruges, som den er, uden godkendelse. Der er én besked pr. creator, uanset produkt;
- slås beskeden fra, gemmes teksten til senere, men den trykkes ikke.

Kortet vises ikke på et site uden WooCommerce.

## Admin functionality
Ingen formular. Audit-loggen viser "Thank-you note changed" med de ændrede felter. Teksten selv logges aldrig.

## Technical implementation
- `ThankYouNoteSettings` holder til/fra og teksten. `printed()` giver den trykte tekst og er tom, når beskeden er slået fra.
- `ThankYouNoteRepository` validerer og gemmer i creator-meta `thank_you_note`, med audit `thank_you_note_changed`.
- `MeThankYouNoteController` giver `GET`/`POST /me/thank-you-note`.
- `PackingSlipNotes` lægger filteret `merchboard_packing_slip_notes` på (prioritet 10, to argumenter):
  - ordrelinjernes produkter slås op i `ProductCreatorLink::creator_id()`;
  - hver aktiv creator kommer med én gang, i linjernes rækkefølge;
  - samlingsprodukter, inaktive og slettede creators og beskeder, der er slået fra eller tomme, springes over;
  - en fejl giver ingen beskeder i stedet for en fejl på følgesedlen.
- Alt registreres i `CommunityModule`, som kun indlæses med WooCommerce.

### Brug i følgeseddel-pluginet

```php
$order = wc_get_order( $order_id );
$notes = apply_filters( 'merchboard_packing_slip_notes', array(), $order );

foreach ( $notes as $note ) {
	// $note['creator_id'] (int), $note['name'] (string), $note['text'] (string med \n).
	echo '<div class="thank-you-note">';
	echo '<strong>' . esc_html( $note['name'] ) . '</strong>';
	echo wp_kses_post( wpautop( esc_html( $note['text'] ) ) );
	echo '</div>';
}
```

- Filteret tager en `WC_Order` eller et ordre-id.
- Uden Merch Dashboard returnerer `apply_filters` bare det tomme array, så pluginet virker også uden.
- Teksten er ren tekst og skal escapes ved udskrift.
- Andre plugins kan lægge egne beskeder i samme liste; Merch Dashboard tilføjer sine til sidst.

### Hurtig test uden følgeseddel-pluginet
I WP-CLI på stg:

```bash
wp eval 'print_r( apply_filters( "merchboard_packing_slip_notes", array(), <ordre-id> ) );'
```

## Relevant files
- `src/Modules/Community/ThankYouNote/`
- `src/Modules/Community/Rest/MeThankYouNoteController.php`
- `assets/src/dashboard/api/thankYouNote.ts`
- `assets/src/dashboard/sections/profile/ThankYouNoteCard.tsx`
- `tests/Unit/Modules/Community/PackingSlipNotesTest.php`
- `tests/Integration/Community/ThankYouNoteTest.php`
- `assets/src/dashboard/test/thank-you-note.test.tsx`

## Database/data used
Creator-meta `thank_you_note` (`{enabled, text}`), se [DATABASE.md](../DATABASE.md). Nøglen slettes med creatorens øvrige meta.

## Permissions
- Ruten kræver en aktiv creator via `CreatorContext` og er `/me`-scopet (ADR-007).
- Filteret giver kun navn og tekst, som creatoren selv har valgt at vise kunderne.

## API endpoints
`GET`/`POST /merchboard/v1/me/thank-you-note`, se [API.md](../API.md).

## Hooks
`merchboard_packing_slip_notes` (filter), se [ARCHITECTURE.md](../ARCHITECTURE.md#12-hooks-udvidelsespunkter).

## Dependencies
WooCommerce (ordrer og produktkoblingen til creatorer).

## Tests
- Unit: filteret med flere creators, dubletter, en inaktiv og en slettet creator, en besked der er slået fra, et samlingsprodukt, et ordre-id og en fejl.
- Integration: REST, validering, audit uden tekst, og sletning med creatorens meta.
- Jest: kortet gemmer, viser fejl og skjules uden butik.

## Known limitations
- Beskeden følger produktets nuværende creator, ikke creatoren på købstidspunktet.
- Merch Dashboard ved ikke, om følgeseddel-pluginet faktisk har trykt beskeden.

## Future improvements
- En forhåndsvisning af følgesedlen i dashboardet.
