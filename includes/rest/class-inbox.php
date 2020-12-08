<?php
namespace Activitypub\Rest;

/**
 * ActivityPub Inbox REST-Class
 *
 * @author Matthias Pfefferle
 *
 * @see https://www.w3.org/TR/activitypub/#inbox
 */
class Inbox {
	/**
	 * Initialize the class, registering WordPress hooks
	 */
	public static function init() {
		\add_action( 'rest_api_init', array( '\Activitypub\Rest\Inbox', 'register_routes' ) );
		\add_filter( 'rest_pre_serve_request', array( '\Activitypub\Rest\Inbox', 'serve_request' ), 11, 4 );
		\add_action( 'activitypub_inbox_follow', array( '\Activitypub\Rest\Inbox', 'handle_follow' ), 10, 2 );
		\add_action( 'activitypub_inbox_unfollow', array( '\Activitypub\Rest\Inbox', 'handle_unfollow' ), 10, 2 );
		//\add_action( 'activitypub_inbox_like', array( '\Activitypub\Rest\Inbox', 'handle_reaction' ), 10, 2 );
		//\add_action( 'activitypub_inbox_announce', array( '\Activitypub\Rest\Inbox', 'handle_reaction' ), 10, 2 );
		\add_action( 'activitypub_inbox_create', array( '\Activitypub\Rest\Inbox', 'handle_create' ), 10, 2 );
	}

	/**
	 * Register routes
	 */
	public static function register_routes() {
		\register_rest_route(
			'activitypub/1.0', '/inbox', array(
				array(
					'methods'  => \WP_REST_Server::EDITABLE,
					'callback' => array( '\Activitypub\Rest\Inbox', 'shared_inbox' ),
					'permission_callback' => '__return_true'
				),
			)
		);

		\register_rest_route(
			'activitypub/1.0', '/users/(?P<user_id>\d+)/inbox', array(
				array(
					'methods'  => \WP_REST_Server::EDITABLE,
					'callback' => array( '\Activitypub\Rest\Inbox', 'user_inbox' ),
					'args'     => self::request_parameters(),
					'permission_callback' => '__return_true'
				),
			)
		);

		// \register_rest_route(
		// 	'activitypub/1.0', '/users/(?P<user_id>\d+)/inbox', array(
		// 		array(
		// 			'methods'  =>  \WP_REST_Server::READABLE,
		// 			'callback' => array( '\Activitypub\Rest\Inbox', 'user_inbox_get' ),
		// 			'args'     => self::get_parameters(),
		// 			'permission_callback' => '__return_true'
		// 		),
		// 	)
		// );
	}

	/**
	 * Hooks into the REST API request to verify the signature.
	 *
	 * @param bool                      $served  Whether the request has already been served.
	 * @param WP_HTTP_ResponseInterface $result  Result to send to the client. Usually a WP_REST_Response.
	 * @param WP_REST_Request           $request Request used to generate the response.
	 * @param WP_REST_Server            $server  Server instance.
	 *
	 * @return true
	 */
	public static function serve_request( $served, $result, $request, $server ) {
		if ( '/activitypub' !== \substr( $request->get_route(), 0, 12 ) ) {
			return $served;
		}

		$signature = $request->get_header( 'signature' );

		if ( ! $signature ) {
			return $served;
		}

		$headers = $request->get_headers();

		// verify signature
		//\Activitypub\Signature::verify_signature( $headers, $key );

		return $served;
	}

	/**
	 * Renders the user-inbox
	 *
	 * @param  WP_REST_Request   $request
	 *
	 * @return WP_REST_Response
	 */
	public static function user_inbox( $request ) {
		$user_id = $request->get_param( 'user_id' );

		$data = $request->get_params();
		$type = $request->get_param( 'type' );

		\do_action( 'activitypub_inbox', $data, $user_id, $type );
		\do_action( "activitypub_inbox_{$type}", $data, $user_id );

		return new \WP_REST_Response( array(), 202 );
	}

	/**
	 * Renders the user-inbox
	 *
	 * @param  WP_REST_Request   $request
	 *
	 * @return WP_REST_Response
	 */
	public static function user_inbox_get( $request ) {
		$user_id = $request->get_param( 'user_id' );

		$json = new \stdClass();

		$json->{'@context'} = \Activitypub\get_context();
		$json->id = \get_rest_url( null, "/activitypub/1.0/users/$user_id/inbox" ); // phpcs:ignore
		$json->type = 'OrderedCollection';
		
		$response = new \WP_REST_Response( $json, 200 );

		$response->header( 'Content-Type', 'application/activity+json' );

		return $response;
	}

	/**
	 * The shared inbox
	 *
	 * @param  [type] $request [description]
	 *
	 * @return WP_Error not yet implemented
	 */
	public static function shared_inbox( $request ) {

	}

