<?php
/**
 * This is the main /friends/ template.
 *
 * @package Friends
 */

$friends = Friends::get_instance();
include __DIR__ . '/header.php'; ?>
<section class="posts">
	<?php
	if ( ! have_posts() ) {
		if ( $friends->frontend->post_format ) {
			$post_formats = get_post_format_strings();
			// translators: %s is a post format title.
			echo esc_html( sprintf( __( "Your friends haven't posted anything with the post format %s yet!", 'friends' ), $post_formats[ $friends->frontend->post_format ] ) );
		} elseif ( isset( $_GET['p'] ) && $_GET['p'] > 1 ) {
			esc_html_e( 'No further posts of your friends could were found.', 'friends' );
		} else {
			esc_html_e( "Your friends haven't posted anything yet!", 'friends' );
		}
	} else {

		while ( have_posts() ) {
			the_post();
			$friend_user = new Friend_User( get_the_author_meta( 'ID' ) );
			$avatar = get_post_meta( get_the_ID(), 'gravatar', true );
			$recommendation = get_post_meta( get_the_ID(), 'recommendation', true );

			$part_base = __DIR__ . '/parts/content';
			$part = $part_base . '.php';
			$part_post_format = $part_base . '-' . get_post_format() . '.php';
			if ( file_exists( $part_post_format ) ) {
				include $part_post_format;
			} else {
				include $part;
			}
		}

		the_posts_navigation();
	}
	?>
</section>
<?php
include __DIR__ . '/footer.php';
