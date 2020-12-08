<?php
namespace Activitypub;

/**
 * ActivityPub C2S Class
 *
 * @author Django Doucet
 */
class C2S {
	/**
	 * Initialize the class, registering WordPress hooks
	 */
	public static function init() {
		\add_filter( 'is_protected_meta', array( '\Activitypub\C2S', 'activitypub_hide_meta_fields' ), 10, 2 );
		\add_filter( 'manage_mention_posts_columns', array( '\Activitypub\C2S', 'activitypub_posts_columns' ), 10 );
		\add_filter( 'manage_mention_posts_sortable_columns', array( '\Activitypub\C2S', 'activitypub_posts_sortable_columns' ), 10 );
		\add_filter( 'manage_mention_posts_custom_column', array( '\Activitypub\C2S', 'activitypub_posts_custom_columns' ), 20, 2 );
		\add_filter( 'page_row_actions', array( '\Activitypub\C2S', 'activitypub_post_row_actions' ), 10, 2 );
		\add_action( 'load-edit.php', array( '\Activitypub\C2S', 'activitypub_post_actions') );
		
		\add_action( 'admin_enqueue_scripts', array( '\Activitypub\C2S', 'scripts_reply_comments' ), 10, 2 );
		\add_filter( 'comment_row_actions', array( '\Activitypub\C2S', 'reply_comments_actions' ), 10, 2 );

		\add_action( 'post_submitbox_misc_actions', array( '\Activitypub\C2S', 'post_audience_html') );
	}

	/**
	 * Hide metafields from UI
	 */
	public static function activitypub_hide_meta_fields( $protected, $meta_key ) {
		\error_log( '$protected: ' . print_r( $protected, true ) );
		if ( 'mention' == get_post_type() || 'activitypub' == get_comment_type() ) {
			if ( in_array( $meta_key, array( 
				'mentions',
				'audience',
				'ap_object', 
				'read_status', 
				'inreplyto', 
				'author', 
				'author_url', 
				'source_url', 
				'avatar_url', 
				'protocol' 
				) ) ) {
				return true;
			}
		}
		return $protected;
	}

	public static function activitypub_posts_custom_columns( $column, $post_id ) {
		switch ( $column ) {
			case 'actor':
				$user = \wp_get_current_user();
				$status = \get_post_field( 'post_status', $post_id, 'display' ); 
				$author_meta = \get_post_meta( $post_id );
				if ( $status === 'inbox' || $status === 'moderation' || $status === 'trash' ) {
					$author = $author_meta['author'][0];
					$author_url = $author_meta['author_url'][0];
					$avatar_url = $author_meta['avatar_url'][0];
					$webfinger = \Activitypub\url_to_webfinger( $author_url );
					echo "<strong><img src='$avatar_url' class='avatar avatar-32 photo' width='32' height='32' loading='lazy'>$author</strong><br><a href='$author_url'>$author_url</a>";
				} else {
					$author_url = \get_author_posts_url( $user->ID );
					echo "<strong>" . get_avatar( $user->ID, 32 ) . $user->display_name . "</strong><br><a href='$author_url'>$author_url</a>";
				}
				break;

			case 'type':
				$audience = \get_post_meta( $post_id, 'audience', true );
				if ( !empty( $audience ) ) {
					echo $audience;
				} else {
					_e( '', 'activitypub' );
				}
				break;
	 
			case 'content':
				echo \get_post_field( 'post_content', $post_id, 'display' ); 
				break;
		}
	}

	public static function activitypub_posts_sortable_columns( $columns ) {
		$custom_col_order = array(
			$columns['type'] => __( 'Type', 'activitypub' ),
			$columns['author'] => __( 'Author', 'activitypub' ),
		);
		return $custom_col_order;
	}

	public static function activitypub_posts_columns( $columns ) {
		$custom_col_order = array(
			'cb' => $columns['cb'],
			'actor' => __( 'Actor', 'activitypub' ),
			'type' => __( 'Type', 'activitypub' ),
			'title' => $columns['title'],
			'content' => __( 'Content', 'activitypub' ),
			'date' => $columns['date'],
		);
		return $custom_col_order;
	}

