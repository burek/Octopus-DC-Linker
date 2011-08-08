<?php

//------------------------------------------------------------------------------

define('IP_ADDRESS', '0.0.0.0');
define('LISTEN_PORT', '5555');
define('LISTEN_QUEUE', 10);
define('MAX_RCV_BUF_ITEM_SIZE', 4095);

//------------------------------------------------------------------------------
function log_msg($msg) {
//------------------------------------------------------------------------------
	echo sprintf("[%s] %s\n", date('Y-M-d H:i:s'), $msg);
}

//------------------------------------------------------------------------------
function log_err($msg) {
//------------------------------------------------------------------------------
	log_msg("ERROR: $msg");
}

//------------------------------------------------------------------------------

echo "\n";
$server = socket_create(AF_INET, SOCK_STREAM, SOL_TCP) or die('socket_create: ' . socket_strerror(socket_last_error()));
socket_set_option($server, SOL_SOCKET, SO_REUSEADDR, 1) or die('socket_set_option: ' . socket_strerror(socket_last_error()));
socket_bind($server, IP_ADDRESS, LISTEN_PORT) or die('socket_bind: ' . socket_strerror(socket_last_error()));
socket_listen($server, LISTEN_QUEUE) or die('socket_listen: ' . socket_strerror(socket_last_error()));
socket_set_nonblock($server) or die('socket_set_nonblock: ' . socket_strerror(socket_last_error()));
log_msg(sprintf('Server created on address:port "%s:%s". Listening for incoming connections...', IP_ADDRESS, LISTEN_PORT));

//------------------------------------------------------------------------------
function onShutdown()
//------------------------------------------------------------------------------
{
	global $server, $clients;

	log_msg('Terminate signal received, shutting down everything...');

	log_msg('Closing server...');
	socket_shutdown($server, 2) or log_err('socket_shutdown(server): ' . socket_strerror(socket_last_error()));
	socket_close($server);

	log_msg('Closing peers...');
	foreach ($clients as $k => $v) {
		sockClose($k);
	}

	log_msg("Done.");
	exit();
}
//------------------------------------------------------------------------------

declare(ticks = 1);
pcntl_signal(SIGTERM, 'onShutdown');
pcntl_signal(SIGINT, 'onShutdown');

//------------------------------------------------------------------------------
// HERE STARTS THE ACTUAL CODE
//------------------------------------------------------------------------------

require('utils.php');
require('nmdc.php');

$clients = array(); // $clients[$socket] = client object
$rcvbufs = array(); // $rcvbufs[$socket] = array of strings
$sndbufs = array(); // $sndbufs[$socket] = array of strings

//------------------------------------------------------------------------------
function sockClose($s) {
//------------------------------------------------------------------------------
	global $clients, $rcvbufs, $sndbufs;
	if (isset($clients[$s])) unset($clients[$s]);
	if (isset($rcvbufs[$s])) unset($rcvbufs[$s]);
	if (isset($sndbufs[$s])) unset($sndbufs[$s]);
	// socket_shutdown($p, 2) or log_err('socket_shutdown(peer): ' . socket_strerror(socket_last_error()));
	socket_close($s);
}

//------------------------------------------------------------------------------
function sockRead($s, $buf) {
//------------------------------------------------------------------------------
	global $rcvbufs;

	// make sure the array exists
	if (!is_array($rcvbufs[$s]))
		$rcvbufs[$s] = array();

	// if $buf is too small, add it to previous buf in array, to save memory
	if (count($rcvbufs[$s]) == 0)
		$rcvbufs[$s][] = $buf;
	else {
		end($rcvbufs[$s]);
		$last_key = key($rcvbufs[$s]);
		if ((strlen($rcvbufs[$s][$last_key]) + strlen($buf)) > MAX_RCV_BUF_ITEM_SIZE) // too  big
			$rcvbufs[$s][] = $buf; // create new
		else
			$rcvbufs[$s][$last_key] .= $buf; // concat with last
	}

	if (strpos($buf, PROTO_DELIMITER) !== false) {
		handleProtoMsg($s, $rcvbufs[$s]);
	}
}

//------------------------------------------------------------------------------
// main loop
//------------------------------------------------------------------------------

do {
	$rlist = array_keys($clients);
	$rlist[] = $server; // add the listening server too
	$wlist = array_keys($sndbufs);

	$res = @socket_select($rlist, $wlist, $e=NULL, 1);
	if ($res === false) {
		log_err('socket_select(): ' . socket_strerror(socket_last_error()));
		sleep(1); // just in case we don't hog the cpu if this error persists
		continue;
	}

	//------------------------------------------------------------------------------
	foreach ($rlist as $rsock) {
	//------------------------------------------------------------------------------

		/* ACCEPT */
		if ($rsock === $server) {
			$newsock = socket_accept($server);
			if ($newsock === false) {
				log_err('socket_accept(): ' . socket_strerror(socket_last_error()));
				continue;
			}
			$clients[$newsock] = 1;
			socket_getpeername($newsock, $addr, $port) or log_err('socket_getpeername(): ' . socket_strerror(socket_last_error()));
			log_msg("INFO: New connection accepted from $addr:$port");

		/* READ */
		} else { // read
			$buf = socket_read($rsock, 4096, PHP_BINARY_READ);
			if ($buf === false) { // error
				log_err('socket_read(): ' . socket_strerror(socket_last_error()));
				sockClose($rsock);
			} elseif ($buf == '') { // closed connection
 				socket_getpeername($rsock, $addr, $port) or log_err('socket_getpeername(): ' . socket_strerror(socket_last_error()));
				log_msg("INFO: Connection closed from $addr:$port");
				sockClose($rsock);
			} else { // data received
				sockRead($rsock, $buf);
			}
		}
	}
	//------------------------------------------------------------------------------
	foreach ($wlist as $wsock) {
	//------------------------------------------------------------------------------
		
	}
} while (true);

?>

