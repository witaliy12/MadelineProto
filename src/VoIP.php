<?php

declare(strict_types=1);

/*
Copyright 2016-2018 Daniil Gentili
(https://daniil.it)
This file is part of MadelineProto.
MadelineProto is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
MadelineProto is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
See the GNU Affero General Public License for more details.
You should have received a copy of the GNU General Public License along with MadelineProto.
If not, see <http://www.gnu.org/licenses/>.
*/

namespace danog\MadelineProto;

use AssertionError;
use danog\MadelineProto\EventHandler\Update;
use danog\MadelineProto\MTProto\PermAuthKey;
use danog\MadelineProto\MTProtoTools\Crypt;
use danog\MadelineProto\Stream\Common\FileBufferedStream;
use danog\MadelineProto\Stream\ConnectionContext;
use danog\MadelineProto\VoIP\AckHandler;
use danog\MadelineProto\VoIP\CallState;
use danog\MadelineProto\VoIP\Endpoint;
use danog\MadelineProto\VoIP\MessageHandler;
use danog\MadelineProto\VoIP\VoIPState;
use phpseclib3\Math\BigInteger;
use Revolt\EventLoop;
use SplQueue;
use Throwable;
use Webmozart\Assert\Assert;

use function Amp\delay;
use function Amp\File\openFile;

final class VoIP extends Update
{
    const NET_TYPE_UNKNOWN = 0;
    const NET_TYPE_GPRS = 1;
    const NET_TYPE_EDGE = 2;
    const NET_TYPE_3G = 3;
    const NET_TYPE_HSPA = 4;
    const NET_TYPE_LTE = 5;
    const NET_TYPE_WIFI = 6;
    const NET_TYPE_ETHERNET = 7;
    const NET_TYPE_OTHER_HIGH_SPEED = 8;
    const NET_TYPE_OTHER_LOW_SPEED = 9;
    const NET_TYPE_DIALUP = 10;
    const NET_TYPE_OTHER_MOBILE = 11;

    const DATA_SAVING_NEVER = 0;
    const DATA_SAVING_MOBILE = 1;
    const DATA_SAVING_ALWAYS = 2;

    const PROXY_NONE = 0;
    const PROXY_SOCKS5 = 1;

    const AUDIO_STATE_NONE = -1;
    const AUDIO_STATE_CREATED = 0;
    const AUDIO_STATE_CONFIGURED = 1;
    const AUDIO_STATE_RUNNING = 2;

    /** @internal */
    const PKT_INIT = 1;
    /** @internal */
    const PKT_INIT_ACK = 2;
    /** @internal */
    const PKT_STREAM_STATE = 3;
    /** @internal */
    const PKT_STREAM_DATA = 4;
    /** @internal */
    const PKT_UPDATE_STREAMS = 5;
    /** @internal */
    const PKT_PING = 6;
    /** @internal */
    const PKT_PONG = 7;
    /** @internal */
    const PKT_STREAM_DATA_X2 = 8;
    /** @internal */
    const PKT_STREAM_DATA_X3 = 9;
    /** @internal */
    const PKT_LAN_ENDPOINT = 10;
    /** @internal */
    const PKT_NETWORK_CHANGED = 11;
    /** @internal */
    const PKT_SWITCH_PREF_RELAY = 12;
    /** @internal */
    const PKT_SWITCH_TO_P2P = 13;
    /** @internal */
    const PKT_NOP = 14;

    /** @internal */
    const TLID_DECRYPTED_AUDIO_BLOCK = "\xc1\xdb\xf9\x48";
    /** @internal */
    const TLID_SIMPLE_AUDIO_BLOCK = "\x0d\x0e\x76\xcc";

    /** @internal */
    const TLID_REFLECTOR_SELF_INFO = "\xC7\x72\x15\xc0";
    /** @internal */
    const TLID_REFLECTOR_PEER_INFO = "\x1C\x37\xD9\x27";

    /** @internal */
    const PROTO_ID = 'GrVP';

    /** @internal */
    const PROTOCOL_VERSION = 9;
    /** @internal */
    const MIN_PROTOCOL_VERSION = 9;

    /** @internal */
    const STREAM_TYPE_AUDIO = 1;
    /** @internal */
    const STREAM_TYPE_VIDEO = 2;

    /** @internal */
    const CODEC_OPUS = 'SUPO';

