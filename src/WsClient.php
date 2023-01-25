<?php

namespace Peterchu;

use Exception;
use Throwable;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class WsClient
{
    const NO_FRAME = -1;
    /**
     * websocket 訊息類型(RFC 6455, section 5.2)
     */
    const CONTINUATION_FRAME = 0;
    const TEXT_MESSAGE = 1;
    const BINARY_MESSAGE = 2;
    const CLOSE_MESSAGE = 8;
    const PING_MESSAGE = 9;
    const PONG_MESSAGE = 10;
    /**
     * websocket close code
     */
    const CLOSE_NORMAL_CLOSURE = 1000;
    const CLOSE_GOING_AWAY = 1001;
    /**
     * websocket server 連線 url
     */
    private string $url;
    /**
     * Sec-WebSocket-Protocol
     */
    private array $protocols;
    /**
     * 每次讀取的秒數, 超過則重新讀取
     */
    private int $streamTimeout = 5;
    /**
     * 確認與 server 連線狀態間隔秒數
     */
    private int $keepAliveInterval = 180;
    /**
     * 過幾秒沒收到 Pong 就中斷連線
     */
    private int $maxReceivePongSeconds = 600;
    /**
     * 最後一次 ping 的時間
     */
    private int $lastPingTime = 0;
    /**
     * 最早 ping 的時間, 收到 pong 時重置
     */
    private int $firstPingTime = 0;
    /**
     * 與 server 建立連線時 timeout, 預設10秒
     */
    private int $connectTimeout = 10;
    /**
     * 有效的 pong payload data
     */
    private array $validPongPayloadData = [];
    /**
     * 自訂 headers
     */
    private array $headers = [];
    /**
     * 當連線時觸發 callback
     *
     * @var callable
     */
    private $onConnection;
    /**
     * 收到 server 傳過來的訊息時觸發 callback
     *
     * @var callable
     */
    private $onMessage;
    /**
     * 收到 server 傳過來的 close frame 時觸發 callback
     *
     * @var callable
     */
    private $onClose;
    /**
     * 與 server 連線的 stream
     *
     * @var resource|null
     */
    private $stream = null;

    public function __construct(string $url, array $protocols = [])
    {
        $this->url = $url;
        $this->protocols = $protocols;
    }

    /**
     * @param callable $onConnection
     * @return WsClient
     */
    public function setOnConnection(callable $onConnection): WsClient
    {
        $this->onConnection = $onConnection;
        return $this;
    }

    /**
     * @param callable $onMessage
     * @return WsClient
     */
    public function setOnMessage(callable $onMessage): WsClient
    {
        $this->onMessage = $onMessage;
        return $this;
    }

    /**
     * @param callable $onClose
     * @return WsClient
     */
    public function setOnClose(callable $onClose): WsClient
    {
        $this->onClose = $onClose;
        return $this;
    }

    /**
     * @param int $seconds
     * @return WsClient
     */
    public function setMaxReceivePongSeconds(int $seconds): WsClient
    {
        $this->maxReceivePongSeconds = $seconds;
        return $this;
    }

    /**
     * @param int $seconds
     * @return WsClient
     */
    public function setKeepAliveInterval(int $seconds): WsClient
    {
        $this->keepAliveInterval = $seconds;
        return $this;
    }

    /**
     * @param int $seconds
     * @return WsClient
     */
    public function setStreamTimeout(int $seconds): WsClient
    {
        $this->streamTimeout = $seconds;
        return $this;
    }

    /**
     * @param int $seconds
     * @return WsClient
     */
    public function setConnectTimeout(int $seconds): WsClient
    {
        $this->connectTimeout = $seconds;
        return $this;
    }

    /**
     * @param array $headers
     * @return WsClient
     */
    public function setHeaders(array $headers): WsClient
    {
        $this->headers = $headers;
        return $this;
    }

    /**
     * 取得目前的 stream
     *
     * @return resource|null
     */
    public function getStream()
    {
        return $this->stream;
    }

    /**
     * 與 server 建立連線, 並持續接收訊息
     *
     * @throws Exception|GuzzleException
     */
    public function run(): void
    {
        $this->connect();

        if (is_callable($this->onConnection)) {
            call_user_func($this->onConnection);
        }

        echo "WsClient: info: connection with the server($this->url) is established\n";

        // 處理 websocket frame
        while (true) {
            list($frameType, $message) = $this->read();

            if ($frameType === self::CLOSE_MESSAGE) {
                if (is_resource($this->stream)) {
                    if (!feof($this->stream)) {
                        $this->write(self::CLOSE_MESSAGE, pack('n*', $message['code'] === null ? self::CLOSE_NORMAL_CLOSURE : $message['code']));
                    }

                    fclose($this->stream);
                }

                if (is_callable($this->onClose)) {
                    call_user_func($this->onClose, $frameType, $message);
                }

                echo "WsClient: info: disconnected from server($this->url)\n";

                break;
            }

            if (!is_callable($this->onMessage) || $frameType === self::NO_FRAME) {
                continue;
            }

            call_user_func($this->onMessage, $frameType, $message);
        }

        $this->disconnect();

        echo "WsClient: info: disconnected from server($this->url)\n";
    }

    /**
     * 進行 websocket handshake
     *
     * @throws GuzzleException
     * @throws Exception
     */
    public function handshake(): void
    {
        $client = new Client();

        $secWebSocketKey = base64_encode(random_bytes(16));

        $headers = array_merge([
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Key' => $secWebSocketKey,
            'Sec-WebSocket-Version' => '13',
        ], $this->headers);

        if (!empty($this->protocols)) {
            $headers['Sec-WebSocket-Protocol'] = implode(', ', $this->protocols);
        }

        $response = $client->request('GET', $this->url, [
            'headers' => $headers,
            'stream' => true,
            'timeout' => $this->connectTimeout,
            'read_timeout' => $this->streamTimeout,
        ]);

        if ($response->getStatusCode() !== 101) {
            throw new Exception('WsClient: status code is not 101');
        }

        if (!$response->hasHeader('upgrade')) {
            throw new Exception('WsClient: \'Upgrade\' header is not found');
        }

        if (!$response->hasHeader('connection')) {
            throw new Exception('WsClient: \'Connection\' header is not found');
        }

        $upgrade = array_map('strtolower', $response->getHeader('upgrade'));

        if (!in_array('websocket', $upgrade)) {
            throw new Exception('WsClient: \'Upgrade\' header is not match \'websocket\'');
        }

        $connection = array_map('strtolower', $response->getHeader('connection'));

        if (!in_array('upgrade', $connection)) {
            throw new Exception('WsClient: \'Connection\' header is not match \'Upgrade\'');
        }

        if (!$response->hasHeader('sec-websocket-accept')) {
            throw new Exception('WsClient: \'Sec-WebSocket-Accept\' header is not found');
        }

        $secWebSocketAccept = base64_encode(pack('H*', sha1($secWebSocketKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));

        if ($response->getHeader('sec-websocket-accept')[0] !== $secWebSocketAccept) {
            throw new Exception('WsClient: \'Sec-WebSocket-Accept\' header does not match the expected value');
        }

        // 目前不支援 sec-websocket-extensions
        if ($response->hasHeader('sec-websocket-extensions')) {
            throw new Exception('WsClient: \'Sec-WebSocket-Extensions\' header is not supported');
        }

        $this->stream = $response->getBody()->detach();
    }

    /**
     * 處理 websocket frame
     *
     * @param bool $withoutPingPong 不檢查 ping pong
     * @param int|null $timeout 秒數
     * @return array
     * @throws Exception
     */
    public function read(bool $withoutPingPong = false, int $timeout = null): array
    {
        $finalFragment = false;
        $frameType = null;
        $message = '';

        if ($timeout !== null) {
            $startTime = time();
        }

        while ($finalFragment === false) {
            $bytes = $this->readBytes(2, $withoutPingPong, $timeout === null ? null : $timeout - (time() - $startTime));

            $byte1 = unpack('C*', $bytes[0]);
            $byte2 = unpack('C*', $bytes[1]);

            // 解析 frame header(RFC 6455, section 5.2)
            $opcode = $byte1[1] & 0x0f;
            $fin = ($byte1[1] & 0x80) != 0;
            $rsv1 = ($byte1[1] & 0x40) != 0;
            $rsv2 = ($byte1[1] & 0x20) != 0;
            $rsv3 = ($byte1[1] & 0x10) != 0;
            $mask = ($byte2[1] & 0x80) != 0;
            $payloadLength = $byte2[1] & 0x7f;

            $errors = [];

            if ($rsv1) {
                $errors[] = 'RSV1 must be 0';
            }

            if ($rsv2) {
                $errors[] = 'RSV2 must be 0';
            }

            if ($rsv3) {
                $errors[] = 'RSV3 must be 0';
            }

            switch ($opcode) {
                case self::CLOSE_MESSAGE:
                case self::PING_MESSAGE:
                case self::PONG_MESSAGE:
                    // RFC 6455, section 5.5
                    if ($payloadLength > 125) {
                        $errors[] = 'control frames must have a payload length of 125 bytes or less';
                    }

                    if (!$fin) {
                        $errors[] = 'control frames must not be fragmented';
                    }

                    break;
                case self::TEXT_MESSAGE:
                case self::BINARY_MESSAGE:
                    if ($frameType !== null) {
                        $errors[] = 'receive text frame or binary frame in the middle of a fragmented message';
                    }

                    $finalFragment = $fin;
                    $frameType = $opcode;

                    break;
                case self::CONTINUATION_FRAME:
                    if ($frameType === null) {
                        $errors[] = 'receive continuation frame before text frame or binary frame';
                    }

                    $finalFragment = $fin;

                    break;
                default:
                    $errors[] = 'unknown opcode is received';

                    break;
            }

            if (count($errors) > 0) {
                $errMsg = implode(', ', $errors);
                throw new Exception("WsClient: $errMsg");
            }

            if ($payloadLength === 126) {
                $bytes = $this->readBytes(2, $withoutPingPong, $timeout === null ? null : $timeout - (time() - $startTime));

                $data = unpack('n*', $bytes);
                $payloadLength = $data[1];
            } else if ($payloadLength === 127) {
                $bytes = $this->readBytes(8, $withoutPingPong, $timeout === null ? null : $timeout - (time() - $startTime));

                $data = unpack('J*', $bytes);
                $payloadLength = $data[1];
            }

            $payload = $this->readBytes($payloadLength, $withoutPingPong, $timeout === null ? null : $timeout - (time() - $startTime));

            if ($opcode === self::CONTINUATION_FRAME || $opcode === self::TEXT_MESSAGE || $opcode === self::BINARY_MESSAGE) {
                $message .= $payload;
            }

            switch ($opcode) {
                case self::CLOSE_MESSAGE:
                    // RFC 6455, section 5.5.1
                    $code = strlen($payload) >= 2 ? unpack('n*', substr($payload, 0, 2)) : [1 => null];
                    $reason = strlen($payload) > 2 ? utf8_decode(substr($payload, 2)) : null;

                    return [self::CLOSE_MESSAGE, ['code' => $code[1], 'reason' => $reason]];
                case self::PING_MESSAGE:
                    if (!$withoutPingPong) {
                        $this->write(self::PONG_MESSAGE, $payload);
                    }

                    if ($frameType === null) {
                        return [self::NO_FRAME, ''];
                    }

                    break;
                case self::PONG_MESSAGE:
                    if (!$withoutPingPong) {
                        $now = time();
                        $maxReceivePongSeconds = $this->maxReceivePongSeconds;
                        $this->validPongPayloadData = array_values(array_filter($this->validPongPayloadData, function($t) use($maxReceivePongSeconds, $now) {
                            return $now - $t <= $maxReceivePongSeconds;
                        }));

                        if (in_array(intval($payload), $this->validPongPayloadData)) {
                            $this->firstPingTime = 0;
                        }
                    }

                    if ($frameType === null) {
                        return [self::NO_FRAME, ''];
                    }

                    break;
            }
        }

        return [$frameType, $message];
    }

    /**
     * websocket send
     *
     * @param int $frameType
     * @param string $message
     * @return void
     * @throws Exception
     */
    public function write(int $frameType, string $message = ''): void
    {
        switch ($frameType) {
            case self::TEXT_MESSAGE:
                $opcode = 0x1;
                break;
            case self::BINARY_MESSAGE:
                $opcode = 0x2;
                break;
            case self::CLOSE_MESSAGE:
                $opcode = 0x8;
                break;
            case self::PING_MESSAGE:
                $opcode = 0x9;
                break;
            case self::PONG_MESSAGE:
                $opcode = 0xA;
                break;
            default:
                throw new Exception('WsClient: unknown opcode is received');
        }

        $finrsv = 0x80;

        $payloadLength = strlen($message);

        if (in_array($frameType, [self::CLOSE_MESSAGE, self::PING_MESSAGE, self::PONG_MESSAGE]) && $payloadLength > 125) {
            throw new Exception('WsClient: control frames must have a payload length of 125 bytes or less');
        }

        $pl = $payloadLength;

        if ($payloadLength > 125 && $payloadLength <= 65535) {
            $pl = 126;
        } else if ($payloadLength > 65535) {
            $pl = 127;
        }

        $frame = pack('C*', $finrsv | $opcode, 0x80 | $pl);

        if ($pl === 126) {
            $frame .= pack('n', $payloadLength);
        } else if ($pl === 127) {
            $frame .= pack('J', $payloadLength);
        }

        $maskingKey = random_bytes(4);

        $frame .= $maskingKey;

        $payload = '';
        for ($i = 0; $i < strlen($message); ++$i) {
            $payload .= $message[$i] ^ $maskingKey[$i % 4];
        }

        $frame .= $payload;

        fwrite($this->stream, $frame, strlen($frame));
    }

    /**
     * 檢查是否有在時限內收到 pong frame
     *
     * @throws Exception
     */
    public function checkIsAlive(): void
    {
        if ($this->firstPingTime !== 0) {
            $now = time();

            if ($now - $this->firstPingTime > $this->maxReceivePongSeconds) {
                throw new Exception('WsClient: no pong received from server');
            }
        }
    }

    /**
     * 檢查連線 resource
     *
     * @throws Exception
     */
    public function checkStream(): void
    {
        if (!is_resource($this->stream)) {
            throw new Exception('WsClient: connection is not established');
        }

        if (feof($this->stream)) {
            throw new Exception('WsClient: connection exception and disconnect from server');
        }
    }

    /**
     * 檢查是否需要發送 ping frame
     *
     * @throws Exception
     */
    public function sendPingFrame(): void
    {
        $now = time();

        if ($this->lastPingTime === 0) {
            $this->lastPingTime = $now;
        }

        if ($now - $this->lastPingTime > $this->keepAliveInterval) {
            $this->write(self::PING_MESSAGE, $now);
            $this->lastPingTime = $now;
            $this->validPongPayloadData[] = $now;

            if ($this->firstPingTime === 0) {
                $this->firstPingTime = $now;
            }
        }
    }

    /**
     * 與 ws server 連線
     *
     * @throws GuzzleException
     */
    public function connect(): void
    {
        // 進行 handshake
        $this->handshake();

        $this->lastPingTime = 0;
        $this->firstPingTime = 0;
        $this->validPongPayloadData = [];
    }

    /**
     * 中斷連線
     *
     * @param int $statusCode
     * @param string $reason
     * @throws Exception
     */
    public function disconnect(int $statusCode = self::CLOSE_NORMAL_CLOSURE, string $reason = ''): void
    {
        if (is_resource($this->stream)) {
            if (!feof($this->stream)) {
                $this->write(self::CLOSE_MESSAGE, pack('n*', $statusCode) . $reason);

                $startTime = time();

                while (true) {
                    $remainTime = 10 - (time() - $startTime);

                    if ($remainTime <= 0) {
                        break;
                    }

                    try {
                        list($frameType) = $this->read(true, $remainTime);
                    } catch (Throwable $th) {
                        break;
                    }

                    if ($frameType === self::CLOSE_MESSAGE) {
                        break;
                    }
                }
            }

            fclose($this->stream);
        }

        $this->stream = null;
    }

    /**
     * close stream
     */
    public function closeStream(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }

        $this->stream = null;
    }

    /**
     * 讀取 Bytes
     *
     * @param int $bytes
     * @param bool $withoutPingPong 不檢查 ping pong
     * @param int|null $timeout 秒數
     * @return string
     * @throws Exception
     */
    public function readBytes(int $bytes, bool $withoutPingPong = false, int $timeout = null): string
    {
        $readLength = 1024;
        $data = '';

        if ($timeout !== null) {
            $startTime = time();
        }

        while (($read = strlen($data)) < $bytes) {
            $this->checkStream();

            if (!$withoutPingPong) {
                $this->checkIsAlive();
                $this->sendPingFrame();
            }

            if ($bytes - $read < $readLength) {
                $readLength = $bytes - $read;
            }

            if (($d = fread($this->stream, $readLength)) !== false) {
                $data .= $d;
            }

            if ($timeout !== null) {
                if (time() - $startTime > $timeout) {
                    throw new Exception('WsClient: read timeout');
                }
            }
        }

        return $data;
    }
}
