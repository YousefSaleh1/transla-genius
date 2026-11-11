<?php

namespace CodingPartners\TranslaGenius\Tests\Unit;

use CodingPartners\TranslaGenius\Providers\OpenAiCompatibleProvider;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Http\Client\ConnectionException;
use PHPUnit\Framework\TestCase;

/**
 * A timeout is recognized from the exception Guzzle raised, not its message:
 * Guzzle 8 throws a dedicated timeout exception; Guzzle 7 a ConnectException
 * whose handler context has cURL errno 28. Laravel keeps either as the
 * `previous` of its ConnectionException. The messages here say nothing
 * about timing out, so only those checks can pass.
 */
class TimeoutDetectionTest extends TestCase
{
    protected function isTimeout(ConnectionException $e): bool
    {
        return (new \ReflectionMethod(OpenAiCompatibleProvider::class, 'isTimeout'))->invoke(null, $e);
    }

    protected function wrap(\Throwable $guzzle): ConnectionException
    {
        return new ConnectionException('the request did not complete', 0, $guzzle);
    }

    public function test_guzzle_8_timeout_exceptions()
    {
        if (! class_exists(NetworkTimeoutException::class)) {
            $this->markTestSkipped('Guzzle 7 has no dedicated timeout exceptions.');
        }

        $request = new Request('POST', 'https://api.test');
        $this->assertTrue($this->isTimeout($this->wrap(new NetworkTimeoutException('stopped', $request))));
        $this->assertTrue($this->isTimeout($this->wrap(new ConnectTimeoutException('stopped', $request))));
        $this->assertFalse($this->isTimeout($this->wrap(new ConnectException('refused', $request))));
    }

    public function test_guzzle_7_errno_28_in_the_handler_context()
    {
        if (! method_exists(ConnectException::class, 'getHandlerContext')) {
            $this->markTestSkipped('Guzzle 8 exceptions carry no handler context.');
        }

        $request = new Request('POST', 'https://api.test');
        $this->assertTrue($this->isTimeout($this->wrap(new ConnectException('stopped', $request, null, ['errno' => 28]))));
        $this->assertFalse($this->isTimeout($this->wrap(new ConnectException('stopped', $request, null, ['errno' => 7]))));
    }

    public function test_the_message_is_only_a_fallback()
    {
        $this->assertTrue($this->isTimeout(new ConnectionException('cURL error 28: Operation timed out after 1001 milliseconds')));
        $this->assertFalse($this->isTimeout(new ConnectionException('cURL error 7: Failed to connect')));
    }
}
