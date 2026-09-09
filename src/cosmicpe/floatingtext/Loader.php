<?php

declare(strict_types=1);

namespace cosmicpe\floatingtext;

use cosmicpe\floatingtext\db\Database;
use cosmicpe\floatingtext\form\FloatingTextForm;
use cosmicpe\floatingtext\handler\FloatingTextFindAndReplaceHandler;
use cosmicpe\floatingtext\handler\FloatingTextFindAndReplaceTickerHandler;
use cosmicpe\floatingtext\handler\FloatingTextHandlerManager;
use cosmicpe\floatingtext\world\WorldManager;
use pocketmine\command\PluginCommand;
use pocketmine\entity\Entity;
use pocketmine\entity\EntityFactory;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\plugin\PluginBase;
use pocketmine\world\World;
use RuntimeException;
use const PHP_INT_MIN;

final class Loader extends PluginBase{

	private Database $database;
	private WorldManager $world_manager;
	private FloatingTextHandlerManager $handler_manager;

	protected function onLoad() : void{
		EntityFactory::getInstance()->register(FloatingTextEntity::class, fn(World $world, CompoundTag $nbt) : FloatingTextEntity => throw new RuntimeException("Did not expect floating text entity to save"), ["cosmicpe:floating_text"]);
		$this->world_manager = new WorldManager();
		$this->handler_manager = new FloatingTextHandlerManager($this->world_manager);
	}

	protected function onEnable() : void{
		$this->database = new Database($this);
		$this->world_manager->init($this);

		$command = $this->getCommand("floatingtext");
		if(!($command instanceof PluginCommand)){
			throw new RuntimeException("Cannot find command \"floatingtext\"");
		}
		$service = new FloatingTextService($this->database, $this->world_manager);
		$command->setExecutor(new FloatingTextCommandExecutor($service, $this->world_manager, new FloatingTextForm($service)));

		$this->registerPlaceholders();
	}

	private function registerPlaceholders() : void{
		$server = $this->getServer();

		$this->handler_manager->register(new FloatingTextFindAndReplaceHandler("{ip}", (string) $this->getConfig()->get("placeholder-ip")));

		$this->handler_manager->register(new FloatingTextFindAndReplaceTickerHandler($this, "{online}", fn() : string => (string) count($server->getOnlinePlayers()), 100));
		$this->handler_manager->register(new FloatingTextFindAndReplaceTickerHandler($this, "{max_players}", fn() : string => (string) $server->getMaxPlayers(), 100));
		$this->handler_manager->register(new FloatingTextFindAndReplaceTickerHandler($this, "{tps}", fn() : string => number_format($server->getTicksPerSecondAverage(), 2), 20));
		$this->handler_manager->register(new FloatingTextFindAndReplaceTickerHandler($this, "{date}", fn() : string => date("Y-m-d"), 1200));
		$this->handler_manager->register(new FloatingTextFindAndReplaceTickerHandler($this, "{time}", fn() : string => date("H:i"), 1200));
	}

	protected function onDisable() : void{
		$this->database->close();
		$this->world_manager->destroy();
	}

	public function getDatabase() : Database{
		return $this->database;
	}

	public function getWorldManager() : WorldManager{
		return $this->world_manager;
	}

	public function getHandlerManager() : FloatingTextHandlerManager{
		return $this->handler_manager;
	}
}