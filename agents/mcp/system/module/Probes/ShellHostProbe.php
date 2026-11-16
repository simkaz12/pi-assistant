<?php

namespace App\Modules\System\Probes;

use App\Modules\System\Data\ContainerStatus;
use App\Modules\System\Data\DynamicDnsStatus;
use App\Modules\System\Data\HermesJob;
use App\Modules\System\Data\RunTick;
use App\Modules\System\Data\WireguardPeer;
use App\Modules\System\Exceptions\ProbeFailed;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Redis;
use PDO;
use Throwable;

class ShellHostProbe implements HostProbe
{
    private const SYSTEMCTL = '/usr/bin/systemctl';

    private const DOCKER = '/usr/bin/docker';

    public function unitStates(array $units): array
    {
        $output = $this->run([self::SYSTEMCTL, 'is-active', ...$units], allowFailure: true);
        $lines = preg_split('/\R/', trim($output), -1, PREG_SPLIT_NO_EMPTY);

        if (count($lines) !== count($units)) {
            throw new ProbeFailed('systemctl returned '.count($lines).' states for '.count($units).' units');
        }

        return array_combine($units, array_map('trim', $lines));
    }

    public function containers(): array
    {
        $output = $this->run([self::DOCKER, 'ps', '-a', '--format', '{{json .}}']);
        $rows = [];

        foreach (preg_split('/\R/', trim($output), -1, PREG_SPLIT_NO_EMPTY) as $line) {
            $row = json_decode($line, true);

            if (is_array($row) && isset($row['ID'], $row['Names'], $row['State'])) {
                $rows[] = $row;
            }
        }

        $inspected = $this->inspected(array_column($rows, 'ID'));

        return array_map(function (array $row) use ($inspected): ContainerStatus {
            $name = (string) $row['Names'];

            return new ContainerStatus(
                $name,
                (string) $row['State'],
                $this->health($row),
                $this->project((string) ($row['Labels'] ?? '')),
                $inspected[$name]['policy'] ?? null,
                $inspected[$name]['exit_code'] ?? null,
            );
        }, $rows);
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, array{policy: string, exit_code: int}>
     */
    private function inspected(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $output = $this->run([
            self::DOCKER,
            'inspect',
            '-f',
            '{{.Name}} {{.HostConfig.RestartPolicy.Name}} {{.State.ExitCode}}',
            ...$ids,
        ]);
        $inspected = [];

        foreach (preg_split('/\R/', trim($output), -1, PREG_SPLIT_NO_EMPTY) as $line) {
            $fields = explode(' ', trim($line));

            if (count($fields) < 3) {
                continue;
            }

            $inspected[ltrim($fields[0], '/')] = ['policy' => $fields[1], 'exit_code' => (int) $fields[2]];
        }

        return $inspected;
    }

    private function project(string $labels): ?string
    {
        return preg_match('/(?:^|,)com\.docker\.compose\.project=([^,]*)/', $labels, $match) === 1
            ? $match[1]
            : null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function health(array $row): ?string
    {
        $health = $row['HealthStatus'] ?? null;

        if (is_string($health) && $health !== '') {
            return $health;
        }

        if (preg_match('/\((healthy|unhealthy|health: starting)\)/', (string) ($row['Status'] ?? ''), $match)) {
            return $match[1] === 'health: starting' ? 'starting' : $match[1];
        }

        return null;
    }

    public function wireguardPeers(): array
    {
        $container = (string) config('system.wireguard_container', 'wg-easy');
        $config = json_decode(
            $this->run([self::DOCKER, 'exec', $container, 'cat', '/etc/wireguard/wg0.json']),
            true,
        );

        if (! is_array($config) || ! is_array($config['clients'] ?? null)) {
            throw new ProbeFailed('wg0.json has no clients');
        }

        $handshakes = $this->handshakes(
            $this->run([self::DOCKER, 'exec', $container, 'wg', 'show', 'wg0', 'dump']),
        );
        $peers = [];

        foreach ($config['clients'] as $client) {
            $address = (string) ($client['address'] ?? '');
            $handshake = $handshakes[$address] ?? 0;

            $peers[] = new WireguardPeer(
                (string) ($client['name'] ?? $address),
                $address,
                (bool) ($client['enabled'] ?? false),
                $handshake > 0 ? CarbonImmutable::createFromTimestamp($handshake) : null,
            );
        }

        return $peers;
    }

    /**
     * @return array<string, int>
     */
    private function handshakes(string $dump): array
    {
        $handshakes = [];
        $lines = preg_split('/\R/', trim($dump), -1, PREG_SPLIT_NO_EMPTY);

        foreach (array_slice($lines, 1) as $line) {
            $fields = explode("\t", $line);

            if (count($fields) < 5) {
                continue;
            }

            $address = explode('/', explode(',', $fields[3])[0])[0];
            $handshakes[$address] = (int) $fields[4];
        }

        return $handshakes;
    }

    public function certDaysLeft(string $host): int
    {
        $address = (string) config('system.cert.connect_address', '127.0.0.1');
        $port = (int) config('system.cert.port', 443);
        $timeout = (int) config('system.probe_timeout', 5);

        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'peer_name' => $host,
            'verify_peer' => false,
            'verify_peer_name' => false,
        ]]);

