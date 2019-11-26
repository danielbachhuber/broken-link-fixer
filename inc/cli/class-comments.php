<?php

namespace Broken_Link_Fixer\CLI;

use WP_CLI;

class Comments extends Base {

	/**
	 * Scans comment author urls and content for their status codes.
	 *
	 * ## OPTIONS
	 *
	 * [--ids=<comment-ids>]
	 * : Limit process to specific comment IDs.
	 *
	 * [--limit=<limit>]
	 * : Only perform a certain number of replacements.
	 */
	public function __invoke( $args, $assoc_args ) {
		global $wpdb;

		$query = "SELECT * FROM {$wpdb->comments} WHERE comment_approved=1";
		if ( ! empty( $assoc_args['ids'] ) ) {
			$query .= " AND comment_ID IN("
					. implode(
						',',
						array_map(
							'intval',
							explode(
								',',
								$assoc_args['ids']
							)
						)
					) . ')';
		}
		$query .= ' ORDER BY comment_ID ASC';

		WP_CLI::log( 'Starting comment scan...' );
		foreach (
			new \WP_CLI\Iterators\Query( $query, 1000 ) as $i => $comment
		) {

			if ( ! empty( $assoc_args['limit'] ) && ( $i + 1 ) >= $assoc_args['limit'] ) {
				break;
			}

			// TODO skip if checked in the last 30 days

			if ( ! empty( $comment->comment_author_url ) ) {
				$url         = $comment->comment_author_url;
				$status_code = $this->get_url_http_status( $url );
				WP_CLI::log( "{$comment->comment_ID}, comment_author, {$url}, {$status_code}" );
				switch ( $status_code ) {
					case 301:
						$resolved_url  = $this->get_url_redirect_destination( $url );
						if ( ! empty( $resolved_url ) ) {
							WP_CLI::log( " - Replaced with: {$resolved_url}" );
						} else {
							WP_CLI::log( ' - No target found for redirected URL.' );
						}
						break;
					case 404:
						WP_CLI::log( ' - Removed author URL.' );
						break;

				}
			}

			// Check comment content.
		}
		WP_CLI::success( 'Comment scan complete.' );
	}
}
