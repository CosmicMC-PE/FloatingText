<?php

declare(strict_types=1);

namespace cosmicpe\floatingtext\form;

use Closure;
use pocketmine\form\Form;
use pocketmine\player\Player;
use function array_values;
use function is_array;

final class CustomForm implements Form{

	/** @var list<array<string, mixed>> */
	private array $elements = [];

	private ?Closure $on_close = null;

	/**
	 * @param string $title
	 * @param Closure(Player, list<mixed>) : void $on_submit
	 */
	public function __construct(
		private string $title,
		private Closure $on_submit
	){}

	public function addLabel(string $text) : self{
		$this->elements[] = ["type" => "label", "text" => $text];
		return $this;
	}

	public function addInput(string $text, string $default = "", string $placeholder = "") : self{
		$this->elements[] = ["type" => "input", "text" => $text, "default" => $default, "placeholder" => $placeholder];
		return $this;
	}

	public function addToggle(string $text, bool $default = false) : self{
		$this->elements[] = ["type" => "toggle", "text" => $text, "default" => $default];
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

		if(is_array($data)){
			($this->on_submit)($player, array_values($data));
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function jsonSerialize() : array{
		return [
			"type" => "custom_form",
			"title" => $this->title,
			"content" => $this->elements
		];
	}
}
