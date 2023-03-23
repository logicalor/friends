<?php
/**
 * Friends ActivityPub Parser
 *
 * With this parser, we can talk to ActivityPub.
 *
 * @since @2.1.4
 *
 * @package Friends
 * @author Alex Kirk
 */

namespace Friends;

use WP_Error;

/**
 * This is the class for integrating ActivityPub into the Friends Plugin.
 */
class Feed_Parser_ActivityPub extends Feed_Parser_V2 {
	const SLUG = 'activitypub';
	const NAME = 'ActivityPub';
	const URL = 'https://www.w3.org/TR/activitypub/';
	const ACTIVITYPUB_USERNAME_REGEXP = '(?:([A-Za-z0-9_-]+)@((?:[A-Za-z0-9_-]+\.)+[A-Za-z]+))';

	private $friends_feed;

	/**
	 * Constructor.
	 *
	 * @param      Feed $friends_feed  The friends feed.
	 */
	public function __construct( Feed $friends_feed ) {
		$this->friends_feed = $friends_feed;

		\add_action( 'init', array( $this, 'register_post_meta' ) );
		\add_action( 'admin_menu', array( $this, 'admin_menu' ), 20 );
		\add_filter( 'feed_item_allow_set_metadata', array( $this, 'feed_item_allow_set_metadata' ), 10, 3 );

		\add_action( 'activitypub_inbox', array( $this, 'handle_received_activity' ), 10, 3 );
		\add_action( 'friends_user_feed_activated', array( $this, 'queue_follow_user' ), 10 );
		\add_action( 'friends_user_feed_deactivated', array( $this, 'queue_unfollow_user' ), 10 );
		\add_action( 'friends_feed_parser_activitypub_follow', array( $this, 'follow_user' ), 10, 2 );
		\add_action( 'friends_feed_parser_activitypub_unfollow', array( $this, 'unfollow_user' ), 10, 2 );
		\add_action( 'friends_feed_parser_activitypub_like', array( $this, 'like_post' ), 10, 3 );
		\add_action( 'friends_feed_parser_activitypub_unlike', array( $this, 'unlike_post' ), 10, 3 );
		\add_filter( 'friends_rewrite_incoming_url', array( $this, 'friends_webfinger_resolve' ), 10, 2 );

		\add_filter( 'friends_edit_friend_table_end', array( $this, 'activitypub_settings' ), 10 );
		\add_filter( 'friends_edit_friend_after_form_submit', array( $this, 'activitypub_save_settings' ), 10 );
		\add_filter( 'friends_modify_feed_item', array( $this, 'modify_incoming_item' ), 9, 3 );
		\add_filter( 'friends_edit_friend_after_avatar', array( $this, 'admin_show_update_avatar' ) );
		\add_filter( 'friends_suggest_user_login', array( $this, 'suggest_user_login' ), 10, 2 );

		\add_filter( 'the_content', array( $this, 'the_content' ), 99, 2 );
		\add_filter( 'activitypub_extract_mentions', array( $this, 'activitypub_extract_mentions' ), 10, 2 );

		\add_action( 'friends_user_post_reaction', array( $this, 'post_reaction' ) );
		\add_action( 'friends_user_post_undo_reaction', array( $this, 'undo_post_reaction' ) );

		\add_filter( 'pre_comment_approved', array( $this, 'pre_comment_approved' ), 10, 2 );

		\add_filter( 'friends_reblog_button_label', array( $this, 'friends_reblog_button_label' ), 10, 2 );
		// Don't post via ActivityPub since we'll use announce there.
		\add_filter( 'friends_reblog', array( $this, 'unqueue_activitypub_create' ), 9 );
		\add_filter( 'friends_reblog', array( $this, 'reblog' ), 20, 2 );
		\add_filter( 'friends_reblog', array( $this, 'maybe_unqueue_friends_reblog_post' ), 9, 2 );

		\add_filter( 'pre_get_remote_metadata_by_actor', array( $this, 'disable_webfinger_for_example_domains' ), 9, 2 );

		add_filter( 'friends_get_feed_metadata', array( $this, 'friends_get_feed_metadata' ), 10, 2 );
		add_filter( 'friends_get_activitypub_metadata', array( $this, 'friends_activitypub_metadata' ), 10, 2 );
	}

	/**
	 * Add the admin menu to the sidebar.
	 */
	public function admin_menu() {
		$friends = Friends::get_instance();
		$unread_badge = $friends->admin->get_unread_badge();

		$menu_title = __( 'Friends', 'friends' ) . $unread_badge;
		$page_type = sanitize_title( $menu_title );

		add_submenu_page(
			'friends',
			__( 'ActivityPub', 'friends' ),
			__( 'ActivityPub', 'friends' ),
			Friends::required_menu_role(),
			'friends-activitypub-settings',
			array( $this, 'settings' )
		);

		add_action( 'load-' . $page_type . '_page_friends-activitypub-settings', array( $this, 'process_settings' ) );
	}

