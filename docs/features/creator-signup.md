# Feature: Creators opretter sig selv, aftalepapir og "gå live"

## Purpose
Creators skal selv kunne oprette sig, acceptere aftalepapiret og forberede deres shop uden at vente på admin. Butikken viser dem først, når en administrator har godkendt, at de går live (projektejerens ønske 2026-11-06).

## Current status
I gang (fase 32, ADR-091–094). 32.2 (live-status og spærringer) og 32.3 (aftalepapiret: Indstillinger → Aftale, `/me/agreement`, boksen øverst i dashboardet og spærringen af udbetalinger) er implementeret 2026-11-06; 32.4–32.6 mangler.

## User-facing functionality
- **Oprettelse** på `/<dashboard-slug>/opret`:
  - creatornavn, e-mail, kanal-link, "Jeg er fyldt 18 år" og aftalepapiret med accept;
  - mailen "Bekræft e-mail og vælg adgangskode";
  - efter den første adgangskode åbner MitCCMerch.
- **Før go-live:** profil, kategoriforslag, designs og alerts virker, men intet vises i butikken. Oplæsning åbner først efter go-live. Et godkendt design står som "Godkendt – kommer i butikken, når du er live".
- **"Anmod om at gå live"**: kræver accept af den gældende aftale. Status: venter, godkendt, eller afvist med note og mulighed for at anmode igen.
- **Ny væsentlig aftaleversion:** creatoren accepterer igen, før der kan anmodes om go-live eller udbetaling.

## Admin functionality
- **Indstillinger:**
  - "Creators kan oprette sig selv" (fra som standard);
  - "Overkategori for creators";
  - fanen "Aftale" med editor, udgivelse af versioner (væsentlig eller ikke) og antal accepter pr. version.
- **Forslag → Go-live:** creator, kanal-link, accepteret version, ventende designs og kategoriforslag.
  - **Godkend** med tillæg og kategorinavn. Kategorien oprettes, og designs kommer i butikken.
  - **Afvis** med note.
- **Notifikationer:** "Ny creator har oprettet sig" og "Anmodning om at gå live".
- **Grundprodukter:** en prisstigning kræver en dato mindst 14 dage frem (ADR-094). Den planlagte ændring vises og kan annulleres.

## Technical implementation
Se ADR-091–094. Hovedprincippet er forhindring frem for filtrering: en creator, der ikke er live, når aldrig butikken. Konkret afviser følgende:
- kategori-mapping;
- produktkobling;
- rabatkode;
- designer-anvendelse;
- godkendelse af kategoriforslag;
- oplæsning.

## Database/data used
Migration 45 (planlagt):
- `merchboard_creators.awaiting_live` og `agreement_required` (begge 0 som standard);
- tabellerne `merchboard_agreement_versions`, `merchboard_agreement_acceptances`, `merchboard_creator_signups` og `merchboard_go_live_requests`.

## Permissions
- **Oprettelsessiden** er offentlig, med nonce, honeypot og rate limit.
- **Rollen:** pluginet giver kun `merchboard_creator`, og først når e-mailen er bekræftet.
- **Admin:** `merchboard_manage_creators` til go-live og aftaleteksten.
- **"Se som creator"** kan ikke acceptere.

## Known limitations
- Aftalepapiret er et udkast og ikke juridisk gennemgået (ADR-061, ADR-092). Projektejeren godkender teksten, før funktionen slås til.
- Ingen CAPTCHA (ekstern tjeneste, ADR-016).

## Aftalepapir: udkast (version 1)
> **Udkast, ikke juridisk gennemgået.**
> - Bygger på den hidtidige samarbejdsaftale (projektejeren 2026-11-06) og ADR-061 pkt. 3.
> - [adresse] og [CVR-nr.] udfyldes af admin i editoren; virksomhedens oplysninger står ikke i repoet.
> - Punkter markeret ⚖ bør især gennemgås af en advokat.

**Samarbejdsaftale for creators hos CCMerch**

**1. Parter**
Aftalen indgås mellem dig som creator, med det navn og den e-mail, du opretter dig med på MitCCMerch, og CCMerch, [adresse], CVR-nr. [CVR-nr.] ("vi").

**2. Formål**
2.1 Du kan få produceret og solgt merchandise i dit navn i vores webshop.
2.2 Du henviser dine følgere til din side i webshoppen.
2.3 Vi står for produktion, salg, forsendelse og kundeservice.

**3. Oprettelse og go-live**
3.1 Du skal være fyldt 18 år.
3.2 Du kan forberede din shop på MitCCMerch, men intet vises i webshoppen, før vi har godkendt din anmodning om at gå live. Afviser vi, får du en begrundelse.

