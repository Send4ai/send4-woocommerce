<?php
/**
 * A client secret is checked before it is tried.
 *
 * @package Send4WC
 */

require_once dirname( __DIR__ ) . '/Support/WpCliDouble.php';

/**
 * `wp send4wc connect` is the one place a secret is typed in by hand: the
 * Connect button receives its credential from Send4 itself. Send4 secrets are
 * `secret_` and 48 letters and digits, so anything that could not be one — a
 * space or a tab from a careless paste, a truncated copy, markup — is refused
 * with a message rather than sent. It never spends one of the ten sign-ins a
 * minute Send4 allows, and never gets near the credential that works.
 */
class Send4WC_Connect_Secret_Test extends Send4WC_Test_Case {

	/**
	 * A made-up secret in the shape Send4 issues.
	 *
	 * @var string
	 */
	const REAL_SECRET = 'secret_NotARealSecretNotARealSecretNotARealSecret000000';

	/**
	 * Start every test with no recorded output.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		WP_CLI::$output = array();
	}

	/**
	 * Run `wp send4wc connect` and return the error it stopped with, '' when
	 * it did not stop.
	 *
	 * @param string $client_id     --client-id.
	 * @param string $client_secret --client-secret, as typed.
	 * @param array  $flags         Other flags.
	 * @return string
	 */
	private function connect( $client_id, $client_secret, array $flags = array() ) {
		try {
			( new Send4WC_Cli() )->connect(
				array(),
				array_merge(
					array(
						'client-id'     => $client_id,
						'client-secret' => $client_secret,
					),
					$flags
				)
			);
		} catch ( Send4WC_Cli_Halted $halted ) {
			return $halted->getMessage();
		}

		return '';
	}

	/**
	 * A real secret reaches Send4 exactly as it was typed.
	 *
	 * @return void
	 */
	public function test_a_send4_secret_is_tried_unchanged() {
		$this->http->queueTokenRejected( 401 );

		$this->connect( 'client_new', self::REAL_SECRET );

		$requests = $this->http->requests();
		$this->assertNotEmpty( $requests, 'The secret was tried.' );
		$this->assertStringEndsWith( '/api/v2/auth/token', $requests[0]['url'] );
		$this->assertSame( self::REAL_SECRET, $requests[0]['body']['client_secret'] );
	}

	/**
	 * Things that cannot be a Send4 secret.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function notASecretProvider() {
		return array(
			'a space inside'  => array( 'secret_NotARealSecret NotARealSecretNotARealSecret000000' ),
			'a tab inside'    => array( "secret_NotARealSecret\tNotARealSecretNotARealSecret000000" ),
			'too short'       => array( 'secret_abc' ),
			'punctuation'     => array( 'secret_NotARealSecret!NotARealSecretNotARealSecret000000' ),
			'a non-ASCII one' => array( 'secret_NotARealSecretąNotARealSecretNotARealSecret000000' ),
			'markup'          => array( '<b>secret_NotARealSecretNotARealSecretNotARealSecret000000</b>' ),
		);
	}

	/**
	 * Anything else is refused with a message and never sent to Send4, and
	 * neither the working connection nor its destination moves.
	 *
	 * @dataProvider notASecretProvider
	 *
	 * @param string $typed What was typed.
	 * @return void
	 */
	public function test_something_that_is_not_a_secret_is_refused( $typed ) {
		$before = Send4WC_Settings::connection();

		$error = $this->connect( 'client_new', $typed, array( 'url-base' => 'https://staging.send4.ai' ) );

		$this->assertStringContainsString( 'That is not a Send4 client secret.', $error );
		$this->assertSame( array(), $this->http->requests(), 'Nothing is sent to Send4.' );
		$this->assertSame( $before, Send4WC_Settings::connection(), 'The working connection is left as it was.' );
		$this->assertFalse( get_option( Send4WC_Settings::BASE_URL_OPTION, false ), 'And so is where it points.' );
	}

	/**
	 * An empty secret is refused as missing. It is never filled in from the
	 * saved one, which would prove a pair nobody typed.
	 *
	 * @return void
	 */
	public function test_an_empty_secret_is_refused_as_missing() {
		$error = $this->connect( 'client_new', '' );

		$this->assertStringContainsString( 'Both --client-id and --client-secret are required.', $error );
		$this->assertSame( array(), $this->http->requests() );
		$this->assertSame( 'test-client-id', Send4WC_Settings::connection()['client_id'] );
	}

	/**
	 * The rule itself.
	 *
	 * @return void
	 */
	public function test_the_shape_of_a_secret() {
		$this->assertTrue( Send4WC_Settings::is_client_secret( self::REAL_SECRET ) );
		$this->assertTrue( Send4WC_Settings::is_client_secret( 'secret_' . str_repeat( 'Z', 48 ) ) );
		$this->assertTrue( Send4WC_Settings::is_client_secret( 'future-key_0123456789' ), 'A future key with "-" still fits.' );

		$this->assertFalse( Send4WC_Settings::is_client_secret( '' ) );
		$this->assertFalse( Send4WC_Settings::is_client_secret( 'secret_short' ) );
		$this->assertFalse( Send4WC_Settings::is_client_secret( str_repeat( 'a', 256 ) ) );
		$this->assertFalse( Send4WC_Settings::is_client_secret( self::REAL_SECRET . "\n" ) );
		$this->assertFalse( Send4WC_Settings::is_client_secret( null ) );
		$this->assertFalse( Send4WC_Settings::is_client_secret( array( self::REAL_SECRET ) ) );
	}
}
