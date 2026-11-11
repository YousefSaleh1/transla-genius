<?php

namespace CodingPartners\TranslaGenius\Tests\Support;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Starts tests/fixtures/chat_server.py, a real local chat-completions
 * endpoint that counts connections and can answer late (X-Delay). TLS when
 * TRANSLAGENIUS_TEST_TLS_CERT/_KEY are set (and PHP is told to trust the
 * certificate, e.g. -d curl.cainfo=...).
 */
trait RunsChatServer
{
    private ?Process $chatServer = null;

    private string $chatServerBase = '';

    private int $connectionQueries = 0;

    /** Starts the server and returns its chat-completions URL; skips the test without python3. */
    protected function startChatServer(): string
    {
        $python = (new ExecutableFinder)->find('python3');
        if ($python === null) {
            $this->markTestSkipped('python3 is needed for the local chat server.');
        }

        $cert = getenv('TRANSLAGENIUS_TEST_TLS_CERT') ?: null;
        $key = getenv('TRANSLAGENIUS_TEST_TLS_KEY') ?: null;
        $tls = $cert !== null && $key !== null;

        $this->chatServer = new Process(array_merge([$python, __DIR__.'/../fixtures/chat_server.py'], $tls ? [$cert, $key] : []));
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

    /** Connections the server accepted from the code under test (not counting these queries). */
    protected function serverConnections(): int
    {
        $context = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $total = (int) json_decode((string) file_get_contents("{$this->chatServerBase}/__connections", false, $context), true)['connections'];

        return $total - ++$this->connectionQueries;
    }
}
