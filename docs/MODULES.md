# Moduler

> Status: **Fundament implementeret (fase 2):** `Module`, `Container` og boot i `Plugin`. Der findes endnu ingen moduler.

## Formål

Systemet skal kunne vokse med nye moduler (Payouts, Campaigns, Notifications …) uden at eksisterende kode skal omskrives. Et modul er en mappe under `src/Modules/<Navn>/` med én indgangsklasse, der implementerer `MerchBoard\Core\Module`.

## Kontrakt

```php
namespace MerchBoard\Core;

interface Module {
	/** Stable, unique id, e.g. 'creators'. Used in filters, settings and logs. */
	public function id(): string;

	/** Register hooks only. No queries, no output, no heavy work. */
	public function register( Container $container ): void;
}
```

Det er bevidst minimalt. Valgfrie evner tilføjes som små separate interfaces, *når* der opstår et konkret behov. Ingen af dem bygges på forhånd.

## Registrering

`Plugin::boot()` (på `plugins_loaded`) sender `Plugin::default_modules()` gennem filteret `merchboard_modules` og kalder `register( $container )` på hvert modul. Til sidst fyres `merchboard_loaded` med containeren. Elementer, der ikke implementerer `Module`, og dublet-ID'er springes over med `_doing_it_wrong()`.

**Sådan kobles et nyt modul på:** Opret `src/Modules/<Navn>/<Navn>Module.php`, som implementerer `MerchBoard\Core\Module`, og tilføj en instans i `Plugin::default_modules()`. Moduler, der afhænger af et andet plugin, tilføjes betinget:

```php
private function default_modules(): array {
	$modules = array(
		new Modules\Creators\CreatorsModule(),
		// …
	);
	if ( class_exists( 'WooCommerce' ) ) {
		$modules[] = new Modules\WooCommerce\WooCommerceModule();
	}
	return $modules;
}
```

Hovedfilen og boot-logikken ændres ikke, når moduler tilføjes.

Rækkefølgen er kun vigtig for services, som andre moduler slår op. Den løses ved at registrere services lazy i containeren (factory-closures), så opslag først sker ved brug.

## Container

`MerchBoard\Core\Container` er en minimal lazy service locator med `set( string $id, callable $factory )`, `has()` og `get( string $id )`. Singletons pr. request. `get()` er typesikker for PHPStan (`class-string<T>` → `T`). Den kaster `LogicException` ved ukendt service, cirkulær afhængighed, forkert returtype fra en factory og ved erstatning af en allerede oprettet service. Den eksisterer for at samle wiring ét sted og gøre services udskiftelige i tests, ikke for at være et DI-framework. Service-IDs er klassenavne (`CreatorRepository::class`).

## Hvad et modul typisk registrerer

| Område | Mekanisme |
| --- | --- |
| Services | `$container->set( FooService::class, fn( $c ) => new FooService( $c->get( FooRepository::class ) ) )` |
| Admin-undermenu | `admin_menu` → `add_submenu_page( 'merchboard', … )` under Merch Dashboard-menuen |
| REST-routes | `rest_api_init` → controller-klasse, der arver `MerchBoard\Core\Http\RestController` |
| Dashboard-sektion | filter `merchboard_dashboard_sections` (id, label, ikon, capability og JS-chunk). Se [FRONTEND.md](FRONTEND.md) |
| Settings | egen sektion/tab i Settings-siden via Settings API |
| Domæne-events | `do_action( 'merchboard_<modul>_<event>', … )` |
| Cron | `merchboard_<modul>_<job>`, scheduleres ved aktivering og fjernes ved deaktivering |

## Regler

1. Et modul må kun **læse andre modulers data via deres services/repositories**, aldrig via direkte SQL mod deres tabeller.
2. Moduler må ikke have cirkulære afhængigheder. Brug hooks, hvis to moduler skal reagere på hinanden.
3. Et modul, der afhænger af et tredjeparts-plugin (WooCommerce), indlæses betinget og må ikke fatale, hvis plugin'et deaktiveres.
4. Et nyt modul får et feature-dokument i `docs/features/` og en linje i tabellen nedenfor.
5. Schema for nye tabeller tilføjes som migrationer i `src/Core/Database/Migrations/` (central, lineær rækkefølge. Se [DATABASE.md](DATABASE.md)).

## Moduloversigt

| Modul | Status | Ansvar | Fase |
| --- | --- | --- | --- |
| Core (ikke et modul) | Delvist | **Færdig:** Plugin, Autoloader, Requirements, Container, Module. **Planlagt:** Migrator, Capabilities, Settings, Logger, Assets | 2 ✅, 3-5 |
| Creators | Planlagt | Creator-entity, repository, service og admin-administration | 6, 10 |
| Access | Planlagt | `CreatorContext`, login-redirect, blokering af wp-admin for creators | 8 |
| Admin | Planlagt | Topmenu, Overview, Settings og Logs | 9, 11 |
| Dashboard | Planlagt | Route, shell, sektion-registry og `/me`-endpoints | 12-15 |
| WooCommerce | Planlagt | Produkt↔creator, sales ledger, produkter/ordrer i dashboard | 17-19 |
| Statistics | Fremtid | Aggregeringer og grafer | 20 |
| Payouts | Fremtid | Afregning til creators | – |
| Notifications | Fremtid | E-mail/in-app-notifikationer | – |
| Integrations | Fremtid | Eksterne tjenester (webhooks, API'er) | – |
| Campaigns, Support, Reporting | Fremtid | – | – |
