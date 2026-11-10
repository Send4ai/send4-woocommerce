<?php
/**
 * Telling Send4 which versions this store runs.
 *
 * @package Send4WC
 */

/**
 * Once the plugin is on WordPress.org, every released version can still be
 * installed somewhere, and Send4 can only find out which by being told. The
 * report rides on the store inventory push as `environment`, so it reaches
 * Send4 on every connect, every hourly sync and every "Sync now" — and it must
 * never cost the store its inventory.
 */
class Send4WC_Environment_Report_Test extends Send4WC_Test_Case {

	/**
	 * A token exchange plus an accepted push.
	 *
	 * @return void
	 */
	private function queueAcceptedPush() {
		$this->http->queueToken();
		$this->http->queueStoreSaved();
	}

	/**
	 * The shape Send4 reads (app WordPressSiteEnvironment): six keys, nothing else.
	 *
	 * @return void
	 */
	public function test_the_store_push_carries_the_environment() {
		$this->queueAcceptedPush();

		$this->assertTrue( Send4WC_Store_Sync::push_inventory() );

		$requests = $this->http->requestsTo( '/api/integrations/wordpress/store' );
		$this->assertCount( 1, $requests );
		$this->assertSame( 'PUT', $requests[0]['method'] );

		$body = $requests[0]['body'];
		$this->assertArrayHasKey( 'statuses', $body, 'The inventory is still the point of the push.' );
		$this->assertSame(
			array( 'plugin_version', 'wp_version', 'php_version', 'wc_version', 'locale', 'multisite' ),
			array_keys( $body['environment'] )
		);
		$this->assertSame( SEND4WC_VERSION, $body['environment']['plugin_version'] );
		$this->assertSame( get_bloginfo( 'version' ), $body['environment']['wp_version'] );
		$this->assertSame( WC_VERSION, $body['environment']['wc_version'] );
		$this->assertSame( get_locale(), $body['environment']['locale'] );
		$this->assertSame( is_multisite(), $body['environment']['multisite'] );
	}

	/**
	 * Distribution builds append a suffix to PHP_VERSION that is longer than
	 * the 32 characters Send4 keeps, which would turn the value into nothing.
	 *
	 * @return void
	 */
	public function test_the_php_version_is_the_plain_release_number() {
		$report = Send4WC_Environment::report();

		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', $report['php_version'] );
		$this->assertSame( PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION, $report['php_version'] );
	}

	/**
	 * The WooCommerce the store runs is reported as it is loaded, not guessed.
	 *
	 * @return void
	 */
	public function test_wc_version_is_the_running_woocommerce() {
		$this->assertTrue( defined( 'WC_VERSION' ), 'The suite runs beside WooCommerce.' );
		$this->assertSame( WC_VERSION, Send4WC_Environment::report()['wc_version'] );
	}

	/**
	 * The report holds what the store runs, never anything about a buyer.
	 *
	 * @return void
	 */
	public function test_the_report_holds_nothing_but_versions_and_flags() {
		foreach ( Send4WC_Environment::report() as $key => $value ) {
			$this->assertTrue(
				null === $value || is_bool( $value ) || ( is_string( $value ) && strlen( $value ) <= 32 ),
				$key . ' is not a short version, locale or flag.'
			);
		}
	}

	/**
	 * After an update the next sync reports the environment as it is then,
	 * never one remembered from an earlier push. The locale stands in for a
	 * version here, because it is the one part of it a test can change.
	 *
	 * @return void
	 */
	public function test_each_push_reports_the_environment_as_it_is_now() {
		$this->queueAcceptedPush();
		$this->assertTrue( Send4WC_Store_Sync::push_inventory() );

		$locale = static function () {
			return 'lt_LT';
		};
		add_filter( 'locale', $locale );

		try {
			$this->http->queueStoreSaved();
			$this->assertTrue( Send4WC_Store_Sync::push_inventory() );
		} finally {
			remove_filter( 'locale', $locale );
		}

		$requests = $this->http->requestsTo( '/api/integrations/wordpress/store' );
		$this->assertCount( 2, $requests );
		$this->assertSame( 'lt_LT', $requests[1]['body']['environment']['locale'] );
	}
}
