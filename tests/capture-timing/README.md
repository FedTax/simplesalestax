# DEV-6611 capture timing and recovery study

The driver exercises LocalWP's WordPress front controller through a private PHP CGI process. Processing samples place an order through WooCommerce Store API checkout with cash on delivery. Completed samples time a separate WooCommerce Orders REST update after checkout. Both modes use TaxCloud V3. A single-package cart contains two units of the same product. A split cart contains two equally priced products assigned to different origins.

The timing copy instruments the call to `SST_Order::do_capture()` in the order controller. Each request records elapsed wall time, PHP request time and the duration and status of every TaxCloud HTTP call, including Data Import's existence checks. Measurements use fresh orders and randomized ordering of the 16 comparison groups within each repetition. Successful samples contribute to the median and nearest-rank p95. The added request time is the difference between the capture-on and capture-off medians in the same group.

## Run locally

Requirements are the LocalWP site containing this checkout, its MySQL socket, Python 3.9 or later and PHP 8.0 or later with `php-cgi` and mysqli. Use private run directories outside the WordPress tree. Configure both dedicated Test profiles in the existing ignored `cypress/credentials.local.json` before running real API measurements.

```sh
python3 tests/capture-timing/run.py --socket "$SST_E2E_MYSQL_SOCKET" --directory /tmp/dev6611-checks --verify
python3 tests/capture-timing/run.py --socket "$SST_E2E_MYSQL_SOCKET" --directory /tmp/dev6611-baseline --confirm-test-connections --samples 50 --workers 4
python3 tests/capture-timing/run.py --socket "$SST_E2E_MYSQL_SOCKET" --directory /tmp/dev6611-background --confirm-test-connections --background --samples 50 --workers 4
python3 tests/capture-timing/report.py /tmp/dev6611-baseline/samples.jsonl --background /tmp/dev6611-background/samples.jsonl
```

Use a new directory for a different source revision. Reusing a directory resumes its frozen source copy and skips recorded samples. A full baseline creates 800 local orders, 400 of which submit sales to the Test connections. The background comparison creates 200 further Data Import orders. Split orders create two remote package records. Test recipients use the run's synthetic email address.

The probe loads WooCommerce and TaxCloud against the local database with request-scoped integration settings. It suppresses outgoing mail and webhooks. The installed plugin files and saved merchant settings are independent of the measurement copy. CGI timing includes PHP startup and shutdown. Browser rendering, the LocalWP reverse proxy and the merchant's additional plugins are outside the measurement.

For background samples, the harness reserves its action for a separate, immediate Action Scheduler run under the same Test connection. The reported confirmation interval runs from the original status trigger through worker completion. A live store's cron interval and queue backlog add to this interval.

## Recovery checks

`--verify` runs WooCommerce, its order editor data model, the order controller and Action Scheduler using simulated TaxCloud responses. It exercises HPOS and legacy order storage separately. Coverage includes the three trigger choices, duplicate transitions, contending database sessions, manual and automatic uploads, retry exhaustion, remote conflicts, refunds before capture, edits, settings changes, enqueue failure and overdue display.
