# Feature: Notifikationer

## Purpose
Creators og administratorer får besked, når noget sker, så de ikke selv skal holde øje med dashboardet og køerne. Beskeden kommer som e-mail og, for creators, ved et ikon (klokken) øverst i dashboardet ([ADR-079](../DECISIONS.md), ROADMAP fase 29).

## Current status
I gang (fase 29, version 1.4.0; plan godkendt 2026-10-27). 29.2 (kernen: katalog, lager, valg, afsendelse og mailskabelonen) 29.3 (admin-siden med til/fra, eksempler, testmail og log) og 29.4 (klokken og creatorens e-mailvalg under Profil) 29.5 (creator-hændelserne) og 29.6 (alerts, administrator-mails og det daglige saldo-job) er implementeret og testet automatisk. Mangler: review, runtime-test og release (29.7).

## User-facing functionality
- **Klokken:** et lille ikon i dashboardets topbar med en rød boble med antal ulæste (9+ over 9).
  - Et klik åbner et panel med notifikationerne som korte tekstlinjer: nyeste først, tidspunkt og et link til den relevante side. Her er "Markér alle som læst" og "Vis flere".
  - At åbne panelet markerer de viste som læst.
  - Antallet hentes, når dashboardet åbner, og igen hvert minut, mens fanen er synlig.
  - Ikke en popup som Nyheder, og ikke mailens layout.
- **E-mail** i butikkens design (som projektejerens nye WooCommerce-mails): butiksnavn, et kort med farvet topkant, overskrift, hilsen, tekst, en infoboks eller tabel, en knap til dashboardet og en sidefod med butikkens navn og adresse.
- **Valg:** under Profil (kortet "E-mailnotifikationer") kan creatoren slå mails fra for typer, der ikke er vigtige, og som administratoren sender som mail. Valget gemmes straks. Vigtige mails (udbetalinger, afregningsbilag, sikkerhed og konto) kan ikke slås fra.
- **Følsomme data:** mails indeholder aldrig gavekortkoder, rabatkoder, bankoplysninger eller CVR. De fortæller, at noget er sket, og linker til dashboardet.
- **Kundens data når aldrig creatoren** (projektejerens regel 2026-10-28): "Nyt salg" og "Refunderet" har kun ordrenummer, varenavne, antal, beløb og valuta; aldrig kundens e-mail, navn eller adresse, og afsenderen er butikken. Låst af en integrationstest.
- **Ordremails til administratoren** sender WooCommerce selv ("Ny ordre"); Merch Dashboard sender ingen (projektejerens beslutning 2026-10-28). Administrator-mails handler kun om udbetalinger, kategoriforslag, sager og fejl.

## Katalog
**M** = e-mail, **D** = klokken i dashboardet. Kanalerne er standardindstillingen; administratoren kan ændre dem. **Vigtig** = creatoren kan ikke slå mailen fra.

**Til creatoren:**

