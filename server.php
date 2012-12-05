#!/php -q
<?php

// Set date to avoid errors
date_default_timezone_set("America/New_York");

function arrowsToDirection($str){
    $directions = array();
    for($i = 0; $i < strlen($str); $i++){
        $directions[] = $str[$i] == '<' ? 'left' : ($str[$i] == '>' ? 'right' : 'self');
    }
    return $directions;
}

function gentoken() {
    $chars = "abcdefghijklmnopqrstuvwxyz1234567890";
    $string = "";
    $nchars = strlen($chars);
    for ($i = 0; $i < 30; $i++)
        $string .= $chars[mt_rand(0, $nchars - 1)];
    return $string;
}

// Run from command prompt > php demo.php
require_once("includes/websocket.server.php");
require_once("wonders.php");
require_once("player.php");

// Main server class
class WonderServer implements IWebSocketServerObserver{
    protected $debug = true;
    protected $server;
    protected $users = array(); // all users ever (keyed by $user->getId)
    protected $conns = array(); // all active connections (keyed by $conn->id)
    protected $games = array();

    public function __construct(){
        $this->server = new WebSocketServer('tcp://0.0.0.0:12345', 'superdupersecretkey');
        $this->server->addObserver($this);
    }

    public function onConnect(IWebSocketConnection $user){
    }

    public function broadcastAll($msg, $exclude=false){
        foreach($this->users as $u)
            if(!$exclude || $u != $exclude) $u->sendString($msg);
    }

    public function broadcastTo($msg, $players, $exclude=false){
        foreach($players as $player)
            if(!$exclude || $player != $exclude) $player->sendString($msg);
    }

    public function onMessage(IWebSocketConnection $conn, IWebSocketMessage $msg){
        $arr = json_decode($msg->getData(), true);
        // If this is a new websocket connection, handle the user up front
        if ($arr['messageType'] == 'myid') {
            if (isset($this->users[$arr['id']])) {
                $user = $this->users[$arr['id']];
            } else {
                $user = new Player(gentoken(), $conn->getId());
            }
            $this->users[$user->getId()] = $user;
            $this->conns[$conn->getId()] = $user;
            $user->setConnection($conn);
            $user->send('myname',
                        array('name' => $user->getName(),
                              'id'   => $user->getId()));
            foreach($this->games as $game) {
                if ($game->started)
                    continue;
                $user->send('newgame',
                            array('name' => $game->name,
                                  'creator' => $game->creator->name,
                                  'id' => $game->id));
            }

            $this->say("{$user->getId()} connected");
            return;
        }

        // Otherwise we better have a user set for them, and then continue on
        // as normally when processing the message
        if (!isset($this->conns[$conn->getId()]))
            return;
        $user = $this->conns[$conn->getId()];

        switch($arr['messageType']){
            case 'newgame':
                $game = new SevenWonders();
                $game->name = $arr['name'];
                $game->maxplayers = intval($arr['players']);
                $game->id = count($this->games);
                $game->server = $this;
                $game->addPlayer($user);

                // ERRORS NOT SHOWING ON CLIENT: FIX FIX FIX
                if($game->maxplayers > 7 or $game->maxplayers < 1)
                    return $user->sendString(packet('Cannot create game: number of players invalid', 'error'));
                elseif($game->name == '')
                    return $user->sendString(packet('Game needs a valid name', 'error'));

                $this->games[] = $game;
                $packet = packet(array('name' => $game->name, 'creator' => $game->creator->name, 'id' => $game->id), "newgame");
                $this->broadcastAll($packet, $user);
                break;

            case 'joingame':
                if(!isset($user->game)){
                    $id = intval($arr['id']);
                    if(isset($this->games[$id]) && !$this->games[$id]->started){
                        $this->games[$id]->addPlayer($user);
                    } else {
                        // error game not exist/game already started
                    }
                } else {
                    // error already in game
                }
                break;

            case 'changename':
                if ($user->game == null && $arr['name'] != '') {
                    $user->setName($arr['name']);
                }
                // Broadcast name change here in case they're hosting a game?
                break;

            default:
                // if(isset($user->game)) $user->game->onMessage($user, $arr);
                // else $user->sendString("Error: could not recognize command " . $arr['messageType']);
                print_r($arr);
                break;
        }
    }

    public function onDisconnect(IWebSocketConnection $conn){
        if (!isset($this->conns[$conn->getId()]))
            return;
        $user = $this->conns[$conn->getId()];
        $this->say("{$user->getId()} disconnected");
        foreach($this->games as $game) {
            if(in_array($user, $game->players))
                $game->removePlayer($user);
        }
        unset($this->conns[$conn->getId()]);
    }

    public function onAdminMessage(IWebSocketConnection $conn,
                                   IWebSocketMessage $msg) {
        $this->say("Admin Message received!");

        $frame = WebSocketFrame::create(WebSocketOpcode::PongFrame);
        $conn->sendFrame($frame);
    }

    public function say($msg){
        echo "Log: $msg \r\n";
    }

    public function run(){
        $this->server->run();
    }
}

// Start server
$server = new WonderServer();
$server->run();
?>
