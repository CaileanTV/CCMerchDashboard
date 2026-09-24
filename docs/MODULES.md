# Moduler

> Status: **Design.** Implementeres fra roadmap-fase 2.

## Formål

Systemet skal kunne vokse med nye moduler (Payouts, Campaigns, Notifications …) uden at eksisterende kode skal omskrives. Et modul er en mappe under `src/Modules/<Navn>/` med én indgangsklasse, der implementerer `CCMerch\Core\Module`.

## Kontrakt

```php
namespace CCMerch\Core;

interface Module {
	/** Stable, unique id, e.g. 'creators'. Used in filters, settings and logs. */
	public function id(): string;

	/** Register hooks only. No queries, no output, no heavy work. */
	public function register( Container $container ): void;
}
```

Det er bevidst minimalt. Valgfrie evner tilføjes som små separate interfaces, *når* der opstår et konkret behov. Ingen af dem bygges på forhånd.

## Registrering

`Plugin::boot()` bygger listen:

```php
$modules = [
	new Modules\Creators\CreatorsModule(),
	new Modules\Access\AccessModule(),
	new Modules\Admin\AdminModule(),
	new Modules\Dashboard\DashboardModule(),
];
if ( class_exists( 'WooCommerce' ) ) {
	$modules[] = new Modules\WooCommerce\WooCommerceModule();
}
$modules = apply_filters( 'ccmerch_modules', $modules );
```

Rækkefølgen er kun vigtig for services, som andre moduler slår op. Den løses ved at registrere services lazy i containeren (factory-closures), så opslag først sker ved brug.

## Container

`CCMerch\Core\Container` er en minimal lazy service locator med `set( string $id, callable $factory )` og `get( string $id )`. Singletons pr. request. Den eksisterer for at samle wiring ét sted og gøre services udskiftelige i tests, ikke for at være et DI-framework. Service-IDs er klassenavne (`CreatorRepository::class`).

## Hvad et modul typisk registrerer

| Område | Mekanisme |
| --- | --- |
| Services | `$container->set( FooService::class, fn( $c ) => new FooService( $c->get( FooRepository::class ) ) )` |
| Admin-undermenu | `admin_menu` → `add_submenu_page( 'ccmerch', … )` under CC Merch-menuen |
| REST-routes | `rest_api_init` → controller-klasse, der arver `CCMerch\Core\Http\RestController` |
| Dashboard-sektion | filter `ccmerch_dashboard_sections` (id, label, ikon, capability og JS-chunk). Se [FRONTEND.md](FRONTEND.md) |
| Settings | egen sektion/tab i Settings-siden via Settings API |
| Domæne-events | `do_action( 'ccmerch_<modul>_<event>', … )` |
| Cron | `ccmerch_<modul>_<job>`, scheduleres ved aktivering og fjernes ved deaktivering |

## Regler

1. Et modul må kun **læse andre modulers data via deres services/repositories**, aldrig via direkte SQL mod deres tabeller.
2. Moduler må ikke have cirkulære afhængigheder. Brug hooks, hvis to moduler skal reagere på hinanden.
3. Et modul, der afhænger af et tredjeparts-plugin (WooCommerce), indlæses betinget og må ikke fatale, hvis plugin'et deaktiveres.
4. Et nyt modul får et feature-dokument i `docs/features/` og en linje i tabellen nedenfor.
5. Schema for nye tabeller tilføjes som migrationer i `src/Core/Database/Migrations/` (central, lineær rækkefølge. Se [DATABASE.md](DATABASE.md)).

## Moduloversigt

| Modul | Status | Ansvar | Fase |
| --- | --- | --- | --- |
| Core (ikke et modul) | Planlagt | Plugin, Container, Migrator, Capabilities, Settings, Logger, Assets | 2-5 |
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
