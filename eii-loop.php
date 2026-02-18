<?php

/*---------------------------------------------------------------*/
/* THEME SETUP
/*---------------------------------------------------------------*/
function eii_setup() { 
    add_theme_support( 'post-thumbnails' );
    add_theme_support( 'align-wide' ); 

    add_theme_support( 'disable-custom-font-sizes' );
    add_theme_support( 'disable-custom-colors' );
    add_theme_support( 'disable-custom-gradients' );
    add_theme_support( 'custom-units', array() );

    remove_theme_support( 'core-block-patterns' );

    add_theme_support( 'editor-styles' );
    add_editor_style( 'dist/css/admin/wp-editor.css' );
}
add_action( 'after_setup_theme', 'eii_setup' );


/*---------------------------------------------------------------*/
/* IS_BLOG
/*---------------------------------------------------------------*/
if ( !function_exists( 'isBlog' ) ) {
    function isBlog(){
        if( is_404() )
            return false;

        if( is_search() )
            return false;

        if( get_post_type() == 'post' )
            return true;

        return false;
    }
}


/*---------------------------------------------------------------*/
/* EII - POST DATE
/*---------------------------------------------------------------*/
function eii_the_date( $format = 'F j, Y' ) {
    printf( '<time datetime="%1$s">%2$s</time>',
        get_the_time('Y-m-j'),
        get_the_time( $format )
    );
}


/*---------------------------------------------------------------*/
/* EII - POST AUTHOR
/*---------------------------------------------------------------*/
function eii_the_author() {
    printf( '<span class="vcard author"><a class="url fn n" href="%1$s" rel="author" title="%2$s">%3$s</a></span>',
        get_author_posts_url( get_the_author_meta( 'ID' ) ),
        esc_attr( 'View all posts by '.  get_the_author() ),
        get_the_author()
    );
}


/*---------------------------------------------------------------*/
/* EII - POST CATEGORY
/*---------------------------------------------------------------*/
function eii_the_category() {
    $categories = get_the_category( get_the_ID() );

    $output = '<ul class="categories">';

    foreach ( $categories as $category ) {
        $output .= '<li>';
        $output .= '<a class="" href="'. get_category_link( $category->term_id ) .'">'. $category->name . '</a>';
        $output .= '</li>';
    }

    $output .= '</ul>';

    echo $output;
}


/*---------------------------------------------------------------*/
/* EII - FEATURED IMAGE
/*---------------------------------------------------------------*/
if( !function_exists( 'eii_the_featured_image' ) ){
    function eii_the_featured_image( $size = "large" ){
        if ( has_post_thumbnail( get_the_ID() ) ) {
            echo '<span class="featured-image">' . get_the_post_thumbnail( get_the_ID(), $size ) . '</span>';
        } else {
            echo '<span class="featured-image"><img src="' . get_home_url() .'/'. eii_get_config('og_image') . '" alt="'. get_option( 'blogname' ) .'"></span>';
        }
    }
}  


/*---------------------------------------------------------------*/
/* FILTER - Comments Count - Do not count trackbacks or pingbacks
/*---------------------------------------------------------------*/
function comment_count( $count ) {
    $comment_count = 0;
    $comments = get_approved_comments( $GLOBALS['id'] );

    foreach ( $comments as $comment )
        if ( $comment->comment_type != 'trackback' && $comment->comment_type != 'pingback' ) 
            $comment_count++;

    return $comment_count;
}
if( !is_admin() ) add_filter( 'get_comments_number', 'comment_count', 0 );


/*---------------------------------------------------------------*/
/* EII - EXCERPT
/*---------------------------------------------------------------*/
function eii_the_excerpt( $post_id = null, $length = 55, $more = ' &hellip;' ) {
    $post_id = is_null( $post_id ) ? get_the_ID() : $post_id;
    echo eii_get_the_excerpt( $post_id, $length, $more );
}