| Gruppe | Notifikation | Udløses af | Kanaler | Vigtig |
| --- | --- | --- | --- | --- |
| Salg | Nyt salg (én pr. ordre: varer, antal, beløb, fortjeneste, når den kendes) | nye salgsrækker i synkroniseringen; ikke backfill, ikke ordrer udelukket af creatorens egen kode | M+D | |
| Salg | Salg refunderet eller annulleret | refunderings-, tilbageførsels- og rettelsesrækker | D | |
| Udbetaling | Din saldo er klar til udbetaling | dagligt job: den udbetalbare saldo er steget | M+D | |
| Udbetaling | Udbetalingsanmodning modtaget | creatorens anmodning | M+D | ✓ |
| Udbetaling | Udbetaling annulleret | creatorens annullering | D | |
| Udbetaling | Udbetaling gennemført, afregningsbilag udstedt | administratorens gennemførelse | M+D | ✓ |
| Udbetaling | Udbetaling afvist (med begrundelse) | administratorens afvisning | M+D | ✓ |
| Udbetaling | Gavekort-udbetaling klar (koden ses i dashboardet) | registrering af gavekort | M+D | ✓ |
| Udbetaling | Rettelsesbilag udstedt | rettelse af et bilag | M+D | ✓ |
| Udbetaling | Regulering af en tidligere afregning | udført regulering | M+D | ✓ |
| Profil | Kategoriforslag godkendt | administratorens godkendelse | M+D | |
| Profil | Kategoriforslag afvist (med note) | administratorens afvisning | M+D | |
| Konto | Sletteanmodning behandlet | administratoren lukker sagen (ADR-083) | M+D | |
| Konto | Sletteanmodning afvist (med note) | administratorens afvisning (ADR-083) | M+D | |
| Admin | Ny sletteanmodning (`admin_product_removal_requested`) | en creator beder om at få slettet et legacy-produkt (ADR-083) | M+D | |
| Rabatkode | Din rabatkode er oprettet (koden ses i dashboardet) | administratorens første kode | M+D | ✓ |
| Rabatkode | Din rabatkode er nulstillet | ny kode (creatoren, "Se som creator" eller admin) | M+D | ✓ |
| Rabatkode | Din rabatkode er afsluttet | administratorens afslutning | M+D | ✓ |
| Konto | Velkommen til Merch Dashboard (med login-link) | creator oprettet med bruger eller bruger tilknyttet | M | ✓ |
| Konto | Kontoen er deaktiveret eller genaktiveret | statusskift | M+D | ✓ |
| Konto | Din fortjeneste er ændret (standard eller pr. produkt) | prisindstillinger | M+D | ✓ |
| Konto | Din momsstatus er ændret | momsstatus | M+D | ✓ |
| Konto | Dine stamdata til afregning er ændret | administratorens ændring | M+D | ✓ |
| Konto | Nyt produkt i din butik | produkt koblet til creatoren | D | |
| Konto | En administrator har set dit dashboard | "Se som creator" startet | D | |
| Nyheder | Ny nyhed fra Merch Dashboard | udgivelse | D | |
| Alerts | Chatbotten har mistet adgang til din kanal | token afvist (én pr. kanal, til den forbindes igen) | M+D | |
| Alerts | Din webhook er sat på pause efter 10 fejl | levering | M+D | |
| Alerts | Nye YouTube-vilkår skal accepteres | administratoren fornyer vilkårene | M+D | |
| Alerts | API-nøgle oprettet / tilbagekaldt af administrator | nøgler | M+D | ✓ |

**Til administratoren** (kun e-mail, til sitets admin-e-mail):
- ny udbetalingsanmodning;
- nyt kategoriforslag;
- ordre kræver beslutning (åben sag, K2);
- fejl i salgssynkroniseringen på en ordre;
- kategoriafstemning opgivet;
- regulering fejlede;
- gavekort afvist som kupon;
- creator har nulstillet sin rabatkode (fra som standard);
- creator har annulleret en udbetaling (fra som standard).

## Admin functionality
Merch Dashboard → **Notifikationer** (`merchboard_manage_settings`):
- én tabel pr. modtager og gruppe med navn, beskrivelse og kontakterne "E-mail" og "Dashboard" (vigtige typer er markeret);
- "Vis eksempel": mailen med eksempeldata i en sandkasset iframe og dashboardets tekstlinje;
- "Send testmail til mig";
- en log over de seneste 50 afsendelser (type, modtager, status og fejl);
- modtageren af administrator-mails vises (sitets admin-e-mail).

## Technical implementation
- **Modul** `src/Modules/Notifications/` (ubetinget): `NotificationTypes`, `NotificationSettings`, `Notifier`, `NotificationRepository`, `EmailRenderer`, `Mailer`, admin-siden og `MeNotificationsController`.
- **Udløsning:** `do_action( 'merchboard_notify', $type, $creator_id, $data )` efter commit hvert sted, hvor noget sker.
- **Afsendelse:** Action Scheduler (`merchboard_notification_send`, højst 3 forsøg), ellers ved `shutdown`. Det daglige saldo-tjek er `merchboard_notification_balance_check`.
- **Skabeloner:** `templates/emails/` med inline-CSS og tekstversion. Farver, logo, skrift, sidefod og afsender fra WooCommerce' e-mailindstillinger, når WooCommerce er aktivt.

