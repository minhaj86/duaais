<?php
/**
 * Comments template.
 *
 * @package DUAAIS
 */

if ( post_password_required() ) {
	return;
}
?>

<section class="comments-area" id="comments">
	<?php if ( have_comments() ) : ?>
		<h2 class="comments-title">
			<?php
			printf(
				esc_html( _nx( '%1$s comment', '%1$s comments', get_comments_number(), 'comments title', 'duaais' ) ),
				esc_html( number_format_i18n( get_comments_number() ) )
			);
			?>
		</h2>

		<ol class="comment-list">
			<?php
			wp_list_comments(
				array(
					'avatar_size' => 48,
					'style'       => 'ol',
					'short_ping'  => true,
				)
			);
			?>
		</ol>

		<?php the_comments_pagination(); ?>
	<?php endif; ?>

	<?php if ( ! comments_open() && get_comments_number() ) : ?>
		<p><?php esc_html_e( 'Comments are closed.', 'duaais' ); ?></p>
	<?php endif; ?>

	<?php
	comment_form(
		array(
			'class_container'      => 'comment-respond',
			'class_form'           => 'comment-form',
			'title_reply'          => __( 'Join the conversation', 'duaais' ),
			'title_reply_before'   => '<h2 id="reply-title" class="comment-reply-title">',
			'title_reply_after'    => '</h2>',
			'label_submit'         => __( 'Post comment', 'duaais' ),
			'comment_notes_before' => '<p class="comment-notes">' . esc_html__( 'Your email address will not be published.', 'duaais' ) . '</p>',
		)
	);
	?>
</section>