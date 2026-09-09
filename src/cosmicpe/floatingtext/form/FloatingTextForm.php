<?php

declare(strict_types=1);

namespace cosmicpe\floatingtext\form;

use cosmicpe\floatingtext\FloatingText;
use cosmicpe\floatingtext\FloatingTextCommandExecutor;
use cosmicpe\floatingtext\FloatingTextEntity;
use cosmicpe\floatingtext\FloatingTextService;
use InvalidArgumentException;
use pocketmine\command\utils\CommandException;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use function count;
use function explode;
use function implode;
use function mb_strimwidth;
use function str_replace;
use function trim;

final class FloatingTextForm{

	private const COLOUR_HINT = "Use & for colour codes, e.g. &aGreen &lBold &r reset.";

	public function __construct(
		private FloatingTextService $service
	){}

	public function sendMainMenu(Player $player) : void{
		$nearby = [];
		foreach($player->getWorld()->getNearbyEntities($player->getBoundingBox()->expandedCopy(8, 8, 8)) as $entity){
			if($entity instanceof FloatingTextEntity){
				$nearby[$entity->getFloatingTextId()] = $entity->getFloatingText();
			}
		}

		$form = new SimpleForm(
			TextFormat::BOLD . TextFormat::BLUE . "FloatingText",
			count($nearby) > 0
				? TextFormat::GRAY . "Select a nearby floating text to edit, or create a new one."
				: TextFormat::GRAY . "No floating texts nearby. Create one at your position."
		);
		$form->addButton(TextFormat::DARK_GREEN . "Add floating text here", function(Player $player) : void{
			$this->sendEditForm($player, null, null);
		});
		foreach($nearby as $id => $text){
			$preview = str_replace(TextFormat::EOL, " ", $text->line);
			$form->addButton(TextFormat::WHITE . "#{$id} " . TextFormat::GRAY . mb_strimwidth($preview, 0, 40, "..."), function(Player $player) use($id) : void{
				$this->sendEditForm($player, $id, null);
			});
		}
		$form->addButton(TextFormat::GRAY . "Command help", static function(Player $player) : void{
			$player->sendMessage(FloatingTextCommandExecutor::help("ft"));
		});
		$player->sendForm($form);
	}

	public function sendEditForm(Player $player, ?int $id, ?string $error) : void{
		$lines = [];
		if($id !== null){
			try{
				$world = $this->service->getWorldForTextModification($player->getWorld());
				$text = $this->service->getTextInWorld($world, $id);
			}catch(CommandException $e){
				$player->sendMessage(TextFormat::RED . $e->getMessage());
				return;
			}
			foreach(explode(TextFormat::EOL, $text->line) as $line){
				$lines[] = str_replace(TextFormat::ESCAPE, "&", $line);
			}
		}

		$count = count($lines);
		$title = $id !== null ? "Edit floating text #{$id}" : "New floating text";
		$form = new CustomForm($title, function(Player $player, array $values) use($id, $count) : void{
			$this->handleEditSubmit($player, $id, $count, $values);
		});

		$hint = self::COLOUR_HINT;
		if($error !== null){
			$hint = TextFormat::RED . $error . TextFormat::RESET . TextFormat::EOL . $hint;
		}
		$form->addLabel($hint);

		foreach($lines as $i => $line){
			$n = $i + 1;
			$form->addInput("Line {$n}", $line);
			$form->addToggle("Delete line {$n}", false);
		}
		$form->addInput("Add new line", "", "Leave empty to add nothing");
		if($id !== null){
			$form->addToggle(TextFormat::RED . "Remove this entire floating text", false);
		}

		$player->sendForm($form);
	}

	/**
	 * @param list<mixed> $values element layout: [0] label, then per line input+toggle, then "add new line" input, then (edit only) remove toggle
	 */
	private function handleEditSubmit(Player $player, ?int $id, int $count, array $values) : void{
		$new_lines = [];
		for($i = 0; $i < $count; ++$i){
			$delete = ($values[2 * $i + 2] ?? false) === true;
			if(!$delete){
				$new_lines[] = (string) ($values[2 * $i + 1] ?? "");
			}
		}

		$added = (string) ($values[2 * $count + 1] ?? "");
		if(trim($added) !== ""){
			$new_lines[] = $added;
		}

		$remove_all = $id !== null && (($values[2 * $count + 2] ?? false) === true);

		try{
			if($id !== null){
				$world = $this->service->getWorldForTextModification($player->getWorld());
				if($remove_all){
					try{
						$world->remove($id);
					}catch(InvalidArgumentException){
						throw new CommandException("No floating text with the ID {$id} was found!");
					}
					$player->sendMessage(TextFormat::GREEN . "Removed floating text #{$id}!");
					return;
				}

				if(count($new_lines) === 0){
					$this->sendEditForm($player, $id, "A floating text must have at least one line.");
					return;
				}

				$old = $this->service->getTextInWorld($world, $id);
				$line = TextFormat::colorize(implode(TextFormat::EOL, $new_lines));
				$this->service->updateFloatingText($world, $id, new FloatingText($old->world, $old->x, $old->y, $old->z, $line));
				$player->sendMessage(TextFormat::GREEN . "Updated floating text #{$id}!");
				return;
			}

			if(count($new_lines) === 0){
				$this->sendEditForm($player, null, "A floating text must have at least one line.");
				return;
			}

			$line = TextFormat::colorize(implode(TextFormat::EOL, $new_lines));
			$this->service->addFloatingText($player->getPosition(), $line, static function(int $id, FloatingText $text) use($player) : void{
				if($player->isOnline()){
					$player->sendMessage(TextFormat::GREEN . "Added floating text #{$id} at your position!");
				}
			});
		}catch(CommandException $e){
			$player->sendMessage(TextFormat::RED . $e->getMessage());
		}
	}
}
