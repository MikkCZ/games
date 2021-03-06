<?php
namespace Prsi;
use Ratchet\ConnectionInterface;

class Game {
	const RANK_SEVEN = 0;
	const RANK_QUEEN = 5;
	const RANK_ACE = 7;
	
	public $players;
	private $deck;
	private $hands;
	private $playing;
	private $upcard;
	private $toDraw;

	function __construct() {
		$this->players = new \SplObjectStorage;
		$this->hands = new \SplObjectStorage;
	}

	function start() {
		$this->deck = range(0, 31);
		shuffle($this->deck);
		$this->playing = iterator_to_array($this->players);
		$playing = reset($this->playing);
		foreach ($this->players as $player) {
			$hand = array();
			for ($i = 0; $i < 4; $i++) {
				$hand[array_pop($this->deck)] = true;
			}
			$this->hands[$player] = $hand;
		}
		$this->upcard = array_pop($this->deck);
		$this->toDraw = ($this->getRank($this->upcard) == self::RANK_SEVEN ? 2 : ($this->getRank($this->upcard) == self::RANK_ACE ? 0 : 1));
		foreach ($this->players as $i => $player) {
			$data = array(
				'cards' => array_keys($this->hands[$player]),
				'upcard' => $this->upcard,
				'action' => ($playing == $player ? $this->getAction() : ''),
				'start' => true,
			);
			if ($this->getRank($this->upcard) == self::RANK_QUEEN) {
				$data['sound'] = 'suit-' . $this->getSuit($this->upcard);
			} elseif ($this->toDraw != 1) {
				$data['sound'] = "beres-$this->toDraw";
			}
			if ($this->getRank($this->upcard) == self::RANK_QUEEN) {
				$data['suit'] = $this->getSuit($this->upcard);
			}
			Client::send($player, ($i ? "" : "You play."), $data);
		}
	}
	
	private function getAction() {
		$toDraw = min($this->toDraw, count($this->deck));
		return ($toDraw == 0 ? 'pass' : 'draw' . ($toDraw != 1 ? " $this->toDraw" : ""));
	}
	
	function play(ConnectionInterface $from, $msg) {
		if ($from != current($this->playing)) {
			Client::send($from, "Not your turn.");
			return;
		}
		$card = trim($msg, "\r\nabcd");
		$suit = trim($msg, "\r\n0123456789");
		$data = array('upcard' => $card);
		
		$hand = $this->hands[$from];
		if ($card == 32) {
			$cards = array();
			for ($i = 0; $i < $this->toDraw && $this->deck; $i++) {
				$drawn = array_pop($this->deck);
				$hand[$drawn] = true;
				$cards[] = $drawn;
			}
			if ($cards) {
				Client::send($from, "", array('cards' => $cards));
			}
			$data['sound'] = ($this->toDraw ? 'lizu' : 'stojim');
			$this->toDraw = 1;
		} else {
			if (!isset($this->hands[$from][$card])) {
				Client::send($from, "You don't have this card.");
				return;
			}
			if ($this->toDraw > 1) {
				if ($this->getRank($card) != self::RANK_SEVEN) {
					Client::send($from, "You can play only Seven on top of Seven.");
					return;
				}
			} elseif ($this->toDraw == 0 && $this->getRank($card) != self::RANK_ACE) {
				Client::send($from, "You can play only Ace on top of Ace.");
				return;
			}
			$upcard = $card;
			if ($this->getRank($card) == self::RANK_QUEEN) {
				if (!$suit) {
					Client::send($from, "Choose suit.");
					return;
				}
				$data['suit'] = ord($suit) - ord('a');
				$data['sound'] = "suit-$data[suit]";
				$upcard = 8 * $data['suit'] + $card % 8;
			} elseif ($this->getSuit($this->upcard) != $this->getSuit($card) && $this->getRank($this->upcard) != $this->getRank($card)) {
				Client::send($from, "You can play only a card with the same suit or rank.");
				return;
			}
			if ($this->getRank($card) == self::RANK_SEVEN) {
				$this->toDraw = ($this->toDraw == 1 ? 2 : $this->toDraw + 2);
				$data['sound'] = "beres-$this->toDraw";
			} elseif ($this->getRank($card) == self::RANK_ACE) {
				$this->toDraw = 0;
				$data['sound'] = "beres-0";
			}
			unset($hand[$card]);
			if (!count($hand)) {
				$data['sound'] = "vyhral-jsem";
			}
			$this->upcard = $upcard;
		}
		$this->hands[$from] = $hand;
		
		$nextPlayer = $this->nextPlayer();
		foreach ($this->players as $player) {
			$data['action'] = ($player == $nextPlayer ? $this->getAction() : '');
			Client::send($player, ($from == $player && !count($this->hands[$player]) ? "You won." : ($player == $nextPlayer ? "You play." : "")), $data);
		}
		if (!count($this->hands[$from])) {
			unset($this->playing[array_search($from, $this->playing)]);
		}
	}
	
	private function getSuit($card) {
		return floor($card / 8);
	}
	
	private function getRank($card) {
		return $card % 8;
	}
	
	function nextPlayer() {
		$playing = next($this->playing);
		return $playing ?: reset($this->playing);
	}
}
