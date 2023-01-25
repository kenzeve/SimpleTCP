<?php

declare(strict_types=1);

namespace kenzeve\SimpleTCP;

use pocketmine\snooze\SleeperNotifier;
use pocketmine\thread\Thread;
use pocketmine\utils\BinaryStream;
use function chr;
use function pack;
use function socket_accept;
use function socket_close;
use function socket_getpeername;
use function socket_read;
use function socket_select;
use function socket_set_nonblock;
use function socket_set_option;
use function socket_write;
use const SO_KEEPALIVE;
use const SOL_SOCKET;

class TCPThread extends Thread{

	/** @var \Threaded */
	private $internal;
	/** @var \Threaded */
	private $external;

	/** @var bool */
	private $stop;

	/** @var \Socket */
	private $socket;
	/** @var \Socket */
	private $ipcSocket;

	/** @var \ThreadedLogger */
	private $logger;
	/** @var SleeperNotifier */
	private $notifier;

	public function __construct(\Socket $socket, \Socket $ipcSocket, \ThreadedLogger $logger, SleeperNotifier $notifier){
		$this->internal = new \Threaded();
		$this->external = new \Threaded();

		$this->stop = false;

		$this->socket = $socket;
		$this->ipcSocket = $ipcSocket;

		$this->logger = $logger;
		$this->notifier = $notifier;
	}

	public function getThreadName() : string{
		return "SimpleTCP";
	}

	public function close() : void{
		$this->stop = true;
	}

	protected function onRun() : void{
		/** @var \Socket[] $clients */
		$clients = [];

		/** @var int $nextClientId */
		$nextClientId = 1;

		while(!$this->stop){
			$close = [];

			while(($buf = $this->readExternal()) !== null){
				$stream = new BinaryStream($buf);
				switch($stream->getByte()){
					case Signal::WRITE:
						$id = $stream->getInt();
						$packet = $stream->getRemaining();
						$client = $clients[$id];
						if(!isset($close[$id])){
							socket_write($client, $packet);
						}
						break;
					case Signal::CLOSE:
						$id = $stream->getInt();
						$client = $clients[$id];
						if(!isset($close[$id])){
							$close[$id] = $client;
						}
						break;
				}
			}

			$r = $clients;
			$r["main"] = $this->socket;
			$r["ipc"] = $this->ipcSocket;
			$w = null;
			$e = null;

			if(socket_select($r, $w, $e, 5, 0) > 0){
				foreach($r as $id => $sock){
					if($sock === $this->socket){
						if(($client = socket_accept($this->socket)) !== false){
							socket_set_nonblock($client);
							socket_set_option($client, SOL_SOCKET, SO_KEEPALIVE, 1);

							$id = $nextClientId++;
							$clients[$id] = $client;
							socket_getpeername($client, $ip, $port);
							$this->logger->debug("New connection: " . $ip . ":" . $port);
							$this->doWriteInternal(chr(Signal::OPEN) . pack("N", $id) . $ip . ":" . $port);
						}
					}elseif($sock === $this->ipcSocket){
						socket_read($sock, 65535);
					}else{
						if(isset($close[$id])){
							continue;
						}

						$packet = @socket_read($sock, 65535);
						if($packet === false || $packet === ""){ // client sent empty packet when disconnected.
							$close[$id] = $sock;
							$this->doWriteInternal(chr(Signal::CLOSE) . pack("N", $id));
							continue;
						}
						$this->doWriteInternal(chr(Signal::READ) . pack("N", $id) . $packet);
					}
				}
			}

			foreach($close as $id => $socket){
				socket_getpeername($socket, $ip, $port);
				$this->logger->debug("Closed connection: " . $ip . ":" . $port);
				@socket_close($socket);
				unset($clients[$id]);
			}
		}
	}

	private function doWriteInternal(string $buffer) : void{
		$this->writeInternal($buffer);
		$this->notifier->wakeupSleeper();
	}

	public function writeInternal(string $buffer) : void{
		$this->synchronized(function() use ($buffer) : void{
			$this->internal[] = $buffer;
		});
	}

	public function readExternal() : ?string{
		return $this->synchronized(function() : ?string{
			return $this->external->shift();
		});
	}

	public function readInternal() : ?string{
		return $this->synchronized(function() : ?string{
			return $this->internal->shift();
		});
	}

	public function writeExternal(string $buffer) : void{
		$this->synchronized(function() use ($buffer) : void{
			$this->external[] = $buffer;
		});
	}
}