	public function settings() {
		Friends::template_loader()->get_template_part(
			'admin/settings-header',
			null,
			array(
				'menu'   => array(
					__( 'ActivityPub Settings', 'friends' ) => 'friends-activitypub-settings',
				),
				'active' => 'friends-activitypub-settings',
				'title'  => __( 'Friends', 'friends' ),
			)
		);

		if ( isset( $_GET['updated'] ) ) {
			?>
			<div id="message" class="updated notice is-dismissible"><p><?php esc_html_e( 'Settings were updated.', 'friends' ); ?></p></div>
			<?php
		} elseif ( isset( $_GET['error'] ) ) {
			?>
			<div id="message" class="updated error is-dismissible"><p><?php esc_html_e( 'An error occurred.', 'friends' ); ?></p></div>
			<?php
		}

		Friends::template_loader()->get_template_part(
			'admin/activitypub-settings',
			null,
			array(
				'reblog' => ! get_user_option( 'friends_activitypub_dont_reblog' ),
			)
		);

		Friends::template_loader()->get_template_part( 'admin/settings-footer' );
	}

	public function process_settings() {
		if ( empty( $_POST ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'friends-activitypub-settings' ) ) {
			return;
		}

		if ( isset( $_POST['activitypub_reblog'] ) ) {
			delete_user_option( get_current_user_id(), 'friends_activitypub_dont_reblog' );
		} else {
			update_user_option( get_current_user_id(), 'friends_activitypub_dont_reblog', true );
		}
		wp_safe_redirect( add_query_arg( 'updated', 'true', admin_url( 'admin.php?page=friends-activitypub-settings' ) ) );
		exit;
	}

	public function friends_get_feed_metadata( $meta, $feed ) {
		if ( self::SLUG === $feed->get_parser() ) {
			return $this->friends_activitypub_metadata( $meta, $feed->get_url() );
		}
		return $meta;
	}

	public function friends_activitypub_metadata( $meta, $url ) {
		return array_merge( $meta, self::get_metadata( $url ) );
	}

	function register_post_meta() {
		register_post_meta(
			Friends::CPT,
			self::SLUG,
			array(
				'show_in_rest' => true,
				'single'       => true,
				'type'         => 'object',
			)
		);
	}

	function feed_item_allow_set_metadata( $verdict, $key, $value ) {
		if ( self::SLUG === $key && ! empty( $value ) ) {
			// We don't want to insert empty post meta.
			return true;
		}
		return $verdict;
	}

	/**
	 * Allow logging a message via an action.
	 *
	 * @param string $message The message to log.
	 * @param array  $objects Optional objects as meta data.
	 * @return void
	 */
	private function log( $message, $objects = array() ) {
		// Receive debug messages from the ActivityPub feed parser.
		do_action( 'friends_activitypub_log', $message, $objects );
	}

	/**
	 * Determines if this is a supported feed and to what degree we feel it's supported.
	 *
	 * @param      string      $url        The url.
	 * @param      string      $mime_type  The mime type.
	 * @param      string      $title      The title.
	 * @param      string|null $content    The content, it can't be assumed that it's always available.
	 *
	 * @return     int  Return 0 if unsupported, a positive value representing the confidence for the feed, use 10 if you're reasonably confident.
	 */
	public function feed_support_confidence( $url, $mime_type, $title, $content = null ) {
		if ( preg_match( '/^@?[^@]+@((?:[a-z0-9-]+\.)+[a-z]+)$/i', $url ) ) {
			return 10;
		}

		if ( 'application/activity+json' === $mime_type ) {
			return 10;
		}

		return 0;
	}

	/**
	 * Format the feed title and autoselect the posts feed.
	 *
	 * @param      array $feed_details  The feed details.
	 *
	 * @return     array  The (potentially) modified feed details.
	 */
	public function update_feed_details( $feed_details ) {
		$meta = $this->get_metadata( $feed_details['url'] );
		if ( ! $meta || is_wp_error( $meta ) ) {
			return $feed_details;
		}

		if ( isset( $meta['name'] ) ) {
			$feed_details['title'] = $meta['name'];
		} elseif ( isset( $meta['preferredUsername'] ) ) {
			$feed_details['title'] = $meta['preferredUsername'];
		}

		if ( isset( $meta['id'] ) ) {
			$feed_details['url'] = $meta['id'];
		}

		if ( isset( $meta['icon']['type'] ) && 'image' === strtolower( $meta['icon']['type'] ) ) {
			$feed_details['avatar'] = $meta['icon']['url'];
		}

		if ( ! empty( $meta['summary'] ) ) {
			$feed_details['description'] = $meta['summary'];
		}

		// Disable polling.
		$feed_details['interval'] = YEAR_IN_SECONDS;
		$feed_details['next-poll'] = gmdate( 'Y-m-d H:i:s', time() + YEAR_IN_SECONDS );

		return $feed_details;
	}

	/**
	 * Rewrite a Mastodon style URL @username@server to a URL via webfinger.
	 *
	 * @param      string $url           The URL to filter.
	 * @param      string $incoming_url  Potentially a mastodon identifier.
	 *
	 * @return     string  The rewritten URL.
	 */
	public function friends_webfinger_resolve( $url, $incoming_url ) {
		if ( preg_match( '/^@?' . self::ACTIVITYPUB_USERNAME_REGEXP . '$/i', $incoming_url ) ) {
			$resolved_url = \Activitypub\Webfinger::resolve( $incoming_url );
			if ( ! is_wp_error( $resolved_url ) ) {
				return $resolved_url;
			}
		}
		return $url;
	}

	public static function get_metadata( $url ) {
		if ( ! is_string( $url ) ) {
			return array();
		}

		if ( false !== strpos( $url, '@' ) && false === strpos( $url, '/' ) && preg_match( '#^https?://#', $url, $m ) ) {
			$url = substr( $url, strlen( $m[0] ) );
		}
		return \Activitypub\get_remote_metadata_by_actor( $url );
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
		$discovered_feeds = array();

		$meta = self::get_metadata( $url );
		if ( $meta && ! is_wp_error( $meta ) ) {
			$discovered_feeds[ $meta['id'] ] = array(
				'type'        => 'application/activity+json',
				'rel'         => 'self',
				'post-format' => 'status',
				'parser'      => self::SLUG,
				'autoselect'  => true,
			);
		}

		return $discovered_feeds;
	}

