<?php

declare(strict_types=1);

namespace integration\Bridge\Temporal;

use Gplanchat\Bridge\Temporal\Http\CurlGrpcTransport;
use Gplanchat\Bridge\Temporal\Http\GrpcWire;
use Gplanchat\Bridge\Temporal\Http\JsonGatewayWorkflowServiceClient;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Workflowservice\V1\DescribeWorkflowExecutionRequest;
use Temporal\Api\Workflowservice\V1\DescribeWorkflowExecutionResponse;

/**
 * The curl wires against a one-shot TLS server: its certificate is signed by a CA only `ca=` names,
 * and it refuses a client without the certificate `cert=`/`key=` name. It answers over HTTP/1.1,
 * which curl falls back to when ALPN offers no h2, and records the request it read.
 */
#[RequiresPhpExtension('openssl')]
#[RequiresPhpExtension('curl')]
final class TlsAndApiKeyOverCurlTest extends TestCase
{
    private static string $dir;

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/durable-tls-' . bin2hex(random_bytes(4));
        mkdir(self::$dir);
        $cnf = self::$dir . '/openssl.cnf';
        file_put_contents($cnf, "[req]\ndistinguished_name=dn\n[dn]\n[ca]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign\n"
            . "[server]\nsubjectAltName=IP:127.0.0.1\nextendedKeyUsage=serverAuth\n[client]\nextendedKeyUsage=clientAuth\n");
        $options = static fn(string $section): array => ['config' => $cnf, 'digest_alg' => 'sha256', 'x509_extensions' => $section];
        $key = static fn(): \OpenSSLAsymmetricKey => openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA, 'config' => $cnf]) ?: throw new \RuntimeException('openssl_pkey_new');

        $caKey = $key();
        $ca = openssl_csr_sign(openssl_csr_new(['commonName' => 'durable test CA'], $caKey, $options('ca')) ?: throw new \RuntimeException('csr'), null, $caKey, 1, $options('ca'), 1) ?: throw new \RuntimeException('ca');
        openssl_x509_export_to_file($ca, self::$dir . '/ca.pem');
        foreach (['server' => 2, 'client' => 3] as $name => $serial) {
            $leafKey = $key();
            $leaf = openssl_csr_sign(openssl_csr_new(['commonName' => $name], $leafKey, $options($name)) ?: throw new \RuntimeException('csr'), $ca, $caKey, 1, $options($name), $serial) ?: throw new \RuntimeException($name);
            openssl_x509_export_to_file($leaf, self::$dir . "/{$name}.pem");
            openssl_pkey_export_to_file($leafKey, self::$dir . "/{$name}.key", null, ['config' => $cnf]);
        }
    }

    public static function tearDownAfterClass(): void
    {
        array_map('unlink', glob(self::$dir . '/*') ?: []);
        rmdir(self::$dir);
    }

    public function testGrpcOverCurlVerifiesTheServerWithTheCaAndSendsTheClientCertificateAndTheKey(): void
    {
        $seen = $this->served("HTTP/1.1 200 OK\r\ncontent-type: application/grpc\r\ngrpc-status: 0\r\ncontent-length: 5\r\nconnection: close\r\n\r\n" . GrpcWire::frame(''), static function (TemporalConnection $connection): void {
            (new CurlGrpcTransport($connection))->unary('/temporal.api.workflowservice.v1.WorkflowService/DescribeWorkflowExecution', new DescribeWorkflowExecutionRequest(), DescribeWorkflowExecutionResponse::class, [], 5_000);
        });

        self::assertStringStartsWith('POST /temporal.api.workflowservice.v1.WorkflowService/DescribeWorkflowExecution ', $seen);
        self::assertMatchesRegularExpression('#^authorization: Bearer k3y\r$#mi', $seen);
        self::assertMatchesRegularExpression('#^temporal-namespace: ns\.acct\r$#mi', $seen);
    }

    public function testTheJsonGatewayOverCurlVerifiesTheServerWithTheCaAndSendsTheClientCertificateAndTheKey(): void
    {
        $seen = $this->served("HTTP/1.1 200 OK\r\ncontent-type: application/json\r\ncontent-length: 2\r\nconnection: close\r\n\r\n{}", static function (TemporalConnection $connection): void {
            (new JsonGatewayWorkflowServiceClient($connection))->DescribeWorkflowExecution(
                new DescribeWorkflowExecutionRequest(['namespace' => 'ns.acct', 'execution' => new WorkflowExecution(['workflow_id' => 'w'])]),
                [],
                ['timeout' => 5_000_000],
            );
        });

        self::assertStringStartsWith('GET /api/v1/namespaces/ns.acct/workflows/w', $seen);
        self::assertMatchesRegularExpression('#^authorization: Bearer k3y\r$#mi', $seen);
        self::assertMatchesRegularExpression('#^temporal-namespace: ns\.acct\r$#mi', $seen);
    }

    /**
     * Runs $call against a server that answers $reply once, and returns the request head it read,
     * or '' when no handshake completed.
     *
     * @param \Closure(TemporalConnection): void $call
     */
    private function served(string $reply, \Closure $call): string
    {
        $dir = self::$dir;
        $server = <<<'PHP'
            [$dir, $reply] = [$argv[1], base64_decode($argv[2])];
            $context = stream_context_create(['ssl' => ['local_cert' => "$dir/server.pem", 'local_pk' => "$dir/server.key",
                'verify_peer' => true, 'verify_peer_name' => false, 'cafile' => "$dir/ca.pem"]]);
            $socket = stream_socket_server('tls://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
            echo stream_socket_get_name($socket, false), "\n";
            $client = @stream_socket_accept($socket, 10);
            $head = '';
            while (false !== $client && !str_contains($head, "\r\n\r\n") && !feof($client)) {
                $head .= fread($client, 8192);
            }
            file_put_contents("$dir/seen", $head);
            false === $client || fwrite($client, $reply);
            PHP;
        $process = proc_open([\PHP_BINARY, '-r', $server, '--', $dir, base64_encode($reply)], [1 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $port = (int) substr(strrchr(trim((string) fgets($pipes[1])), ':') ?: ':0', 1);

        try {
            $call(TemporalConnection::fromDsn(\sprintf(
                'temporal+tls://127.0.0.1:%d?namespace=ns.acct&api_key=k3y&ca=%s&cert=%s&key=%s',
                $port,
                rawurlencode("{$dir}/ca.pem"),
                rawurlencode("{$dir}/client.pem"),
                rawurlencode("{$dir}/client.key"),
            )));
        } finally {
            proc_close($process);
        }

        return (string) @file_get_contents("{$dir}/seen");
    }
}
