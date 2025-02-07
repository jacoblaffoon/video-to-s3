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

use Aws\S3\S3Client;

// Include necessary files
require_once VIDEO_TO_S3_PATH . 'vendor/autoload.php';
require_once VIDEO_TO_S3_PATH . 'includes/functions.php';

// Admin menu
add_action('admin_menu', 'video_to_s3_add_admin_menu');

// Add a handler for the metadata update action
add_action('admin_post_update_video_metadata', 'video_to_s3_update_video_metadata');


function video_to_s3_add_admin_menu() {
    add_menu_page('Video to S3', 'Video to S3', 'manage_options', 'video-to-s3', 'video_to_s3_dashboard');
}

/**
 * Displays the content for the Video to S3 Dashboard page.
 * This function is responsible for rendering the main dashboard interface
 * where users can interact with the video upload and management features.
 *
 * @return void
 */
function video_to_s3_admin_styles() {
    echo '<style>
        .vts-secret-key-wrapper {
            display: inline-block;
        }
        .vts-secret-key-wrapper button {
            margin-left: 10px;
        }
        .video-list {
            /* Remove flex-wrap and flex display */
            margin-top: 20px;
        }
        .video-item-row {
            width: 100%; /* Full width for each row */
            margin-bottom: 10px; /* Spacing between rows */
        }
        .video-item {
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 10px;
            width: 100%; /* Make each item take full width of its row */
            box-shadow: 0 1px 1px rgba(0,0,0,0.04);
            background: #fff;
        }
        .video-item strong {
            display: block;
            margin-bottom: 5px;
        }
        .metadata-status {
            font-size: 0.9em;
            margin-top: 5px;
            font-style: italic;
        }
        .metadata-status em {
            color: #888;
        }
    </style>';
}
add_action('admin_head', 'video_to_s3_admin_styles');

// Add a new column to the media library list view
add_filter('manage_media_columns', 'add_s3_url_column');
function add_s3_url_column($columns) {
    $columns['s3_url'] = __('S3 URL');
    return $columns;
}

// Display S3 URL in the new column
add_action('manage_media_custom_column', 'display_s3_url_column_content', 10, 2);
function display_s3_url_column_content($column_name, $post_id) {
    if ('s3_url' === $column_name) {
        $metadata = wp_get_attachment_metadata($post_id);
        if (is_array($metadata) && isset($metadata['file'])) {
            $bucket = get_option('vts_aws_bucket');
            $region = get_option('vts_aws_region');
            $s3_url = "https://{$bucket}.s3.{$region}.amazonaws.com/{$metadata['file']}";
            echo esc_url($s3_url);
        } else {
            echo 'No S3 URL';
        }
    }
}

// Modify the attachment details view to show S3 or Local URL
add_filter('attachment_fields_to_edit', 'modify_attachment_details_for_s3_or_local', 10, 2);
function modify_attachment_details_for_s3_or_local($form_fields, $post) {
    $metadata = wp_get_attachment_metadata($post->ID);
    $bucket = get_option('vts_aws_bucket');
    $region = get_option('vts_aws_region');
    
    if (is_array($metadata) && isset($metadata['file'])) {
        // Metadata already suggests S3 storage
        $s3_url = "https://{$bucket}.s3.{$region}.amazonaws.com/{$metadata['file']}";
        $display_url = esc_url($s3_url);
        $label = 'S3 URL';
    } else {
        // No S3 metadata found, but we know it should be there if uploaded to S3
        $local_url = wp_get_attachment_url($post->ID);
        $display_url = esc_url($local_url);
        $label = 'Local URL';

        // Attempt to update metadata if it seems the file should be in S3
        $file_path = get_attached_file($post->ID);
        $s3_filename = basename($file_path); // Assuming the local filename matches the S3 key
        $s3_url = "https://{$bucket}.s3.{$region}.amazonaws.com/{$s3_filename}";

        // Check if file exists in S3 before updating metadata
        if (video_to_s3_check_file_exists($bucket, $s3_filename)) {
            $metadata = array(
                'file' => $s3_filename,
            );
            wp_update_attachment_metadata($post->ID, $metadata);
            $display_url = esc_url($s3_url);
            $label = 'S3 URL (Metadata Updated)';
        }
    }
    
    // Replace or add the URL field
    if (isset($form_fields['url'])) {
        $form_fields['url']['value'] = $display_url;
        $form_fields['url']['label'] = $label;
        $form_fields['url']['input'] = 'html';
        $form_fields['url']['html'] = '<a href="' . $display_url . '" target="_blank">' . $display_url . '</a>';
    } else {
        $form_fields['url'] = array(
            'label' => $label,
            'input' => 'html',
            'html'  => '<a href="' . $display_url . '" target="_blank">' . $display_url . '</a>',
            'value' => $display_url
        );
    }
    
    return $form_fields;
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
    
    try {
        $selected_videos = isset($_POST['selected_videos']) ? $_POST['selected_videos'] : null;
        video_to_s3_upload_videos_logic($selected_videos);

        // Set a transient to inform the user about the upload
        set_transient('vts_upload_messages', ['[info]Upload process completed.'], 60);

        // Ensure $existing_videos is always an array before merging
        $existing_videos = get_transient('vts_existing_videos') ?: [];
        set_transient('vts_existing_videos', array_unique($existing_videos), 60 * 60 * 24); // Keep for one day

        // Redirect back to the dashboard
        wp_redirect(admin_url('admin.php?page=video-to-s3'));
        exit;
    } catch (Exception $e) {
        // Log the error, but try to continue or at least handle gracefully
        error_log("Error in upload process: " . $e->getMessage());
        set_transient('vts_upload_messages', ['[error]An error occurred during upload: ' . $e->getMessage()], 60);
        wp_redirect(admin_url('admin.php?page=video-to-s3'));
        exit;
    }
}

// Add function to handle video deletion
add_action('admin_post_delete_video_from_s3', 'video_to_s3_delete_video');
function video_to_s3_delete_video() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    $video = isset($_GET['video']) ? sanitize_text_field($_GET['video']) : '';

    if ($video) {
        if (isset($_GET['confirm']) && $_GET['confirm'] == 'yes') {
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
                $s3->deleteObject([
                    'Bucket' => $bucket,
                    'Key'    => $video
                ]);
                set_transient('vts_upload_messages', ['[info]Video ' . $video . ' was deleted from S3.'], 60);
            } catch (S3Exception $e) {
                set_transient('vts_upload_messages', ['[error]Failed to delete video ' . $video . ': ' . $e->getMessage()], 60);
            }
        } else {
            // Redirect back with a confirmation prompt
            wp_redirect(admin_url('admin.php?page=video-to-s3&confirm_delete=' . urlencode($video)));
            exit;
        }
    }

    wp_redirect(admin_url('admin.php?page=video-to-s3'));
    exit;
}
