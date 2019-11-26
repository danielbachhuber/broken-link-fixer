<?php

namespace Broken_Link_Fixer\CLI;

class Base {

	/**
	 * Regex to match links.
	 *
	 * @var string
	 */
	const LINK_MATCH_REGEX = '#<a[^>]*href=[\'"](?<url>[^\'"]+)[\'"][^>]*>(?<text>[^<]+)</a>#';

	/**
	 * Regex to match standalone URLs.
	 *
	 * @var string
	 */
	const STANDALONE_URL_MATCH_REGEX = '#(^|\s)(?<url>https?[^\s]+)(\s|$)#';

	/**
	 * URLs that have already been resolved, with their destination.
	 *
	 * @var array
	 */
	protected $resolved_url_redirects = [];

	/**
	 * URLs that have already been checked, with their status.
	 *
	 * @var array
	 */
	protected $url_status_codes = [];

	/**
	 * Gets the HTTP status for the URL.
	 *
	 * @param string $url URL to check.
	 * @return integer|false
	 */
	protected function get_url_http_status( $url ) {
		if ( isset( $this->url_status_codes[ $url ] ) ) {
			return $this->url_status_codes[ $url ];
		}

		$response    = wp_remote_head( $url );
		$status_code = false;
		if ( is_wp_error( $response ) ) {
			if ( false !== stripos( $response->get_error_message(), 'cURL error 6: Could not resolve:' ) ) {
				$status_code = 404;
			}
		} else {
			$status_code = wp_remote_retrieve_response_code( $response );
		}
		$this->url_status_codes[ $url ] = $status_code;
		return $this->url_status_codes[ $url ];
	}

	/**
	 * Resolves a given URL to the end destination of its redirects.
	 *
	 * @param string $url URL to check.
	 * @return string|false
	 */
	protected function get_url_redirect_destination( $url ) {
		if ( isset( $this->resolved_url_redirects[ $url ] ) ) {
			return $this->resolved_url_redirects[ $url ];
		}
		$response = wp_remote_head( $url, [ 'redirection' => 5 ] );
		if ( is_wp_error( $response ) ) {
			$resolved_url = false;
		} else {
			$resolved_url = $response['http_response']->get_response_object()->url;
		}
		$this->resolved_url_redirects[ $url ] = $resolved_url;
		return $this->resolved_url_redirects[ $url ];
	}


}
