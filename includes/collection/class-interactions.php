<?php
namespace Activitypub\Collection;

use WP_Error;

use function Activitypub\object_id_to_comment;
use function Activitypub\get_remote_metadata_by_actor;

/**
 * ActivityPub Interactions Collection
 */
class Interactions {
	/**
	 * Add a comment to a post
	 *
	 * @param array $activity The activity-object
	 *
	 * @return array|false The commentdata or false on failure
	 */
	public static function add_comment( $activity ) {
		if (
			! isset( $activity['object'] ) ||
			! isset( $activity['object']['id'] )
		) {
			return new WP_Error(
				'activitypub_no_valid_object',
				__( 'No object id found.', 'activitypub' ),
				array( 'status' => 400 )
			);
		}

		if ( ! isset( $activity['object']['inReplyTo'] ) ) {
			return new WP_Error(
				'activitypub_no_reply',
				__( 'Object is no reply.', 'activitypub' ),
				array( 'status' => 400 )
			);
		}

		$in_reply_to     = \esc_url_raw( $activity['object']['inReplyTo'] );
		$comment_post_id = \url_to_postid( $in_reply_to );
		$parent_comment  = object_id_to_comment( $in_reply_to );

		// save only replys and reactions
		if ( ! $comment_post_id && $parent_comment ) {
			$comment_post_id = $parent_comment->comment_post_ID;
		}

		// not a reply to a post or comment
		if ( ! $comment_post_id ) {
			return new WP_Error(
				'activitypub_no_reply',
				__( 'Object is no reply.', 'activitypub' ),
				array( 'status' => 400 )
			);
		}

		$meta = get_remote_metadata_by_actor( $activity['actor'] );

		if ( ! $meta || \is_wp_error( $meta ) ) {
			return new WP_Error(
				'activitypub_invalid_follower',
				__( 'Invalid Follower', 'activitypub' ),
				array( 'status' => 400 )
			);
		}

		$commentdata = array(
			'comment_post_ID' => $comment_post_id,
			'comment_author' => \esc_attr( $meta['name'] ),
			'comment_author_url' => \esc_url_raw( $meta['url'] ),
			'comment_content' => addslashes( \wp_kses( $activity['object']['content'], 'pre_comment_content' ) ),
			'comment_type' => 'comment',
			'comment_author_email' => '',
			'comment_parent' => $parent_comment ? $parent_comment->comment_ID : 0,
			'comment_meta' => array(
				'source_id'  => \esc_url_raw( $activity['object']['id'] ),
				'source_url' => \esc_url_raw( $activity['object']['url'] ),
				'avatar_url' => \esc_url_raw( $meta['icon']['url'] ),
				'protocol'   => 'activitypub',
			),
		);

		// disable flood control
		\remove_action( 'check_comment_flood', 'check_comment_flood_db', 10 );

		// do not require email for AP entries
		\add_filter( 'pre_option_require_name_email', '__return_false' );
		// No nonce possible for this submission route
		\add_filter(
			'akismet_comment_nonce',
			function() {
				return 'inactive';
			}
		);
		\add_filter( 'wp_kses_allowed_html', array( self::class, 'allowed_comment_html' ), 10, 2 );

		$comment = \wp_new_comment( $commentdata, true );

		\remove_filter( 'wp_kses_allowed_html', array( self::class, 'allowed_comment_html' ), 10 );
		\remove_filter( 'pre_option_require_name_email', '__return_false' );

		// re-add flood control
		\add_action( 'check_comment_flood', 'check_comment_flood_db', 10, 4 );

		return $comment;
	}

	/**
	 * Adds line breaks to the list of allowed comment tags.
	 *
	 * @param  array  $allowedtags Allowed HTML tags.
	 * @param  string $context     Context.
	 * @return array               Filtered tag list.
	 */
	public static function allowed_comment_html( $allowedtags, $context = '' ) {
		if ( 'pre_comment_content' !== $context ) {
			// Do nothing.
			return $allowedtags;
		}

		// Add `p` and `br` to the list of allowed tags.
		if ( ! array_key_exists( 'br', $allowedtags ) ) {
			$allowedtags['br'] = array();
		}

		if ( ! array_key_exists( 'p', $allowedtags ) ) {
			$allowedtags['p'] = array();
		}

		return $allowedtags;
	}
}