    protected MessageHandler $messageHandler;
    protected VoIPState $voipState = VoIPState::CREATED;
    protected CallState $callState;

    protected readonly array $call;

    /** @var list<string> */
    protected array $holdFiles = [];
    /** @var list<string> */
    protected array $inputFiles = [];

    /**
     * @var array<Endpoint>
     */
    protected array $sockets = [];
    protected ?Endpoint $bestEndpoint = null;
    protected bool $pendingPing = true;
    protected ?string $timeoutWatcher = null;
    protected ?string $pingWatcher = null;
    protected float $lastIncomingTimestamp = 0.0;
    protected int $opusTimestamp = 0;
    protected SplQueue $packetQueue;
    protected array $tempHoldFiles = [];

    /** Auth key */
    protected readonly string $authKey;
    /** Protocol call ID */
    protected readonly string $protocolCallID;

    /** Phone call ID */
    public readonly int $callID;
    /** Whether the call is an outgoing call */
    public readonly bool $outgoing;
    /** ID of the other user in the call */
    public readonly int $otherID;
    /** ID of the creator of the call */
    public readonly int $creatorID;
    /** When was the call created */
    public readonly int $date;
    /** @var ?list{string, string, string, string} */
    protected ?array $visualization = null;

    /**
     * Constructor.
     * 
     * @internal
     */
    public function __construct(
        protected readonly MTProto $API,
        array $call
    )
    {
        $call['_'] = 'inputPhoneCall';
        $this->packetQueue = new SplQueue;
        $this->call = $call;
        $this->date = $call['date'];
        $this->callID = $call['id'];
        if ($call['_'] === 'phoneCallWaiting') {
            $this->outgoing = false;
            $this->otherID = $call['participant_id'];
            $this->creatorID = $call['admin_id'];
            $this->callState = CallState::INCOMING;
        } else {
            $this->outgoing = true;
            $this->otherID = $call['admin_id'];
            $this->creatorID = $call['participant_id'];
            $this->callState = CallState::REQUESTED;
        }
    }

    /**
     * Confirm requested call.
     * @internal
     */
    public function confirm(array $params): bool
    {
        if ($this->callState !== CallState::REQUESTED) {
            $this->API->logger->logger(\sprintf(Lang::$current_lang['call_error_2'], $this->callID));
            return false;
        }
        $this->API->logger->logger(\sprintf(Lang::$current_lang['call_confirming'], $this->otherID), Logger::VERBOSE);
        $dh_config = $this->API->getDhConfig();
        $params['g_b'] = new BigInteger((string) $params['g_b'], 256);
        Crypt::checkG($params['g_b'], $dh_config['p']);
        $key = \str_pad($params['g_b']->powMod($this->call['a'], $dh_config['p'])->toBytes(), 256, \chr(0), STR_PAD_LEFT);
        try {
            $res = ($this->API->methodCallAsyncRead('phone.confirmCall', ['key_fingerprint' => \substr(\sha1($key, true), -8), 'peer' => ['id' => $params['id'], 'access_hash' => $params['access_hash'], '_' => 'inputPhoneCall'], 'g_a' => $this->call['g_a'], 'protocol' => ['_' => 'phoneCallProtocol', 'udp_reflector' => true, 'min_layer' => 65, 'max_layer' => 92]]))['phone_call'];
        } catch (RPCErrorException $e) {
            if ($e->rpc === 'CALL_ALREADY_ACCEPTED') {
                $this->API->logger->logger(\sprintf(Lang::$current_lang['call_already_accepted'], $params['id']));
                return true;
            }
            if ($e->rpc === 'CALL_ALREADY_DECLINED') {
                $this->API->logger->logger(Lang::$current_lang['call_already_declined']);
                $this->discard(['_' => 'phoneCallDiscardReasonHangup']);
                return false;
            }
            throw $e;
        }
        $visualization = [];
        $length = new BigInteger(\count(Magic::$emojis));
        foreach (\str_split(\hash('sha256', $key.\str_pad($this->call['g_a'], 256, \chr(0), STR_PAD_LEFT), true), 8) as $number) {
            $number[0] = \chr(\ord($number[0]) & 0x7f);
            $visualization[] = Magic::$emojis[(int) (new BigInteger($number, 256))->divide($length)[1]->toString()];
        }
        $this->visualization = $visualization;
        $this->authKey = $key;
        $this->callState = CallState::RUNNING;
        $this->protocolCallID = \substr(\hash('sha256', $key, true), -16);
        $this->initialize($res['connections']);
        return true;
    }
    /**
     * Accept incoming call.
     */
    public function accept(): self
    {
        if ($this->callState === CallState::RUNNING || $this->callState === CallState::ENDED) {
            return $this;
        }
        Assert::eq($this->callState->name, CallState::INCOMING->name);

        $this->API->logger->logger(\sprintf(Lang::$current_lang['accepting_call'], $this->otherID), Logger::VERBOSE);
        $dh_config = $this->API->getDhConfig();
        $this->API->logger->logger('Generating b...', Logger::VERBOSE);
        $b = BigInteger::randomRange(Magic::$two, $dh_config['p']->subtract(Magic::$two));
        $g_b = $dh_config['g']->powMod($b, $dh_config['p']);
        Crypt::checkG($g_b, $dh_config['p']);
        try {
            $this->API->methodCallAsyncRead('phone.acceptCall', ['peer' => ['id' => $this->call['id'], 'access_hash' => $this->call['access_hash'], '_' => 'inputPhoneCall'], 'g_b' => $g_b->toBytes(), 'protocol' => ['_' => 'phoneCallProtocol', 'udp_reflector' => true, 'udp_p2p' => true, 'min_layer' => 65, 'max_layer' => 92]]);
        } catch (RPCErrorException $e) {
            if ($e->rpc === 'CALL_ALREADY_ACCEPTED') {
                $this->API->logger->logger(\sprintf(Lang::$current_lang['call_already_accepted'], $this->callID));
                return $this;
            }
            if ($e->rpc === 'CALL_ALREADY_DECLINED') {
                $this->API->logger->logger(Lang::$current_lang['call_already_declined']);
                $this->discard(['_' => 'phoneCallDiscardReasonHangup']);
                return $this;
            }
            throw $e;
        }
        $this->call['b'] = $b;

        $this->callState = CallState::ACCEPTED;

        return $this;
    }

