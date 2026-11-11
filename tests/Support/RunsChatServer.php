<?php

namespace CodingPartners\TranslaGenius\Tests\Support;

use Symfony\Component\Process\Process;

/**
 * Local chat-completions endpoints for tests that need a real HTTP server,
 * started with the PHP binary running the tests (nothing else to install).
 *
 * - startChatServer(): PHP's built-in server with tests/fixtures/chat_router.php;
 *   answers late on X-Delay.
 * - startKeepAliveServer(): tests/fixtures/keepalive_server.php, which keeps
 *   connections alive and counts them (the built-in server closes every
 *   connection and has no TLS). TLS when TRANSLAGENIUS_TEST_TLS_CERT/_KEY are
 *   set, with PHP told to trust the certificate (-d curl.cainfo=...).
 */
trait RunsChatServer
{
    private ?Process $chatServer = null;

    private string $chatServerBase = '';

    private int $connectionQueries = 0;

    /** PHP's built-in server; returns its chat-completions URL. */
    protected function startChatServer(): string
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) explode(':', (string) stream_socket_get_name($probe, false))[1];
        fclose($probe);

        // One worker (with PHP_CLI_SERVER_WORKERS its forked children keep
        // the output pipes open, and stopping it blocks): requests queue.
        $this->chatServer = new Process([PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__.'/../fixtures/chat_router.php']);
        $this->chatServer->start();

        // Ready once it accepts a connection.
        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
            if ($socket !== false) {
                fclose($socket);
                break;
            }
            usleep(20_000);
        }

        $this->chatServerBase = "http://127.0.0.1:{$port}";

        return "{$this->chatServerBase}/v1/chat/completions";
    }

    /** The connection-counting keep-alive server; returns its chat-completions URL. */
    protected function startKeepAliveServer(): string
    {
        $cert = getenv('TRANSLAGENIUS_TEST_TLS_CERT') ?: null;
        $key = getenv('TRANSLAGENIUS_TEST_TLS_KEY') ?: null;
        $tls = $cert !== null && $key !== null;

        $this->chatServer = new Process(array_merge([PHP_BINARY, __DIR__.'/../fixtures/keepalive_server.php'], $tls ? [$cert, $key] : []));
        $this->chatServer->start();
        $this->chatServer->waitUntil(fn ($type, $output) => preg_match('/^\d+/', $output) === 1);
        $port = (int) trim($this->chatServer->getOutput());

        $this->chatServerBase = ($tls ? 'https://localhost' : 'http://127.0.0.1').":{$port}";

        return "{$this->chatServerBase}/v1/chat/completions";
    }

    protected function stopChatServer(): void
    {
        $this->chatServer?->stop(0);
    }

    /** Connections the keep-alive server accepted from the code under test (not counting these queries). */
    protected function serverConnections(): int
    {
        $context = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $total = (int) json_decode((string) file_get_contents("{$this->chatServerBase}/__connections", false, $context), true)['connections'];

        return $total - ++$this->connectionQueries;
    }
}
