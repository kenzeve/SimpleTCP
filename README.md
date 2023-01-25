# SimpleTCP

<a href="https://poggit.pmmp.io/ci/kenzeve/SimpleTCP/SimpleTCP">  
    <img src="https://poggit.pmmp.io/ci.shield/kenzeve/SimpleTCP/SimpleTCP">  
</a>

## Usage
Very simple usage:
```php
$server = SimpleTCP::start("0.0.0.0", 2000, CustomSession::class);
```

`CustomSession.php`:
```php
class CustomSession extends Session{

	public function handlePacket(string $packet) : void{
		//packet handling stuff here
		//now I just send back packet from client
		$this->write($packet);
	}
}
```
