<?php
/**
 * Scans links in post content for their status codes.
 *
 * @package broken-link-fixer
 */

namespace Broken_Link_Fixer\CLI;

use WP_CLI;

/**
 * Scans links in post content for their status codes.
 */
class Posts extends Base {

	/**
	 * Scans links in post content for their status codes.
	 *
	 * ## OPTIONS
	 *
	 * [--ids=<ids>]
	 * : Limit process to specific post IDs.
	 *
	 * [--start=<id>]
	 * : Start the process at a specific post ID.
	 *
	 * [--dry-run]
	 * : Run the process without modifications.
	 *
	 * [--limit=<limit>]
	 * : Only perform a certain number of replacements.
	 */
	public function __invoke( $args, $assoc_args ) {
		global $wpdb;

		$query = "SELECT * FROM {$wpdb->posts} WHERE post_status='publish'";
		if ( ! empty( $assoc_args['ids'] ) ) {
			$query .= ' AND ID IN('
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
			$query .= $wpdb->prepare( ' AND ID >=%d', $assoc_args['start'] );
		}
		$query .= ' ORDER BY ID ASC';

		WP_CLI::log( sprintf( 'Starting post scan%s...', ! empty( $assoc_args['dry-run'] ) ? ' with dry run' : '' ) );
		$content_url_count = 0;
		foreach (
			new \WP_CLI\Iterators\Query( $query, 1000 ) as $i => $post
		) {
			if ( ! empty( $assoc_args['limit'] ) && ( $i + 1 ) >= $assoc_args['limit'] ) {
				break;
			}

			$last_check = get_post_meta( $post->ID, self::LAST_CHECK_META_KEY, true );
			if ( $last_check
				&& ( strtotime( $last_check ) + ( 30 * DAY_IN_SECONDS ) ) > time() ) {
				continue;
			}

			$updated_data = [];
			if ( false !== stripos( $post->post_content, 'http' ) ) {
				$callback = function( $matches ) use ( $post ) {
					$return      = $matches[0];
					$url         = $matches['url'];
					$status_code = $this->get_url_http_status( $url );
					WP_CLI::log( "{$post->ID}, post_content, {$url}, {$status_code}" );
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
				$content  = $post->post_content;
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
				if ( $content !== $post->post_content ) {
					$content_url_count++;
					$updated_data['post_content'] = $content;
				}
			}

			if ( empty( $assoc_args['dry-run'] ) ) {
				if ( ! empty( $updated_data ) ) {
					$updated_data['ID'] = $post->ID;
					wp_update_post( $updated_data );
				}
				update_post_meta(
					$post->ID,
					self::LAST_CHECK_META_KEY,
					gmdate( 'Y-m-d H:i:s' )
				);
			}
		}
		WP_CLI::success( "Post scan complete. {$content_url_count} content URLs updated." );
	}
}