	/**
	 * Fetches a feed and returns the processed items.
	 *
	 * @param      string    $url        The url.
	 * @param      User_Feed $user_feed The user feed.
	 *
	 * @return     array            An array of feed items.
	 */
	public function fetch_feed( $url, User_Feed $user_feed = null ) {
		if ( $user_feed ) {
			$this->disable_polling( $user_feed );
		}

		// This is only for previwing.
		if ( wp_doing_cron() ) {
			return array();
		}

		$meta = self::get_metadata( $url );
		if ( is_wp_error( $meta ) || ! isset( $meta['outbox'] ) ) {
			return array();
		}

		$response = \Activitypub\safe_remote_get( $meta['outbox'], Friends::get_main_friend_user_id() );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new \WP_Error( 'activitypub_could_not_get_outbox_meta', null, compact( 'meta', 'url' ) );
		}

		$outbox = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! isset( $outbox['first'] ) ) {
			return new \WP_Error( 'activitypub_could_not_find_outbox_first_page', null, compact( 'url', 'meta', 'outbox' ) );
		}

		$response = \Activitypub\safe_remote_get( $outbox['first'], Friends::get_main_friend_user_id() );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new \WP_Error(
				'activitypub_could_not_get_outbox',
				null,
				array(
					'meta' => $outbox,
					$url   => $outbox['first'],
				)
			);
		}
		$outbox_page = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! isset( $outbox_page['type'] ) || 'OrderedCollectionPage' !== $outbox_page['type'] ) {
			return new \WP_Error(
				'activitypub_outbox_page_invalid_type',
				null,
				array(
					'outbox_page' => $outbox_page,
					$url          => $outbox['first'],
				)
			);
		}

		$items = array();
		foreach ( $outbox_page['orderedItems'] as $object ) {
			$type = strtolower( $object['type'] );
			$items[] = $this->process_incoming_activity( $type, $object );
		}

		return $items;
	}

	public function suggest_user_login( $login, $url ) {
		if ( preg_match( '#^https?://([^/]+)/users/([^/]+)#', $url, $m ) ) {
			return $m[2] . '-' . $m[1];
		}
		return $login;
	}
	/**
	 * Disable polling for this feed.
	 *
	 * @param User_Feed $user_feed The user feed.
	 * @return void
	 */
	private function disable_polling( User_Feed $user_feed ) {
		$user_feed->update_metadata( 'interval', YEAR_IN_SECONDS );
		$user_feed->update_metadata( 'next-poll', gmdate( 'Y-m-d H:i:s', time() + YEAR_IN_SECONDS ) );
	}

	/**
	 * Handles "Create" requests
	 *
	 * @param  array  $object  The activity object.
	 * @param  int    $user_id The id of the local blog user.
	 * @param string $type  The type of the activity.
	 */
	public function handle_received_activity( $object, $user_id, $type ) {
		if ( ! in_array(
			$type,
			array(
				// We don't need to handle 'Accept' types since it's handled by the ActivityPub plugin itself.
				'create',
				'announce',
			),
			true
		) ) {
			return false;
		}
		$actor_url = $object['actor'];
		$user_feed = false;
		if ( Friends::check_url( $actor_url ) ) {
			// Let's check if we follow this actor. If not it might be a different URL representation.
			$user_feed = $this->friends_feed->get_user_feed_by_url( $actor_url );
		}

		if ( is_wp_error( $user_feed ) || ! Friends::check_url( $actor_url ) ) {
			$meta = $this->get_metadata( $actor_url );
			if ( ! $meta || ! isset( $meta['url'] ) ) {
				$this->log( 'Received invalid meta for ' . $actor_url );
				return false;
			}

			$actor_url = $meta['url'];
			if ( ! Friends::check_url( $actor_url ) ) {
				$this->log( 'Received invalid meta url for ' . $actor_url );
				return false;
			}
		}

		$user_feed = $this->friends_feed->get_user_feed_by_url( $actor_url );
		if ( ! $user_feed || is_wp_error( $user_feed ) ) {
			$this->log( 'We\'re not following ' . $actor_url );
			// We're not following this user.
			return false;
		}

		$item = $this->process_incoming_activity( $type, $object, $user_id );

		if ( $item instanceof Feed_Item ) {
			$this->friends_feed->process_incoming_feed_items( array( $item ), $user_feed );
			return true;
		}

		return false;
	}

	/**
	 * Process an incoming activity.
	 *
	 * @param string $type The type of the activity.
	 * @param array  $object The activity object.
	 * @param int    $user_id The id of the local user if any.
	 * @return Feed_Item|null The feed item or null if it's not a valid activity.
	 */
	private function process_incoming_activity( $type, $object, $user_id = null ) {
		switch ( $type ) {
			case 'create':
				return $this->handle_incoming_post( $object['object'] );
				break;
			case 'announce':
				return $this->handle_incoming_announce( $object['object'], $user_id );
				break;
		}
		return null;
	}

	/**
	 * Map the Activity type to a post fomat.
	 *
	 * @param      string $type   The type.
	 *
	 * @return     string  The determined post format.
	 */
	private function map_type_to_post_format( $type ) {
		return 'status';
	}

	/**
	 * We received a post for a feed, handle it.
	 *
	 * @param      array $object     The object from ActivityPub.
	 */
	private function handle_incoming_post( $object ) {
		$permalink = $object['id'];
		if ( isset( $object['url'] ) ) {
			$permalink = $object['url'];
		}

		$data = array(
			'permalink'    => $permalink,
			'content'      => $object['content'],
			'post_format'  => $this->map_type_to_post_format( $object['type'] ),
			'date'         => $object['published'],
			'_external_id' => $object['id'],
			self::SLUG     => array(),
		);

		if ( isset( $object['reblog'] ) && $object['reblog'] ) {
			$data[ self::SLUG ]['reblog'] = $object['reblog'];
		}

		if ( isset( $object['attributedTo'] ) ) {
			$meta = $this->get_metadata( $object['attributedTo'] );
			$this->log( 'Attributed to ' . $object['attributedTo'], compact( 'meta' ) );

			if ( $meta ) {
				if ( isset( $meta['name'] ) ) {
					$data['author'] = $meta['name'];
				} elseif ( isset( $meta['preferredUsername'] ) ) {
					$data['author'] = $meta['preferredUsername'];
				}

				$data[ self::SLUG ]['attributedTo'] = array(
					'id' => $meta['id'],
				);
				if ( ! empty( $meta['icon']['url'] ) ) {
					$data[ self::SLUG ]['attributedTo']['icon'] = $meta['icon']['url'];
				}

				if ( ! empty( $meta['summary'] ) ) {
					$data[ self::SLUG ]['attributedTo']['summary'] = $meta['summary'];
				}

				if ( ! empty( $meta['preferredUsername'] ) ) {
					$data[ self::SLUG ]['attributedTo']['preferredUsername'] = $meta['preferredUsername'];
				}

				if ( ! empty( $meta['name'] ) ) {
					$data[ self::SLUG ]['attributedTo']['name'] = $meta['name'];
				}
			}
		}

		if ( isset( $object['application'] ) && $object['application'] ) {
			$data[ self::SLUG ]['application'] = $object['application'];
		}

		if ( ! empty( $object['attachment'] ) ) {
			foreach ( $object['attachment'] as $attachment ) {
				if ( ! isset( $attachment['type'] ) || ! isset( $attachment['mediaType'] ) ) {
					continue;
				}
				if ( 'Document' !== $attachment['type'] || strpos( $attachment['mediaType'], 'image/' ) !== 0 ) {
					continue;
				}

				$data['content'] .= PHP_EOL;
				$data['content'] .= '<!-- wp:image -->';
				$data['content'] .= '<p><img src="' . esc_url( $attachment['url'] ) . '" width="' . esc_attr( $attachment['width'] ) . '"  height="' . esc_attr( $attachment['height'] ) . '" class="size-full" /></p>';
				$data['content'] .= '<!-- /wp:image -->';
			}
		}

		$this->log(
			'Received feed item',
			array(
				'url'  => $permalink,
				'data' => $data,
			)
		);
		return new Feed_Item( $data );
	}

	/**
	 * We received an announced URL (boost) for a feed, handle it.
	 *
	 * @param      array $url     The announced URL.
	 * @param      int   $user_id  The user id (for retrieving the keys).
	 */
	private function handle_incoming_announce( $url, $user_id = null ) {
		if ( ! Friends::check_url( $url ) ) {
			$this->log( 'Received invalid announce', compact( 'url' ) );
			return false;
		}
		$this->log( 'Received announce for ' . $url );
		if ( null === $user_id ) {
			$user_id = Friends::get_main_friend_user_id();
		}
		$response = \Activitypub\safe_remote_get( $url, $user_id );
		if ( \is_wp_error( $response ) ) {
			return $response;
		}
		$json = \wp_remote_retrieve_body( $response );
		$object = \json_decode( $json, true );
		if ( ! $object ) {
			$this->log( 'Received invalid json', compact( 'json' ) );
			return false;
		}
		$this->log( 'Received response', compact( 'url', 'object' ) );

		$object['reblog'] = true;

		return $this->handle_incoming_post( $object );
	}

	/**
	 * Queue a hook to run async.
	 *
	 * @param string $hook The hook name.
	 * @param array  $args The arguments to pass to the hook.
	 * @param string $unqueue_hook Optional a hook to unschedule before queuing.
	 * @return void|bool Whether the hook was queued.
	 */
	public function queue( $hook, $args, $unqueue_hook = null ) {
		if ( $unqueue_hook ) {
			$hook_timestamp = wp_next_scheduled( $unqueue_hook, $args );
			if ( $hook_timestamp ) {
				wp_unschedule_event( $hook_timestamp, $unqueue_hook, $args );
			}
		}

		if ( wp_next_scheduled( $hook, $args ) ) {
			return;
		}

		return \wp_schedule_single_event( \time(), $hook, $args );
	}

	/**
	 * Prepare to follow the user via a scheduled event.
	 *
	 * @param      User_Feed $user_feed  The user feed.
	 *
	 * @return     bool|WP_Error              Whether the event was queued.
	 */
	public function queue_follow_user( User_Feed $user_feed ) {
		if ( self::SLUG !== $user_feed->get_parser() ) {
			return;
		}

		$queued = $this->queue(
			'friends_feed_parser_activitypub_follow',
			array( $user_feed->get_url() ),
			'friends_feed_parser_activitypub_unfollow'
		);

		if ( $queued ) {
			$user_feed->update_last_log( __( 'Queued follow request.', 'friends' ) );
		}

		return $queued;
	}

	/**
	 * Follow a user via ActivityPub at a URL.
	 *
	 * @param      string $url    The url.
	 * @param      int    $user_id   The current user id.
	 */
	public function follow_user( $url, $user_id = null ) {
		if ( null === $user_id ) {
			$user_id = Friends::get_main_friend_user_id();
		}

		$meta = $this->get_metadata( $url );
		$user_feed = User_Feed::get_by_url( $url );
		if ( is_wp_error( $meta ) ) {
			if ( $user_feed instanceof User_Feed ) {
				$user_feed->update_last_log(
					sprintf(
						// translators: %s an error message.
						__( 'Error: %s', 'friends' ),
						$meta->get_error_code() . ' ' . $meta->get_error_message()
					)
				);
			}
			return $meta;
		}
		$to = $meta['id'];
		$inbox = \Activitypub\get_inbox_by_actor( $to );
		$actor = \get_author_posts_url( $user_id );

		$activity = new \Activitypub\Model\Activity( 'Follow', \Activitypub\Model\Activity::TYPE_SIMPLE );
		$activity->set_to( null );
		$activity->set_cc( null );
		$activity->set_actor( $actor );
		$activity->set_object( $to );
		$activity->set_id( $actor . '#follow-' . \preg_replace( '~^https?://~', '', $to ) );
		$activity = $activity->to_json();
		$response = \Activitypub\safe_remote_post( $inbox, $activity, $user_id );

		if ( $user_feed instanceof User_Feed ) {
			$user_feed->update_last_log(
				sprintf(
				// translators: %s is the response from the remote server.
					__( 'Sent follow request with response: %s', 'friends' ),
					wp_remote_retrieve_response_code( $response ) . ' ' . wp_remote_retrieve_response_message( $response )
				)
			);
		}
	}

	/**
	 * Prepare to unfollow the user via a scheduled event.
	 *
	 * @param      User_Feed $user_feed  The user feed.
	 *
	 * @return     bool|WP_Error              Whether the event was queued.
	 */
	public function queue_unfollow_user( User_Feed $user_feed ) {
		if ( self::SLUG !== $user_feed->get_parser() ) {
			return false;
		}

		$queued = $this->queue(
			'friends_feed_parser_activitypub_unfollow',
			array( $user_feed->get_url() ),
			'friends_feed_parser_activitypub_follow'
		);

		if ( $queued ) {
			$user_feed->update_last_log( __( 'Queued unfollow request.', 'friends' ) );
		}

		return $queued;
	}

	/**
	 * Unfllow a user via ActivityPub at a URL.
	 *
	 * @param      string $url    The url.
	 * @param      int    $user_id   The current user id.
	 */
	public function unfollow_user( $url, $user_id = null ) {
		if ( null === $user_id ) {
			$user_id = Friends::get_main_friend_user_id();
		}
		$meta = $this->get_metadata( $url );
		$user_feed = User_Feed::get_by_url( $url );
		if ( is_wp_error( $meta ) ) {
			if ( $user_feed instanceof User_Feed ) {
				$user_feed->update_last_log(
					sprintf(
						// translators: %s an error message.
						__( 'Error: %s', 'friends' ),
						$meta->get_error_code() . ' ' . $meta->get_error_message()
					)
				);
			}
			return $meta;
		}
		$to = $meta['id'];
		$inbox = \Activitypub\get_inbox_by_actor( $to );
		$actor = \get_author_posts_url( $user_id );

		$activity = new \Activitypub\Model\Activity( 'Undo', \Activitypub\Model\Activity::TYPE_SIMPLE );
		$activity->set_to( null );
		$activity->set_cc( null );
		$activity->set_actor( $actor );
		$activity->set_object(
			array(
				'type'   => 'Follow',
				'actor'  => $actor,
				'object' => $to,
				'id'     => $to,
			)
		);
		$activity->set_id( $actor . '#unfollow-' . \preg_replace( '~^https?://~', '', $to ) );
		$activity = $activity->to_json();
		$response = \Activitypub\safe_remote_post( $inbox, $activity, $user_id );

		$user_feed = User_Feed::get_by_url( $url );
		if ( $user_feed instanceof User_Feed ) {
			$user_feed->update_last_log(
				sprintf(
				// translators: %s is the response from the remote server.
					__( 'Sent unfollow request with response: %s', 'friends' ),
					wp_remote_retrieve_response_code( $response ) . ' ' . wp_remote_retrieve_response_message( $response )
				)
			);
		}
	}

	public function get_possible_mentions() {
		static $users = null;
		if ( ! method_exists( '\Friends\User_Feed', 'get_by_parser' ) ) {
			return array();
		}

		if ( is_null( $users ) || ! apply_filters( 'activitypub_cache_possible_friend_mentions', true ) ) {
			$feeds = User_Feed::get_by_parser( 'activitypub' );
			$users = array();
			foreach ( $feeds as $feed ) {
				$user = $feed->get_friend_user();
				$slug = sanitize_title( $user->user_nicename );
				$users[ '@' . $slug ] = $feed->get_url();
			}
		}
		return $users;
	}

	/**
	 * Extract the mentions from the post_content.
	 *
	 * @param array  $mentions The already found mentions.
	 * @param string $post_content The post content.
	 * @return mixed The discovered mentions.
	 */
	public function activitypub_extract_mentions( $mentions, $post_content ) {
		$users = $this->get_possible_mentions();
		preg_match_all( '/@(?:[a-zA-Z0-9_-]+)/', $post_content, $matches );
		foreach ( $matches[0] as $match ) {
			if ( isset( $users[ $match ] ) ) {
				$mentions[ $match ] = $users[ $match ];
			}
		}
		return $mentions;
	}

	public function the_content( $the_content ) {
		$protected_tags = array();
		$the_content = preg_replace_callback(
			'#<a.*?href=[^>]+>.*?</a>#i',
			function( $m ) use ( &$protected_tags ) {
				$c = count( $protected_tags );
				$protect = '!#!#PROTECT' . $c . '#!#!';
				$protected_tags[ $protect ] = $m[0];
				return $protect;
			},
			$the_content
		);

		$the_content = \preg_replace_callback( '/@(?:[a-zA-Z0-9_-]+)/', array( $this, 'replace_with_links' ), $the_content );

		$the_content = str_replace( array_keys( $protected_tags ), array_values( $protected_tags ), $the_content );

		return $the_content;
	}

	/**
	 * Replace the mention with a link to the user.
	 *
	 * @param array $result The matched username.
	 *
	 * @return string The replaced username.
	 */
	public function replace_with_links( array $result ) {
		$users = $this->get_possible_mentions();
		if ( ! isset( $users[ $result[0] ] ) ) {
			return $result[0];
		}

		$metadata = $this->get_metadata( $users[ $result[0] ] );
		if ( is_wp_error( $metadata ) || empty( $metadata['url'] ) ) {
			return $result[0];
		}

		$username = ltrim( $users[ $result[0] ], '@' );
		if ( ! empty( $metadata['name'] ) ) {
			$username = $metadata['name'];
		}
		if ( ! empty( $metadata['preferredUsername'] ) ) {
			$username = $metadata['preferredUsername'];
		}
		$username = '@<span>' . $username . '</span>';
		return \sprintf( '<a rel="mention" class="u-url mention" href="%s">%s</a>', $metadata['url'], $username );
	}

	public function activitypub_save_settings( User $friend ) {
		if ( isset( $_POST['_wpnonce'] ) && wp_verify_nonce( $_POST['_wpnonce'], 'edit-friend-feeds-' . $friend->ID ) ) {

			if ( isset( $_POST['friends_show_replies'] ) && $_POST['friends_show_replies'] ) {
				$friend->update_user_option( 'activitypub_friends_show_replies', '1' );
			} else {
				$friend->delete_user_option( 'activitypub_friends_show_replies' );
			}
		}
	}

	public function activitypub_settings( User $friend ) {
		$has_activitypub_feed = false;
		foreach ( $friend->get_active_feeds() as $feed ) {
			if ( 'activitypub' === $feed->get_parser() ) {
				$has_activitypub_feed = true;
			}
		}

		if ( ! $has_activitypub_feed ) {
			return;
		}

		?>
		<tr>
			<th>ActivityPub</th>
			<td>
				<fieldset>
					<div>
						<input type="checkbox" name="friends_show_replies" id="friends_show_replies" value="1" <?php checked( '1', $friend->get_user_option( 'activitypub_friends_show_replies' ) ); ?> />
						<label for="friends_show_replies"><?php esc_html_e( "Don't hide @mentions of feeds you don't follow", 'friends' ); ?></label>
						</div>
				</fieldset>
				<p class="description">
				<?php
				esc_html_e( "Show ActivityPub '@mention' posts even when you do not follow the feed being mentioned.", 'friends' );
				?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Apply the feed rules
	 *
	 * @param  Feed_Item $item         The feed item.
	 * @param  User_Feed $feed         The feed object.
	 * @param  User      $friend_user The friend user.
	 * @return Feed_Item The modified feed item.
	 */
	public function modify_incoming_item( Feed_Item $item, User_Feed $feed = null, User $friend_user = null ) {
		if ( ! $feed || 'activitypub' !== $feed->get_parser() ) {
			return $item;
		}

		if ( ! $friend_user->get_user_option( 'activitypub_friends_show_replies' ) ) {
			$plain_text_content = \wp_strip_all_tags( $item->post_content );

			if ( preg_match( ' /^@(?:[a-zA-Z0-9_.-]+)/i', $plain_text_content, $m ) ) {
				$users = $this->get_possible_mentions();
				if ( ! isset( $users[ $m[0] ] ) ) {
					$item->_feed_rule_transform = array(
						'post_status' => 'trash',
					);
				}
			}
		}

		return $item;
	}

	public function admin_show_update_avatar( User $friend_user ) {
		$avatars = array();
		foreach ( $friend_user->get_active_feeds() as $user_feed ) {
			if ( 'activitypub' === $user_feed->get_parser() ) {
				$details = $this->update_feed_details(
					array(
						'url' => $user_feed->get_url(),
					)
				);
				if ( isset( $details['avatar'] ) ) {
					$avatars[ $details['avatar'] ] = sprintf(
						// translators: %s is a username.
						__( 'Avatar of %s', 'friends' ),
						$details['title']
					);
				}
			}
		}
		if ( empty( $avatars ) ) {
			return;
		}

		?>
		<tr>
				<th><label for="friends_set_avatar"><?php esc_html_e( 'Avatar via ActivityPub', 'friends' ); ?></label></th>
				<td>
					<?php
					foreach ( $avatars as $avatar => $title ) {
						?>
						<a href="" data-nonce="<?php echo esc_attr( wp_create_nonce( 'set-avatar-' . $friend_user->ID ) ); ?>" data-id="<?php echo esc_attr( $friend_user->ID ); ?>" class="set-avatar"><img src="<?php echo esc_url( $avatar ); ?>" alt="<?php echo esc_attr( $title ); ?>" title="<?php echo esc_attr( $title ); ?>" width="32" height="32" /></a>
						<?php
					}
					?>
					<p class="description">
						<?php esc_html_e( 'Click to set as new avatar.', 'friends' ); ?><br/>
					</p>
				</td>
			</tr>
		<?php
	}

	private function get_feed_for_post( \WP_Post $post ) {
		$friend = new User( $post->post_author );
		if ( ! $friend || is_wp_error( $friend ) ) {
			return $friend;
		}

		$host = wp_parse_url( $post->guid, PHP_URL_HOST );

		foreach ( $friend->get_active_feeds() as $feed ) {
			if ( 'activitypub' !== $feed->get_parser() ) {
				continue;
			}

			$feed_host = wp_parse_url( $feed->get_url(), PHP_URL_HOST );
			if ( $feed_host !== $host ) {
				continue;
			}

			return $feed;
		}

		return null;
	}

	/**
	 * Create a status based on a post reaction.
	 *
	 * @param      int $post_id  The post ID.
	 */
	public function post_reaction( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}
		$feed = $this->get_feed_for_post( $post );
		if ( ! $feed ) {
			return;
		}
		return $this->queue_like_post( $post, $feed );
	}

	/**
	 * Prepare to follow the user via a scheduled event.
	 *
	 * @param      \WP_Post  $post       The post.
	 * @param      User_Feed $user_feed  The user feed.
	 *
	 * @return     bool|WP_Error              Whether the event was queued.
	 */
	public function queue_like_post( \WP_Post $post, User_Feed $user_feed ) {
		$external_post_id = get_post_meta( $post->ID, 'external-id', true );
		if ( ! $external_post_id ) {
			$external_post_id = $post->guid;
		}

		$queued = $this->queue(
			'friends_feed_parser_activitypub_like',
			array( $user_feed->get_url(), $external_post_id ),
			'friends_feed_parser_activitypub_unlike'
		);

		if ( $queued ) {
			$user_feed->update_last_log( __( 'Queued like request.', 'friends' ) );
		}

		return $queued;
	}

	/**
	 * Like a post.
	 *
	 * @param mixed $url The URL of the user.
	 * @param mixed $external_post_id The post to like.
	 * @param mixed $user_id The current user id.
	 * @return void
	 */
	public function like_post( $url, $external_post_id, $user_id ) {
		$inbox = \Activitypub\get_inbox_by_actor( $url );
		$actor = \get_author_posts_url( $user_id );

		$activity = new \Activitypub\Model\Activity( 'Like', \Activitypub\Model\Activity::TYPE_SIMPLE );
		$activity->set_to( null );
		$activity->set_cc( null );
		$activity->set_actor( $actor );
		$activity->set_object( $external_post_id );
		$activity->set_id( $actor . '#like-' . \preg_replace( '~^https?://~', '', $external_post_id ) );
		$activity = $activity->to_json();
		$response = \Activitypub\safe_remote_post( $inbox, $activity, $user_id );

		$user_feed = User_Feed::get_by_url( $url );
		if ( $user_feed instanceof User_Feed ) {
			$user_feed->update_last_log(
				sprintf(
				// translators: %s is the response from the remote server.
					__( 'Sent like request with response: %s', 'friends' ),
					wp_remote_retrieve_response_code( $response ) . ' ' . wp_remote_retrieve_response_message( $response )
				)
			);
		}
	}

	/**
	 * Create a status based on a post reaction.
	 *
	 * @param      int $post_id  The post ID.
	 */
	public function undo_post_reaction( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}
		$feed = $this->get_feed_for_post( $post );
		if ( ! $feed ) {
			return;
		}
		return $this->queue_unlike_post( $post, $feed );
	}

	/**
	 * Prepare to follow the user via a scheduled event.
	 *
	 * @param      \WP_Post  $post       The post.
	 * @param      User_Feed $user_feed  The user feed.
	 *
	 * @return     bool|WP_Error              Whether the event was queued.
	 */
	public function queue_unlike_post( \WP_Post $post, User_Feed $user_feed ) {
		$external_post_id = get_post_meta( $post->ID, 'external-id', true );
		if ( ! $external_post_id ) {
			$external_post_id = $post->guid;
		}

		$queued = $this->queue(
			'friends_feed_parser_activitypub_unlike',
			array( $user_feed->get_url(), $external_post_id ),
			'friends_feed_parser_activitypub_like'
		);

		if ( $queued ) {
			$user_feed->update_last_log( __( 'Queued unlike request.', 'friends' ) );
		}

		return $queued;
	}

	/**
	 * Unlike a post.
	 *
	 * @param mixed $url The URL of the user.
	 * @param mixed $external_post_id The post to like.
	 * @param mixed $user_id The current user id.
	 * @return void
	 */
	public function unlike_post( $url, $external_post_id, $user_id ) {
		$inbox = \Activitypub\get_inbox_by_actor( $url );
		$actor = \get_author_posts_url( $user_id );

		$activity = new \Activitypub\Model\Activity( 'Undo', \Activitypub\Model\Activity::TYPE_SIMPLE );
		$activity->set_to( null );
		$activity->set_cc( null );
		$activity->set_actor( $actor );
		$activity->set_object(
			array(
				'type'   => 'Like',
				'actor'  => $actor,
				'object' => $external_post_id,
				'id'     => $actor . '#like-' . \preg_replace( '~^https?://~', '', $external_post_id ),
			)
		);
		$activity->set_id( $actor . '#unlike-' . \preg_replace( '~^https?://~', '', $external_post_id ) );
		$activity = $activity->to_json();
		$response = \Activitypub\safe_remote_post( $inbox, $activity, $user_id );

		$user_feed = User_Feed::get_by_url( $url );
		if ( $user_feed instanceof User_Feed ) {
			$user_feed->update_last_log(
				sprintf(
				// translators: %s is the response from the remote server.
					__( 'Sent unlike request with response: %s', 'friends' ),
					wp_remote_retrieve_response_code( $response ) . ' ' . wp_remote_retrieve_response_message( $response )
				)
			);
		}
	}

	public function friends_reblog_button_label( $button_label ) {
		if ( get_post_meta( get_the_ID(), 'parser', true ) === 'activitypub' ) {
			if ( get_user_option( 'friends_activitypub_dont_reblog' ) ) {
				$button_label = _x( 'Boost', 'button', 'friends' );
			} else {
				$button_label = _x( 'Reblog & Boost', 'button', 'friends' );
			}
		}
		return $button_label;
	}

	public function unqueue_activitypub_create( $ret ) {
		// TODO: Harden against cases when ActivityPub changes the priority.
		remove_action( 'transition_post_status', array( '\Activitypub\Activitypub', 'schedule_post_activity' ), 33, 3 );
		return $ret;
	}

	public function maybe_unqueue_friends_reblog_post( $ret, $post ) {
		if ( ! get_user_option( 'friends_activitypub_dont_reblog' ) ) {
			return $ret;
		}

		if ( get_post_meta( $post->ID, 'parser', true ) !== 'activitypub' ) {
			return $ret;
		}

		remove_filter( 'friends_reblog', array( 'Friends\Frontend', 'reblog' ), 10, 2 );
	}

	public function reblog( $ret, $post ) {
		if ( get_post_meta( $post->ID, 'parser', true ) === 'activitypub' ) {
			$this->announce( $post->guid );
			update_post_meta( $post->ID, 'reblogged', 'activitypub' );
			return true;
		}
		return $ret;
	}


	public function announce( $url ) {
		$user_id = get_current_user_id();
		$actor = \get_author_posts_url( get_current_user_id() );

		$activity = new \Activitypub\Model\Activity( 'Announce', \Activitypub\Model\Activity::TYPE_SIMPLE );
		$activity->set_to( null );
		$activity->set_cc( null );
		$activity->set_actor( $actor );
		$activity->set_object( $url );
		$activity->set_id( $actor . '#announce-' . \preg_replace( '~^https?://~', '', $url ) );
		$inboxes = \Activitypub\get_follower_inboxes( $user_id );

		$followers_url = \get_rest_url( null, '/activitypub/1.0/users/' . intval( $user_id ) . '/followers' );

		foreach ( $inboxes as $inbox => $to ) {
			$to = array_values( array_unique( $to ) );
			$activity->set_to( $to );

			\Activitypub\safe_remote_post( $inbox, $activity->to_json(), $user_id );
		}
	}

	/**
	 * Approve comments that
	 *
	 * @param      bool  $approved     Whether the comment is approved.
	 * @param      array $commentdata  The commentdata.
	 *
	 * @return     bool    Whether the comment is approved.
	 */
	public function pre_comment_approved( $approved, $commentdata ) {
		if ( ! $approved || is_string( $approved ) && 'activitypub' === $commentdata['comment_meta']['protocol'] ) {
			$user_feed = User_Feed::get_by_url( $commentdata['comment_author_url'] );
			if ( $user_feed instanceof User_Feed ) {
				$approved = true;
			}
		}
		return $approved;
	}

	/**
	 * Disable Webfinger for example domains
	 *
	 * @param mixed $metadata Already retrieved metadata.
	 * @param mixed $actor The actor as URL or username.
	 * @return mixed The potentially added metadata for example domains.
	 */
	function disable_webfinger_for_example_domains( $metadata, $actor ) {
		if ( ! $metadata ) {
			$username = null;
			$domain = null;
			if ( preg_match( '/^@?' . self::ACTIVITYPUB_USERNAME_REGEXP . '$/i', $actor, $m ) ) {
				$username = $m[1];
				$domain = $m[2];
			} else {
				$p = wp_parse_url( $actor );
				if ( $p ) {
					if ( isset( $p['host'] ) ) {
						$domain = $p['host'];
					}
					if ( isset( $p['path'] ) ) {
						$path_parts = explode( '/', trim( $p['path'], '/' ) );
						$username = ltrim( array_pop( $path_parts ), '@' );
					}
				}
			}
			if ( preg_match( '/[^a-zA-Z0-9.-]/', $domain ) ) { // Has invalid characters.
				return $metadata;
			}

			if (
				rtrim( strtok( $domain, '.' ), '0123456789' ) === 'example' // A classic example.org domain.
				|| preg_match( '/(my|your|our)-(domain)/', $domain )
				|| preg_match( '/(test)/', $domain )
				|| in_array( $username, array( 'example' ), true )
			) {
				$metadata = array(
					'url'  => sprintf( 'https://%s/users/%s/', $domain, $username ),
					'name' => $username,
				);
			}
		}
		return $metadata;
	}
}
