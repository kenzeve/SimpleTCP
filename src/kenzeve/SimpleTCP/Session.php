<?php

declare(strict_types=1);

namespace kenzeve\SimpleTCP;

use function chr;
use function count;
use function pack;

class Session{

	protected bool $closed = false;
	/** @var string[] */
	private array $writeBuffer = [];

	public function __construct(
		protected SimpleTCPServer $server,
		private int $id,
		private string $ip,
		private int $port
	){
		$this->onConnect();
	}

	public function isClosed() : bool{
		return $this->closed;
	}

	/**
	 * @return int
	 */
	public function getId() : int{
		return $this->id;
	}

	/**
	 * @return string
	 */
	public function getIp() : string{
		return $this->ip;
	}

	public function onConnect() : void{

	}

	/**
	 * @return int
	 */
	public function getPort() : int{
		return $this->port;
	}

	public function write(string $buffer) : void{
		$this->writeBuffer[] = $buffer;
	}

	public function writeAndFlush(string $buffer) : void{
		$this->write($buffer);
		$this->flush();
	}

	public function flush() : void{
		if(count($this->writeBuffer) > 0){
			foreach($this->writeBuffer as $buffer){
				$this->server->writeExternal(chr(Signal::WRITE) . pack("N", $this->id) . $buffer, false);
			}
			$this->server->notify();
			$this->writeBuffer = [];
		}
	}

	public function close() : void{
		if($this->closed){
			return;
		}
		$this->closed = true;
		$this->onClose();
	}

	public function onClose() : void{

	}

	public function handlePacket(string $packet) : void{

	}
}