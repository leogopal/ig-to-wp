<?php

/*  
Script used to import Instagram data export to WordPress using WP-CLI

Prerequisities:
 * Download this php-file (script) to your wp-content folder (ie. wp-content/igtowp.php) 
 * Download json + media files from IG to ig_download folder (ie. wp-content/ig_download/media.json)
 * Create temp folder for auto generated video thumbnails inside ig_download folder (ie. wp-content/ig_download/temp)
 * WP-CLI installed and configured
 
Some paths (eg. path to FFMPEG binary) needs to be updated in code

It uses the filenames from IG as page sluq to make it unique

To run it you WP-CLI: wp eval-file igtowp.php
You probably want to regenerate thumbnails after import. Use WP-CLI: wp media regenerate --yes

I use a temporary WP install to import the data. I then massage it manually before I export+import it to my live site.

I'm using the following plugins on the import-site:
 * Bulk delete & Media cleaner to empty the site

 * Core functionallity, Display posts, Display posts - pagination to show the posts in a grid
   Shortcode to show posts in grid: [display-posts image_size="thumbnail" wrapper="div" wrapper_class="display-posts-listing grid" posts_per_page="500" pagination="true"]
   The CSS is in the child theme

 * All-in-one WP Migration V6.77 + the built in WP import / export to move + migrate + merge sites

TODO: fix logging / error handling

*/

parsejson();

function parsejson(){

    error_reporting( error_reporting() & ~E_NOTICE );

    $ig_json_file_uri = get_stylesheet_directory() . "/../../ig_download/media.json";
    $slices = json_decode( file_get_contents( $ig_json_file_uri ), true );
    
    //convert_photos_to_wp_posts($slices['photos'][0]);
    //convert_videos_to_wp_posts($slices['videos'][0]);
    
/*
    WP_CLI::log("Starting to import photos");
    if ($slices['photos']) {
        foreach ($slices['photos'] as $slice) {
            convert_photos_to_wp_posts($slice);
       }
    }

    WP_CLI::log("Starting to import videos");
    if ($slices['videos']) { 
        foreach ($slices['videos'] as $slice) {
           convert_videos_to_wp_posts($slice);
       }
    }
*/
}

function convert_photos_to_wp_posts($insta_photos){
    convert_insta_posts_to_wp_posts($insta_photos, true);
}
function convert_videos_to_wp_posts($insta_videos){
    convert_insta_posts_to_wp_posts($insta_videos, false);
}

