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
	 * [--types=<types>]
	 * : Limit the process to specific post types.
	 *
	 * [--start=<id>]
	 * : Start the process at a specific post ID.
	 *
	 * [--dry-run]
	 * : Run the process without modifications.
	 *
	 * [--force]
	 * : Run the process even if a post has been checked in the last 30 days.
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
		if ( ! empty( $assoc_args['types'] ) ) {
			$query .= ' AND post_type IN(\''
					. implode(
						'\',\'',
						array_map(
							'sanitize_title',
							explode(
								',',
								$assoc_args['types']
							)
						)
					) . '\')';
		}
		if ( ! empty( $assoc_args['start'] ) ) {
			$query .= $wpdb->prepare( ' AND ID >=%d', $assoc_args['start'] );
		}
		$query .= ' ORDER BY ID ASC';

		WP_CLI::log( sprintf( 'Starting post scan%s at %s...', ! empty( $assoc_args['dry-run'] ) ? ' with dry run' : '', gmdate( 'Y-m-d H:i:s' ) ) );
		$post_content_url_count = 0;
		$post_excerpt_url_count = 0;
		foreach (
			new \WP_CLI\Iterators\Query( $query, 1000 ) as $i => $post
		) {
			if ( ! empty( $assoc_args['limit'] ) && ( $i + 1 ) >= $assoc_args['limit'] ) {
				break;
			}

			if ( $i && 0 === $i % 100 ) {
				\WP_CLI\Utils\wp_clear_object_cache();
			}

			$last_check = get_post_meta( $post->ID, self::LAST_CHECK_META_KEY, true );
			if ( $last_check
				&& empty( $assoc_args['force'] )
				&& ( strtotime( $last_check ) + ( 30 * DAY_IN_SECONDS ) ) > time() ) {
				continue;
			}

			$updated_data = [];
			foreach ( [ 'post_content', 'post_excerpt' ] as $post_field ) {
				if ( false === stripos( $post->{$post_field}, 'http' ) ) {
					continue;
				}
				$callback = function( $matches ) use ( $post, $post_field ) {
					$return      = $matches[0];
					$url         = $matches['url'];
					$status_code = $this->get_url_http_status( $url );
					WP_CLI::log( "{$post->ID}, {$post_field}, {$url}, {$status_code}" );
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
							WP_CLI::log( " - Removed {$post_field} URL." );
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
				$content  = $post->{$post_field};
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
				if ( $content !== $post->{$post_field} ) {
					$increment = "{$post_field}_url_count";
					$$increment++;
					$updated_data[ $post_field ] = $content;
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
		$complete_time = gmdate( 'Y-m-d H:i:s' );
		WP_CLI::success( "Post scan complete at {$complete_time}. {$post_content_url_count} content URLs updated; {$post_excerpt_url_count} excerpt URLs updated." );
	}
}
