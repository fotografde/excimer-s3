<?php declare(strict_types=1);

namespace Gpht\ExcimerS3;

/**
 * @immutable
 * @psalm-immutable
 */
final class Endpoint
{
    public static function web(string $dsnString, string $prefix = 'app'): void
    {
        if ((isset($_GET['profile']) || isset($_GET['timer'])) && extension_loaded('excimer')) {
            $excimer = ExcimerS3::ofDsn($dsnString);
            if (isset($_GET['profile']) && is_string($_GET['profile'])) {
                $path = str_replace('__', '/', $_GET['profile']);
                $excimer->trace($prefix . '/' . $path);
            }
            if (isset($_GET['timer']) && is_string($_GET['timer'])) {
                $path = str_replace('__', '/', $_GET['timer']);
                $excimer->timer($prefix . '/' . $path);
            }
        }
    }

    public static function cli(string $dsnString, string $prefix = 'app'): void
    {
        if (extension_loaded('excimer') && ((getenv("PROFILE") !== false) || (getenv("TIMER") !== false))) {
            $excimer = ExcimerS3::ofDsn($dsnString);
            $profilePath = getenv("PROFILE");
            if (is_string($profilePath)) {
                $path = str_replace('__', '/', $profilePath);
                $excimer->trace($prefix . '/' . $path);
            }
            $timerPath = getenv("TIMER");
            if (is_string($timerPath)) {
                $path = str_replace('__', '/', $timerPath);
                $excimer->timer($prefix . '/' . $path);
            }
        }
    }
}
