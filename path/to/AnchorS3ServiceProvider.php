namespace Chronicle\AnchorS3;

use Illuminate\Support\ServiceProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class AnchorS3ServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->publishes([
            __DIR__.'/../config/chronicle-anchor-s3.php' => config_path('chronicle-anchor-s3.php'),
        ]);

        if ($this->app->runningInConsole()) {
            $this->commands([
                ChronicleAnchorDoctorCommand::class,
            ]);
        }
    }

    public function register()
    {
        $this->app->bind('chronicle.anchor.s3', function ($app) {
            return new S3ObjectLockAnchor($app['config']);
        });
    }
}