    /**
     * Complete call handshake.
     * 
     * @internal
     */
    public function complete(array $params): bool
    {
        if ($this->callState !== CallState::ACCEPTED) {
            $this->API->logger->logger(\sprintf(Lang::$current_lang['call_error_3'], $params['id']));
            return false;
        }
        $this->API->logger->logger(\sprintf(Lang::$current_lang['call_completing'], $this->otherID), Logger::VERBOSE);
        $dh_config = $this->API->getDhConfig();
        if (\hash('sha256', $params['g_a_or_b'], true) != $this->call['g_a_hash']) {
            throw new SecurityException('Invalid g_a!');
        }
        $params['g_a_or_b'] = new BigInteger((string) $params['g_a_or_b'], 256);
        Crypt::checkG($params['g_a_or_b'], $dh_config['p']);
        $key = \str_pad($params['g_a_or_b']->powMod($this->call['b'], $dh_config['p'])->toBytes(), 256, \chr(0), STR_PAD_LEFT);
        if (\substr(\sha1($key, true), -8) != $params['key_fingerprint']) {
            throw new SecurityException(Lang::$current_lang['fingerprint_invalid']);
        }
        $visualization = [];
        $length = new BigInteger(\count(Magic::$emojis));
        foreach (\str_split(\hash('sha256', $key.\str_pad($params['g_a_or_b']->toBytes(), 256, \chr(0), STR_PAD_LEFT), true), 8) as $number) {
            $number[0] = \chr(\ord($number[0]) & 0x7f);
            $visualization[] = Magic::$emojis[(int) (new BigInteger($number, 256))->divide($length)[1]->toString()];
        }
        $this->visualization = $visualization;
        $this->authKey = $key;
        $this->callState = CallState::RUNNING;
        $this->protocolCallID = \substr(\hash('sha256', $key, true), -16);
        $this->initialize($params['connections']);
        return true;
    }
    /**
     * Wakeup function.
     */
    public function __wakeup(): void
    {
        if ($this->callState === CallState::RUNNING) {
            $this->startReadLoop();
            if ($this->voipState === VoIPState::ESTABLISHED) {
                $this->startWriteLoop();
            }    
        }
    }

    /**
     * Get call emojis (will return null if the call is not inited yet).
     * 
     * @return ?list{string, string, string, string}
     */
    public function getVisualization(): ?array
    {
        return $this->visualization;
    }

