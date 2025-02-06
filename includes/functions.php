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

