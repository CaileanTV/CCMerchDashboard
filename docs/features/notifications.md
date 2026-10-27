# Feature: Notifikationer

## Purpose
Creators og administratorer får besked, når noget sker, så de ikke selv skal holde øje med dashboardet og køerne. Beskeden kommer som e-mail og, for creators, ved et ikon (klokken) øverst i dashboardet ([ADR-079](../DECISIONS.md), ROADMAP fase 29).

## Current status
I gang (fase 29, version 1.4.0; plan godkendt 2026-10-27). 29.2 (kernen: katalog, lager, valg, afsendelse og mailskabelonen) er implementeret og testet automatisk; endnu udløser intet notifikationer, og admin-siden og klokken mangler.

## User-facing functionality
- **Klokken:** et lille ikon i dashboardets topbar med en rød boble med antal ulæste (9+ over 9).
  - Et klik åbner et panel med notifikationerne som korte tekstlinjer: nyeste først, tidspunkt og et link til den relevante side. Her er "Markér alle som læst" og "Vis flere".
  - At åbne panelet markerer de viste som læst.
  - Ikke en popup som Nyheder, og ikke mailens layout.
- **E-mail** i butikkens design (som projektejerens nye WooCommerce-mails): butiksnavn, et kort med farvet topkant, overskrift, hilsen, tekst, en infoboks eller tabel, en knap til dashboardet og en sidefod med butikkens navn og adresse.
- **Valg:** under Profil kan creatoren slå mails fra for typer, der ikke er vigtige. Vigtige mails (udbetalinger, afregningsbilag, sikkerhed og konto) kan ikke slås fra.
- **Følsomme data:** mails indeholder aldrig gavekortkoder, rabatkoder, bankoplysninger eller CVR. De fortæller, at noget er sket, og linker til dashboardet.

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
- `src/Modules/Notifications/` (`NotificationsModule`, `NotificationTypes` og `Catalog/CreatorTypes`/`AdminTypes`, `NotificationSettings`, `NotificationPreferences`, `Notifier`, `NotificationSender`, `NotificationRepository`, `Email/EmailRenderer`, `Email/EmailStyle`, `Email/Mailer`)
- `templates/emails/layout.php`
- `src/Core/Database/Migrations/CreateNotificationsTable.php`
- `tests/Integration/Notifications/NotificationsTest.php`, `tests/Integration/Support/RecordingMailer.php`

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
- `PATCH /me/notification-preferences`

Se [API.md](../API.md), når de er implementeret.

## Hooks
- Action `merchboard_notify` (udløses af modulerne).
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

## Known limitations
- En creator uden WordPress-bruger får ingen mail.
- Administrator-mails går kun til sitets admin-e-mail.

## Future improvements
- Daglig eller ugentlig opsamling som et valg for creatoren.
- Flere modtagere af administrator-mails.
