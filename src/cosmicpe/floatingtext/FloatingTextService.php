<?php

declare(strict_types=1);

namespace cosmicpe\floatingtext;

use Closure;
use cosmicpe\floatingtext\db\Database;
use cosmicpe\floatingtext\world\WorldInstance;
use cosmicpe\floatingtext\world\WorldManager;
use pocketmine\command\utils\CommandException;
use pocketmine\world\Position;
use pocketmine\world\World;
use function str_contains;

final class FloatingTextService{

	private const TICKING_PLACEHOLDERS = ["{online}", "{max_players}", "{tps}", "{date}", "{time}"];

	public function __construct(
		private Database $database,
		private WorldManager $world_manager
	){}

	public function validateLine(string $line) : void{
		if(!str_contains($line, "{user}")){
			return;
		}

		foreach(self::TICKING_PLACEHOLDERS as $placeholder){
			if(str_contains($line, $placeholder)){
				throw new CommandException("Cannot use {user} together with {$placeholder} on the same floating text.");
			}
		}
	}

	/**
	 * @param Closure(int, FloatingText) : void $callback
	 */
	public function addFloatingText(Position $pos, string $line, Closure $callback) : void{
		$this->validateLine($line);
		$text = new FloatingText($pos->getWorld()->getFolderName(), $pos->x, $pos->y, $pos->z, $line);
		$this->database->add($text, function(int $id) use($pos, $text, $callback) : void{
			$this->world_manager->get($pos->getWorld())->add($id, $text);
			$callback($id, $text);
		});
	}

	public function updateFloatingText(WorldInstance $world, int $id, FloatingText $text) : void{
		$this->validateLine($text->line);
		$world->update($id, $text);
	}

	public function getWorldForTextModification(World $world) : WorldInstance{
		$instance = $this->world_manager->get($world);
		if($instance->isLoading()){
			throw new CommandException("Cannot modify text while the world is loading. Try again after some time.");
		}
		return $instance;
	}

	public function getTextInWorld(WorldInstance $world, int $id) : FloatingText{
		return $world->getText($id) ?? throw new CommandException("No floating text with the ID {$id} was found!");
	}
}
