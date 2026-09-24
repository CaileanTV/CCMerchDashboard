# Merch Dashboard – dokumentation

Denne mappe er projektets tekniske hukommelse. Den er skrevet til både udviklere og AI-agenter og skal holdes opdateret sammen med koden.

> **AI-agenter:** Start med [AI-DEVELOPMENT.md](AI-DEVELOPMENT.md) og [PROJECT-STATUS.md](PROJECT-STATUS.md).

## Hvad er projektet?

Et selvstændigt WordPress-plugin (`merchboard`), som lader virksomheden administrere content creators/streamere og deres merchandise. Det har to brugerflader:

1. **WordPress Admin**: virksomhedens administratorer styrer creators, adgang, indstillinger og logs.
2. **Creator Dashboard** (`/creator-dashboard/`): et selvstændigt, moderne og responsivt dashboard, hvor den enkelte creator kun ser sine egne data.

WooCommerce er datakilde for produkter og ordrer og integreres som et valgfrit modul.

## Dokumentoversigt

| Dokument | Indhold |
| --- | --- |
| [PROJECT-STATUS.md](PROJECT-STATUS.md) | Hvor står projektet nu: færdigt, i gang, planlagt, gæld og næste skridt |
| [ROADMAP.md](ROADMAP.md) | Overordnet plan og de konkrete implementeringsfaser |
| [ARCHITECTURE.md](ARCHITECTURE.md) | Overordnet arkitektur, mappestruktur, namespaces, boot-flow og konventioner |
| [MODULES.md](MODULES.md) | Modulsystemet, og hvordan nye moduler tilføjes |
| [DATABASE.md](DATABASE.md) | Datamodel: hvad der ligger i users, meta, options, WooCommerce og custom tables |
| [USER-ROLES.md](USER-ROLES.md) | Roller, capabilities og adgangsmodel |
| [SECURITY.md](SECURITY.md) | Security-arkitektur, trusselsmodel og tjekliste |
| [API.md](API.md) | REST API-design og konventioner |
| [FRONTEND.md](FRONTEND.md) | Creator dashboard: frontend-arkitektur og design system |
| [ADMIN.md](ADMIN.md) | Struktur for WordPress Admin |
| [WOOCOMMERCE.md](WOOCOMMERCE.md) | Arkitektur for WooCommerce-integrationen |
| [TESTING.md](TESTING.md) | Teststrategi |
| [DEVELOPMENT.md](DEVELOPMENT.md) | Lokalt udviklingsmiljø, kommandoer, dependencies, build og deploy |
| [DECISIONS.md](DECISIONS.md) | Architecture Decision Records (ADR) |
| [CHANGELOG.md](CHANGELOG.md) | Større ændringer |
| [AI-DEVELOPMENT.md](AI-DEVELOPMENT.md) | Regler for AI-agenter, herunder Project Isolation |
| [features/](features/) | Ét dokument pr. større feature (se [features/_TEMPLATE.md](features/_TEMPLATE.md)) |
| [reports/](reports/) | Tidsstemplede analyser og rapporter |

## Sprog

- Dokumentation skrives på **dansk**. Tekniske termer må gerne være på engelsk.
- Kode, kodekommentarer, commit messages, hook-navne og UI-kildestrenge skrives på **engelsk**. UI oversættes via WordPress i18n.
