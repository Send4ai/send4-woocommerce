<?php
/**
 * The translation template points at files that exist, and is this plugin's.
 *
 * @package Send4WC
 */

/**
 * A translator opens a string's source reference to see where it is used.
 * The forms plugin's `make pot` used to mount the repo at /app, and make-pot
 * makes a path relative with a plain str_replace of its source directory, so
 * the "/app" inside admin/views/partials/app-bar.php went too: its 0.1.4
 * shipped a template that filed all of that file's strings under a
 * partials-bar.php that does not exist. Nothing failed, which is why this
 * does.
 *
 * Line numbers are left alone on purpose. They drift with every edit, and a
 * template that is a few lines out costs a translator a moment, not a wrong
 * file.
 */
class Send4WC_Pot_References_Test extends Send4WC_Test_Case {

	/**
	 * The template, relative to the repo root.
	 */
	const POT = 'languages/send4-for-woocommerce.pot';

	/**
	 * The template's text.
	 *
	 * @return string
	 */
	private function pot() {
		$path = dirname( __DIR__, 2 ) . '/' . self::POT;

		$this->assertFileExists( $path, self::POT . ' is missing. Generate it with make-pot.' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return (string) file_get_contents( $path );
	}

	/**
	 * Every "#: file:line" names a file in the repo.
	 *
	 * @return void
	 */
	public function test_every_source_reference_names_a_file_that_exists() {
		$root = dirname( __DIR__, 2 );

		preg_match_all( '/^#: (.+)$/m', $this->pot(), $matches );

		$files = array();

		foreach ( $matches[1] as $line ) {
			// gettext allows several references on one line, a space apart.
			foreach ( preg_split( '/\s+/', trim( $line ) ) as $reference ) {
				$files[ (string) preg_replace( '/:\d+$/', '', (string) $reference ) ] = true;
			}
		}

		$this->assertNotEmpty( $files, 'No source references found, so this test would prove nothing.' );

		$missing = array();

		foreach ( array_keys( $files ) as $file ) {
			if ( ! is_file( $root . '/' . $file ) ) {
				$missing[] = $file;
			}
		}

		$this->assertSame(
			array(),
			$missing,
			self::POT . ' names files that are not in the repo. Regenerate it with make-pot and commit the result.'
		);
	}

	/**
	 * This plugin was copied from the forms plugin, whose template is made for
	 * the `send4` text domain. A template made for that domain carries none of
	 * this plugin's strings, and nothing would fail while it shipped.
	 *
	 * @return void
	 */
	public function test_the_template_is_for_this_plugins_text_domain() {
		$pot = $this->pot();

		$this->assertStringContainsString( '"X-Domain: send4-for-woocommerce\n"', $pot );
		$this->assertStringContainsString( '"Project-Id-Version: Send4 for WooCommerce ', $pot );
		$this->assertStringContainsString( 'msgid "Order events"', $pot, 'The screen\'s own strings should be in it.' );
		$this->assertStringContainsString( '#: admin/views/partials/app-bar.php', $pot, 'The app bar\'s strings must be filed under the app bar.' );
	}
}
