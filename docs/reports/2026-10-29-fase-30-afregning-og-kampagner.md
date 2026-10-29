# Fase 30 – Afregning og kampagner (trin 30.3–30.4)

**Dato:** 2026-10-29.

**Status:** implementeret og testet automatisk (eksperimentelle commits). Intet er runtime-testet, fordi der endnu ikke findes designer-produkter i butikken; det sker i 30.15 på Local med "Test Creator Cailean".

| Trin | Commit | Tests |
| --- | --- | --- |
| 30.3 Afregning med sikret grundpris | `335af63` | 535 berørte integrationstests grønne; CI grøn |
| 30.4 Kampagner | `3129633` | 472 berørte integrationstests grønne; lint og `npm test` grønne (2231 PHPUnit, 569 Jest); CI grøn |

## 1. Hvad der er lavet

**Afregning (30.3, ADR-080 pkt. 8):**
- Uden for virksomhedens egne kampagner får virksomheden altid grundprisen. En rabat tages fra creatorens fortjeneste og bliver aldrig under 0.
- Creatorens egen rabatkode giver højst fortjenesten i rabat.
- Fortjeneste og udbetaling beregnes én gang, når salget registreres, og gemmes ved salgsrækken (schema-version 36). Saldo, statistik og afregningsbilag bruger de gemte beløb.
- Legacy-produkter afregnes præcis som før.

**Kampagner (30.4, ADR-080 pkt. 9):**
- Ny admin-side **Merch Dashboard → Kampagner** med navn, start og slut (sitets tidszone), kuponkoder og "omfatter tilbudspriser". Hver ændring står i audit-loggen.
- En ordrelinje er i en kampagne, når kampagnen kørte, da linjen blev købt, og
  - ordren brugte en af kampagnens kuponkoder, eller
  - kampagnen omfatter tilbudspriser, og linjen blev solgt under sin pris før kuponer.
- I en kampagne deles rabatten forholdsmæssigt med creatoren (eksempel: grundpris 150, fortjeneste 50, betalt 180 → creatoren får 45).
- Afgørelsen tages én gang pr. ordrelinje. En senere refundering trækker præcis det samme tilbage, også selvom kampagnen er ændret i mellemtiden.
- Sikkerhed:
  - en creators egen rabatkode afvises som kampagnekode, fordi den ellers kunne bringe virksomheden under grundprisen;
  - en kampagne, der dækker registrerede salg, kan ikke slettes, kun afsluttes ved at flytte slutdatoen;
  - er ordrens kupondata ukendt, skrives intet; der gættes aldrig.

## 2. Fejl fundet og rettet undervejs

- **Pris-kilden blev aldrig brugt i butikken (fra 30.3, rettet i 30.4):** WooCommerce-modulet opretter sine hooks, før ProductDesigner-modulet registrerer designerens pris-kilde. Designer-produkter ville derfor være blevet afregnet efter den gamle andelsmodel. Det har ikke ramt noget, da der ingen designer-produkter findes. Kilderne slås nu op, når en ordre kommer (`ContainerDesignerSources`), og en unit-test låser det.
- **Falske testfejl i første kørsel af 30.3:** skyldtes, at kode blev ændret, mens integrationstestene kørte. Koden var i orden; en ny kørsel med samme rækkefølge var grøn.

## 3. Punkter til opfølgning (projektejeren)

Valg, jeg har truffet ud fra ADR-080, og som kan rettes, hvis du ønsker det anderledes:

1. **Tilbudspris:** en linje regnes som solgt til tilbudspris, når prisen før kuponer er mindst 0,01 kr. under listeprisen. Det omfatter også en pris, som en administrator har rettet manuelt på ordren (også virksomhedens egen rabat).
2. **Flere kampagner på samme tid:** den ældste kampagne (laveste id) vinder.
3. **Refundering kun af et beløb** (uden antal): virksomheden bærer den, og creatorens fortjeneste ændres ikke.
4. **Salg i en anden valuta** end grundprisens: fortjenesten er ukendt (blokerer udbetaling af den valuta), aldrig omregnet og aldrig efter andelsmodellen.
5. **Kampagnens tidspunkt** er købstidspunktet (ordrelinjen oprettes), ikke betalingstidspunktet.

## 4. Næste

- 30.5 Legacy-værn og writer: markør, `DesignerProductGuard`, `WooCommerceDesignerWriter` og arkitekturtest.
- Runtime-test af afregning og kampagner i 30.15.