## Relevant files
- Moduler kan lægge egne typer til via filteret `merchboard_notification_types` i en egen gruppe (fase 30: produktdesigneren, `DesignerNotifications`). Siden Notifikationer viser og gemmer kun katalogets grupper; modulets egen admin-side har typernes til/fra (`Admin/NotificationSwitches` genbruges).
- `src/Modules/Notifications/` (`NotificationsModule`, `NotificationTypes` og `Catalog/CreatorTypes`/`AdminTypes`, `NotificationSettings`, `NotificationPreferences`, `Notifier`, `NotificationSender`, `NotificationRepository`, `Email/EmailRenderer`, `Email/EmailStyle`, `Email/Mailer`)
- `src/Modules/Notifications/Admin/` (`NotificationsAdminPage`, `NotificationsAdminActions`)
- `templates/emails/layout.php`
- `src/Core/Database/Migrations/CreateNotificationsTable.php`
- `src/Modules/Notifications/MeNotificationsController.php`
- Dashboard: `assets/src/dashboard/api/notifications.ts`, `components/NotificationBell.tsx`, `sections/profile/EmailNotificationsCard.tsx`
- `tests/Integration/Notifications/NotificationsTest.php`, `tests/Integration/Notifications/NotificationsAdminTest.php`, `tests/Integration/Notifications/MeNotificationsEndpointTest.php`, `tests/Integration/Support/RecordingMailer.php`, `assets/src/dashboard/test/notifications.test.tsx`

## Database/data used
- `merchboard_notifications` (schema-version 34).
- Admin-valgene (option), creatorens fravalg (user meta) og den sidst kendte udbetalbare saldo (creatormeta).

Se [DATABASE.md](../DATABASE.md), når tabellen er implementeret.

## Permissions
- Admin: `merchboard_manage_settings`, POST, nonce og PRG.
- Creator: `CreatorContext` (ADR-007); kun egne notifikationer. Under "Se som creator" markeres intet som læst, og valgene kan ikke ændres.

## API endpoints
- `GET /me/notifications`
- `POST /me/notifications/read`
- `GET`, `PATCH /me/notification-preferences`

Se [API.md](../API.md).

## Hooks
- Action `merchboard_notify` (udløses af modulerne).
- Action `merchboard_news_published` (`$id`, `$title`, `$published`): en nyhed er udgivet; modulet giver hver creator, der ser den, én notifikation.
- Action `merchboard_creator_status_changed` (findes fra fase 9) giver `account_status`.

