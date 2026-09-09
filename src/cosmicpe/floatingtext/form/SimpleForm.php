<?php

declare(strict_types=1);

namespace cosmicpe\floatingtext\form;

use Closure;
use pocketmine\form\Form;
use pocketmine\player\Player;
use function is_int;

final class SimpleForm implements Form{

	/** @var list<array{text: string}> */
	private array $buttons = [];

	/** @var list<Closure(Player) : void> */
	private array $handlers = [];

	private ?Closure $on_close = null;

	public function __construct(
		private string $title,
		private string $text = ""
	){}

	/**
	 * @param Closure(Player) : void $handler
	 */
	public function addButton(string $text, Closure $handler) : self{
		$this->buttons[] = ["text" => $text];
		$this->handlers[] = $handler;
		return $this;
	}

	/**
	 * @param Closure(Player) : void $handler
	 */
	public function setOnClose(Closure $handler) : self{
		$this->on_close = $handler;
		return $this;
	}

	public function handleResponse(Player $player, mixed $data) : void{
		if($data === null){
			if($this->on_close !== null){
				($this->on_close)($player);
			}
			return;
		}

		if(is_int($data) && isset($this->handlers[$data])){
			($this->handlers[$data])($player);
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function jsonSerialize() : array{
		return [
			"type" => "form",
			"title" => $this->title,
			"content" => $this->text,
			"buttons" => $this->buttons
		];
	}
}
