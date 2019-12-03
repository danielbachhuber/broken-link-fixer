<?php
/**
 * Scans comment author urls and content for their status codes.
 *
 * @package broken-link-fixer
 */

namespace Broken_Link_Fixer\CLI;

use WP_CLI;

/**
 * Scans comment author urls and content for their status codes.
 */
class Comments extends Base {

	/**
	 * Scans comment author urls and content for their status codes.
	 *
	 * ## OPTIONS
	 *
	 * [--ids=<comment-ids>]
	 * : Limit process to specific comment IDs.
	 *
	 * [--start=<id>]
	 * : Start the process at a specific comment ID.
	 *
	 * [--posts=<post-ids>]
	 * : Limit process to specific post IDs.
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
			$query .= ' AND comment_ID IN('
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
		if ( ! empty( $assoc_args['start'] ) ) {
			$query .= $wpdb->prepare( ' AND comment_ID >=%d', $assoc_args['start'] );
		}
		if ( ! empty( $assoc_args['posts'] ) ) {
			$query .= ' AND comment_post_ID IN('
					. implode(
						',',
						array_map(
							'intval',
							explode(
								',',
								$assoc_args['posts']
							)
						)
					) . ')';
		}
		$query .= ' ORDER BY comment_ID ASC';

		WP_CLI::log( sprintf( 'Starting comment scan%s...', ! empty( $assoc_args['dry-run'] ) ? ' with dry run' : '' ) );
		$author_url_count  = 0;
		$content_url_count = 0;
		foreach (
			new \WP_CLI\Iterators\Query( $query, 1000 ) as $i => $comment
		) {
			if ( ! empty( $assoc_args['limit'] ) && ( $i + 1 ) >= $assoc_args['limit'] ) {
				break;
			}

			$last_check = get_comment_meta( $comment->comment_ID, self::LAST_CHECK_META_KEY, true );
			if ( $last_check
				&& ( strtotime( $last_check ) + ( 30 * DAY_IN_SECONDS ) ) > time() ) {
				continue;
			}

			$updated_data = [];
			if ( ! empty( $comment->comment_author_url ) ) {
				$url         = $comment->comment_author_url;
				$status_code = $this->get_url_http_status( $url );
				WP_CLI::log( "{$comment->comment_ID}, comment_author_url, {$url}, {$status_code}" );
				switch ( $status_code ) {
					case 301:
						$resolved_url = $this->get_url_redirect_destination( $url );
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
						$url = '';
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
							$resolved_url = $this->get_url_redirect_destination( $url );
							if ( ! empty( $resolved_url ) ) {
								WP_CLI::log( " - Replaced with: {$resolved_url}" );
								$return = str_replace( $url, $resolved_url, $return );
							} else {
								WP_CLI::log( ' - No target found for redirected URL; URL removed.' );
								$return = isset( $matches['text'] ) ? $matches['text'] : '';
								if ( isset( $matches['before'] ) ) {
									$return = $matches['before'] . $return;
								}
								if ( isset( $matches['after'] ) ) {
									$return = $return . $matches['after'];
								}
							}
							break;
						case 404:
							WP_CLI::log( ' - Removed content URL.' );
							$return = isset( $matches['text'] ) ? $matches['text'] : '';
							if ( isset( $matches['before'] ) ) {
								$return = $matches['before'] . $return;
							}
							if ( isset( $matches['after'] ) ) {
								$return = $return . $matches['after'];
							}
							break;
					}
					return $return;
				};
				$content  = $comment->comment_content;
				$content  = preg_replace_callback(
					self::LINK_MATCH_REGEX,
					$callback,
					$content
				);
				$content  = preg_replace_callback(
					self::STANDALONE_URL_MATCH_REGEX,
					$callback,
					$content
				);
				if ( $content !== $comment->comment_content ) {
					$content_url_count++;
					$updated_data['comment_content'] = $content;
				}
			}

			if ( empty( $assoc_args['dry-run'] ) ) {
				if ( ! empty( $updated_data ) ) {
					$updated_data['comment_ID'] = $comment->comment_ID;
					wp_update_comment( $updated_data );
				}
				update_comment_meta(
					$comment->comment_ID,
					self::LAST_CHECK_META_KEY,
					gmdate( 'Y-m-d H:i:s' )
				);
			}
		}
		WP_CLI::success( "Comment scan complete. {$author_url_count} author URLs updated; {$content_url_count} content URLs updated." );
	}
}
