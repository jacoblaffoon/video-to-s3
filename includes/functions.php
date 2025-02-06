<?php
use Aws\S3\S3Client;

function video_to_s3_upload_videos_logic() {
    // Retrieve AWS credentials from plugin settings
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
    $videos = $wpdb->get_results("SELECT ID, guid FROM {$wpdb->prefix}posts WHERE post_type = 'attachment' AND post_mime_type LIKE 'video/%'");

    foreach ($videos as $video) {
        $file_path = str_replace(get_site_url(), ABSPATH, $video->guid);
        
        if (file_exists($file_path)) {
            try {
                // Upload the file to S3
                $result = $s3->putObject([
                    'Bucket' => $bucket,
                    'Key'    => basename($file_path),
                    'SourceFile' => $file_path,
                    'ACL'    => 'public-read' // Optional: public access
                ]);

                // Log or echo success
                error_log("Uploaded: " . $video->guid . " to S3");
                echo "Uploaded: " . $video->guid . " to S3<br>";
            } catch (Exception $e) {
                // Log or echo errors
                error_log("Error uploading " . $video->guid . ": " . $e->getMessage());
                echo "Error uploading " . $video->guid . ": " . $e->getMessage() . "<br>";
            }
        } else {
            error_log("File not found: " . $file_path);
            echo "File not found: " . $file_path . "<br>";
        }
    }
}