**4. Designs og rettigheder** ⚖
4.1 Alle designs godkendes af os, før de kommer i webshoppen. Vi kan afvise eller fjerne et design, fx hvis det kan krænke andres rettigheder, er stødende eller ikke kan produceres.
4.2 Du bekræfter, at du har rettighederne til alt, du uploader (billeder, logoer og tekster), eller tilladelse fra den, der har dem.
4.3 Du giver os ret til at bruge dine designs til at producere, vise og markedsføre dine produkter, så længe aftalen gælder, og til at gøre ordrer færdige.
4.4 Krænker et design, du har uploadet, andres rettigheder, holder du os skadesløs for krav fra tredjemand.
4.5 Vi har ansvaret for produkterne, leveringen og kundeservicen. Vi holder dig fri for krav fra kunder og myndigheder om vores produkter og ydelser.

**5. Din fortjeneste**
5.1 Din fortjeneste er et tillæg til vores pris på produktet. Tillægget er en procentsats eller et beløb, som vi aftaler med dig, eller som du selv sætter i produktdesigneren.
5.2 Er du ikke momsregistreret, trækkes 20 % af fortjenesten, og resten udbetales. Er du momsregistreret, udbetales hele fortjenesten. ⚖
5.3 Rabatter på dine produkter regnes af din fortjeneste, som aldrig bliver mindre end 0 kr. Ved kampagner, vi selv kører, deles rabatten mellem os og dig i forhold til prisen.
5.4 Nogle ydelser koster et gebyr, fx at gøre et motiv klar til broderi. Gebyret vises, før du sender designet, og trækkes fra din fortjeneste.
5.5 Du optjener fortjeneste af betalte salg. Refunderes et salg, trækkes fortjenesten tilbage.
5.6 Aftalen omfatter kun fortjeneste af produktsalg. Bonusser, sponsorater og lignende aftales særskilt.

**6. Priser**
6.1 Vores priser kan ses på MitCCMerch.
6.2 Stiger vores priser, giver vi skriftlig besked mindst 14 dage før. Et prisfald kan gælde med det samme.

**7. Udbetaling og afregning** ⚖
7.1 Du anmoder selv om udbetaling af din saldo på MitCCMerch på de måder, vi tilbyder der.
7.2 Du accepterer, at vi udsteder afregningsbilaget for dine salg på dine vegne (selvfakturering). Bilaget kan ses på MitCCMerch, når udbetalingen er gennemført. Er der fejl i et bilag, skal du give os besked inden 14 dage.
7.3 Du giver os korrekte oplysninger om dig og din momsregistrering og fortæller os, hvis de ændrer sig.

**8. Samarbejde og kontakt**
8.1 Vi behandler kunder og samarbejdspartnere professionelt og venligt.
8.2 Vi svarer normalt på henvendelser inden for 1–2 hverdage.

**9. Varighed og ophør**
9.1 Aftalen gælder fra din accept, indtil den opsiges.
9.2 Begge parter kan opsige aftalen skriftligt til udgangen af en måned. Efter gensidig aftale kan den ophøre med det samme.
9.3 Vi kan ophæve aftalen med det samme, hvis du groft overtræder den, fx ved at uploade designs, der krænker andres rettigheder. ⚖
9.4 Ved ophør tages dine produkter ud af webshoppen, og din optjente fortjeneste gøres op og udbetales (slutafregning).

**10. Genforhandling og ændringer**
10.1 Du kan til enhver tid bede om at genforhandle din fortjeneste, dog ikke de første 6 måneder. Undtaget er tilfælde, hvor produktets egenskaber, type eller prisudvikling gør det rimeligt.
10.2 Væsentlige ændringer af aftalen skal du acceptere igen på MitCCMerch. Om mindre ændringer giver vi dig skriftlig besked.

**11. Fortrolighed**
11.1 Din fortjeneste og dine aftalte priser er fortrolige. Ingen af os må videregive dem uden den andens samtykke, heller ikke efter aftalens ophør.

**12. Persondata**
12.1 Vi behandler dine oplysninger efter vores privatlivspolitik. ⚖ Link indsættes af admin.

**13. Lovvalg**
13.1 Aftalen er underlagt dansk ret.

*Ved at sætte kryds bekræfter du, at du er fyldt 18 år, og accepterer aftalen. Vi gemmer, hvilken version du har accepteret, og hvornår.*

### Hvad er ændret i forhold til den hidtidige kontrakt
- **Underskrifter** er erstattet af en digital accept.
- **Afkrydsning af ydelser (2.2) og fast ugentlig eller månedlig udbetaling (4.3)** er erstattet af standardydelser og udbetaling efter anmodning.
- **"Én faktura per ordre" (4.2)** er erstattet af selvfakturering med afregningsbilag (ADR-060 og ADR-061).
- **Svartid** er ændret fra "samme dag kl. 8–17" til "normalt 1–2 hverdage" (projektejerens anbefalede rettelse).
- **Fortrolighed** gælder nu kun egne priser og fortjeneste.
- **Prisdokumentet (3.4)** er erstattet af priserne på MitCCMerch.
- **Særlige forhold (9.1)** udgår.
- **Nye punkter:**
  - rettigheder til designs;
  - rabatter og gebyrer;
  - alder;
  - go-live;
  - ophævelse ved grov overtrædelse;
  - persondata.