	/**
	 * The supported parameters
	 *
	 * @return array list of parameters
	 */
	public static function request_parameters() {
		$params = array();

		$params['page'] = array(
			'type' => 'integer',
		);

		$params['user_id'] = array(
			'required' => true,
			'type' => 'integer',
		);

		$params['id'] = array(
			'required' => true,
			'type' => 'string',
			'validate_callback' => function( $param, $request, $key ) {
				if ( ! \is_string( $param ) ) {
					$param = $param['id'];
				}
				return ! \Activitypub\is_blacklisted( $param );
			},
			'sanitize_callback' => 'esc_url_raw',
		);

		$params['actor'] = array(
			'required' => true,
			//'type' => array( 'object', 'string' ),
			'validate_callback' => function( $param, $request, $key ) {
				if ( ! \is_string( $param ) ) {
					$param = $param['id'];
				}
				return ! \Activitypub\is_blacklisted( $param );
			},
			'sanitize_callback' => function( $param, $request, $key ) {
				if ( ! \is_string( $param ) ) {
					$param = $param['id'];
				}
				return \esc_url_raw( $param );
			},
		);

		$params['type'] = array(
			'required' => true,
			//'type' => 'enum',
			//'enum' => array( 'Create' ),
			'sanitize_callback' => function( $param, $request, $key ) {
				return \strtolower( $param );
			},
		);

		$params['object'] = array(
			'required' => true,
			//'type' => 'object',
		);

		return $params;
	}

	/**
	 * The supported parameters
	 *
	 * @return array list of parameters
	 */
	public static function get_parameters() {
		$params = array();

		$params['page'] = array(
			'type' => 'integer',
		);

		$params['user_id'] = array(
			'required' => true,
			'type' => 'integer',
		);

		$params['id'] = array(
//			'required' => true,
			'type' => 'string',
			'validate_callback' => function( $param, $request, $key ) {
				if ( ! \is_string( $param ) ) {
					$param = $param['id'];
				}
				return ! \Activitypub\is_blacklisted( $param );
			},
			'sanitize_callback' => 'esc_url_raw',
		);

		$params['actor'] = array(
//			'required' => true,
			//'type' => array( 'object', 'string' ),
			'validate_callback' => function( $param, $request, $key ) {
				if ( ! \is_string( $param ) ) {
					$param = $param['id'];
				}
				return ! \Activitypub\is_blacklisted( $param );
			},
			'sanitize_callback' => function( $param, $request, $key ) {
				if ( ! \is_string( $param ) ) {
					$param = $param['id'];
				}
				return \esc_url_raw( $param );
			},
		);

		return $params;
	}

	/**
	 * Handles "Follow" requests
	 *
	 * @param  array $object  The activity-object
	 * @param  int   $user_id The id of the local blog-user
	 */
	public static function handle_follow( $object, $user_id ) {
		// save follower
		\Activitypub\Peer\Followers::add_follower( $object['actor'], $user_id );

		// get inbox
		$inbox = \Activitypub\get_inbox_by_actor( $object['actor'] );

		// send "Accept" activity
		$activity = new \Activitypub\Model\Activity( 'Accept', \Activitypub\Model\Activity::TYPE_SIMPLE );
		$activity->set_object( $object );
		$activity->set_actor( \get_author_posts_url( $user_id ) );
		$activity->set_to( $object['actor'] );
		$activity->set_id( \get_author_posts_url( $user_id ) . '#follow' . \preg_replace( '~^https?://~', '', $object['actor'] ) );

		$activity = $activity->to_simple_json();
		$response = \Activitypub\safe_remote_post( $inbox, $activity, $user_id );
	}

	/**
	 * Handles "Unfollow" requests
	 *
	 * @param  array $object  The activity-object
	 * @param  int   $user_id The id of the local blog-user
	 */
	public static function handle_unfollow( $object, $user_id ) {
		\Activitypub\Peer\Followers::remove_follower( $object['actor'], $user_id );
	}

	/**
	 * Handles "Reaction" requests
	 *
	 * @param  array $object  The activity-object
	 * @param  int   $user_id The id of the local blog-user
	 */
	public static function handle_reaction( $object, $user_id ) {
		$meta = \Activitypub\get_remote_metadata_by_actor( $object['actor'] );

		$commentdata = array(
			'comment_post_ID' => \url_to_postid( $object['object'] ),
			'comment_author' => \esc_attr( $meta['name'] ),
			'comment_author_email' => '',
			'comment_author_url' => \esc_url_raw( $object['actor'] ),
			'comment_content' => \esc_url_raw( $object['actor'] ),
			'comment_type' => \esc_attr( \strtolower( $object['type'] ) ),
			'comment_parent' => 0,
			'comment_meta' => array(
				'source_url' => \esc_url_raw( $object['attributedTo'] ),
				'avatar_url' => \esc_url_raw( $meta['icon']['url'] ),
				'protocol' => 'activitypub',
			),
		);

		// disable flood control
		\remove_action( 'check_comment_flood', 'check_comment_flood_db', 10 );

		$state = \wp_new_comment( $commentdata, true );

		// re-add flood control
		\add_action( 'check_comment_flood', 'check_comment_flood_db', 10, 4 );
	}

