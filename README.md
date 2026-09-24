# Merch Dashboard

WordPress plugin for creator and merchandise management: admin tooling in WordPress Admin and a secure, standalone dashboard for content creators.

**Status:** Foundation phase. See [docs/PROJECT-STATUS.md](docs/PROJECT-STATUS.md).

## Quick start

Requirements: PHP ≥ 8.2, Node 24, npm ≥ 11, Composer 2 (global or `.tools/composer.phar`).

```bash
npm ci
node bin/composer.mjs install
npm run lint        # ESLint + PHPCS + PHPStan
npm test            # PHPUnit
npm run wp:start    # local WordPress + WooCommerce at http://127.0.0.1:9400 (admin / password)
```

## Documentation

All project documentation lives in [docs/](docs/README.md) (in Danish). AI agents must follow [docs/AI-DEVELOPMENT.md](docs/AI-DEVELOPMENT.md).

## License

GPL-2.0-or-later
