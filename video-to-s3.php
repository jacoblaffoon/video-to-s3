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
require_once VIDEO_TO_S3_PATH . 'includes/aws-sdk/aws-autoloader.php';
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
    
    // Form for AWS credentials (in production, use a more secure method like environment variables or an options page)
    echo '<form method="post" action="">';
    settings_fields('video_to_s3_options');
    do_settings_sections('video_to_s3');
    submit_button('Update AWS Credentials');

    // Check if form submitted and update options
    if (isset($_POST['submit'])) {
        update_option('vts_aws_key', sanitize_text_field($_POST['aws_key']));
        update_option('vts_aws_secret', sanitize_text_field($_POST['aws_secret']));
        update_option('vts_aws_bucket', sanitize_text_field($_POST['aws_bucket']));
        update_option('vts_aws_region', sanitize_text_field($_POST['aws_region']));
        echo '<p>Settings Updated!</p>';
    }

    echo '<h3>Upload Videos to S3</h3>';
    echo '<p><a href="' . admin_url('admin-post.php?action=upload_videos_to_s3') . '" class="button">Upload Videos to S3</a></p>';
    echo '</div>';
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
function video_to_s3_aws_key_field() { echo '<input type="text" name="aws_key" value="' . esc_attr(get_option('vts_aws_key')) . '" />'; }
function video_to_s3_aws_secret_field() { echo '<input type="text" name="aws_secret" value="' . esc_attr(get_option('vts_aws_secret')) . '" />'; }
function video_to_s3_aws_bucket_field() { echo '<input type="text" name="aws_bucket" value="' . esc_attr(get_option('vts_aws_bucket')) . '" />'; }
function video_to_s3_aws_region_field() { echo '<input type="text" name="aws_region" value="' . esc_attr(get_option('vts_aws_region')) . '" />'; }

// Handle upload action
add_action('admin_post_upload_videos_to_s3', 'video_to_s3_upload_videos');
function video_to_s3_upload_videos() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    // Here would be the logic to upload videos, similar to the script in your previous query
    echo '<div class="wrap"><h1>Uploading Videos...</h1><p>Check your server logs for detailed output.</p></div>';
    
    // Redirect back to the dashboard after the process (you'd want to show results here)
    wp_redirect(admin_url('admin.php?page=video-to-s3'));
    exit;
}