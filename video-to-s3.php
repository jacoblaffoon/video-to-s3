<?php
/*
Plugin Name: Video to S3
Description: Upload videos from WordPress media library to an AWS S3 bucket.
Version: 1.0
Author: Jacob Laffoon
*/

// Prevent direct access
if (!defined('ABSPATH')) exit;

// Define constants
define('VIDEO_TO_S3_PATH', plugin_dir_path(__FILE__));
define('VIDEO_TO_S3_URL', plugin_dir_url(__FILE__));

// Include necessary files
require_once VIDEO_TO_S3_PATH . 'vendor/autoload.php';
require_once VIDEO_TO_S3_PATH . 'includes/functions.php';

// Admin menu
add_action('admin_menu', 'video_to_s3_add_admin_menu');

function video_to_s3_add_admin_menu() {
    add_menu_page('Video to S3', 'Video to S3', 'manage_options', 'video-to-s3', 'video_to_s3_dashboard');
}

// Dashboard page content
function video_to_s3_dashboard() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    echo '<div class="wrap">';
    echo '<h1>Video to S3 Dashboard</h1>';
    
    // Display any transient notices
    if ($messages = get_transient('vts_upload_messages')) {
        foreach ($messages as $message) {
            $notice_type = strpos($message, '[error]') !== false ? 'error' : 'info';
            echo '<div class="notice notice-' . $notice_type . ' is-dismissible"><p>' . esc_html(str_replace(['[error]', '[info]'], '', $message)) . '</p></div>';
        }
        delete_transient('vts_upload_messages');
    }

    // List contents of S3 bucket
    $bucket_contents = video_to_s3_list_bucket_contents();
    echo '<h2>Contents of S3 Bucket</h2>';
    if (!empty($bucket_contents)) {
        echo '<ul>';
        foreach ($bucket_contents as $video) {
            echo '<li>' . esc_html($video) . '</li>';
        }
        echo '</ul>';
    } else {
        echo '<p>No videos found in the S3 bucket.</p>';
    }

    // Form for AWS credentials
    echo '<form method="post" action="options.php">';
    settings_fields('video_to_s3_options');
    wp_nonce_field('update-aws-credentials', 'update_aws_credentials_nonce');
    do_settings_sections('video_to_s3');
    submit_button('Update AWS Credentials');

    // Check if form submitted and update options
    if (isset($_POST['submit']) && wp_verify_nonce($_POST['update_aws_credentials_nonce'], 'update-aws-credentials')) {
        echo '<p class="notice notice-success is-dismissible">Settings Updated!</p>';
    }

    echo '<h3>Upload Videos to S3</h3>';
    echo '<p><a href="' . admin_url('admin-post.php?action=upload_videos_to_s3') . '" class="button">Upload Videos to S3</a></p>';
    echo '</div>';
}

function video_to_s3_list_bucket_contents() {
    $aws_key = get_option('vts_aws_key');
    $aws_secret = get_option('vts_aws_secret');
    $bucket = get_option('vts_aws_bucket');
    $region = get_option('vts_aws_region');

    $s3 = new S3Client([
        'version' => 'latest',
        'region' => $region,
        'credentials' => [
            'key' => $aws_key,
            'secret' => $aws_secret,
        ]
    ]);

    try {
        $objects = $s3->getIterator('ListObjects', [
            'Bucket' => $bucket,
        ]);

        $videos_in_bucket = [];
        foreach ($objects as $object) {
            $videos_in_bucket[] = $object['Key'];
        }
        return $videos_in_bucket;
    } catch (Exception $e) {
        error_log("Error listing S3 bucket contents: " . $e->getMessage());
        return []; // Return an empty array on failure
    }
}

// Register settings
add_action('admin_init', 'video_to_s3_register_settings');
function video_to_s3_register_settings() {
    register_setting('video_to_s3_options', 'vts_aws_key');
    register_setting('video_to_s3_options', 'vts_aws_secret');
    register_setting('video_to_s3_options', 'vts_aws_bucket');
    register_setting('video_to_s3_options', 'vts_aws_region');

    add_settings_section('video_to_s3_section', 'AWS Settings', null, 'video_to_s3');

    add_settings_field('aws_key', 'AWS Access Key', 'video_to_s3_aws_key_field', 'video_to_s3', 'video_to_s3_section');
    add_settings_field('aws_secret', 'AWS Secret Key', 'video_to_s3_aws_secret_field', 'video_to_s3', 'video_to_s3_section');
    add_settings_field('aws_bucket', 'S3 Bucket Name', 'video_to_s3_aws_bucket_field', 'video_to_s3', 'video_to_s3_section');
    add_settings_field('aws_region', 'AWS Region', 'video_to_s3_aws_region_field', 'video_to_s3', 'video_to_s3_section');
}

// Helper functions for settings fields
function video_to_s3_aws_key_field() { 
    echo '<input type="text" name="vts_aws_key" value="' . esc_attr(get_option('vts_aws_key')) . '" />'; 
}
function video_to_s3_aws_secret_field() { 
    echo '<input type="text" name="vts_aws_secret" value="' . esc_attr(get_option('vts_aws_secret')) . '" />'; 
}
function video_to_s3_aws_bucket_field() { 
    echo '<input type="text" name="vts_aws_bucket" value="' . esc_attr(get_option('vts_aws_bucket')) . '" />'; 
}
function video_to_s3_aws_region_field() { 
    echo '<input type="text" name="vts_aws_region" value="' . esc_attr(get_option('vts_aws_region')) . '" />'; 
}

// Handle upload action
add_action('admin_post_upload_videos_to_s3', 'video_to_s3_upload_videos');
function video_to_s3_upload_videos() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }
    
    video_to_s3_upload_videos_logic(); 

    // Set a transient to inform the user about the upload
    set_transient('vts_upload_messages', ['[info]Upload process completed.'], 60);

    // Redirect back to the dashboard
    wp_redirect(admin_url('admin.php?page=video-to-s3'));
    exit;
}