**Udløsersteder (29.5), alle efter commit:**
- Salg: `SalesSynchronizer` (kun live-synkronisering, aldrig backfill) via `SalesNotices`: `sale_new` én pr. ordre, cyklus og creator; `sale_reversed` for refunderings-, tilbageførsels- og rettelsesrækker. Linjer udelukket af creatorens egen kode giver ingen rækker og derfor ingen notifikation.
- Udbetalinger: `PayoutRequests` (anmodning, annullering), `PayoutDecisions` (gennemført med bilagsnummer, afvist med begrundelse) og `PayoutGiftCards` (gavekort, aldrig koden) via `PayoutNotice`; `SettlementDocuments::correct`; `PayoutRecalculations` (pr. udført regulering).
- Kategoriforslag: `CategoryProposals::approve`/`reject` (feltets nøgle; navnet oversættes ved visning).
- Rabatkode: `CreatorCodeService::issue` (administratorens første kode eller nulstilling) og `end`; aldrig koden. En første kode, creatoren selv får i dashboardet, giver ingen notifikation, for den står allerede på skærmen.
- Konto: `CreatorService` (velkomst, når en bruger tilknyttes; én gang pr. creator og bruger), statusskift, `CreatorPricingSettings` (fortjeneste som standard eller pr. produkt, nuværende momsstatus), `PayoutSettings::set_billing_details` (aldrig værdierne), `ProductCreatorLink::link` (manuel kobling) og `Impersonation::start` (aldrig hvem).
- Nyheder: `NewsService::publish` → `merchboard_news_published`.
- Alerts (29.6): `ChatSender` (botten afvist; én gang pr. forbindelse, til creatoren forbinder igen), `WebhookDispatcher` (sat på pause), `YouTubeLegal` → action `merchboard_youtube_terms_renewed` (hver creator med YouTube-kanal, én gang pr. version), `AlertApiKeys` (ny nøgle med navn; administratorens tilbagekaldelse; aldrig nøglen).
- Administrator-mails (29.6), kun til `admin_email`; creatorens navn slås op af `Notifier`: udbetalingsanmodning og annullering (`PayoutNotice::admin`), kategoriforslag (én pr. indsendelse med felterne), åben sag (`OrderExclusionAssessor::committed`, én pr. ordre), fejl i salgssynkroniseringen (én pr. ordre og fejlkode), kategoriafstemning opgivet, regulering fejlet, gavekort afvist som kupon (aldrig koden) og creatorens egen nulstilling af rabatkoden.
- Saldo-jobbet (29.6): `BalanceReadyNotices` på Action Scheduler-hooket `merchboard_notification_balance_check` kører én gang i døgnet og planlægger selv den næste kørsel; en sideindlæsning i wp-admin planlægger det, hvis intet venter. Pr. aktiv creator og valuta gemmes det beløb, der kan udbetales, i creatormeta (`balance_notified`). Er beløbet steget, får creatoren `balance_ready`. Første kørsel noterer kun beløbet, så udgivelsen ikke giver alle en besked på én gang.
- Dedupe-nøglen er `{type}:{creator}:{hændelse}`, så en gentaget handling (samme idempotensnøgle, et dobbeltklik, en ny synkronisering, en genudgivelse) ikke giver en ny notifikation.
- Action Scheduler-hooks `merchboard_notification_send` og `merchboard_notification_balance_check`.

## Dependencies
- WordPress' `wp_mail`.
- Action Scheduler (følger med WooCommerce) til afsendelse og det daglige job.
- WooCommerce' e-mailindstillinger til designet, når WooCommerce er aktivt.

## Tests
- `NotificationsTest`:
  - alle 36 typer renderes med eksempeldata og escaping;
  - dedupe, administratorens og creatorens valg (vigtige ignorerer fravalg), creator uden bruger og administrator-mails;
  - afsendelse med forsøg efter 1 og 5 minutter, derefter `failed`; uden kø ved `shutdown`;
  - klokkens læst-markering, beskæring og oprydning ved sletning af en creator;
  - admin-valg med audit.
- `NotificationsAdminTest`: admin-siden.
- `MeNotificationsEndpointTest`: kun egne notifikationer, læst-markering (ikke under "Se som creator"), e-mailvalg (kun ikke-vigtige; valg for typer, administratoren har slået fra, bevares) og `PATCH` afvist under "Se som creator".
- Jest (`notifications.test.tsx`): validering (også links, der ikke er dashboard-stier), boblen (9+), panelet som ren tekst, læst-markering, "Vis flere", "Markér alle som læst", Escape og klik udenfor, opdatering hvert minut og e-mailvalgene.

## Known limitations
- En creator uden WordPress-bruger får ingen mail.
- Administrator-mails går kun til sitets admin-e-mail.
- Produkter, der kobles via creatorens kategori (afstemningen), giver ingen notifikation; kun manuelle koblinger.
- "Nyt salg" viser ikke fortjenesten endnu (feltet er valgfrit i skabelonen).

## Future improvements
- Daglig eller ugentlig opsamling som et valg for creatoren.
- Flere modtagere af administrator-mails.
