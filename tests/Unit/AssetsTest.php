<?php
/**
 * Asset URLs that survive a cache which ignores `?ver=`.
 *
 * @package Send4WC
 */

/**
 * Send4WC_Assets. The admin stylesheet is the plugin's only asset, and
 * a CDN that ignores the query string would otherwise go on serving the
 * previous release's styles to the Send4 screen after an update.
 */
class Send4WC_Assets_Test extends Send4WC_Test_Case {

	/**
	 * The version goes ahead of the extension, so the file keeps its type.
	 *
	 * @return void
	 */
	public function test_the_version_goes_into_the_file_name() {
		$this->assertSame( 'admin/css/admin.0.1.0.css', Send4WC_Assets::versioned( 'admin/css/admin.css', '0.1.0' ) );
		$this->assertSame( 'admin/js/screen.min.0.1.0.js', Send4WC_Assets::versioned( 'admin/js/screen.min.js', '0.1.0' ) );
	}

	/**
	 * A path with no extension has nowhere to put the version, so it is left
	 * as it is rather than mangled.
	 *
	 * @return void
	 */
	public function test_a_path_without_an_extension_is_left_alone() {
		$this->assertSame( 'LICENSE', Send4WC_Assets::versioned( 'LICENSE', '0.1.0' ) );
	}

	/**
	 * A built plugin has the versioned copy and serves it; a checkout does not,
	 * and serves the file as it is.
	 *
	 * @return void
	 */
	public function test_the_versioned_copy_is_served_only_where_it_exists() {
		$dir = trailingslashit( get_temp_dir() ) . 'send4wc-assets-' . wp_generate_password( 8, false ) . '/';

		wp_mkdir_p( $dir . 'admin/css' );

		$this->assertSame( 'admin/css/admin.css', Send4WC_Assets::resolve( 'admin/css/admin.css', $dir, '0.1.0' ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $dir . 'admin/css/admin.0.1.0.css', '' );

		$this->assertSame( 'admin/css/admin.0.1.0.css', Send4WC_Assets::resolve( 'admin/css/admin.css', $dir, '0.1.0' ) );

		// Another release's copy is not this release's.
		$this->assertSame( 'admin/css/admin.css', Send4WC_Assets::resolve( 'admin/css/admin.css', $dir, '0.1.1' ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $dir . 'admin/css/admin.0.1.0.css' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( $dir . 'admin/css' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( $dir . 'admin' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( $dir );
	}

	/**
	 * url() resolves against the plugin's own directory and version, so this
	 * checkout, which ships no versioned copies, serves the stylesheet the
	 * Send4 screen enqueues as it is.
	 *
	 * @return void
	 */
	public function test_the_url_is_the_plugins_own_file() {
		$this->assertFileExists( SEND4WC_PLUGIN_DIR . 'admin/css/admin.css' );
		$this->assertFileDoesNotExist( SEND4WC_PLUGIN_DIR . Send4WC_Assets::versioned( 'admin/css/admin.css', SEND4WC_VERSION ) );

		$this->assertSame( SEND4WC_PLUGIN_URL . 'admin/css/admin.css', Send4WC_Assets::url( 'admin/css/admin.css' ) );
	}
}