	/**
	 * Handles "Create" requests
	 *
	 * @param  array $object  The activity-object
	 * @param  int   $user_id The id of the local blog-user
	 */
	public static function handle_create( $object, $user_id ) {
		$meta = \Activitypub\get_remote_metadata_by_actor( $object['actor'] );
		$avatar_url = null;
		$audience = \Activitypub\get_audience( $object );
		
		// Security TODO: 
		// static function: enforce host check ($object['id'] must match $object['object']['url'] && $object['actor'] domain )
		// move to before handle_create

		//Determine parent post and/or parent comment
		$comment_post_ID = $object_parent = $object_parent_ID = 0;
		if ( isset( $object['object']['inReplyTo'] ) ) {
			$comment_post_ID = \url_to_postid( $object['object']['inReplyTo'] );
			//if not a direct reply to a post, remote post parent
			if ( $comment_post_ID === 0 ) {
				//verify if reply to a local or remote received comment
				$object_parent_ID = \Activitypub\url_to_commentid( \esc_url_raw( $object['object']['inReplyTo'] ) );
				if ( !is_null( $object_parent_ID ) ) {
					//replied to a local comment (which has a post_ID)
					$object_parent = get_comment( $object_parent_ID );
					$comment_post_ID = $object_parent->comment_post_ID;
				} else {
					$object_parent_ID = \Activitypub\url_to_metapostid( \esc_url_raw( $object['object']['inReplyTo'] ) );
				}
			}
		}		

		// 'id' is referenced by 'inReplyTo'
		$source_url = \esc_url_raw( $object['object']['id'] );

		// if no name is set use peer username
		if ( !empty( $meta['name'] ) ) {
			$name = \esc_attr( $meta['name'] );
		} else {
			$name = \esc_attr( $meta['preferredUsername'] );
		}
		// if avatar is set 
		if ( !empty( $meta['icon']['url'] ) ) {
			$avatar_url = \esc_attr( $meta['icon']['url'] );
		}
		
		// Only create WP_Comment for public replies to posts	
		if ( ( in_array( AS_PUBLIC, $object['to'] )
			|| in_array( AS_PUBLIC, $object['cc'] ) )
			&& ( !empty( $comment_post_ID ) 
			|| !empty ( $object_parent ) 
			) ) {
			
			$commentdata = array(
				'comment_post_ID' => $comment_post_ID,
				'comment_author' => $name,
				'comment_author_url' => \esc_url_raw( $object['actor'] ),
				'comment_content' => \wp_filter_kses( $object['object']['content'] ),
				'comment_type' => 'activitypub',
				'comment_author_email' => '',
				'comment_parent' => $object_parent_ID,
				'comment_meta' => array(
					'audience' => $audience,
					'inReplyTo' => \esc_url_raw( $object['object']['inReplyTo'] ),
					'source_url' => $source_url,
					'avatar_url' => $avatar_url,
					'ap_object' => \serialize( $object ),
					'protocol' => 'activitypub',
				),
			);

			// disable flood control
			\remove_action( 'check_comment_flood', 'check_comment_flood_db', 10 );

			$state = \wp_new_comment( $commentdata, true );

			// re-add flood control
			\add_action( 'check_comment_flood', 'check_comment_flood_db', 10, 4 );
			

		} else {
			// Not a public reply to a public post
			// TODO how to safely handle $object['attachment']?
			$title = $summary = null;
			if ( isset( $object['object']['summary'] ) ) {
				$summary = \wp_strip_all_tags( $object['object']['summary'] );
				$title = \wp_trim_words( $summary, 10 );
			}
			$postdata = array(
				'post_author' => $object['user_id'],
				'post_content' => \wp_filter_kses( $object['object']['content'] ),
				'post_title' => $title,
				'post_excerpt' => $summary,
				'post_status' => 'inbox',
				'post_type' => 'mention',
				'post_parent' => $object_parent_ID,
				'meta_input' => array(
					'audience' 	=> $audience,
					'ap_object' 	=> \serialize( $object ),
					//'read_status' 	=> 'unread',
					//'mention_type' => \Activitypub\get_audience( $object['object'] ),
					'inreplyto'	=> \esc_url_raw( $object['object']['inReplyTo'] ),
					'author' 		=> $name,
					'author_url' 	=> \esc_url_raw( $object['actor'] ),
					'source_url' 	=> $source_url,
					'avatar_url' 	=> $avatar_url,
					'protocol' 	=> 'activitypub',
				),
			);

			$post_id = \wp_insert_post( $postdata );

			if( !is_wp_error( $post_id ) ) {
				error_log( $post_id );
		    	wp_send_json_success( array( 'post_id' => $post_id ), 200 );
		  } else {
				error_log( $post_id->get_error_message() );
		    	wp_send_json_error( $post_id->get_error_message() );
		  }
		}
	}
}
