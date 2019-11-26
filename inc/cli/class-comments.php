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
	 * [--dry-run]
	 * : Run the process without modifications.
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
		$author_url_count  = 0;
		$content_url_count = 0;
		foreach (
			new \WP_CLI\Iterators\Query( $query, 1000 ) as $i => $comment
		) {

			if ( ! empty( $assoc_args['limit'] ) && ( $i + 1 ) >= $assoc_args['limit'] ) {
				break;
			}

			// TODO skip if checked in the last 30 days

			$updated_data = [];
			if ( ! empty( $comment->comment_author_url ) ) {
				$url         = $comment->comment_author_url;
				$status_code = $this->get_url_http_status( $url );
				WP_CLI::log( "{$comment->comment_ID}, comment_author_url, {$url}, {$status_code}" );
				switch ( $status_code ) {
					case 301:
						$resolved_url  = $this->get_url_redirect_destination( $url );
						if ( ! empty( $resolved_url ) ) {
							WP_CLI::log( " - Replaced with: {$resolved_url}" );
							$url = $resolved_url;
						} else {
							WP_CLI::log( ' - No target found for redirected URL.' );
							$url = '';
						}
						break;
					case 404:
						WP_CLI::log( ' - Removed author URL.' );
						break;

				}
				if ( $url !== $comment->comment_author_url ) {
					$author_url_count++;
					$updated_data['comment_author_url'] = $url;
				}
			}

			if ( false !== stripos( $comment->comment_content, 'http' ) ) {
				$callback = function( $matches ) use ( $comment ) {
					$return      = $matches[0];
					$url         = $matches['url'];
					$status_code = $this->get_url_http_status( $url );
					WP_CLI::log( "{$comment->comment_ID}, comment_content, {$url}, {$status_code}" );
					switch ( $status_code ) {
						case 301:
							$resolved_url  = $this->get_url_redirect_destination( $url );
							if ( ! empty( $resolved_url ) ) {
								WP_CLI::log( " - Replaced with: {$resolved_url}" );
								$return = str_replace( $url, $resolved_url, $return );
							} else {
								WP_CLI::log( ' - No target found for redirected URL.' );
								$return = isset( $matches['text'] ) ? $matches['text'] : '';
							}
							break;
						case 404:
							WP_CLI::log( ' - Removed content URL.' );
							$return = isset( $matches['text'] ) ? $matches['text'] : '';
							break;
					}
					return $return;
				};
				$content = $comment->comment_content;
				$content = preg_replace_callback(
					self::LINK_MATCH_REGEX,
					$callback,
					$content
				);
				$content = preg_replace_callback(
					self::STANDALONE_URL_MATCH_REGEX,
					$callback,
					$content
				);
				if ( $content !== $comment->comment_content ) {
					$content_url_count++;
					$updated_data['comment_content'] = $content;
				}
			}

			if ( ! empty( $updated_data ) && empty( $assoc_args['dry-run'] ) ) {
				$updated_data['comment_ID'] = $comment->comment_ID;
				wp_update_comment( $updated_data );
			}

		}
		WP_CLI::success( "Comment scan complete. {$author_url_count} author URLs updated; {$content_url_count} content URLs updated." );
	}
}
