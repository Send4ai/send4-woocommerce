# Send4 for WooCommerce

A WordPress plugin that sends a WooCommerce order to [Send4](https://send4.ai) when it reaches a
status you choose, so an AI flow can text the buyer and book the delivery or installation slot
over SMS.

Contact forms are a separate plugin, [send4-wordpress](https://github.com/send4ai/send4-wordpress)
(`send4` on WordPress.org). The two can run on one site, and each keeps its own connection.

```
order status changes ──► Store_Events ──► queue (order id + topic, no buyer data)
 (checkout, admin,          │                        │
  REST, gateways)           │                        ▼
                     only the topics       Delivery (Action Scheduler now,
                     Send4 asked for       WP-Cron retries ~1.5 days)
                                                     │  builds the event from the live order
                                                     ▼
                                     POST /api/integrations/wordpress/store-events

   hourly + on connect + "Sync now" ──► Store_Sync ──► handshake: which topics?
                                                   └─► PUT /api/integrations/wordpress/store
                                                        (statuses, categories, phone rule)
```

Three rules hold the design together:

- **Nothing runs while a buyer waits.** A status change can happen inside a checkout, so the
  hook only reads options and writes one queue row. The topic list it needs is cached locally
  (`Send4WC_Settings::store_topics()`) and refreshed by the hourly sync, never fetched in the hook.
- **Only the topics Send4 asked for leave the store.** A topic a flow starts on goes with the
  order's details; a topic that only ends runs (`order.status.cancelled`, `order.status.refunded`)
  goes with the order's number and status alone; every other status is not sent.
- **The queue holds a reference, not a copy.** The event is built from the order when it is
  sent (`Send4WC_Order_Payload::build()`), so the database never holds a buyer's details waiting
  on Send4, and an order created with `wc_create_order()` is complete by the time it goes.

The plugin's classes are `Send4WC_*`, its options and hooks `send4wc_*`. The prefix deliberately
does not start with `Send4_`: the forms plugin's autoloader claims every `Send4_*` class, and the
two plugins must never load each other's code.

## Repository layout

| Path | What it is |
|---|---|
| `send4-for-woocommerce.php`, `includes/`, `admin/` | the plugin |
| `includes/class-send4wc-store-events.php` | the order hooks |
| `includes/class-send4wc-order-payload.php` | what an order event says |
| `includes/class-send4wc-store-inventory.php`, `class-send4wc-store-sync.php` | what the store tells Send4 it has |
| `includes/class-send4wc-queue.php`, `class-send4wc-delivery.php` | the outbox and its worker |
| `tests/` | PHPUnit against a real WordPress and WooCommerce |
| `bin/` | the zip build, the version check, a WP-CLI wrapper for the dev stack |

## Local development

Requirements: Docker and Composer.

```bash
composer install
make install        # WordPress + WooCommerce on http://localhost:8090, admin / admin
```

Then open **WooCommerce → Send4** and press **Connect with Send4**. The store talks to the Send4
app on the host's port 8080 unless `SEND4WC_API_BASE_URL` says otherwise (`.env`, see
`.env.example`).

`make order STATUS=processing` creates a test order and moves it to a status; `make queue`,
`make run-queue`, `make sync` and `make status` show what the plugin is doing.

## Tests

```bash
make test             # PHPUnit on PHP 8.3, single site
make test-php74       # the PHP 7.4 leg CI runs
make test-multisite   # the multisite leg
make lint             # PHPCS + PHPStan
```

The suite runs against a real WordPress and a real WooCommerce (installed by Composer from
WPackagist into `tests/plugins/`), in a container, against the compose file's MariaDB. PHPStan
reads WooCommerce from `php-stubs/woocommerce-stubs`.

## Releasing

`bin/build-zip.sh` produces `build/send4-for-woocommerce.zip`.

A pushed `v*` tag is a release (`.github/workflows/release.yml`).

1. Bump the version in all three places: the `Version` header and `SEND4WC_VERSION` in
   `send4-for-woocommerce.php`, and `Stable tag` in `readme.txt`. Write the changelog entry, run
   `make pot`, then `make release-check` and `make zip`. The readme keeps two changelog entries,
   as WordPress.org asks: move the older one to the top of `changelog.txt`.
2. Merge to `main`, then run `git tag vX.Y.Z && git push origin vX.Y.Z`. Tag a commit that is
   already on `main`: the workflow refuses any other.
3. The workflow runs all of CI on the tag, checks the tag against the version, and creates the
   GitHub release with the zip attached. That zip is what a hand install and the WordPress.org
   reviewer get.
4. The `wordpress-org` job commits the same zip to the WordPress.org SVN. It is **skipped** until
   the repository variable `WPORG_PUBLISH` is `true`, which is set once the plugin is approved.

⚠️ The WordPress.org slug comes from the `Plugin Name` header at submission and never changes:
submit with `Plugin Name: Send4 for WooCommerce`, which gives `send4-for-woocommerce`.
⚠️ A tag cannot be moved once WordPress.org has it, so a fix is always a new patch version.

## License

GPL v2 or later. See [LICENSE](LICENSE).
