<?php

declare(strict_types=1);

namespace cosmicpe\floatingtext;

use cosmicpe\floatingtext\form\FloatingTextForm;
use cosmicpe\floatingtext\world\WorldManager;
use InvalidArgumentException;
use pocketmine\command\Command;
use pocketmine\command\CommandExecutor;
use pocketmine\command\CommandSender;
use pocketmine\command\utils\CommandException;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use pocketmine\world\Position;
use function abs;
use function array_keys;
use function array_map;
use function array_pop;
use function array_shift;
use function array_slice;
use function assert;
use function basename;
use function count;
use function date;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function is_array;
use function is_dir;
use function is_file;
use function is_numeric;
use function is_string;
use function json_decode;
use function json_encode;
use function mkdir;
use function sprintf;
use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

final class FloatingTextCommandExecutor implements CommandExecutor{

	public static function help(string $label) : string{
		return TextFormat::BOLD . TextFormat::BLUE . "Floating Text Command" . TextFormat::RESET . TextFormat::EOL .
			TextFormat::BLUE . "/{$label}" . TextFormat::GRAY . " - Opens the floating text editor (forms)" . TextFormat::EOL .
			TextFormat::BLUE . "/{$label} add <...text>" . TextFormat::GRAY . " - Adds a floating text at your location" . TextFormat::EOL .
			TextFormat::BLUE . "/{$label} prepend <id> <...text>" . TextFormat::GRAY . " - Prepends a line to a floating text" . TextFormat::EOL .
			TextFormat::BLUE . "/{$label} append <id> <...text>" . TextFormat::GRAY . " - Appends a line to a floating text" . TextFormat::EOL .
			TextFormat::BLUE . "/{$label} shift <id>" . TextFormat::GRAY . " - Shifts a line off of a floating text" . TextFormat::EOL .
			TextFormat::BLUE . "/{$label} pop <id>" . TextFormat::GRAY . " - Pops a line off of a floating text" . TextFormat::EOL .
			TextFormat::BLUE . "/{$label} split <id>" . TextFormat::GRAY . " - Separate a multi-line floating text" . TextFormat::EOL .
			TextFormat::BLUE . "/{$label} set <id> <...text>" . TextFormat::GRAY . " - Changes an existing line's value on a floating text" . TextFormat::EOL .
			TextFormat::BLUE . "/{$label} move <id>" . TextFormat::GRAY . " - Moves a floating text to your location" . TextFormat::EOL .
			TextFormat::BLUE . "/{$label} near" . TextFormat::GRAY . " - Lists all floating texts near your location" . TextFormat::EOL .
			TextFormat::BLUE . "/{$label} remove <id>" . TextFormat::GRAY . " - Removes a floating text" . TextFormat::EOL .
			TextFormat::BLUE . "/{$label} export [world]" . TextFormat::GRAY . " - Exports floating texts to a JSON file, to migrate them to another server" . TextFormat::EOL .
			TextFormat::BLUE . "/{$label} import <file>" . TextFormat::GRAY . " - Imports floating texts from a previously exported JSON file";
	}

	public function __construct(
		private FloatingTextService $service,
		private WorldManager $world_manager,
		private FloatingTextForm $form,
		private Loader $loader
	){}

	private function parseInt(string $argument, string $name) : int{
		$id = (int) $argument;
		if($argument !== (string) $id){
			throw new CommandException("Invalid {$name}: {$id}");
		}
		return $id;
	}

	private function parseFloatingTextId(string $argument) : int{
		return $this->parseInt($argument, "floating text id");
	}