	/**
	 * page_row_actions
	 */
	public static function activitypub_post_row_actions( $actions, $post ) {
		if ( $post->post_type == "mention" ) {
			
			if ( $post->post_status == "inbox" ) {

				$trash = $actions['trash'];

				// Get post attributes for Reply & Quick reply
				$mentions = \Activitypub\get_recipients( $post->ID, true );
				$summary = $post->post_title;
				$audience = \get_post_meta( $post->ID, 'audience', true );

				// Reply to this post
				$reply_url = admin_url( 'post-new.php?post_type=mention&post_parent=' . $post->ID . '&content=' . $mentions .'&post_title=' . $summary . '&audience=' . $audience );
				$reply_link = add_query_arg( array( 'action' => 'reply' ), $reply_url );
				$actions = array(
					'reply' => sprintf( '<a href="%1$s">%2$s</a>',
					esc_url( $reply_link ),
					esc_html( __( 'Reply', 'activitypub' ) ) )
				);

				// Quick Reply to this mention
				/*$reply_format = '<button type="button" data-post-id="%d" data-action="%s" class="%s button-link" aria-expanded="false" aria-label="%s" data-recipients="%s" data-summary="%s">%s</button>';
				$actions['inline hide-if-no-js'] = sprintf(
					$reply_format,
					$post->ID,
					'replyto',
					'postinline',
					esc_attr__( 'Reply to this mention' ),
					$mentions,
					$summary,
					__( 'Quick reply', 'activitypub' )
				);*/

				// Block user
				/*$block_url = admin_url( 'edit.php?post_type=activitypub&post=' . $post->ID );
				$block_link = wp_nonce_url( add_query_arg( array( 'action' => 'block' ), $block_url ) );
				$actions = array_merge( $actions, array(
					'block' => sprintf( '<a href="%1$s" title="%2$s">%3$s</a>',
							esc_url( $block_link ), 
							__( 'Block this user', 'activitypub' ),
							__( 'Block', 'activitypub' )
						) 
					) 
				);*/
					
				// Report post to moderation.
				$report_url = admin_url( 'edit.php?post_type=mention&post=' . $post->ID );
				$report_link = wp_nonce_url( add_query_arg( array( 'action' => 'report' ), $report_url ) );
				$actions = array_merge( $actions, array(
					'report' => sprintf( '<a href="%1$s" title="%2$s">%3$s</a>',
							esc_url( $report_link ), 
							__( 'Report this mention to moderation', 'activitypub' ),
							__( 'Report', 'activitypub' )
						) 
					) 
				);
			
				$actions['trash'] = $trash;
			}
		}
	 
		return $actions;
	}

	/**
	* Row Actions
 	* load-edit.php
 	*/
	public static function activitypub_post_actions() {
		
		if(	isset( $_GET['post_type'] ) && $_GET['post_type'] === 'mention') {

			if(	isset( $_GET['action'] ) ) {
				// Report post for moderation (Change status)
				if(	$_GET['action'] === 'report') {
					$post_id = $_GET['post'];
					$update_post = array(
						'post_type' => 'mention',
						'ID' => $post_id,
						'edit_date' => false,
						'post_status' => 'moderation',
					);
					$u_post_id = wp_update_post($update_post);
					if( is_wp_error( $u_post_id ) ) {
						error_log( $u_post_id );
						wp_send_json_error( array( 'post_id' => $u_post_id ) );
					} else {
						wp_send_json_success( array( 'post_id' => $u_post_id ), 200 );
					}
				}
				// Block actor
				// if( $_GET['action'] === 'block' ) {
				// 	$post_id = $_GET['post'];
				// }
			}
			
		}		
	}

