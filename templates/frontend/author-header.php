<?php
/**
 * This template contains the author header on /friends/.
 *
 * @package Friends
 */

$edit_user_link = $args['friends']->admin->admin_edit_user_link( false, get_the_author_meta( 'ID' ) );
$feeds = count( $args['friend_user']->get_feeds() );
$active_feeds = count( $args['friend_user']->get_active_feeds() );

?><div>
<h2 id="page-title"><?php echo get_the_author_meta( 'display_name' ); ?>
<?php
$args['friends']->frontend->link(
	$args['friend_user']->user_url,
	'',
	array(
		'class' => 'label dashicons dashicons-external',
		'style' => 'vertical-align: middle; margin-left: .5em',
	),
	$args['friend_user']
);
?>

</h2>

<span class="chip"><?php echo esc_html( $args['friend_user']->get_role_name() ); ?></span>

<span class="chip"><?php echo esc_html( sprintf( /* translators: %s is a localized date (F j, Y) */__( 'Since %s', 'friends' ), date_i18n( __( 'F j, Y' ), strtotime( $args['friend_user']->user_registered ) ) ) ); ?></span>

<?php foreach ( $args['friend_user']->get_post_count_by_post_format() as $post_format => $count ) : ?>
	<a class="chip" href="<?php echo esc_attr( $args['friend_user']->get_local_friends_page_post_format_url( $post_format ) ); ?>"><?php echo esc_html( $args['friends']->get_post_format_plural_string( $post_format, $count ) ); ?></a>
<?php endforeach; ?>

<?php if ( $edit_user_link ) : ?>
<a class="chip" href="<?php echo esc_attr( $edit_user_link ); ?>">
	<?php echo esc_html( sprintf( /* translators: %s is the number of feeds */_n( '%s feed', '%s feeds', $active_feeds, 'friends' ), $active_feeds ) ); ?>

	<?php if ( $feeds - $active_feeds > 1 ) : ?>
	&nbsp;<small><?php echo esc_html( sprintf( /* translators: %s is the number of feeds */_n( '(+%s more)', '(+%s more)', $feeds - $active_feeds, 'friends' ), $feeds - $active_feeds ) ); ?></small>
	<?php endif; ?>

</a>

<a class="chip" href="<?php echo esc_attr( $edit_user_link ); ?>"><?php esc_html_e( 'Edit' ); ?></a>
<?php endif; ?>

<?php if ( $args['friend_user']->can_refresh_feeds() && apply_filters( 'friends_debug', false ) ) : ?>
<a class="chip" href="<?php echo esc_url( self_admin_url( 'admin.php?page=friends-refresh&user=' . $args['friend_user']->ID ) ); ?>"><?php esc_html_e( 'Refresh', 'friends' ); ?></a>
<?php endif; ?>
<?php do_action( 'friends_author_header', $args['friend_user'] ); ?>
</div>