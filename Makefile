# Local development for Send4 for WooCommerce.
#
#   make up        start WordPress + MariaDB
#   make install   install WordPress and WooCommerce, activate the plugin, add a product
#   make status    print the plugin's view of the world
#   make down      stop; make reset also throws the database away
#
# Connect from WooCommerce -> Send4 -> Connect with Send4, against the Send4 app
# SEND4WC_API_BASE_URL points at (default: the app on the host's port 8080).

SHELL := /bin/bash
WP := bin/wp

# The status `make order` moves its test order to.
STATUS ?= processing

-include .env
export

.PHONY: up down reset install status order queue run-queue sync cron-run logs test test-php74 test-multisite lint pot zip release-check help

help:
	@grep -E '^[a-z0-9-]+:.*?##' $(MAKEFILE_LIST) | sed 's/:.*##/\t/' | column -t -s $$'\t'

up: ## Start the containers
	docker compose up -d db wordpress

down: ## Stop the containers, keeping data
	docker compose down

reset: ## Stop the containers and delete the database and WordPress files
	docker compose down -v

install: up ## Install WordPress and WooCommerce, activate the plugin, add a product
	$(WP) core install \
		--url=http://localhost:$${WP_HOST_PORT:-8090} \
		--title="Send4 store dev" \
		--admin_user=admin \
		--admin_password=admin \
		--admin_email=dev@send4.local \
		--skip-email
	$(WP) plugin install woocommerce --activate
	$(WP) plugin activate send4-for-woocommerce
	$(WP) rewrite structure '/%postname%/' --hard
	$(WP) wc product create --name="Sofa Oslo" --regular_price=899 --user=admin
	@echo
	@echo "Store:  http://localhost:$${WP_HOST_PORT:-8090}/wp-admin (admin / admin)"
	@echo "Next:   WooCommerce -> Send4 -> Connect with Send4"

status: ## Print the plugin's view of the world
	$(WP) send4wc status

order: ## Make a test order and move it to a status: make order STATUS=processing
	$(WP) eval '$$o = wc_create_order(); $$o->add_product( wc_get_product( wc_get_products( array( "limit" => 1, "return" => "ids" ) )[0] ), 1 ); $$o->set_address( array( "first_name" => "Jonas", "last_name" => "Jonaitis", "phone" => "861234567", "country" => "LT", "city" => "Vilnius", "address_1" => "Gedimino pr. 1" ), "billing" ); $$o->calculate_totals(); $$o->save(); $$o->update_status( "$(STATUS)" ); echo $$o->get_id() . PHP_EOL;'

queue: ## Show the order-event queue
	$(WP) send4wc queue

run-queue: ## Send every due order event now
	$(WP) send4wc run_queue

sync: ## Ask Send4 which statuses it wants and send it the store's statuses and categories
	$(WP) send4wc sync_store

cron-run: ## Run due WP-Cron events now
	$(WP) cron event run --due-now

logs: ## Tail the WordPress debug log
	docker compose exec -T wordpress tail -n 100 -f /var/www/html/wp-content/debug.log

# The tests container bind-mounts this directory, vendor/ included, so the
# dependencies have to exist on the host before it can run anything.
vendor: composer.json
	composer install --no-interaction --prefer-dist

test: vendor ## Run the PHPUnit suite
	docker compose up -d db
	docker compose run --rm --build tests vendor/bin/phpunit

# CI runs the suite on 7.4 as well as 8.3, and 7.4 is the version the plugin
# header promises. Reproducing that leg locally is the only way to find out
# before a push that something is 8-only.
test-php74: vendor ## Run the PHPUnit suite on PHP 7.4, as CI does
	docker compose up -d db
	docker compose build --build-arg PHP_VERSION=7.4 tests
	docker compose run --rm tests vendor/bin/phpunit
	docker compose build tests

test-multisite: vendor ## Run the PHPUnit suite as a multisite network, as CI does
	docker compose up -d db
	docker compose run --rm -e WP_TESTS_MULTISITE=1 --build tests vendor/bin/phpunit

lint: vendor ## Run PHPCS and PHPStan
	vendor/bin/phpcs
	php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress

# The header declares Domain Path: /languages, so translators expect a template
# there. Regenerate before a release.
#
# The mount point is a path no file in the repo could contain, on purpose:
# make-pot makes a path relative with a plain str_replace of the source
# directory, so mounted at /app it also ate the "/app" in
# admin/views/partials/app-bar.php. tests/Unit/PotReferencesTest.php watches.
pot: ## Regenerate languages/send4-for-woocommerce.pot from the source
	docker run --rm --user root -v "$(CURDIR)":/send4wc-pot-source -w /send4wc-pot-source wordpress:cli-2.12.0-php8.3 \
		wp i18n make-pot . languages/send4-for-woocommerce.pot --slug=send4-for-woocommerce --domain=send4-for-woocommerce --allow-root \
		--exclude=vendor,tests,docker,build,bin,node_modules

zip: ## Build the distributable plugin zip
	bin/build-zip.sh

# The same check the release workflow runs on the pushed tag. Run it before
# tagging: a tag cannot be moved once WordPress.org has published it.
release-check: ## Check a release is consistent: make release-check [TAG=v0.1.0]
	bin/check-version.sh $(TAG)
