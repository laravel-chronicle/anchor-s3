namespace Chronicle\AnchorS3;

use Aws\S3\S3Client;
use Illuminate\Support\Facades\Config;

class S3ObjectLockAnchor
{
    private $s3Client;

    public function __construct(Config $config)
    {
        $this->s3Client = new S3Client([
            'region' => $config->get('aws.default_region'),
            'credentials' => $config->get('aws.credentials'),
        ]);
    }

    public function getBucketVersioning($bucketName)
    {
        try {
            $result = $this->s3Client->getBucketVersioning([
                'Bucket' => $bucketName,
            ]);

            return $result['VersioningConfiguration']['Status'];
        } catch (\Aws\S3\Exception\S3Exception $e) {
            throw new \Exception("Failed to get bucket versioning: " . $e->getMessage());
        }
    }

    public function getObjectLockConfiguration($bucketName)
    {
        try {
            $result = $this->s3Client->getObjectLockConfiguration([
                'Bucket' => $bucketName,
            ]);

            return $result['ObjectLockConfiguration']['Rule']['DefaultRetention']['Mode'];
        } catch (\Aws\S3\Exception\S3Exception $e) {
            throw new \Exception("Failed to get bucket object lock configuration: " . $e->getMessage());
        }
    }
}