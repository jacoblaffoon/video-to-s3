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
        $videos = array_filter($bucket_contents, function($item) {
            return strpos($item, '.mp4') !== false; // Adjust extension if needed
        });

        if (!empty($videos)) {
            echo '<ul>';
            foreach ($videos as $video) {
                echo '<li>' . esc_html($video) . '</li>';
            }
            echo '</ul>';
        } else {
            echo '<p>No videos found in the S3 bucket.</p>';
        }
        ?>
    </div>

    <div id="logs" class="vts-tab-content" style="display:none;">
        <?php
        // List logs in bucket
        $logs = array_filter($bucket_contents, function($item) {
            return strpos($item, 'logs/') === 0; // Assuming logs start with 'logs/'
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

    echo '<h3>Upload Videos to S3</h3>';
    echo '<p><a href="' . admin_url('admin-post.php?action=upload_videos_to_s3') . '" class="button">Upload Videos to S3</a></p>';
    echo '</div>';
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
    </style>';
}
add_action('admin_head', 'video_to_s3_admin_styles');

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