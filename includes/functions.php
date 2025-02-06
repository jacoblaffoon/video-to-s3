<?php
use Aws\S3\S3Client;
use GuzzleHttp\Client;

// Include necessary files if not already done in your main plugin file
require_once VIDEO_TO_S3_PATH . 'vendor/aws/aws-sdk-php/src/functions.php'; // Adjust this path

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
            $key = strtolower($object['Key']); // Convert to lowercase
            // Only add to the list if it's not a log file
            if (!str_starts_with($key, 'logs/') && !str_starts_with($key, 'awslogs/')) {
                $videos_in_bucket[] = $key;
            }
        }
        return $videos_in_bucket;
    } catch (Exception $e) {
        error_log("Error listing S3 bucket contents: " . $e->getMessage());
        return []; // Return an empty array on failure
    }
}

function video_to_s3_upload_videos_logic($selected_videos = null) {
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

    global $wpdb;
    
    $query = "SELECT ID, guid FROM {$wpdb->prefix}posts WHERE post_type = 'attachment' AND post_mime_type LIKE 'video/%'";
    if ($selected_videos) {
        $query .= " AND ID IN (" . implode(',', array_map('intval', $selected_videos)) . ")";
    }
    $videos = $wpdb->get_results($query);

    $processed = 0;
    $errors = [];
    $skipped = 0;
    $uploaded_videos = [];

    foreach ($videos as $video) {
        $file_path = str_replace(get_site_url(), ABSPATH, $video->guid);
        $key = strtolower(basename($file_path)); // Convert to lowercase
        
        if (file_exists($file_path)) {
            $file_size = filesize($file_path);
            error_log("Checking video: " . $video->guid . " (Size: " . $file_size . " bytes)");
            
            if (!video_to_s3_check_file_exists($bucket, $key, $s3)) {
                $content = file_get_contents($file_path);
                if ($content === false) {
                    $errors[] = "Could not read file for upload: " . $video->guid;
                    error_log("Failed to read file: " . $video->guid);
                } else {
                    try {
                        $result = $s3->putObject([
                            'Bucket' => $bucket,
                            'Key'    => $key,
                            'Body'   => $content
                        ]);
                        error_log("Successfully uploaded: " . $video->guid . " to S3");
                        $processed++;
                        $uploaded_videos[] = $key; // Store the filename of uploaded videos
                    } catch (S3Exception $e) {
                        $errors[] = "Error uploading " . $video->guid . ": " . $e->getMessage();
                        error_log("Error uploading " . $video->guid . ": " . $e->getMessage());
                    }
                }
            } else {
                error_log("File already exists in S3: " . $key);
                $skipped++;
            }
        } else {
            $errors[] = "File not found at path: " . $file_path;
            error_log("File not found at path: " . $file_path);
        }
    }

    // Store uploaded videos in a transient for later use
    $existing_videos = get_transient('vts_existing_videos') ?: [];
    $existing_videos = array_merge($existing_videos, $uploaded_videos);
    set_transient('vts_existing_videos', array_unique($existing_videos), 60 * 60 * 24); // Keep for one day

    // Prepare messages for admin notices
    $messages = [];

    if ($processed > 0) {
        $messages[] = '[info]Successfully uploaded ' . $processed . ' videos.';
    }

    if ($skipped > 0) {
        $messages[] = '[info]' . $skipped . ' videos were skipped because they already exist in the S3 bucket.';
    }

    if (!empty($errors)) {
        $error_message = sprintf('[error]Encountered %d errors. Here are the first 3: %s', 
            count($errors), 
            implode(', ', array_slice($errors, 0, 3))
        );
        $messages[] = $error_message;
    }

    // Store messages in transients for display in the admin area
    set_transient('vts_upload_messages', $messages, 60); // Keep for 1 minute

    // Log total processed, skipped, and errors for debugging or logging purposes
    error_log(sprintf("Total videos processed: %d, Skipped: %d, Total errors: %d", $processed, $skipped, count($errors)));
}

function video_to_s3_check_file_exists($bucket, $key, $s3) {
    try {
        $s3->headObject([
            'Bucket' => $bucket,
            'Key'    => $key // Since $key is already lowercased, we use it as is
        ]);
        return true; // File exists
    } catch (S3Exception $e) {
        if ($e->getAwsErrorCode() === 'NotFound') {
            return false; // File does not exist
        } else {
            error_log("Unexpected error checking file existence: " . $e->getMessage());
            return false; // Return false for other exceptions as well
        }
    } catch (Exception $e) {
        // Catch any other unexpected exceptions
        error_log("General error checking file existence: " . $e->getMessage());
        return false;
    }
}

