<?php

/*
		
		  _____     _                 _          
		  \_   \___| | __ _ _ __   __| |         
		   / /\/ __| |/ _` | '_ \ / _` |         
		/\/ /_ \__ \ | (_| | | | | (_| |         
		\____/ |___/_|\__,_|_| |_|\__,_|         
		                                         
		   _            _     _ _            _   
		  /_\  _ __ ___| |__ (_) |_ ___  ___| |_ 
		 //_\\| '__/ __| '_ \| | __/ _ \/ __| __|
		/  _  \ | | (__| | | | | ||  __/ (__| |_ 
		\_/ \_/_|  \___|_| |_|_|\__\___|\___|\__|
		                                         
		@ClouriaNetwork | Apache License 2.0
														*/

declare(strict_types=1);
namespace Clouria\IslandArchitect;

use pocketmine\{Player,
    plugin\PluginBase,
    command\Command,
    command\CommandSender,
    command\PluginCommand,
    scheduler\ClosureTask,
    utils\TextFormat as TF,
    level\Position,
    utils\Utils};
use pocketmine\event\{
	Listener,
	player\PlayerQuitEvent,
	block\BlockPlaceEvent,
	block\BlockBreakEvent,
	level\LevelSaveEvent
};

use muqsit\invmenu\InvMenuHandler;

use Clouria\IslandArchitect\{
	runtime\TemplateIsland,
	runtime\sessions\PlayerSession,
	runtime\sessions\InvMenuSession,
	conversion\IslandDataLoadTask,
	events\TemplateIslandCheckOutEvent
};

use function strtolower;
use function implode;
use function class_exists;
use function microtime;
use function basename;
use function round;
use function preg_replace;
use function stripos;

class IslandArchitect extends PluginBase implements Listener {

	public const DEV_ISLAND = false;

	private static $instance = null;

	/**
	 * @var PlayerSession[]
	 */
	private $sessions = [];

	public function onLoad() : void {
		self::$instance = $this;
	}