    /**
     * Discard call.
     */
    public function discard(array $reason = ['_' => 'phoneCallDiscardReasonDisconnect'], array $rating = []): self
    {
        if ($this->callState === CallState::ENDED) {
            return $this;
        }
        Logger::log("Now closing $this");
        if (isset($this->timeoutWatcher)) {
            EventLoop::cancel($this->timeoutWatcher);
        }

        Logger::log("Closing all sockets in $this");
        foreach ($this->sockets as $socket) {
            $socket->disconnect();
        }
        Logger::log("Closed all sockets, discarding $this");

        $this->API->logger->logger(\sprintf(Lang::$current_lang['call_discarding'], $this->callID), Logger::VERBOSE);
        try {
            $this->API->methodCallAsyncRead('phone.discardCall', ['peer' => $this->call, 'duration' => \time() - $this->calls[$call['id']]->whenCreated(), 'connection_id' => $this->calls[$call['id']]->getPreferredRelayID(), 'reason' => $reason]);
        } catch (RPCErrorException $e) {
            if (!\in_array($e->rpc, ['CALL_ALREADY_DECLINED', 'CALL_ALREADY_ACCEPTED'], true)) {
                throw $e;
            }
        }
        if (!empty($rating)) {
            $this->API->logger->logger(\sprintf('Setting rating for call %s...', $this->call), Logger::VERBOSE);
            $this->API->methodCallAsyncRead('phone.setCallRating', ['peer' => $this->call, 'rating' => $rating['rating'], 'comment' => $rating['comment']]);
        }
        $this->API->cleanupCall($this->callID);
        $this->callState = CallState::ENDED;
        return $this;
    }

