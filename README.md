# Atrium Monitoring

Speed and uptime for the [Atrium](https://github.com/AlveBy/atrium) admin, on your own server, with no outside service:

- **server timings** of every request: time to the first byte, database queries and their time, cache hits and misses, peak memory, slow queries with their SQL (values replaced by `?`), by route, site and language;
- **visitors' Web Vitals** (LCP, INP, CLS, TTFB, FCP) through a script under 2 KB sent with `sendBeacon`: no cookies, no IP stored, no query strings, Do Not Track and Global Privacy Control respected, a sample rate;
- **uptime** of every site's front page and the addresses you add, every minute; an incident after a number of failures in a row, with an e-mail and a Telegram message;
- **Site health → Speed and uptime**: p50, p75 and p95 by day, week or month, the slowest routes, queries and pages, the checks and incidents, and a dashboard tile.

Measurements go to a file and reach the database once a minute, in one transaction: the middleware adds about 0.15 ms to a request with 20 queries (`php artisan atrium:monitoring:benchmark`). Raw data is kept 7 days, daily percentiles 13 months.

## Install

```bash
composer require alveby/atrium-monitoring
php artisan atrium:module:install alveby/atrium-monitoring
```

Cron must call `php artisan schedule:run` every minute. Alerts are set in **Modules → Monitoring**; the Telegram token can also come from `ATRIUM_MONITORING_TELEGRAM_TOKEN`.

## Commands

```bash
php artisan atrium:monitoring:flush
php artisan atrium:monitoring:uptime
php artisan atrium:monitoring:prune
php artisan atrium:monitoring:benchmark
```

Everything else, including privacy, the percentile maths, the configuration and the hooks for other packages: [docs/monitoring.md](https://github.com/AlveBy/atrium/blob/main/docs/monitoring.md).

## License

MIT