function convert_insta_posts_to_wp_posts( $photos, $is_photo ){

    WP_CLI::log(".");

    $wp_photo_category_id = 1;
    $wp_video_category_id = 5;

    $post_id = -1;
    $author_id = 1;
    $taken_at = $photos['taken_at'];
    $yyyymm = $photos['taken_at'][0].$photos['taken_at'][1].$photos['taken_at'][2].$photos['taken_at'][3].'/'.$photos['taken_at'][5].$photos['taken_at'][6];
    $yyyymmddhhmmss = str_replace('T',' ',$photos['taken_at']);
    $title = $photos['caption'];
    
    $location = "";
    if($photos['location'] != null)
        $location = $photos['location'];
    $local_file_name = explode("/",$photos['path'])[2];
    $local_directory = get_stylesheet_directory() . "/../../ig_download/";
    $ig_temp_directory = get_stylesheet_directory() . "/../../ig_download/temp";
    $local_file_path = $local_directory . $photos['path'] . '';
    $post_content = '<section class="insta-caption">' . $photos['caption'] . '</section><section class="insta-location">' . $location . '</section><section class="insta-imported">This post was automatically imported from Instagram 2019-10-10.</section>';

    if(strlen ($title) > 30) {
        $title = substr($title, 0, 27) . '...';
    }
    if(strlen ($title) < 1) {
        $title = $yyyymmddhhmmss;
    }

    $post_category = array();
    if($is_photo){
        $post_category[] = $wp_photo_category_id;
    } else {
        $post_category[] = $wp_video_category_id;
    }

    if (empty($local_file_name)){
        WP_CLI::log("Skipping - no file name for post: " . $photos);
        return;
    }

    //todo: use sanitize wp method instead to make sure we check with the same string every time?
    $wp_page_slug = str_replace('.','-',local_file_name);

//Create post without adding the image to get a post id where we can attach the image after it's uploaded
    $wp_post = array(
        'post_name'         =>  $wp_page_slug,
        'comment_status'	=>	'closed',
        'ping_status'		=>	'closed',
        'post_date'         =>  $taken_at,
        'post_author'		=>	$author_id,
        'post_title'		=>	$title,
        'post_content'      =>  $post_content,
        'post_status'		=>	'publish',
        'post_type'		    =>	'post',
        'post_category'     =>  $post_category
    );

    if( null == get_page_by_path( $wp_post['post_name'] )) {
        $post_id = wp_insert_post($wp_post);
    } else {
        WP_CLI::log("Inserting post failed");
        WP_CLI::log("photos:");
        var_dump($photos);
        WP_CLI::log("wp_post:");
        var_dump($wp_post);
        WP_CLI::log("post_id:");
        var_dump($post_id);

        return;
    }

//upload imgage without attaching it to a certain post
    $media_upload = upload_file( $local_file_name, $local_file_path, $yyyymm);
    if(!$media_upload)
        return;
    $thumb_upload = null;
    if(!$is_photo){
        //Generate video thumbnail + upload video thumbnail file
        $video_thumb_file_path = generate_thumbnail_from_video($local_file_name, $local_file_path, $taken_at, $ig_temp_directory);
        $explvtfp = explode('/',$video_thumb_file_path);
        $uploaded_thumb_file_name = end($explvtfp);
        $thumb_upload = upload_file( $uploaded_thumb_file_name, $video_thumb_file_path, $yyyymm );
        if(!$thumb_upload)
            return;
    }

//Attach uploaded file to post
    $uploaded_file_path = $media_upload['file'];
    $uploaded_url = $media_upload['url'];
    $explfp = explode('/',$uploaded_file_path);
    $uploaded_file_name = end($explfp);
    if($is_photo) {
        $attach_id = attach_file($uploaded_file_path, $uploaded_file_name, $post_id, true);
    }
    if(!$is_photo){
        //upload video file
        $attach_id = attach_file($uploaded_file_path, $uploaded_file_name, $post_id, false);

        $uploaded_thumb_file_path = $thumb_upload['file'];
        $uploaded_thumb_url = $thumb_upload['url'];
        $utfp = explode('/',$uploaded_thumb_file_path);
        $uploaded_thumb_file_name = end($utfp);

        $attach_thumb_id = attach_file($uploaded_thumb_file_path, $uploaded_thumb_file_name, $post_id, false);

        set_post_thumbnail( $post_id, $attach_thumb_id ); //add thumbnail to featured image 
    }

//If photo set as featured image (adding img. to post content instead)
    /*if($is_photo){
        set_post_thumbnail( $post_id, $attach_id );
    }*/

//Add uploaded file to post content

    $post_with_image_or_video = array(
        'ID'           => $post_id,
        'post_content' => ''
    );

    if($is_photo){
        $post_with_image_or_video['post_content'] = '<figure><img src="' . $uploaded_url . '"></figure>' . $wp_post['post_content'];
    }
    if(!$is_photo){
        $post_with_image_or_video['post_content'] = '<video controls><source src="' . $uploaded_url . '" type="video/mp4"></video>' . $wp_post['post_content'];
    }

    $updpost = wp_update_post( $post_with_image_or_video, true );
    
    if (!$updpost>0) {
        WP_CLI::log("Updating post failed");
        WP_CLI::log("post_with_image_or_video: ");
        var_dump($post_with_image_or_video);
        WP_CLI::log("updpost");
        var_dump($updpost);
        WP_CLI::log("photos");
        var_dump($photos);
        WP_CLI::log("wp_post");
        var_dump($wp_post);
        WP_CLI::log("post_id");
        var_dump($post_id);
    }
}

/* unused
function get_next_unique_post_title($title){

    if ( ! is_admin() ) {
        require_once( ABSPATH . 'wp-admin/includes/post.php' );
    }

    for ($i = 1; $i <= 20; $i++) {
        if(post_exists( $title ) ) {
            $title = $title . " - " . $i;
        }
    }
    if(post_exists( $title ) ) {
        $title = $title . " - " . $local_file_name;
    }

    return $title;
}*/

function upload_file( $file_name, $file_path, $yyyymm ){
    
    $file_bits = file_get_contents($file_path);
    
    if ($file_bits === false) {
        WP_CLI::log("Reading file failed. file_path: " . $file_path);
        var_dump($file_bits);
        return false;
    }

    $uploaded = wp_upload_bits($file_name, null, $file_bits, $yyyymm);

    if ( $uploaded['error'] == false ) {
        return $uploaded;   
    }
    
    WP_CLI::log("Uploading to WP failed");
    var_dump($uploaded);
    return false;
}

function attach_file( $file_path, $file_name, $parent_post_id, $is_photo ) {
    $filetype = wp_check_filetype( basename( $file_path ) );
    $wp_upload_dir = wp_upload_dir();

    $attachment = array(
        'post_title'     => basename( $file_name ),
        'post_content'   => '',
        'post_status'    => 'inherit',
        'post_mime_type' => $filetype['type'],
    );

    $attach_id = wp_insert_attachment( $attachment, $file_path, $parent_post_id );

    if($is_photo){
        require_once( ABSPATH . 'wp-admin/includes/image.php' );
        $attach_data = wp_generate_attachment_metadata( $attach_id, $file_path );
        wp_update_attachment_metadata( $attach_id, $attach_data );
    }
    
    return $attach_id;
}

function generate_thumbnail_from_video($local_file_name, $local_video_file_path, $taken_at, $ig_temp_directory) {
    $temp_image_file_path = $ig_temp_directory . "/" . $local_file_name . '.jpg';

    $ffmpeg_path = '/usr/local/bin/ffmpeg';

    // timestamp: HH:MM:SS.fff
    $ffmpeg_cmd = "$ffmpeg_path -i $local_video_file_path -ss 00:00:01.000 -y -vframes 1 $temp_image_file_path";
    shell_exec($ffmpeg_cmd);

    return $temp_image_file_path;
}

?>
