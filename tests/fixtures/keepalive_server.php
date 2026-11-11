<?php

// A chat-completions endpoint that keeps connections alive and counts the
// ones it accepts: GET /__connections returns the count. Plain TCP, or TLS
// when a certificate and key are given:
//
//     php keepalive_server.php [certfile keyfile]
//
// PHP's built-in server can't do this: it closes every connection after one
// response (Connection: close) and has no TLS. Prints its port, then serves
// until killed. One process, many sockets, via stream_select().

$tls = $argc === 3;
$context = stream_context_create($tls ? ['ssl' => ['local_cert' => $argv[1], 'local_pk' => $argv[2], 'verify_peer' => false]] : []);
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if ($server === false) {
    fwrite(STDERR, "{$error}\n");
    exit(1);
}
echo explode(':', (string) stream_socket_get_name($server, false))[1], "\n";

$connections = 0;
$clients = [];
$buffers = [];

while (true) {
    $read = array_merge([$server], $clients);
    $write = $except = null;
    if (stream_select($read, $write, $except, null) === false) {
        break;
    }

    foreach ($read as $socket) {
        if ($socket === $server) {
            $client = @stream_socket_accept($server, 5);
            if ($client === false) {
                continue;
            }
            if ($tls) {
                stream_set_blocking($client, true);
                if (@stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER) !== true) {
                    fclose($client);

                    continue;
                }
            }
            stream_set_blocking($client, false);
            $connections++;
            $id = (int) $client;
            $clients[$id] = $client;
            $buffers[$id] = '';

            continue;
        }

        $id = (int) $socket;
        // Drain the socket: with TLS, decrypted data can sit in OpenSSL's
        // buffer, where stream_select() can't see it.
        $read_any = false;
        while (($chunk = fread($socket, 65536)) !== false && $chunk !== '') {
            $buffers[$id] .= $chunk;
            $read_any = true;
        }
        if (! $read_any) {
            if (feof($socket)) {
                fclose($socket);
                unset($clients[$id], $buffers[$id]);
            }

            continue;
        }

        // Answer every complete request in the buffer.
        while (($end = strpos($buffers[$id], "\r\n\r\n")) !== false) {
            $head = substr($buffers[$id], 0, $end);
            $length = preg_match('/^Content-Length:\s*(\d+)/mi', $head, $m) ? (int) $m[1] : 0;
            if (strlen($buffers[$id]) < $end + 4 + $length) {
                break;
            }
            $body = substr($buffers[$id], $end + 4, $length);
            $buffers[$id] = substr($buffers[$id], $end + 4 + $length);

            if (str_starts_with($head, 'GET')) {
                $payload = json_encode(['connections' => $connections]);
            } else {
                $request = json_decode($body, true);
                $text = $request['messages'][count($request['messages'] ?? []) - 1]['content'] ?? '';
                $payload = json_encode(['choices' => [['message' => ['content' => $text.'!'], 'finish_reason' => 'stop']]]);
            }
            stream_set_blocking($socket, true);
            fwrite($socket, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: ".strlen($payload)."\r\nConnection: keep-alive\r\n\r\n".$payload);

            // A client that asked to close (PHP's http:// wrapper does) waits for it.
            if (preg_match('/^Connection:\s*close/mi', $head) === 1 || str_contains(strtok($head, "\r\n"), 'HTTP/1.0')) {
                fclose($socket);
                unset($clients[$id], $buffers[$id]);

                continue 2;
            }
            stream_set_blocking($socket, false);
        }
    }
}