	/**
	 * @param CommandSender $sender
	 * @param Command $command
	 * @param string $label
	 * @param string[] $args
	 */
	private function executeCommand(CommandSender $sender, Command $command, string $label, array $args) : void{
		if(isset($args[0]) && ($args[0] === "export" || $args[0] === "import")){
			$this->executeMigrationCommand($sender, $label, $args);
			return;
		}

		if(!($sender instanceof Player)){
			throw new CommandException("This command must be used as a player.");
		}

		if(!isset($args[0])){
			$this->form->sendMainMenu($sender);
			return;
		}

		switch($args[0]){
			case "form":
			case "menu":
			case "edit":
				$this->form->sendMainMenu($sender);
				return;
			case "help":
				throw new CommandException(self::help($label));
			case "add":
				if(!isset($args[1])){
					throw new CommandException(
						"Usage: /{$label} {$args[0]} <...text>" . TextFormat::EOL .
						TextFormat::GRAY . "Hint: You may use & for colour codes."
					);
				}

				$line = TextFormat::colorize(implode(" ", array_slice($args, 1)));
				$this->service->addFloatingText($sender->getPosition(), $line, static function(int $id, FloatingText $text) use($sender) : void{
					if(!($sender instanceof Player) || $sender->isOnline()){
						$sender->sendMessage(TextFormat::GREEN . "Added floating text at your position!");
						$sender->sendMessage(TextFormat::GREEN . sprintf("Position: x=%.4f, y=%.4f, z=%.4f, world=%s", $text->x, $text->y, $text->z, $text->world));
						$sender->sendMessage(TextFormat::GREEN . "Text: {$text->line}");
					}
				});
				return;
			case "prepend":
				if(!isset($args[1]) || !isset($args[2])){
					throw new CommandException(
						"Usage: /{$label} {$args[0]} <id> <...line>" . TextFormat::EOL .
						TextFormat::GRAY . "Hint: Use " . TextFormat::RED . "/{$label} near" . TextFormat::GRAY . " to list nearby floating texts along with their <id>."
					);
				}

				$id = $this->parseFloatingTextId($args[1]);
				$world = $this->service->getWorldForTextModification($sender->getWorld());
				$text = $this->service->getTextInWorld($world, $id);

				$line = TextFormat::colorize(implode(" ", array_slice($args, 2)));
				$text = new FloatingText($text->world, $text->x, $text->y, $text->z, $line . TextFormat::EOL . $text->line);
				$this->service->updateFloatingText($world, $id, $text);

				$sender->sendMessage(TextFormat::GREEN . "Prepended floating text #{$id}!");
				$sender->sendMessage(TextFormat::GREEN . sprintf("Position: x=%.4f, y=%.4f, z=%.4f, world=%s", $text->x, $text->y, $text->z, $text->world));
				$sender->sendMessage(TextFormat::GREEN . "Prepended Text: {$line}");
				return;
			case "append":
				if(!isset($args[1]) || !isset($args[2])){
					throw new CommandException(
						"Usage: /{$label} {$args[0]} <id> <...line>" . TextFormat::EOL .
						TextFormat::GRAY . "Hint: Use " . TextFormat::RED . "/{$label} near" . TextFormat::GRAY . " to list nearby floating texts along with their <id>."
					);
				}

				$id = $this->parseFloatingTextId($args[1]);
				$world = $this->service->getWorldForTextModification($sender->getWorld());
				$text = $this->service->getTextInWorld($world, $id);

				$line = TextFormat::colorize(implode(" ", array_slice($args, 2)));
				$text = new FloatingText($text->world, $text->x, $text->y, $text->z, $text->line . TextFormat::EOL . $line);
				$this->service->updateFloatingText($world, $id, $text);

				$sender->sendMessage(TextFormat::GREEN . "Appended floating text #{$id}!");
				$sender->sendMessage(TextFormat::GREEN . sprintf("Position: x=%.4f, y=%.4f, z=%.4f, world=%s", $text->x, $text->y, $text->z, $text->world));
				$sender->sendMessage(TextFormat::GREEN . "Appended Text: {$line}");
				return;
			case "shift":
				if(!isset($args[1])){
					throw new CommandException(
						"Usage: /{$label} {$args[0]} <id>" . TextFormat::EOL .
						TextFormat::GRAY . "Hint: Use " . TextFormat::RED . "/{$label} near" . TextFormat::GRAY . " to list nearby floating texts along with their <id>."
					);
				}

				$id = $this->parseFloatingTextId($args[1]);
				$world = $this->service->getWorldForTextModification($sender->getWorld());
				$text = $this->service->getTextInWorld($world, $id);

				$line = explode(TextFormat::EOL, $text->line);
				$shifted = array_shift($line);
				$text = new FloatingText($text->world, $text->x, $text->y, $text->z, implode(TextFormat::EOL, $line));
				$this->service->updateFloatingText($world, $id, $text);

				$sender->sendMessage(TextFormat::GREEN . "Shifted floating text #{$id}!");
				$sender->sendMessage(TextFormat::GREEN . sprintf("Position: x=%.4f, y=%.4f, z=%.4f, world=%s", $text->x, $text->y, $text->z, $text->world));
				$sender->sendMessage(TextFormat::GREEN . "Shifted Text: {$shifted}");
				return;
			case "pop":
				if(!isset($args[1])){
					throw new CommandException(
						"Usage: /{$label} {$args[0]} <id>" . TextFormat::EOL .
						TextFormat::GRAY . "Hint: Use " . TextFormat::RED . "/{$label} near" . TextFormat::GRAY . " to list nearby floating texts along with their <id>."
					);
				}

				$id = $this->parseFloatingTextId($args[1]);
				$world = $this->service->getWorldForTextModification($sender->getWorld());
				$text = $this->service->getTextInWorld($world, $id);

				$line = explode(TextFormat::EOL, $text->line);
				$pop = array_pop($line);
				$text = new FloatingText($text->world, $text->x, $text->y, $text->z, implode(TextFormat::EOL, $line));
				$this->service->updateFloatingText($world, $id, $text);

				$sender->sendMessage(TextFormat::GREEN . "Popped floating text #{$id}!");
				$sender->sendMessage(TextFormat::GREEN . sprintf("Position: x=%.4f, y=%.4f, z=%.4f, world=%s", $text->x, $text->y, $text->z, $text->world));
				$sender->sendMessage(TextFormat::GREEN . "Popped Text: {$pop}");
				return;
			case "split":
				if(!isset($args[1])){
					throw new CommandException(
						"Usage: /{$label} {$args[0]} <id>" . TextFormat::EOL .
						TextFormat::GRAY . "Hint: Use " . TextFormat::RED . "/{$label} near" . TextFormat::GRAY . " to list nearby floating texts along with their <id>."
					);
				}

				$id = $this->parseFloatingTextId($args[1]);
				$world = $this->service->getWorldForTextModification($sender->getWorld());
				$text = $this->service->getTextInWorld($world, $id);

				$step = -0.275;

				$lines = explode(TextFormat::EOL, $text->line);
				if(count($lines) === 1){
					throw new CommandException("Floating text #{$id} contains only one line!");
				}

				$text = new FloatingText($text->world, $text->x, $text->y - ($step * count($lines) * 0.5), $text->z, array_shift($lines));
				$this->service->updateFloatingText($world, $id, $text);
				$offset = $step;
				foreach($lines as $line){
					$this->service->addFloatingText(new Position($text->x, $text->y + $offset, $text->z, $sender->getWorld()), $line, static function(int $id, FloatingText $text) : void{});
					$offset += $step;
				}

				$sender->sendMessage(TextFormat::GREEN . "Split floating text #{$id}!");
				$sender->sendMessage(TextFormat::GREEN . sprintf("Position: x=%.4f, y=%.4f, z=%.4f, world=%s", $text->x, $text->y, $text->z, $text->world));
				$sender->sendMessage(TextFormat::GREEN . "Number of splits: " . (count($lines) + 1));
				return;
			case "combine":
				if(!isset($args[1]) || !isset($args[2])){
					throw new CommandException(
						"Usage: /{$label} {$args[0]} <...ids>" . TextFormat::EOL .
						TextFormat::GRAY . "Hint: Use " . TextFormat::RED . "/{$label} near" . TextFormat::GRAY . " to list nearby floating texts along with their <id>."
					);
				}

				$world = $this->service->getWorldForTextModification($sender->getWorld());
				$texts = [];
				foreach(array_slice($args, 1) as $id_arg){
					$id = $this->parseFloatingTextId($id_arg);
					$text = $this->service->getTextInWorld($world, $id);
					$texts[] = $text;
				}

				$line = implode(TextFormat::EOL, array_map(static function(FloatingText $text) : string{ return $text->line; }, $texts));
				$this->service->addFloatingText($sender->getPosition(), $line, static function(int $id, FloatingText $text) use($sender) : void{
					if(!($sender instanceof Player) || $sender->isOnline()){
						$sender->sendMessage(TextFormat::GREEN . "Added floating text at your position!");
						$sender->sendMessage(TextFormat::GREEN . sprintf("Position: x=%.4f, y=%.4f, z=%.4f, world=%s", $text->x, $text->y, $text->z, $text->world));
						$sender->sendMessage(TextFormat::GREEN . "Text: {$text->line}");
					}
				});
				return;
			case "set":
				if(!isset($args[1]) || !isset($args[2]) || !isset($args[3])){
					throw new CommandException(
						"Usage: /{$label} {$args[0]} <id> <line_number> <...text>" . TextFormat::EOL .
						TextFormat::GRAY . "Hint: Use " . TextFormat::RED . "/{$label} near" . TextFormat::GRAY . " to list nearby floating texts along with their <id>."
					);
				}

				$id = $this->parseFloatingTextId($args[1]);
				$line_number = $this->parseInt($args[2], "line number");
				$world = $this->service->getWorldForTextModification($sender->getWorld());
				$text = $this->service->getTextInWorld($world, $id);

				$lines = explode(TextFormat::EOL, $text->line);
				if(!isset($lines[$line_number - 1])){
					throw new CommandException("Line #{$line_number} does not exist floating text with the ID {$id}!");
				}

				$lines[$line_number - 1] = $new_text = TextFormat::colorize(implode(" ", array_slice($args, 3)));
				$text = new FloatingText($text->world, $text->x, $text->y, $text->z, implode(TextFormat::EOL, $lines));
				$this->service->updateFloatingText($world, $id, $text);

				$sender->sendMessage(TextFormat::GREEN . "Updated floating text #{$id}'s line #{$line_number}!");
				$sender->sendMessage(TextFormat::GREEN . sprintf("Position: x=%.4f, y=%.4f, z=%.4f, world=%s", $text->x, $text->y, $text->z, $text->world));
				$sender->sendMessage(TextFormat::GREEN . "Updated line: {$line_number}");
				$sender->sendMessage(TextFormat::GREEN . "New Text: {$new_text}");
				return;
			case "move":
				if(!isset($args[1])){
					throw new CommandException(
						"Usage: /{$label} {$args[0]} <...id>" . TextFormat::EOL .
						TextFormat::GRAY . "Hint: Use " . TextFormat::RED . "/{$label} near" . TextFormat::GRAY . " to list nearby floating texts along with their <id>."
					);
				}

				$world = $this->service->getWorldForTextModification($sender->getWorld());
				$old_texts = [];
				foreach(array_slice($args, 1) as $arg){
					$id = $this->parseFloatingTextId($arg);
					$old_text = $this->service->getTextInWorld($world, $id);
					$old_texts[] = [$id, $old_text];
				}

				$new_pos = $sender->getPosition();
				foreach($old_texts as $index => [$id, $old_text]){
					assert($old_text instanceof FloatingText);
					if($index === 0){
						$pos = $new_pos;
					}else{
						$relative = $old_texts[0][1];
						assert($relative instanceof FloatingText);
						$pos = $new_pos->add($old_text->x - $relative->x, $old_text->y - $relative->y, $old_text->z - $relative->z);
					}

					$new_text = new FloatingText($old_text->world, $pos->x, $pos->y, $pos->z, $old_text->line);
					$world->update($id, $new_text);

					$sender->sendMessage(TextFormat::GREEN . "Moved floating text #{$id}!");
					$sender->sendMessage(TextFormat::GREEN . sprintf("Position: x=%.4f, y=%.4f, z=%.4f, world=%s", $old_text->x, $old_text->y, $old_text->z, $old_text->world));
					$sender->sendMessage(TextFormat::GREEN . sprintf("New Position: x=%.4f, y=%.4f, z=%.4f, world=%s", $new_text->x, $new_text->y, $new_text->z, $new_text->world));
				}
				return;
			case "copy":
				if(!isset($args[1])){
					throw new CommandException(
						"Usage: /{$label} {$args[0]} <id>" . TextFormat::EOL .
						TextFormat::GRAY . "Hint: Use " . TextFormat::RED . "/{$label} near" . TextFormat::GRAY . " to list nearby floating texts along with their <id>."
					);
				}

				$copy_id = $this->parseFloatingTextId($args[1]);
				$text = null;
				foreach($this->world_manager->getAll() as $world){
					$text = $world->getText($copy_id);
					if($text !== null){
						break;
					}
				}

				$text ?? throw new CommandException("No floating text with the id {$args[1]} was found");
				$this->service->addFloatingText($sender->getPosition(), $text->line, static function(int $id, FloatingText $text) use($sender, $copy_id) : void{
					if(!($sender instanceof Player) || $sender->isOnline()){
						$sender->sendMessage(TextFormat::GREEN . "Copied floating text #{$copy_id} to your position!");
						$sender->sendMessage(TextFormat::GREEN . sprintf("Position: x=%.4f, y=%.4f, z=%.4f, world=%s", $text->x, $text->y, $text->z, $text->world));
						$sender->sendMessage(TextFormat::GREEN . "Text: {$text->line}");
					}
				});
				return;
			case "near":
				$world = $sender->getWorld();
				$found = 0;
				foreach($world->getNearbyEntities($sender->getBoundingBox()->expandedCopy(8, 8, 8)) as $entity){
					if($entity instanceof FloatingTextEntity){
						$sender->sendMessage(TextFormat::GRAY . "#{$entity->getFloatingTextId()}: " . TextFormat::RESET . $entity->getNameTag());
						++$found;
					}
				}
				$sender->sendMessage($found > 0 ? TextFormat::GRAY . "Found " . TextFormat::WHITE . $found . TextFormat::GRAY . " floating texts near you!" : TextFormat::RED . "No floating texts were found nearby!");
				return;
			case "remove":
				if(!isset($args[1])){
					throw new CommandException(
						"Usage: /{$label} {$args[0]} <id>" . TextFormat::EOL .
						TextFormat::GRAY . "Hint: Use " . TextFormat::RED . "/{$label} near" . TextFormat::GRAY . " to list nearby floating texts along with their <id>."
					);
				}

				$id = $this->parseFloatingTextId($args[1]);
				$world = $this->service->getWorldForTextModification($sender->getWorld());
				try{
					$text = $world->remove($id);
				}catch(InvalidArgumentException){
					throw new CommandException("No floating text with the ID {$id} was found!");
				}

				$sender->sendMessage(TextFormat::GREEN . "Removed floating text #{$id}!");
				$sender->sendMessage(TextFormat::GREEN . sprintf("Position: x=%.4f, y=%.4f, z=%.4f, world=%s", $text->x, $text->y, $text->z, $text->world));
				$sender->sendMessage(TextFormat::GREEN . "Text: {$text->line}");
				return;
		}

		throw new CommandException(self::help($label));
	}

