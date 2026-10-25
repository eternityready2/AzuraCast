<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Station;
use App\Entity\StationMount;
use App\Radio\AbstractLocalAdapter;
use App\Radio\Frontend\Icecast;
use Throwable;

/**
 * Detects an Icecast that is "running" by every process-liveness check but
 * has stopped accepting listener connections -- e.g. its TCP accept queue
 * has filled up and it is no longer calling accept() on new sockets.
 * Supervisor still reports the process RUNNING in that state, since it never
 * crashed, so AbstractLocalAdapter::isRunning() -- a pure process-state
 * check -- cannot see it. Icecast is then left running-but-deaf (streams
 * time out for listeners, but nothing ever flags it) until someone notices
 * and restarts it by hand.
 *
 * This probe does what a listener's browser does: open a real TCP
 * connection to the frontend port and request the default mount, with a
 * short timeout. A stuck accept queue fails at the connect() step; a
 * process that accepted the connection but is wedged past that point fails
 * at the read() step. Either way, no valid stream response within the
 * timeout means the frontend cannot currently serve listeners, regardless
 * of what Supervisor thinks.
 */
final class AirCheckFrontendConnectivityProbe
{
    private const float CONNECT_TIMEOUT_SECONDS = 3.0;
    private const int READ_TIMEOUT_SECONDS = 3;
    private const int READ_BYTES = 64;

    public function isReachable(Station $station, ?AbstractLocalAdapter $frontendAdapter): bool
    {
        if (!$frontendAdapter instanceof Icecast) {
            // Only Icecast is a plain network listener this probe understands.
            // Any other/unconfigured frontend is left to the existing
            // process-liveness check alone.
            return true;
        }

        $port = $station->frontend_config->port;
        if (null === $port || $port <= 0) {
            return true;
        }

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            sprintf('tcp://127.0.0.1:%d', $port),
            $errno,
            $errstr,
            self::CONNECT_TIMEOUT_SECONDS
        );

        if (false === $socket) {
            return false;
        }

        try {
            stream_set_timeout($socket, self::READ_TIMEOUT_SECONDS);

            $request = 'GET ' . $this->getDefaultMountPath($station) . " HTTP/1.0\r\n"
                . "Host: 127.0.0.1\r\n"
                . "Icy-MetaData: 0\r\n"
                . "Connection: close\r\n\r\n";

            if (false === @fwrite($socket, $request)) {
                return false;
            }

            $response = @fread($socket, self::READ_BYTES);
            $meta = stream_get_meta_data($socket);

            if ($meta['timed_out'] ?? false) {
                return false;
            }

            if (!is_string($response) || '' === $response) {
                return false;
            }

            return 1 === preg_match('/^(ICY|HTTP)\/?\S*\s+200/i', $response);
        } catch (Throwable) {
            return false;
        } finally {
            @fclose($socket);
        }
    }

    private function getDefaultMountPath(Station $station): string
    {
        foreach ($station->mounts as $mount) {
            if ($mount instanceof StationMount && $mount->is_default) {
                return $mount->name;
            }
        }

        return '/';
    }
}
