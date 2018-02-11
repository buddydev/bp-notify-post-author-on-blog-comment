<?php
/**
 * Plugin Name: BuddyPress Notify Post Author on Blog Comment
 * Plugin URI: https://buddydev.com/plugins/bp-notify-post-author-on-blog-comment/
 * Author: BuddyDev
 * Author URI: https://buddydev.com/
 * Description: Notify the Blog post author of any new comment on their blog post
 * Version: 1.0.5
 * License: GPL
 * Text Domain: bp-notify-post-author-on-blog-comment
 * Domain Path: /languages/
 */

class BDBP_Blog_Comment_Notifier {

	private static $instance;
	
	private $id = 'blog_comment_notifier';
	
	private function __construct() {
	    $this->setup();
	}
	
	/**
	 * 
	 * @return BDBP_Blog_Comment_Notifier
	 */
	public static function get_instance() {
		
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}
		
		return self::$instance;
		
	}

    /**
     * Setup necessary hooks
     */
	public function setup() {

        add_action( 'bp_setup_globals', array( $this, 'setup_globals' ) );

        //On New comment
        add_action( 'comment_post', array( $this, 'comment_posted' ), 15, 2 );
        //on delete post, we should delete all notifications for the comment on that post
        //add_action( 'delete_post', array( $this, 'post_deleted' ), 10, 2 );

        // Monitor actions on existing comments
        add_action( 'deleted_comment', array( $this, 'comment_deleted' ) );
        //add_action( 'trashed_comment', array( $this, 'comment_deleted' ) );
        //add_action( 'spam_comment', array( $this, 'comment_deleted' ) );
        //should we do something on the action untrash_comment & unspam_comment

        add_action( 'wp_set_comment_status', array( $this, 'comment_status_changed' ) );

        // Load plugin text domain
        add_action( 'bp_init', array( $this, 'load_textdomain' ) );
        add_action( 'template_redirect', array( $this, 'mark_read' ) );
    }

    /**
     * Load plugin text domain
     *
     * @hook action plugins_loaded
     */
    public function load_textdomain() {
        load_plugin_textdomain( 'bp-notify-post-author-on-blog-comment', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    }
	
	public function setup_globals() {
		//BD: BuddyDev
		if ( ! defined( 'BD_BLOG_NOTIFIER_SLUG' ) ) {
			define( 'BD_BLOG_NOTIFIER_SLUG', 'bd-blog-notifier' );
		}
		
		$bp = buddypress();
		
		$bp->blog_comment_notifier = new stdClass();
		$bp->blog_comment_notifier->id = $this->id;//I asume others are not going to use this is
		$bp->blog_comment_notifier->slug = BD_BLOG_NOTIFIER_SLUG;
		$bp->blog_comment_notifier->notification_callback = array( $this, 'format_notifications' );//show the notification
    
		/* Register this in the active components array */
		$bp->active_components[ $bp->blog_comment_notifier->id ] = $bp->blog_comment_notifier->id;

		do_action( 'blog_comment_notifier_setup_globals' );
	}
	

	/**
	 * Notify when  a comment is posted
	 * 
	 * @param int $comment_id
	 * @param  $comment_status
	 * @return null
	 */
	public function comment_posted( $comment_id = 0, $comment_status = 0 ) {
		
		if ( ! $this->is_bp_active() ) {
			return ;
		}
		
		$comment = get_comment( $comment_id );
		
		//if the comment does not exists(not likely), or if the comment is marked as spam, we don't take any action
		if ( empty( $comment ) || $comment->comment_approved == 'spam' ) {
			return ;
		}
		
		//should we handle trackback? currently we don't
		if ( $comment->comment_type == 'trackback' || $comment->comment_type == 'pingback'  ) {
			return ;
		}
		
		
		$post_id = $comment->comment_post_ID;
		
		$post = get_post( $post_id );
		
		//no need to generate any notification if an author is making comment
		if ( $post->post_author == $comment->user_id ) {
			return ;
		}
		
		//can the post author moderate comment?
		if ( ! user_can( $post->post_author, 'moderate_comments'  ) && $comment->comment_approved == 0  ) {
			return;
		}
		//if we are here, we must provide a notification to the author of the post
						
		$this->notify( $post->post_author, $comment );
		
		
	}

	/**
	 * When a comment status changes, we check for the notification and also 
	 * think about changing the read link?
	 * @param int $comment_id
	 * @param int $comment_status
	 * 
	 */
	public function comment_status_changed( $comment_id = 0, $comment_status = 0 ) {
		
		if ( ! $this->is_bp_active() ) {
			return ;
		}
		
		$comment = get_comment( $comment_id );
		
		if ( empty( $comment ) ) {
			return ;
		}

		//we are only interested in 2 cases
		//1. comment is notified and then it was marked as deleted or spam?
		if (  $comment->comment_approved == 'spam' || $comment->comment_approve == 'trash'  ) {
			
			if ( $this->is_notified( $comment_id ) ) {
				$this->comment_deleted( $comment_id );
			}
			
			return;
		}
		
		//if an apprived comment is marked as pending,  delete notification
		if ( $comment->comment_approve == 0 && $this->is_notified( $comment_id ) ) {
			$this->comment_deleted( $comment_id );
			return ;
			
		}
		
		if ( $comment->comment_approve == 1 ) {
			
			$post = get_post( $comment->comment_post_ID );
			
			if ( get_current_user_id() == $post->post_author ) {
				
				if ( $this->is_notified( $comment_id ) ) {
					$this->comment_deleted ( $comment_id );
				}
				
				return ;
				
			} else {
				
				//if approver is not the author
				
				$this->notify( $post->post_author, $comment );
			}
			
		}
		
	}
	/**
	 * On Comment Delete
	 * @param type $comment_id
	 */
	public function comment_deleted( $comment_id ) {
		
		
		if ( ! $this->is_bp_active() ) {
			return;
		}
		
		bp_notifications_delete_all_notifications_by_type( $comment_id, $this->id );
		
		$this->unmark_notified( $comment_id );
	}
	/**
	 * Generate human readable notification
	 *  
	 * @param string $action
	 * @param string $comment_id
	 * @param string $secondary_item_id
	 * @param string $total_items
	 * @param string $format
	 * @return mixed
	 */
	public function format_notifications( $action, $comment_id, $secondary_item_id, $total_items, $format = 'string',  $notification_id = 0 ) {
   
		$bp = buddypress();
		$switched = false;
		$blog_id = bp_notifications_get_meta( $notification_id, '_blog_id' );

		if ( $blog_id && get_current_blog_id() != $blog_id ) {
			switch_to_blog( $blog_id );
			$switched = true;
		}
		$comment = get_comment( $comment_id );
		
		$post = get_post( $comment->comment_post_ID);
		
		$link = $text = $name = $post_title = $comment_content ='';
		
		if ( $comment->user_id ) {
			$name = bp_core_get_user_displayname ( $comment->user_id );
		} else {
			$name = $comment->comment_author;
		}
		
		$post_title = $post->post_title;
		
		$comment_content = wp_trim_words( $comment->comment_content, 12,  ' ...' );
		
        $text = sprintf(
            __( '%s commented on <strong>%s</strong>: <em>%s</em>', 'bp-notify-post-author-on-blog-comment' ),
            $name,
            $post_title,
            $comment_content
        );
		
		if ( $comment->comment_approved == 1 ) {

				$link = get_comment_link ( $comment );

		} else {
			$link =admin_url( 'comment.php?action=approve&c=' . $comment_id );
		}

		if( $switched ) {
			restore_current_blog();
		}

		if ( $format == 'string' ) {
		
		 return apply_filters( 'bp_blog_notieifier_new_comment_notification_string', '<a href="' . $link . '">' . $text . '</a>' );
		
		}else{
		 
        return array(
                'link'  => $link,
                'text'  => $text);
     
		}
    
	return false;
	}

	/**
	 * Is BuddyPress Active
	 * We test to avoid any fatal errors when Bp is not active
	 * 
	 * @return boolean
	 */
	public function is_bp_active() {
		
		if ( function_exists( 'buddypress' ) ) {
			return true;
		}
		
		return false;
	}
	
	
	/**
	 * Was the comment already added to bp notification?
	 * 
	 * @param type $comment_id
	 * @return boolean
	 */
	public function is_notified( $comment_id ) {
		
		return get_comment_meta( $comment_id, 'bd_post_author_notified', true );
	}
	/**
	 * Mark that a comment was notified to the post author
	 * 
	 * @param int $comment_id
	 */
	public function mark_notified( $comment_id ) {
		
		update_comment_meta( $comment_id, 'bd_post_author_notified', 1 );
	}
	
	/**
	 * Delete the notification mark meta
	 * 
	 * @param int $comment_id
	 */
	public function unmark_notified( $comment_id ) {
		
		delete_comment_meta( $comment_id, 'bd_post_author_notified' );
	}
	
	public function notify( $user_id, $comment ) {
	
		$comment_id = $comment->comment_ID;
		$notificatin_id = bp_notifications_add_notification( array(
                   
                   'item_id'            => $comment_id,
                   'user_id'            => $user_id,
                   'component_name'     => $this->id,
                   'component_action'   => 'new_blog_comment_'. $comment_id,
                   'secondary_item_id'  => $comment->comment_post_ID,
                ));

		if ( $notificatin_id && is_multisite() ) {
			bp_notifications_add_meta( $notificatin_id, '_blog_id', get_current_blog_id() );
		}
		
		$this->mark_notified( $comment_id );
		
		
	}

	public function mark_read() {


        if ( ! $this->is_bp_active() || ! is_singular() ) {
			return ;
		}

		$post_id = get_queried_object_id();

		if ( ! $post_id ) {
			return ;
		}

		return BP_Notifications_Notification::update(
			array( 'is_new' => 0 ),
			array( 'secondary_item_id' => $post_id,
			       'component_name'    => $this->id,
			       'user_id'           => get_current_user_id(),
				)
			);


	}
	
	//we need to delete all notification for the user when he/she visits the single blog post?
	//no, we won't as there is no sure way to know if the user has seen a comment on the front end or not
	
	
}
//initialize
BDBP_Blog_Comment_Notifier::get_instance();