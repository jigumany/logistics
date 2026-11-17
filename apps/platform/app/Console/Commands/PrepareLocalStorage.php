<?php

namespace App\Console\Commands;

use Aws\S3\Exception\S3Exception;
use Illuminate\Console\Command;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class PrepareLocalStorage extends Command
{
    protected $signature = 'storage:prepare-local';

    protected $description = 'Create the private development bucket on the local S3-compatible server.';

    public function handle(): int
    {
        $host = parse_url((string) config('filesystems.disks.s3.endpoint'), PHP_URL_HOST);
        if (! app()->environment('local') || ! in_array($host, ['minio', 'localhost', '127.0.0.1'], true)) {
            throw new RuntimeException('Bucket provisioning is restricted to local development storage.');
        }
        $disk = Storage::disk('s3');
        if (! $disk instanceof AwsS3V3Adapter) {
            throw new RuntimeException('Configure the S3-compatible development disk.');
        }
        $bucket = (string) config('filesystems.disks.s3.bucket');
        retry(15, function () use ($disk, $bucket): void {
            try {
                $disk->getClient()->headBucket(['Bucket' => $bucket]);
            } catch (S3Exception $error) {
                if ($error->getStatusCode() !== 404) {
                    throw $error;
                }$disk->getClient()->createBucket(['Bucket' => $bucket, 'ACL' => 'private']);
            }
        }, 1000);
        $this->info('Private local storage bucket is ready.');

        return self::SUCCESS;
    }
}
