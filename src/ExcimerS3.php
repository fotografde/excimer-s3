<?php declare(strict_types=1);

namespace Gpht\ExcimerS3;

use Aws\Credentials\AssumeRoleCredentialProvider;
use Aws\Credentials\CredentialProvider;
use Aws\S3\S3Client;
use Aws\Sts\StsClient;
use Enqueue\Dsn\Dsn;
use ExcimerProfiler;
use ExcimerTimer;

final readonly class ExcimerS3
{
    private const DATE_FORMAT_DETAIL = 'Y-m-d\TH:i:s.uP';
    private const DATE_FORMAT_SHORT = 'Y-m-d_H:i:s';

    private ExcimerProfiler $excimerProfiler;
    private ExcimerTimer $excimerTimer;

    public function __construct(
        private string   $bucketName,
        private S3Client $s3Client,
    )
    {
        if (!extension_loaded('excimer')) {
            throw new \Exception("Excimer extension is not installed on your php.ini");
        }
        $this->excimerProfiler = new ExcimerProfiler();
        $this->excimerProfiler->setPeriod(0.001); // 1ms
        /**
         * @psalm-suppress UndefinedConstant
         * @psalm-suppress MixedArgument
         */
        $this->excimerProfiler->setEventType(EXCIMER_REAL);
        $this->excimerTimer = new ExcimerTimer();
        $this->excimerTimer->setPeriod(0.1); // every 100ms
    }

    public static function ofDsn(
        string $dsnString,
        array  $clientAdditionalConfig = [],
    ): self
    {
        $dsn = Dsn::parseFirst($dsnString);
        if (null === $dsn || $dsn->getSchemeProtocol() !== 's3') {
            throw new \InvalidArgumentException(
                'Malformed parameter "dsn". example: "s3+http://key:secret@aws:4100/123456789012?region=eu-west-1assume=arn%3Aaws%3Aiam%3A%3A123456789012%3Arole%2Fxaccounts3access&bucket=BucketName"'
            );
        }

        $namespace = $dsn->getPath();
        assert(
            $namespace !== null,
            'error(-><-): s3+http://key:secret@aws:4100->/123456789012<-?region=eu-west-1&bucket=BucketName'
        );
        $bucket = $dsn->getString('bucket');
        assert(
            $bucket !== null,
            'error(-><-): s3+http://key:secret@aws:4100/123456789012?region=eu-west-1&->bucket=BucketName<-'
        );
        $region = $dsn->getString('region');
        assert(
            $region !== null,
            'error(-><-): s3+http://key:secret@aws:4100/123456789012?->region=eu-west-1<-&bucket=BucketName'
        );
        $user = $dsn->getUser();
        $password = $dsn->getPassword();

        $clientConfig = [
            'version' => 'latest',
            'region' => $region,
        ];
        if ($user !== null && $password !== null) {
            $clientConfig['credentials'] = [
                'key' => $user,
                'secret' => $password,
            ];
        } else {
            $provider = CredentialProvider::defaultProvider();
            $clientConfig['credentials'] = $provider;
        }
        $assume = $dsn->getString('assume', null);
        if ($assume !== null) {
            //https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/guide_credentials.html
            //https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/guide_credentials_provider.html#assumerole-provider
            $stsClientConfig = $clientConfig;
            $sessionName = "assume_session";

            $assumeRoleCredentials = new AssumeRoleCredentialProvider(
                [
                    'client' => new StsClient($stsClientConfig),
                    'assume_role_params' => [
                        'RoleArn' => $assume,
                        'RoleSessionName' => $sessionName,
                    ],
                ]
            );
            // To avoid unnecessarily fetching STS credentials on every API operation,
            // the memoize function handles automatically refreshing the credentials when they expire
            $providerAssume = CredentialProvider::memoize($assumeRoleCredentials);
            $clientConfig['credentials'] = $providerAssume;
        }
        $host = $dsn->getHost();
        if ($host !== null) {
            /**
             * @psalm-suppress PossiblyUndefinedIntArrayOffset
             * @var string $schemeExtension
             */
            $schemeExtension = $dsn->getSchemeExtensions()[0];
            $endpoint = sprintf(
                '%s://%s',
                $schemeExtension,
                $host
            );
            if ($dsn->getPort() !== null) {
                $endpoint .= ":{$dsn->getPort()}";
            }
            $clientConfig['endpoint'] = $endpoint;
        }

        return new self(
            $bucket,
            new S3Client(array_merge($clientConfig, $clientAdditionalConfig)),
        );
    }

    public function setPeriod(float $period): self
    {
        $this->excimerProfiler->setPeriod($period);
        $this->excimerTimer->setPeriod($period);

        return $this;
    }

    /**
     * @psalm-suppress UndefinedConstant
     */
    public function setEventType(int $type = EXCIMER_REAL): self
    {
        $this->excimerProfiler->setEventType($type);

        return $this;
    }

    public function trace(string $profilePath = 'app/trace'): void
    {
        $this->excimerProfiler->start();
        $s3Client = $this->s3Client;
        $bucket = $this->bucketName;

        register_shutdown_function(function () use ($profilePath, $s3Client, $bucket) {
            $this->excimerProfiler->stop();
            $data = $this->excimerProfiler->getLog()->getSpeedscopeData();
            $traceString = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $date = date(self::DATE_FORMAT_SHORT);
            $s3Client->registerStreamWrapperV2();
            file_put_contents("s3://{$bucket}/{$profilePath}/{$date}.json", $traceString);
        });
    }

    public function timer(string $profilePath = 'app/trace'): void
    {
        $timer = $this->excimerTimer;
        $startTime = microtime(true);
        $this->s3Client->registerStreamWrapperV2();
        $date = date(self::DATE_FORMAT_SHORT);
        $stream = fopen("s3://{$this->bucketName}/{$profilePath}/{$date}.csv", 'w');
        $timer->setCallback(function () use ($startTime, $stream) {
            $usage = sprintf("%.2f", memory_get_usage() / 1048576); // MB
            $interval = (microtime(true) - $startTime);
            $ms = sprintf("%.2f", $interval);
            fwrite($stream, date(self::DATE_FORMAT_DETAIL) . "; $ms; $usage\n");
        });
        register_shutdown_function(function () use ($stream) {
            fclose($stream);
        });
        $timer->start();
    }

}