	public function onEnable() : void {
		if (!$this->initConfig()) {
			$this->getServer()->getPluginManager()->disablePlugin($this);
			return;
		}
		if (class_exists(InvMenuHandler::class)) if (!InvMenuHandler::isRegistered()) InvMenuHandler::register($this);
		$this->getServer()->getPluginManager()->registerEvents($this, $this);

		$cmd = new PluginCommand('island-architect', $this);
		$cmd->setDescription('Command of the IslandArchitect plugin');
		$cmd->setUsage('/island-architect help');
		$cmd->setAliases(['ia', 'isarch']);
		$cmd->setPermission('island-architect.cmd');
		$this->getServer()->getCommandMap()->register($this->getName(), $cmd);

		$this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function(int $ct) : void {
		    foreach ($this->sessions as $s) if ($s->getIsland() !== null) {
		        $r = $s->getIsland()->getRandomByVector3($s->getPlayer()->getTargetBlock(12));
		        if ($r === null) continue;
		        $s->getPlayer()->sendPopup(TF::YELLOW . 'Random generation block: ' . TF::BOLD . TF::GOLD . $s->getIsland()->getRandomLabel($r));
            }
        }), 10);
	}

	private function initConfig() : bool {
		$this->saveDefaultConfig();
		$conf = $this->getConfig();
		foreach ($all = $conf->getAll() as $k => $v) $conf->remove($k);

		$conf->set('enable-plugin', (bool)($all['enable-plugin'] ?? true));
		$conf->set('island-data-folder', (string)($all['island-data-folder'] ?? $this->getDataFolder() . 'islands/'));
		$conf->set('panel-allow-unstable-item', (bool)($all['panel-allow-unstable-item'] ?? true));
		$conf->set('panel-default-seed', ($pds = $all['panel-default-seed'] ?? null) === null ? null : (int)$pds);

		$conf->save();
		$conf->reload();

		return (bool)$conf->get('enable-plugin', true);
	}

	public function getSession(Player $player, bool $nonnull = false) : ?PlayerSession {
		if (self::DEV_ISLAND) $nonnull = true;
		if (($this->sessions[$player->getName()] ?? null) !== null) $s = $this->sessions[$player->getName()];
		elseif ($nonnull) {
			$s = ($this->sessions[$player->getName()] = new PlayerSession($player));
			if (self::DEV_ISLAND) $s->checkOutIsland(new TemplateIsland('test'));
		}
		return $s ?? null;
	}

	public function onCommand(CommandSender $sender, Command $cmd, string $alias, array $args) : bool {
		if (!$sender instanceof Player) $sender->sendMessage(TF::BOLD . TF::RED . 'Please use the command in-game!');
		else switch (strtolower($args[0] ?? 'help')) {
			case 'pos1':
			case 'p1':
			case '1':
				if (!$sender->hasPermission('island-architect.convert')) return false;
				if (PlayerSession::errorCheckOutRequired($sender, $s = $this->getSession($sender))) break;
				if (isset($args[1]) and isset($args[2]) and isset($args[3])) $vec = new Position((int)$args[1], (int)$args[2], (int)$args[3], $sender->getLevel());
				$vec = $vec ?? $sender->asPosition();
				if (($w = $s->getIsland()->getLevel()) !== null) {
					if ($w !== $vec->getLevel()->getFolderName()) {
					$sender->sendMessage(TF::BOLD . TF::RED . 'You can only run this command in the same world as the island: ' . $w);
						break;
					}
				} else $s->getIsland()->setLevel($vec->getLevel());
				$sender->sendMessage(TF::YELLOW . 'Start coordinate set to ' . TF::GREEN . $vec->getFloorX() . ', ' . $vec->getFloorY() . ', ' . $vec->getFloorZ() . '.');
				$s->getIsland()->setStartCoord($vec);
				break;

			case 'pos2':
			case 'p2':
			case '2':
				if (!$sender->hasPermission('island-architect.convert')) return false;
				if (PlayerSession::errorCheckOutRequired($sender, $s = $this->getSession($sender))) break;
				if (isset($args[1]) and isset($args[2]) and isset($args[3])) $vec = new Position((int)$args[1], (int)$args[2], (int)$args[3], $sender->getLevel());
				$vec = $vec ?? $sender->asPosition();
				if (($w = $s->getIsland()->getLevel()) !== null) if ($w !== $vec->getLevel()->getFolderName()) {
					$sender->sendMessage(TF::BOLD . TF::RED . 'You can only run this command in the same world as the island: ' . $w);
					break;
				} else $s->getIsland()->setLevel($vec->getLevel());
				$sender->sendMessage(TF::YELLOW . 'End coordinate set to ' . TF::GREEN . $vec->getFloorX() . ', ' . $vec->getFloorY() . ', ' . $vec->getFloorZ() . '.');
				$s->getIsland()->setEndCoord($vec);
				break;

			case 'island':
			case 'checkout':
			case 'check-out':
			case 'i':
				if (!isset($args[1])) {
					$sender->sendMessage(TF::BOLD . TF::RED . 'Please enter the island data file name!');
					break;
				}
				$time = microtime(true);
				$sender->sendMessage(TF::YELLOW . 'Loading island ' . TF::GOLD . '"' . $args[1] . '"...');
				$callback = function(?TemplateIsland $is, string $filepath) use ($sender, $time) : void {
					if (!$sender->isOnline()) return;
					if (!isset($is)) $is = new TemplateIsland(basename($filepath, '.json'));
					$s = $this->getSession($sender, true);
					$ev = new TemplateIslandCheckOutEvent($s, $is);
					$ev->call();
					if ($ev->isCancelled()) return;
					$s->checkOutIsland($is);
					$sender->sendMessage(TF::BOLD . TF::GREEN . 'Checked out island "' . $is->getName() . '"! ' . TF::ITALIC . TF::GRAY . '(' . round(microtime(true) - $time, 2) . 's)');
				};
				foreach($this->sessions as $s) if (
					($i = $s->getIsland()) !== null and
					$i->getName() === $args[1]
				) {
					$path = Utils::cleanPath($this->getConfig()->get('island-data-folder', $this->getDataFolder() . 'islands/'));
					$callback($i, $path . ($path[-1] === '/' ? '' : '/') . $i->getName());
					break;
				}
				$task = new IslandDataLoadTask($args[1], $callback);
				$this->getServer()->getAsyncPool()->submitTask($task);
				break;

			case 'random':
			case 'regex':
			case 'r':
				if (PlayerSession::errorCheckOutRequired($sender, $s = $this->getSession($sender))) break;
				if (isset($args[1])) {
					if (empty(preg_replace('/[0-9]+/i', '', $args[1]))) $regexid = (int)$args[1];
					else foreach ($s->getIsland()->getRandomLabels() as $rid => $label) if (stripos($label, $args[1]) !== false) {
                        $regexid = $rid;
                        break;
                    }
                    var_dump($regexid ?? null);
					new InvMenuSession($s, $regexid ?? null);
				} else $s->listRandoms();
				break;

			case 'export':
			case 'convert':
			case 'e':
				if (PlayerSession::errorCheckOutRequired($sender, $s = $this->getSession($sender))) break;
				$s->exportIsland();
				break;
		
			default:
				$cmds[] = 'help ' . TF::ITALIC . TF::GRAY . '(Display available subcommands)';
				if ($sender->hasPermission('island-architect.convert')) {
					$cmds[] = 'island <Island data file name: string> ' . TF::ITALIC . TF::GRAY . '(Check out or create an island)';
					$cmds[] = 'pos1 [xyz: int] ' . TF::ITALIC . TF::GRAY . '(Set the start coordinate of the island)';
					$cmds[] = 'pos2 [xyz: int] ' . TF::ITALIC . TF::GRAY . '(Set the end coordinate of the island)';
					$cmds[] = 'export ' . TF::ITALIC . TF::GRAY . '(Export the checked out island into template island data file)';
					$cmds[] = 'random [Random regex ID: int] ' . TF::ITALIC . TF::GRAY . '(Setup random blocks generation)';
				}
				$sender->sendMessage(TF::BOLD . TF::GOLD . 'Available subcommands: ' . ($glue = "\n" . TF::RESET . '- ' . TF::YELLOW) . implode($glue, $cmds ?? ['help']));
				break;
		}
		return true;
	}

	/**
	 * @priority MONITOR
	 */
	public function onPlayerQuit(PlayerQuitEvent $ev) : void {
		if (($s = $this->getSession($ev->getPlayer())) === null) return;
		$s->close();
		unset($this->sessions[$ev->getPlayer()->getName()]);
	}

	/**
	 * @priority HIGH
	 * @ignoreCancelled
	 */
	public function onBlockBreak(BlockBreakEvent $ev) : void {
		$s = $this->getSession($ev->getPlayer());
		if (isset($s)) $s->onBlockBreak($ev->getBlock()->asVector3());
	}

	/**
	 * @priority HIGH
	 * @ignoreCancelled
	 */
	public function onBlockPlace(BlockPlaceEvent $ev) : void {
		if (($s = $this->getSession($ev->getPlayer())) === null) return;
        if (PlayerSession::errorCheckOutRequired($ev->getPlayer(), $this->getSession($ev->getPlayer()))) $ev->setCancelled();
		else $s->onBlockPlace($ev);
	}

	/**
	 * @priority MONITOR
	 */
	public function onLevelSave(LevelSaveEvent $ev) : void {
		foreach ($this->sessions as $s) $s->saveIsland();
	}

	public static function getInstance() : ?self {
		return self::$instance;
	}
	
}
