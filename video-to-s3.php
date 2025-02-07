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


// Dashboard page content
function video_to_s3_dashboard() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    if (isset($_GET['confirm_delete'])) {
        echo '<div class="notice notice-warning"><p>Are you sure you want to delete ' . esc_html($_GET['confirm_delete']) . '? <a href="' . admin_url('admin-post.php?action=delete_video_from_s3&video=' . urlencode($_GET['confirm_delete']) . '&confirm=yes') . '">Yes</a> | <a href="' . admin_url('admin.php?page=video-to-s3') . '">No</a></p></div>';
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

    // Tabs for different content types
    ?>
    <h2 class="nav-tab-wrapper">
        <a href="#videos" class="nav-tab nav-tab-active">Videos</a>
        <a href="#logs" class="nav-tab">Logs</a>
    </h2>

    <div id="videos" class="vts-tab-content">
        <?php
        // List videos in bucket
        $bucket_contents = video_to_s3_list_bucket_contents();
        echo '<h2>Existing Videos in S3 Bucket</h2>';
        
        if (!empty($bucket_contents)) {
            // Add 'Update All Metadata' button
            echo '<form method="post" action="' . admin_url('admin-post.php') . '">';
            echo '<input type="hidden" name="action" value="update_all_metadata">';
            wp_nonce_field('update_all_metadata_nonce', 'update_all_metadata_nonce');
            echo '<input type="submit" class="button" value="Update All Metadata">';
            echo '</form>';

            echo '<div class="video-list">';
            foreach ($bucket_contents as $video) {
                echo '<div class="video-item">';
                echo '<strong>' . esc_html($video) . '</strong> ';
                echo '<a href="' . admin_url('admin-post.php?action=delete_video_from_s3&video=' . urlencode(strtolower($video))) . '">Delete from S3</a>';
                
                $video_id = video_to_s3_get_video_id_by_filename($video);
                if ($video_id) {
                    $metadata = wp_get_attachment_metadata($video_id);
                    echo '<div class="metadata-status">';
                    if (is_array($metadata) && isset($metadata['file'])) {
                        echo 'Metadata: <em>File: ' . esc_html($metadata['file']) . '</em>';
                    } else {
                        echo 'Metadata: <em>Not found or not updated for S3</em>';
                        // Show update form for individual video
                        echo '<form method="post" action="' . admin_url('admin-post.php') . '">';
                        echo '<input type="hidden" name="action" value="update_video_metadata">';
                        echo '<input type="hidden" name="video_id" value="' . esc_attr($video_id) . '">';
                        echo '<input type="hidden" name="filename" value="' . esc_attr($video) . '">';
                        wp_nonce_field('update_video_metadata_nonce', 'update_video_metadata_nonce');
                        echo '<input type="submit" class="button" value="Update Metadata">';
                        echo '</form>';
                    }
                    echo '</div>';
                }
                echo '</div>'; // Close video-item div
            }
            echo '</div>'; // Close video-list div
        } else {
            echo '<p>No videos found in the S3 bucket.</p>';
        }
        ?>
    </div>

    <div id="logs" class="vts-tab-content" style="display:none;">
        <?php
        // List logs in bucket
        $logs = array_filter(video_to_s3_list_bucket_contents(), function($item) {
            return strpos($item, 'logs/') === 0 || strpos($item, 'AWSLogs/') === 0;
        });

        if (!empty($logs)) {
            echo '<ul>';
            foreach ($logs as $log) {
                echo '<li>' . esc_html($log) . '</li>';
            }
            echo '</ul>';
        } else {
            echo '<p>No logs found in the S3 bucket.</p>';
        }
        ?>
    </div>

    <script type="text/javascript">
    jQuery(document).ready(function($) {
        $('.nav-tab-wrapper a').click(function(e) {
            e.preventDefault();
            var tab_id = $(this).attr('href');
            $('.vts-tab-content').hide();
            $('.nav-tab').removeClass('nav-tab-active');
            $(this).addClass('nav-tab-active');
            $(tab_id).show();
        });
    });
    </script>

    <?php
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
    echo '</form>';

    echo '<h3>Upload Videos to S3</h3>';
    echo '<p><a href="' . admin_url('admin-post.php?action=upload_videos_to_s3') . '" class="button">Upload All Videos to S3</a></p>';

    // Video selection form
    $media_items = get_posts(array('post_type' => 'attachment', 'post_mime_type' => 'video'));
    $existing_videos = get_transient('vts_existing_videos') ?: [];
    echo '<form method="post" action="' . admin_url('admin-post.php') . '">';
    echo '<input type="hidden" name="action" value="upload_videos_to_s3">';
    foreach ($media_items as $item) {
        $video_name = strtolower(basename($item->guid)); // Convert to lowercase
        if (!in_array($video_name, $existing_videos)) {
            echo '<input type="checkbox" name="selected_videos[]" value="' . $item->ID . '">' . esc_html($item->post_title) . '<br>';
        }
    }
    echo '<input type="submit" value="Upload Selected Videos">';
    echo '</form>';

    echo '</div>';
}

