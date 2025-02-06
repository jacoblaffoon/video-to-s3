<?php
require 'vendor/autoload.php';

use Aws\S3\S3Client;

$s3 = new S3Client([
    'version' => 'latest',
    'region'  => 'your-region', // e.g., 'us-east-2'
    'credentials' => [
        'key'    => 'YOUR_AWS_ACCESS_KEY_ID',
        'secret' => 'YOUR_AWS_SECRET_ACCESS_KEY',
    ]
]);

$result = $s3->putObject([
    'Bucket' => 'rtlms',
    'Key'    => '520-Ocean-View-Dr-_-Jacob-Laffoon.mp4',
    'SourceFile' => '/home1/abqzqhmy/public_html/website_063afcac/wp-content/uploads/2025/01/520-Ocean-View-Dr-_-Jacob-Laffoon.mp4',
    'ACL'    => 'public-read'
]);

echo "Upload result: " . print_r($result->toArray(), true);