// Second Phase of Development

// Function to stream video content, whether from local or S3
function get_video_stream($video_url) {
    // Check if the video is stored in S3
    $s3_url_pattern = '/^https?:\/\/.*\.s3\.amazonaws\.com\//';
    if (preg_match($s3_url_pattern, $video_url)) {
        try {
            $client = new GuzzleHttp\Client();
            $response = $client->get($video_url, ['stream' => true]);
            return $response->getBody();
        } catch (Exception $e) {
            // Log the error and provide a fallback or error message
            error_log("Failed to fetch video from S3: " . $e->getMessage());
            return false;
        }
    } else {
        // Local file handling
        if (file_exists($video_url)) {
            return file_get_contents($video_url);
        } else {
            error_log("Local video file not found: " . $video_url);
            return false;
        }
    }
}

// Hook this function into your video display logic
// Example if you have a function to display videos:
add_filter('display_video_content', 'stream_video_content');
function stream_video_content($content, $video_url) {
    if ($stream = get_video_stream($video_url)) {
        // Here you would set the appropriate headers for video streaming
        header('Content-Type: video/mp4'); // Adjust according to the video format
        header('Content-Disposition: inline; filename="' . basename($video_url) . '"');
        echo $stream;
        exit; // Stop further execution
    } else {
        // Handle the case where the video could not be retrieved
        return "Video could not be loaded.";
    }
}

add_filter('manage_media_columns', 'add_s3_status_column');
function add_s3_status_column($columns) {
    $columns['s3_status'] = __('S3 Status');
    return $columns;
}

add_action('manage_media_custom_column', 'display_s3_status', 10, 2);
function display_s3_status($column_name, $post_id) {
    if ('s3_status' === $column_name) {
        $video = get_post($post_id);
        $s3_url_pattern = '/^https?:\/\/.*\.s3\.amazonaws\.com\//';
        echo preg_match($s3_url_pattern, $video->guid) ? 'In S3' : 'Local';
    }
}

add_filter('manage_media_custom_column', 'add_s3_upload_button', 10, 2);
function add_s3_upload_button($column_name, $post_id) {
    if ('s3_status' === $column_name) {
        $video = get_post($post_id);
        $s3_url_pattern = '/^https?:\/\/.*\.s3\.amazonaws\.com\//';
        if (!preg_match($s3_url_pattern, $video->guid)) {
            echo '<a href="' . admin_url('admin-post.php?action=upload_to_s3&video_id=' . $post_id) . '" class="button">Upload to S3</a>';
        }
    }
}

add_action('admin_post_upload_to_s3', 'handle_s3_upload');
function handle_s3_upload() {
    if (!current_user_can('upload_files')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }
    
    $video_id = isset($_GET['video_id']) ? intval($_GET['video_id']) : 0;
    $video = get_post($video_id);
    
    if ($video && $video->post_type == 'attachment' && strpos($video->post_mime_type, 'video/') === 0) {
        $file_path = get_attached_file($video_id);
        if (file_exists($file_path)) {
            video_to_s3_upload_single_video($video_id, $file_path);
            wp_redirect(admin_url('upload.php'));
            exit;
        }
    }
    wp_die('Error uploading video to S3.');
}

// Function to upload a single video to S3 (you should adapt this based on your existing logic)
function video_to_s3_upload_single_video($video_id, $file_path) {
    $aws_key = get_option('vts_aws_key');
    $aws_secret = get_option('vts_aws_secret');
    $bucket = get_option('vts_aws_bucket');
    $region = get_option('vts_aws_region');

    $s3 = new Aws\S3\S3Client([
        'version' => 'latest',
        'region' => $region,
        'credentials' => [
            'key' => $aws_key,
            'secret' => $aws_secret,
        ]
    ]);

    $key = basename($file_path);
    try {
        $result = $s3->putObject([
            'Bucket' => $bucket,
            'Key'    => $key,
            'SourceFile' => $file_path
        ]);
        
        // Update the attachment's guid with the new S3 URL
        update_post_meta($video_id, '_wp_attached_file', $key);
        update_post_meta($video_id, '_wp_attachment_metadata', array('file' => $key));
        $new_guid = "https://{$bucket}.s3.{$region}.amazonaws.com/{$key}";
        wp_update_post(array('ID' => $video_id, 'guid' => $new_guid));
        
        // Log success
        error_log("Successfully uploaded video {$key} to S3");
    } catch (Exception $e) {
        // Log error
        error_log("Error uploading video {$key} to S3: " . $e->getMessage());
        return false;
    }
    return true;
}