	public static function reply_comments_actions( $actions, $comment ) {
		//unset( $actions['reply'] );
		$recipients = \Activitypub\get_recipients( $comment->comment_ID );
		$summary = \Activitypub\get_summary( $comment->comment_ID );
		$audience = \get_comment_meta( $comment->comment_ID, 'audience', true );

		//TODO revise for non-js reply action
		// Public Reply
		$reply_button = '<button type="button" data-comment-id="%d" data-post-id="%d" data-action="%s" class="%s button-link" aria-expanded="false" aria-label="%s" data-recipients="%s" data-summary="%s">%s</button>';
		$actions['reply'] = sprintf(
            $reply_button,
            $comment->comment_ID,
            $comment->comment_post_ID,
            'replyto',
            'vim-r comment-inline',
			esc_attr__( 'Reply to this comment' ),
			$recipients,
			$summary,
            __( 'Reply', 'activitypub' )
		);

		// Block user
		/*$block_url = admin_url( 'edit.php?post_type=activitypub&post=' . $post->ID );
		$block_link = wp_nonce_url( add_query_arg( array( 'action' => 'block' ), $block_url ) );
		$actions = array_merge( $actions, array(
			'block' => sprintf( '<a href="%1$s" title="%2$s">%3$s</a>',
					esc_url( $block_link ), 
					__( 'Block this user', 'activitypub' ),
					__( 'Block', 'activitypub' )
				) 
			) 
		);*/
		
		// Private reply / New mention
		$post_url = admin_url( "post-new.php?post_type=mention&comment_parent=$comment->comment_ID&action=private_reply&content=$recipients&post_title=$summary" );
		$actions['private_reply'] = sprintf( '<a href="%1$s" title="%2$s">%3$s</a>',
			$post_url,
			esc_attr__( 'Reply in private to this comment' ),
            __( 'Private reply', 'activitypub' )
		);

		return $actions;
	}

	public static function scripts_reply_comments( $hook ) {
		if ('edit-comments.php' !== $hook) {
			return;
		}
		wp_enqueue_script( 'activitypub_client', 
			plugin_dir_url(__FILE__) . '/activitypub-client.js', 
			array('jquery'), 
			filemtime( plugin_dir_path(__FILE__) . '/activitypub-client.js' ), 
			true 
		);
	}

	/**
	 * Audience fields 
	 */
    public static function post_audience_html( $post ) {
		wp_nonce_field( 'ap_audience_meta', 'ap_audience_meta_nonce' );
		$audience = $inreplyto = null;
		if ( isset ( $post->ID ) ) {
			$audience = get_post_meta($post->ID, 'audience', true);
			$inreplyto = get_post_meta( $post->ID, 'inreplyto', true);
			if ( isset( $post->post_parent ) ){
				$post_parent = $post->post_parent;
			}
		}
		if (array_key_exists('audience', $_REQUEST)) {
			$audience = $_REQUEST['audience'];
		}
		if (array_key_exists('post_parent', $_REQUEST)) {
			$post_parent = $_REQUEST['post_parent'];
			$inreplyto = get_post_meta( $_REQUEST['post_parent'], 'source_url', true);
		}
		if (array_key_exists('comment_parent', $_REQUEST)) {
			$inreplyto = get_comment_meta( $_REQUEST['comment_parent'], 'source_url', true);
        }
		?>
		<style>
			label {
				display: inline-block;
    			margin-bottom: 4px;
			}
			label + select,
			label + textarea,
			label + input[type=text] {
				display: block;
				width: 100%;
			}
			div[class*=wrap]{
				padding: 10px;
			}
		</style>
		<?php //TODO: if Private message selected, mention required ?>
		<div class="select-wrap" id="aundience-wrap">
			<label for="audience"><?php _e('Post audience', 'activitypub' ); ?></label>
			<select name="audience" id="audience" class="postbox">
				<option value="pubilc" <?php selected($audience, 'public'); ?>><?php _e('Public', 'activitypub' ); ?></option>
				<option value="unlisted" <?php selected($audience, 'unlisted'); ?>><?php _e('Unlisted', 'activitypub' ); ?></option>
				<option value="followers_only" <?php selected($audience, 'followers_only'); ?>><?php _e('Followers only', 'activitypub' ); ?></option>
				<option value="private" <?php selected($audience, 'private'); ?>><?php _e('Private', 'activitypub' ); ?></option>
			</select>
		</div>
		<input type="hidden" name="post_parent" value="<?php echo $post_parent; ?>" />
		<input type="hidden" name="inreplyto" value="<?php echo $inreplyto; ?>" />
        <?php 
	}
}