	/**
	 * @param CommandSender $sender
	 * @param string[] $args
	 */
	private function executeMigrationCommand(CommandSender $sender, string $label, array $args) : void{
		$exports_dir = $this->loader->getDataFolder() . "exports/";
		if(!is_dir($exports_dir)){
			mkdir($exports_dir, 0777, true);
		}

		if($args[0] === "export"){
			$world_filter = $args[1] ?? null;
			$respond = function(array $texts) use($sender, $exports_dir, $world_filter, $label) : void{
				$data = [];
				foreach($texts as $id => $text){
					assert($text instanceof FloatingText);
					$data[] = ["id" => $id, "world" => $text->world, "x" => $text->x, "y" => $text->y, "z" => $text->z, "line" => $text->line];
				}

				$filename = "floatingtexts_" . ($world_filter ?? "all") . "_" . date("Y-m-d_His") . ".json";
				file_put_contents($exports_dir . $filename, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

				$sender->sendMessage(TextFormat::GREEN . "Exported " . count($data) . " floating text(s) to " . TextFormat::WHITE . "exports/{$filename}");
				$sender->sendMessage(TextFormat::GRAY . "Copy this file into the other server's plugin_data/FloatingText/exports/ folder, then run " . TextFormat::WHITE . "/{$label} import {$filename}" . TextFormat::GRAY . " there.");
			};

			if($world_filter === null){
				$this->loader->getDatabase()->loadAll($respond);
			}else{
				$this->loader->getDatabase()->load($world_filter, $respond);
			}
			return;
		}

		// import
		if(!isset($args[1])){
			throw new CommandException("Usage: /{$label} import <file>" . TextFormat::EOL . TextFormat::GRAY . "Hint: Use " . TextFormat::RED . "/{$label} export" . TextFormat::GRAY . " on the source server first, then copy the resulting file into this server's exports/ folder.");
		}

		$filename = basename($args[1]);
		$path = $exports_dir . $filename;
		if(!is_file($path)){
			throw new CommandException("File not found: exports/{$filename}");
		}

		$contents = file_get_contents($path);
		$data = $contents === false ? null : json_decode($contents, true);
		if(!is_array($data)){
			throw new CommandException("File exports/{$filename} is not a valid floating text export.");
		}

		$to_import = [];
		$invalid = 0;
		foreach($data as $entry){
			if(
				!is_array($entry) ||
				!isset($entry["world"], $entry["x"], $entry["y"], $entry["z"], $entry["line"]) ||
				!is_string($entry["world"]) ||
				!is_numeric($entry["x"]) || !is_numeric($entry["y"]) || !is_numeric($entry["z"]) ||
				!is_string($entry["line"])
			){
				++$invalid;
				continue;
			}

			$to_import[] = new FloatingText($entry["world"], (float) $entry["x"], (float) $entry["y"], (float) $entry["z"], $entry["line"]);
		}

		if(count($to_import) === 0){
			$sender->sendMessage(TextFormat::YELLOW . "Nothing to import from exports/{$filename}" . ($invalid > 0 ? " ({$invalid} invalid entries)" : "") . ".");
			return;
		}

		$worlds = [];
		foreach($to_import as $text){
			$worlds[$text->world] = true;
		}
		$worlds = array_keys($worlds);

		// Floating texts already present (by world+position+line) are skipped instead of duplicated,
		// so re-running an import (or importing overlapping exports) is safe.
		$existing_by_world = [];
		$pending = count($worlds);
		$finish = function() use(&$existing_by_world, $to_import, $sender, $filename, $invalid) : void{
			$imported = 0;
			$duplicates = 0;
			$pm_world_manager = $this->loader->getServer()->getWorldManager();
			foreach($to_import as $text){
				$is_duplicate = false;
				foreach($existing_by_world[$text->world] ?? [] as $existing_text){
					if(
						$existing_text->line === $text->line &&
						abs($existing_text->x - $text->x) < 0.001 &&
						abs($existing_text->y - $text->y) < 0.001 &&
						abs($existing_text->z - $text->z) < 0.001
					){
						$is_duplicate = true;
						break;
					}
				}

				if($is_duplicate){
					++$duplicates;
					continue;
				}

				$pm_world = $pm_world_manager->getWorldByName($text->world);
				$world_instance = $pm_world !== null ? $this->world_manager->getNullable($pm_world) : null;
				$this->loader->getDatabase()->add($text, static function(int $id) use($world_instance, $text) : void{
					$world_instance?->add($id, $text);
				});
				++$imported;
			}

			$sender->sendMessage(TextFormat::GREEN . "Imported {$imported} floating text(s) from " . TextFormat::WHITE . "exports/{$filename}");
			if($duplicates > 0){
				$sender->sendMessage(TextFormat::YELLOW . "Skipped {$duplicates} entr" . ($duplicates === 1 ? "y" : "ies") . " that already existed (same world, position and text).");
			}
			if($invalid > 0){
				$sender->sendMessage(TextFormat::YELLOW . "Skipped {$invalid} malformed entr" . ($invalid === 1 ? "y" : "ies") . " in the file.");
			}
		};

		foreach($worlds as $world){
			$this->loader->getDatabase()->load($world, function(array $texts) use($world, &$existing_by_world, &$pending, $finish) : void{
				$existing_by_world[$world] = $texts;
				--$pending;
				if($pending === 0){
					$finish();
				}
			});
		}
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
		try{
			$this->executeCommand($sender, $command, $label, $args);
		}catch(CommandException $e){
			$sender->sendMessage(TextFormat::RED . $e->getMessage());
			return false;
		}
		return true;
	}
}
