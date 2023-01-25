<?php

declare(strict_types=1);

namespace kenzeve\SimpleTCP;

use pocketmine\network\NetworkInterface;
use pocketmine\snooze\SleeperHandler;
use pocketmine\snooze\SleeperNotifier;
use pocketmine\utils\BinaryStream;
use function chr;
use function explode;
use function is_subclass_of;
use function pack;
use function socket_bind;
use function socket_close;
use function socket_create;
use function socket_create_pair;
use function socket_last_error;
use function socket_listen;
use function socket_set_option;
use function socket_strerror;
use function socket_write;
use function trim;
use const AF_INET;
use const AF_UNIX;
use const SO_REUSEADDR;
use const SOCK_STREAM;
use const SOCKET_ENOPROTOOPT;
use const SOCKET_EPROTONOSUPPORT;
use const SOL_SOCKET;
use const SOL_TCP;

class SimpleTCPServer implements NetworkInterface{

	private \Socket $socket;

	private \Socket $mainIPC;
	private \Socket $threadIPC;

	private TCPThread $thread;

	/** @var Session[] */
	private array $sessions = [];

	public function __construct(
		string $ip,
		int $port,
		\ThreadedLogger $logger,
		SleeperHandler $sleeper,
		private string $sessionClass = Session::class,
	){
		if(!is_subclass_of($sessionClass, Session::class)){
			throw new SimpleTCPException("$sessionClass must extend with " . Session::class . " class");
		}

		$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		if($socket === false){
			throw new SimpleTCPException("Failed to create socket: " . socket_strerror(socket_last_error()));
		}
		$this->socket = $socket;

		if(!socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1)){
			throw new SimpleTCPException("Unable to set option on socket: " . trim(socket_strerror(socket_last_error())));
		}

		if(!@socket_bind($this->socket, $ip, $port) or !@socket_listen($this->socket, 5)){
			throw new SimpleTCPException('Failed to open main socket: ' . trim(socket_strerror(socket_last_error())));
		}

		socket_set_nonblock($this->socket);

		$ret = @socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $ipc);
		if(!$ret){
			$err = socket_last_error();
			if(($err !== SOCKET_EPROTONOSUPPORT and $err !== SOCKET_ENOPROTOOPT) or !@socket_create_pair(AF_INET, SOCK_STREAM, 0, $ipc)){
				throw new SimpleTCPException('Failed to open IPC socket: ' . trim(socket_strerror(socket_last_error())));
			}
		}

		[$this->mainIPC, $this->threadIPC] = $ipc;

		$notifier = new SleeperNotifier();

		$sleeper->addNotifier($notifier, function() : void{
			$this->readInternal();
		});

		$this->thread = new TCPThread($this->socket, $this->threadIPC, $logger, $notifier);
	}

	public function start() : void{
		$this->thread->start();
	}

	public function setName(string $name) : void{

	}

	public function tick() : void{
		foreach($this->sessions as $id => $session){
			if($session->isClosed()){
				$this->closeSession($id, false);
				continue;
			}
			$session->flush();
		}
	}

	public function notify() : void{
		if(socket_write($this->mainIPC, "\x00") === false){ // trigger socket_select
			throw new SimpleTCPException("Could not notify main IPC socket");
		}
	}

	private function readInternal() : void{
		while(($buf = $this->thread->readInternal()) !== null){
			$stream = new BinaryStream($buf);
			switch($stream->getByte()){
				case Signal::OPEN:
					$client = $stream->getInt();
					$name = explode(":", $stream->getRemaining());
					$ip = $name[0];
					$port = (int) $name[1];
					$this->openSession($client, $ip, $port);
					break;
				case Signal::CLOSE:
					$client = $stream->getInt();
					$this->closeSession($client, true);
					break;
				case Signal::READ:
					$client = $stream->getInt();
					$packet = $stream->getRemaining();
					$this->handlePacket($client, $packet);
					break;
			}
		}
	}

	public function writeExternal(string $buffer, bool $notify = true) : void{
		$this->thread->writeExternal($buffer);
		if($notify){
			$this->notify();
		}
	}

	private function openSession(int $id, string $ip, int $port) : void{
		$sc = $this->sessionClass ?? Session::class;

		/** @var Session $session */
		$session = new $sc($this, $id, $ip, $port);

		$this->sessions[$id] = $session;
	}

	public function closeSession(int $id, bool $closedByThread) : void{
		if(!isset($this->sessions[$id])){
			return;
		}

		if($closedByThread){
			$session = $this->sessions[$id];
			$session->close();
		}else{
			$this->writeExternal(chr(Signal::CLOSE) . pack("N", $id));
		}

		unset($this->sessions[$id]);
	}

	/**
	 * @return Session[]
	 */
	public function getSessions() : array{
		return $this->sessions;
	}

	private function handlePacket(int $client, string $packet) : void{
		if(!isset($this->sessions[$client])){
			return;
		}
		$session = $this->sessions[$client];
		$session->handlePacket($packet);
	}

	public function shutdown() : void{
		$this->thread->close();
		socket_write($this->mainIPC, "\x00");
		$this->thread->quit();

		@socket_close($this->socket);
		@socket_close($this->mainIPC);
		@socket_close($this->threadIPC);
	}
}