function eii_get_the_excerpt( $post_id = null, $length = 55, $more = ' &hellip;' ) {
    $post_id = is_null( $post_id ) ? get_the_ID() : $post_id;
    if( has_excerpt( $post_id ) )
        return get_the_excerpt( $post_id );

    $text = get_the_content( $more, false, $post_id );
    $text = strip_shortcodes( $text );

    return wp_trim_words( $text, $length, $more );
}


/*---------------------------------------------------------------*/
/* EII - READ MORE - MORE LINK
/*---------------------------------------------------------------*/
function eii_the_read_more( $text='Read More', $class='' ){
    echo '<a href="'. get_the_permalink( get_the_ID() ) .'" class="more-link '. $class .'" >'. __($text) . '</a>';
}


/*---------------------------------------------------------------*/
/* ADD 'LAST' CLASS TO LAST POST WITHIN THE LOOP
/*---------------------------------------------------------------*/
add_filter('post_class', function($classes){
    global $wp_query, $eii_query;

    if( $eii_query ){ 
        if( ($eii_query->current_post + 1) == $eii_query->post_count )
            $classes[] = 'last';
    } else {
        if( ($wp_query->current_post + 1) == $wp_query->post_count )
            $classes[] = 'last';
    }

    return $classes;
});


/*---------------------------------------------------------------*/
/* PAGINATION BUTTONS - SINGLE
/*---------------------------------------------------------------*/
add_filter('next_post_link', 'next_post_link_attributes');
add_filter('previous_post_link', 'prev_post_link_attributes');

function next_post_link_attributes( $output ) {
    $code = 'class="next page-numbers"';
    return str_replace('<a href=', '<a '.$code.' href=', $output);
}

function prev_post_link_attributes( $output ) {
    $code = 'class="prev page-numbers"';
    return str_replace('<a href=', '<a '.$code.' href=', $output);
}


/*---------------------------------------------------------------*/
/* COMMENTS
/*---------------------------------------------------------------*/
function eii_custom_comment( $comment, $args, $depth ) {
    switch ( $comment->comment_type ) :
        case 'pingback'  :
        case 'trackback' :
            ?>
            <li class="post pingback">
                <p>Pingback: <?php comment_author_link(); ?><?php edit_comment_link( '(Edit)' ); ?></p>
            </li>
            <?php
            break;

        default: // normal comment
            ?>
            <li <?php comment_class(); ?> id="li-comment-<?php comment_ID(); ?>">
                <article id="comment-<?php comment_ID(); ?>">
                    <div class="comment-author vcard">
                        <?php echo get_avatar( $comment, 40 ); ?>
                        <?php printf(
                            '%s <span class="says">says:</span>',
                            sprintf( '<cite class="fn">%s</cite>', get_comment_author_link() )
                        ); ?>
                    </div><!-- .comment-author .vcard -->

                    <?php if ( $comment->comment_approved == '0' ) : ?>
                        <em>Your comment is awaiting moderation.</em>
                        <br />
                    <?php endif; ?>

                    <div class="comment-meta commentmetadata">
                        <a href="<?php echo esc_url( get_comment_link( $comment->comment_ID ) ); ?>">
                            <?php printf( '%1$s at %2$s', get_comment_date(), get_comment_time() ); ?>
                        </a>
                        <?php edit_comment_link( '(Edit)', ' ' ); ?>
                    </div><!-- .comment-meta .commentmetadata -->

                    <div class="clear"></div>
                    <div class="comment-body"><?php comment_text(); ?></div>
                    <div class="comment-reply">
                        <?php comment_reply_link( array_merge( $args, array(
                            'depth' => $depth,
                            'max_depth' => $args['max_depth']
                        ) ) ); ?>
                    </div><!-- .reply -->
                    <div class="clear"></div>
                </article><!-- #comment-## -->
            </li>
            <?php
            break;
    endswitch;
}

?>
