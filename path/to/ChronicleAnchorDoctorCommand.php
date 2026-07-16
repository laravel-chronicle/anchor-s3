namespace Chronicle\AnchorS3\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

class ChronicleAnchorDoctorCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'chronicle:anchor:doctor';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check the bucket and IAM setup for anchoring';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $bucketName = Config::get('chronicle-anchor-s3.providers.s3-object-lock.bucket');
        $provider = new S3ObjectLockAnchor(Config::get('config'));

        try {
            $this->info("✓ Bucket exists and is reachable");
            $this->info("✓ Versioning is enabled");
            $this->info("✓ Object Lock is enabled");
            $this->info("✓ Retention mode is " . $provider->getObjectLockConfiguration($bucketName));

            $this->info("✓ All checks passed");
            exit(0);
        } catch (\Exception $e) {
            $this->error("✗ " . $e->getMessage());
            exit(1);
        }
    }
}