    /**
     * Connect to the specified endpoints.
     */
    private function initialize(array $endpoints): void
    {
        foreach ($endpoints as $endpoint) {
            try {
                $this->sockets['v6 '.$endpoint['id']] = new Endpoint(
                    '['.$endpoint['ipv6'].']',
                    $endpoint['port'],
                    $endpoint['peer_tag'],
                    true,
                    $this->outgoing,
                    $this->authKey,
                    $this->protocolCallID,
                    $this->messageHandler
                );
            } catch (Throwable) {
            }
            try {
                $this->sockets['v4 '.$endpoint['id']] = new Endpoint(
                    $endpoint['ip'],
                    $endpoint['port'],
                    $endpoint['peer_tag'],
                    true,
                    $this->outgoing,
                    $this->authKey,
                    $this->protocolCallID,
                    $this->messageHandler
                );
            } catch (Throwable) {
            }
        }
        $this->voipState = VoIPState::WAIT_INIT;
        $this->startReadLoop();
        foreach ($this->sockets as $socket) {
            $socket->write($this->messageHandler->encryptPacket([
                '_' => self::PKT_INIT,
                'protocol' => self::PROTOCOL_VERSION,
                'min_protocol' => self::MIN_PROTOCOL_VERSION,
                'audio_streams' => [self::CODEC_OPUS],
                'video_streams' => []
            ], true));
        }
    }
    /**
     * Handle incoming packet.
     */
    private function handlePacket(Endpoint $socket, array $packet): void
    {
        switch ($packet['_']) {
            case self::PKT_INIT:
                //$this->voipState = VoIPState::WAIT_INIT_ACK;
                $socket->write($this->messageHandler->encryptPacket([
                    '_' => self::PKT_INIT_ACK,
                    'protocol' => self::PROTOCOL_VERSION,
                    'min_protocol' => self::MIN_PROTOCOL_VERSION,
                    'all_streams' => [
                        ['id' => 0, 'type' => self::STREAM_TYPE_AUDIO, 'codec' => self::CODEC_OPUS, 'frame_duration' => 60, 'enabled' => 1]
                    ]
                ]));
                $socket->write($this->messageHandler->encryptPacket([
                    '_' => self::PKT_INIT,
                    'protocol' => self::PROTOCOL_VERSION,
                    'min_protocol' => self::MIN_PROTOCOL_VERSION,
                    'audio_streams' => [self::CODEC_OPUS],
                    'video_streams' => []
                ]));
                break;

            case self::PKT_INIT_ACK:
                if (!$this->bestEndpoint) {
                    $this->bestEndpoint = $socket;
                    $this->pingWatcher = EventLoop::delay(1.0, function (): void {
                        $this->pendingPing = true;
                        foreach ($this->sockets as $socket) {
                            $socket->udpPing();
                            $packet = $this->messageHandler->encryptPacket(['_' => self::PKT_PING]);
                            EventLoop::queue(fn () => $socket->write($packet));
                        }
                    });
                    $this->startWriteLoop();
                }
                break;
            case self::PKT_STREAM_DATA:
                $cnt = 1;
                break;
            case self::PKT_STREAM_DATA_X2:
                $cnt = 2;
                break;
            case self::PKT_STREAM_DATA_X3:
                $cnt = 3;
                break;
            case self::PKT_PING:
                $socket->write($this->messageHandler->encryptPacket(['_' => self::PKT_PONG, 'out_seq_no' => $packet['out_seq_no']]));
                break;
            case self::PKT_PONG:
                if ($this->pendingPing) {
                    $this->pendingPing = false;
                    if ($this->bestEndpoint !== $socket) {
                        Logger::log("Changing best endpoint from {$this->bestEndpoint} to $socket");
                        $this->bestEndpoint = $socket;
                    }
                }
                break;
        }
    }
    private function startReadLoop(): void
    {
        foreach ($this->sockets as $socket) {
            EventLoop::queue(function () use ($socket): void {
                while ($payload = $socket->read()) {
                    $this->lastIncomingTimestamp = \microtime(true);
                    EventLoop::queue($this->handlePacket(...), $socket, $payload);
                }
                Logger::log("Exiting VoIP read loop in $this!");
            });
        }
        $this->timeoutWatcher = EventLoop::repeat(10, function (): void {
            if (\microtime(true) - $this->lastIncomingTimestamp > 10) {
                $this->discard(['_' => 'phoneCallDiscardReasonDisconnect']);
            }
        });
    }
    /**
     * Start write loop.
     */
    private function startWriteLoop(): void
    {
        $this->voipState = VoIPState::ESTABLISHED;
        Logger::log("Call established, sending OPUS data!");

        $this->tempHoldFiles = [];
        while (true) {
            $file = \array_shift($this->inputFiles);
            if (!$file) {
                if (empty($this->tempHoldFiles)) {
                    $this->tempHoldFiles = $this->holdFiles;
                }
                if (empty($this->tempHoldFiles)) {
                    return;
                }
                $file = \array_shift($this->tempHoldFiles);
            }
            $it = $this->openFile($file);
            foreach ($it->opusPackets as $packet) {
                $this->packetQueue->enqueue($packet);
            }
            $t = \microtime(true) + 0.060;
            while (!$this->packetQueue->isEmpty()) {
                $packet = $this->messageHandler->encryptPacket(['_' => self::PKT_STREAM_DATA, 'stream_id' => 0, 'data' => $this->packetQueue->dequeue(), 'timestamp' => $this->opusTimestamp]);

                //Logger::log("Writing {$this->timestamp} in $this!");
                $diff = $t - \microtime(true);
                if ($diff > 0) {
                    delay($diff);
                }
                $this->bestEndpoint->write($packet);
                $t += 0.060;

                $this->opusTimestamp += 60;
            }
        }
    }
    /**
     * Open OGG file for reading.
     */
    private function openFile(string $file): Ogg
    {
        $ctx = new ConnectionContext;
        $ctx->addStream(FileBufferedStream::class, openFile($file, 'r'));
        $stream = $ctx->getStream();
        return new Ogg($stream);
    }
    /**
     * Play file.
     */
    public function play(string $file): self
    {
        $this->inputFiles[] = $file;

        return $this;
    }

    /**
     * Play file.
     */
    public function then(string $file): self
    {
        $this->inputFiles[] = $file;

        return $this;
    }

    /**
     * Files to play on hold.
     */
    public function playOnHold(array $files): self
    {
        $this->holdFiles = $files;

        return $this;
    }

    /**
     * Set output file.
     */
    public function setOutputFile(string $file): self
    {
        $this->outputFile = $file;

        return $this;
    }

    /**
     * Unset output file.
     */
    public function unsetOutputFile(): self
    {
        $this->outputFile = null;

        return $this;
    }

    /**
     * Get call state.
     */
    public function getCallState(): CallState
    {
        return $this->callState;
    }
    /**
     * Get VoIP state.
     */
    public function getVoIPState(): VoIPState
    {
        return $this->voipState;
    }

    /**
     * Get call representation.
     */
    public function __toString(): string
    {
        return "call {$this->callID} with {$this->otherID}";
    }
}
