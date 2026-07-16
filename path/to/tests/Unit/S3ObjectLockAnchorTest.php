namespace Chronicle\AnchorS3\Tests\Unit;

use Tests\TestCase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Artisan;
use Aws\S3\S3Client;

class S3ObjectLockAnchorTest extends TestCase
{
    public function testGetBucketVersioning()
    {
        $bucketName = 'test-bucket';
        $config = Config::shouldReceive('get')->andReturn($bucketName);
        $s3Client = $this->getMockBuilder(S3Client::class)->getMock();
        $s3Client->expects($this->once())->method('getBucketVersioning')->willReturn(['VersioningConfiguration' => ['Status' => 'Enabled']]);

        $provider = new S3ObjectLockAnchor($config);
        $provider->s3Client = $s3Client;

        $this->assertEquals('Enabled', $provider->getBucketVersioning($bucketName));
    }

    public function testGetObjectLockConfiguration()
    {
        $bucketName = 'test-bucket';
        $config = Config::shouldReceive('get')->andReturn($bucketName);
        $s3Client = $this->getMockBuilder(S3Client::class)->getMock();
        $s3Client->expects($this->once())->method('getObjectLockConfiguration')->willReturn(['ObjectLockConfiguration' => ['Rule' => ['DefaultRetention' => ['Mode' => 'COMPLIANCE']]]]);

        $provider = new S3ObjectLockAnchor($config);
        $provider->s3Client = $s3Client;

        $this->assertEquals('COMPLIANCE', $provider->getObjectLockConfiguration($bucketName));
    }
}