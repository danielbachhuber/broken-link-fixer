<?php
/**
 * Plugin Name:     Broken Link Fixer
 * Plugin URI:      PLUGIN SITE HERE
 * Description:     PLUGIN DESCRIPTION HERE
 * Author:          YOUR NAME HERE
 * Author URI:      YOUR SITE HERE
 * Text Domain:     broken-link-fixer
 * Domain Path:     /languages
 * Version:         0.1.0
 *
 * @package         Broken_Link_Fixer
 */

require_once __DIR__ . '/autoload.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'broken-link-fixer comments', 'Broken_Link_Fixer\CLI\Comments' );
}
