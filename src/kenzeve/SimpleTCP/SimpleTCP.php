<?php

declare(strict_types=1);

namespace kenzeve\SimpleTCP;

use pocketmine\Server;

final class SimpleTCP{

	public static function start(string $ip, int $port, string $sessionClass = Session::class) : SimpleTCPServer{
		$server = Server::getInstance();

		$tcp = new SimpleTCPServer($ip, $port, $server->getLogger(), $server->getTickSleeper(), $sessionClass);
		$server->getNetwork()->registerInterface($tcp);

		return $tcp;
	}
}