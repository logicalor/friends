<?php
/**
 * Friends jsonfeed Parser
 *
 * With this parser, we can import RSS and Atom Feeds for a friend.
 *
 * @package Friends
 */

/**
 * This is the class for the feed part of the Friends Plugin.
 *
 * @since 1.0
 *
 * @package Friends
 * @author Alex Kirk
 */
class Friends_Feed_Parser_JSON_Feed extends Friends_Feed_Parser {
	const NAME = 'JSON Feed';
	const URL = 'https://www.jsonfeed.org/';

	/**
	 * Determines if this is a supported feed.
	 *
	 * @param      string $url        The url.
	 * @param      string $mime_type  The mime type.
	 * @param      string $title      The title.
	 *
	 * @return     boolean  True if supported feed, False otherwise.
	 */
	public function is_supported_feed( $url, $mime_type, $title ) {
		$rewritten = $this->rewrite_known_url( $url );
		if ( $rewritten ) {
			$mime_type = $rewritten['type'];
		}

		switch ( $mime_type ) {
			case 'application/feed+json':
				return true;
		}

		return false;
	}

	/**
	 * Rewrite known URLs to their RSS feeds.
	 *
	 * @param      string $url    The url.
	 *
	 * @return     array  An equivalent link array.
	 */
	public function rewrite_known_url( $url ) {
		$host = parse_url( strtolower( $url ), PHP_URL_HOST );

		switch ( $host ) {
			case 'micro.blog':
				if ( preg_match( '#/([^/]+)$#', $url, $m ) ) {
					return array(
						'title' => 'Micro.blog: ' . $m[1],
						'rel'   => 'alternate',
						'type'  => 'application/feed+json',
						'url'   => 'https://micro.blog/posts/' . $m[1],
					);
				}
				return array();
		}

		return array();
	}

	/**
	 * Format the feed title and autoselect the posts feed.
	 *
	 * @param      array $feed_details  The feed details.
	 *
	 * @return     array  The (potentially) modified feed details.
	 */
	public function update_feed_details( $feed_details ) {
		$rewritten = $this->rewrite_known_url( $feed_details['url'] );
		if ( $rewritten ) {
			$feed_details = $rewritten;
		}

		return $feed_details;
	}

	/**
	 * Discover the feeds available at the URL specified.
	 *
	 * @param      string $content  The content for the URL is already provided here.
	 * @param      string $url      The url to search.
	 *
	 * @return     array  A list of supported feeds at the URL.
	 */
	public function discover_available_feeds( $content, $url ) {
		return array();
	}

	/**
	 * Fetches a feed and returns the processed items.
	 *
	 * @param      string $url        The url.
	 *
	 * @return     array            An array of feed items.
	 */
	public function fetch_feed( $url ) {
		$args = array();
		$res = wp_safe_remote_request( $url, $args );

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$body = wp_remote_retrieve_body( $res );
		$json = json_decode( $body );

		$feed_items = array();
		foreach ( $json->items as $item ) {
			$feed_item = (object) array(
				'permalink' => $item->url,
			);
			if ( isset( $item->content_html ) ) {
				$feed_item->content = $item->content_html;
			} elseif ( isset( $item->content_text ) ) {
				$feed_item->content = $item->content_text;
			}

			if ( isset( $item->title ) ) {
				$feed_item->title = $item->title;
			}
			if ( isset( $item->date_published ) ) {
				$feed_item->date = gmdate( 'Y-m-d H:i:s', strtotime( $item->date_published ) );
			}
			if ( isset( $item->date_modified ) ) {
				$feed_item->updated_date = gmdate( 'Y-m-d H:i:s', strtotime( $item->date_modified ) );
				if ( ! isset( $feed_item->date ) ) {
					$feed_item->date = $feed_item->updated_date;
				}
			}
			$feed_items[] = $feed_item;
		}

		return $feed_items;
	}
}