        $stream = @stream_socket_client(
            "ssl://{$address}:{$port}",
            $errno,
            $error,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($stream === false) {
            throw new ProbeFailed("TLS connect to {$address}:{$port} failed: {$error}");
        }

        $certificate = stream_context_get_params($stream)['options']['ssl']['peer_certificate'] ?? null;
        fclose($stream);

        $parsed = $certificate === null ? false : openssl_x509_parse($certificate);

        if ($parsed === false) {
            throw new ProbeFailed('Could not read the peer certificate');
        }

        return (int) floor(($parsed['validTo_time_t'] - time()) / 86400);
    }

    public function dynamicDns(string $host): DynamicDnsStatus
    {
        $timeout = (int) config('system.probe_timeout', 5);
        $resolved = $this->resolvePublicA($host, $timeout);

        if ($resolved === null) {
            throw new ProbeFailed("No A record for {$host}");
        }

        try {
            $public = trim(Http::timeout($timeout)
                ->get('https://api.ipify.org')
                ->throw()
                ->body());
        } catch (Throwable $e) {
            throw new ProbeFailed('Public IP lookup failed: '.$e->getMessage(), previous: $e);
        }

        return new DynamicDnsStatus($resolved, $public);
    }

    private function resolvePublicA(string $host, int $timeout): ?string
    {
        try {
            $answers = Http::timeout($timeout)
                ->withHeaders(['accept' => 'application/dns-json'])
                ->get('https://cloudflare-dns.com/dns-query', ['name' => $host, 'type' => 'A'])
                ->throw()
                ->json('Answer');
        } catch (Throwable) {
            return null;
        }

        foreach (is_array($answers) ? $answers : [] as $answer) {
            if (($answer['type'] ?? null) === 1 && is_string($answer['data'] ?? null)) {
                return $answer['data'];
            }
        }

        return null;
    }

    public function hermesJobs(): array
    {
        $file = (string) config('system.hermes_jobs_file');
        $contents = is_readable($file) ? @file_get_contents($file) : false;

        if ($contents === false) {
            throw new ProbeFailed("{$file} is not readable");
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded) || ! is_array($decoded['jobs'] ?? null)) {
            throw new ProbeFailed("{$file} has no jobs");
        }

        $jobs = [];

        foreach ($decoded['jobs'] as $job) {
            if (is_array($job) && isset($job['id'], $job['name'])) {
                $jobs[] = $this->hermesJob($job);
            }
        }

        return $jobs;
    }

    /**
     * @param  array<string, mixed>  $job
     */
    private function hermesJob(array $job): HermesJob
    {
        return new HermesJob(
            (string) $job['id'],
            (string) $job['name'],
            (bool) ($job['enabled'] ?? false),
            isset($job['state']) ? (string) $job['state'] : null,
            $this->instant($job['paused_at'] ?? null),
            $this->instant($job['next_run_at'] ?? null),
            $this->instant($job['last_run_at'] ?? null),
            isset($job['last_status']) ? (string) $job['last_status'] : null,
        );
    }

    private function instant(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    public function hermesRuns(string $jobId): array
    {
        $path = (string) config('system.hermes_executions_db');

        if (! is_readable($path)) {
            throw new ProbeFailed("{$path} is not readable");
        }

        try {
            $pdo = new PDO('sqlite:file:'.$path.'?mode=ro', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $statement = $pdo->prepare(
                'SELECT COALESCE(started_at, claimed_at) AS at, status FROM executions '
                ."WHERE job_id = ? AND status IN ('completed','failed') "
                .'ORDER BY julianday(claimed_at) DESC, claimed_at DESC, id DESC LIMIT 5',
            );
            $statement->execute([$jobId]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            throw new ProbeFailed('executions.db failed: '.$e->getMessage(), previous: $e);
        }

        $ticks = array_map(
            fn (array $row) => new RunTick(CarbonImmutable::parse($row['at']), $row['status'] === 'completed'),
            $rows,
        );

        return array_reverse($ticks);
    }

    public function queueBacklog(): int
    {
        try {
            return (int) Redis::connection()->llen('queues:default');
        } catch (Throwable $e) {
            throw new ProbeFailed('Redis LLEN failed: '.$e->getMessage(), previous: $e);
        }
    }

    /**
     * @param  list<string>  $command
     */
    private function run(array $command, bool $allowFailure = false): string
    {
        if (! is_executable($command[0])) {
            throw new ProbeFailed("{$command[0]} is not available");
        }

        try {
            $result = Process::timeout((int) config('system.probe_timeout', 5))->run($command);
        } catch (Throwable $e) {
            throw new ProbeFailed(basename($command[0]).' failed: '.$e->getMessage(), previous: $e);
        }

        if (! $allowFailure && $result->failed()) {
            throw new ProbeFailed(basename($command[0]).' exited '.$result->exitCode().': '.trim($result->errorOutput()));
        }

        return $result->output();
    }
}
