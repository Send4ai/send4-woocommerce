<?php
/**
 * Hand-written WP-CLI utility stubs, for static analysis only.
 *
 * A separate file from wp-cli.php because a namespace declaration has to be the
 * first statement in its file, and WP_CLI itself lives in the global namespace.
 *
 * @see https://make.wordpress.org/cli/handbook/references/internal-api/
 * @package Send4WC
 */

namespace WP_CLI\Utils;

/**
 * Render a list of rows in the requested format.
 *
 * The first argument is the format name ("table", "json", "csv", "yaml"), not
 * the command's associative arguments.
 *
 * @param string                         $format Output format.
 * @param array<int,array<string,mixed>> $items  Rows.
 * @param array<int,string>|string       $fields Columns.
 * @return void
 */
function format_items( $format, $items, $fields ) {}
