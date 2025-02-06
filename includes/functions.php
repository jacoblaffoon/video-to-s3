<?php
use Aws\S3\S3Client;

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

function video_to_s3_upload_videos_logic($selected_videos = null) {
    $aws_key = get_option('vts_aws_key');
    $aws_secret = get_option('vts_aws_secret');
    $bucket = get_option('vts_aws_bucket');
    $region = get_option('vts_aws_region');

    // Initialize S3 client
    $s3 = new S3Client([
        'version' => 'latest',
        'region' => $region,
        'credentials' => [
            'key' => $aws_key,
            'secret' => $aws_secret,
        ]
    ]);

    global $wpdb;
    
    // Fetch videos from the database based on selection or all videos
    $query = "SELECT ID, guid FROM {$wpdb->prefix}posts WHERE post_type = 'attachment' AND post_mime_type LIKE 'video/%'";
    if ($selected_videos) {
        $query .= " AND ID IN (" . implode(',', array_map('intval', $selected_videos)) . ")";
    }
    $videos = $wpdb->get_results($query);

    $batch_size = 10; // Number of videos to process in one batch
    $total_videos = count($videos);
    $processed = 0;
    $errors = [];

    $start_time = microtime(true);

    foreach (array_chunk($videos, $batch_size) as $batch) {
        foreach ($batch as $video) {
            $file_path = str_replace(get_site_url(), ABSPATH, $video->guid);
            
            if (file_exists($file_path)) {
                try {
                    $result = $s3->putObject([
                        'Bucket' => $bucket,
                        'Key'    => basename($file_path),
                        'SourceFile' => $file_path,
                        'ACL'    => 'public-read'
                    ]);
                    error_log("Uploaded: " . $video->guid . " to S3");
                    $processed++;
                } catch (Exception $e) {
                    $errors[] = "Error uploading " . $video->guid . ": " . $e->getMessage();
                    error_log("Error uploading " . $video->guid . ": " . $e->getMessage());
                }
            } else {
                $errors[] = "File not found: " . $file_path;
                error_log("File not found: " . $file_path);
            }

            // Check if script execution time is close to PHP's max execution time
            if ((microtime(true) - $start_time) > (ini_get('max_execution_time') - 5)) {
                break 2; // Exit both loops
            }
        }

        // If the script is about to exceed execution time, we'll pause here. 
        // In a real-world scenario, you might want to implement a cron job or use AJAX for this.
    }

    // Prepare messages for admin notices
    $messages = [];

    if ($processed > 0) {
        $messages[] = '[info]Successfully uploaded ' . $processed . ' videos out of ' . $total_videos . '.';
    }

    if (!empty($errors)) {
        $error_message = sprintf('[error]Encountered %d errors. Here are the first 3: %s', 
            count($errors), 
            implode(', ', array_slice($errors, 0, 3))
        );
        $messages[] = $error_message;
    } else if ($processed < $total_videos) {
        $messages[] = '[info]Processing stopped to avoid script timeout. Please run again to continue uploading.';
    }

    // Store messages in transients for display in the admin area
    set_transient('vts_upload_messages', $messages, 60); // Keep for 1 minute

    // Log total processed and errors for debugging or logging purposes
    error_log(sprintf("Total videos processed: %d, Total errors: %d", $processed, count($errors)));
}