// Function to handle updating all metadata
function video_to_s3_update_all_metadata() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    check_admin_referer('update_all_metadata_nonce');

    $bucket_contents = video_to_s3_list_bucket_contents();
    $updated_count = 0;
    $errors = [];

    foreach ($bucket_contents as $video) {
        $video_id = video_to_s3_get_video_id_by_filename($video);
        if ($video_id) {
            try {
                $metadata = array(
                    'file' => $video,  // Assuming filenames in bucket are what we want for metadata
                );
                wp_update_attachment_metadata($video_id, $metadata);
                $updated_count++;
            } catch (Exception $e) {
                $errors[] = "Failed to update metadata for " . $video . ": " . $e->getMessage();
            }
        }
    }

    if ($updated_count > 0) {
        set_transient('vts_upload_messages', ['[info]Updated metadata for ' . $updated_count . ' videos.'], 60);
    }
    if (!empty($errors)) {
        set_transient('vts_upload_messages', array_merge(['[error]Encountered errors while updating metadata:'], $errors), 60);
    }

    wp_redirect(admin_url('admin.php?page=video-to-s3'));
    exit;
}
add_action('admin_post_update_all_metadata', 'video_to_s3_update_all_metadata');

// Helper function to get video ID from filename
function video_to_s3_get_video_id_by_filename($filename) {
    global $wpdb;
    $query = $wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'video/%' AND guid LIKE %s", '%' . $wpdb->esc_like($filename) . '%');
    $result = $wpdb->get_var($query);
    return $result ? intval($result) : false;
}

// Handle metadata update for a video
function video_to_s3_update_video_metadata() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    check_admin_referer('update_video_metadata_nonce');

    $video_id = isset($_POST['video_id']) ? intval($_POST['video_id']) : 0;
    $filename = isset($_POST['filename']) ? sanitize_file_name($_POST['filename']) : '';

    if ($video_id && $filename) {
        try {
            $metadata = array(
                'file' => $filename,
            );
            wp_update_attachment_metadata($video_id, $metadata);
            set_transient('vts_upload_messages', ['[info]Metadata Updated for ' . $filename], 60);
            error_log("Metadata updated successfully for video ID: " . $video_id);
        } catch (Exception $e) {
            error_log("Error updating metadata for video ID: " . $video_id . " - " . $e->getMessage());
            set_transient('vts_upload_messages', ['[error]Failed to update metadata: ' . $e->getMessage()], 60);
        }
    } else {
        error_log("Failed to update metadata due to missing video ID or filename.");
        set_transient('vts_upload_messages', ['[error]Failed to update metadata. Missing video ID or filename.'], 60);
    }

    wp_redirect(admin_url('admin.php?page=video-to-s3'));
    exit;
}

// Helper function for AWS Secret Key field with show/hide functionality
function video_to_s3_aws_secret_field() {
    $secret_key = get_option('vts_aws_secret');
    ?>
    <div class="vts-secret-key-wrapper">
        <input type="password" id="vts_aws_secret" name="vts_aws_secret" value="<?php echo esc_attr($secret_key); ?>" />
        <button type="button" class="button" id="vts_toggle_secret">Show</button>
    </div>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        $('#vts_toggle_secret').on('click', function() {
            var input = $('#vts_aws_secret');
            if (input.attr('type') === 'password') {
                input.attr('type', 'text');
                $(this).text('Hide');
            } else {
                input.attr('type', 'password');
                $(this).text('Show');
            }
        });
    });
    </script>
    <?php
}

function video_to_s3_admin_styles() {
    echo '<style>
        .vts-secret-key-wrapper {
            display: inline-block;
        }
        .vts-secret-key-wrapper button {
            margin-left: 10px;
        }
        .video-list {
            display: flex;
            flex-wrap: wrap;
            margin-top: 20px;
        }
        .video-item {
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 10px;
            margin: 10px;
            width: calc(33.333% - 20px); /* Adjust as needed for your desired layout */
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
