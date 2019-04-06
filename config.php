<?php

use \Workerman\Worker;
use \Workerman\Connection\AsyncTcpConnection;

require_once __DIR__.'/Workerman/Autoloader.php';

function conf($item = '',$def = FALSE)
{
	static $conf;

	if(!isset($conf))
	{
		$path = __DIR__.'/config.json';

		if(file_exists($path))
		{
			$conf = json_decode(file_get_contents($path),TRUE);
		}
		else
		{
			die('configure file does not exist.');
		}
	}

	if(!empty($item))
	{
		if(strpos($item,'.') === FALSE)
		{
			return isset($conf[$item]) ? $conf[$item] : $def;
		}
		else
		{
			$cop = &$conf;

			foreach(explode('.',$item) as $ckey)
			{
				if(isset($cop[$ckey]))
				{
					$cop = &$cop[$ckey];
				}
				else
				{
					return $def;
				}
			}

			return $cop;
		}
	}
	else
	{
		return $conf;
	}
}

function init_channel(&$channel)
{
	if(conf('worker_list'))
	{
		foreach(conf('worker_list') as $li)
		{
			$channel[$li['client_name'].':'.$li['client_addr'].':'.$li['client_port'].':'.$li['server_port']] = array(
				'server_port' => $li['server_port'],
				'client_port' => $li['client_port'],
				'client_addr' => $li['client_addr'],
				'client_name' => $li['client_name'],
			);
		}
	}
	else
	{
		$channel[conf('client_name').':'.conf('client_addr').':'.conf('client_port').':'.conf('server_port')] = array(
			'server_port' => conf('server_port'),
			'client_port' => conf('client_port'),
			'client_addr' => conf('client_addr'),
			'client_name' => conf('client_name'),
		);
	}

	return $channel;
}

function _debug_echo($msg,$exit = FALSE)
{
	if(conf('_debug_mode'))
	{
		if(is_string($msg) || is_numeric($msg))
		{
			echo $msg."\r\n";
		}
		else
		{
			var_dump($msg);
		}

		if($exit) exit;
	}
}

function build_client_worker()
{
	$client_worker = new Worker();

	$client_worker->name = 'soxport';

	$client_worker->channel = array();
	$client_worker->service = array();

	init_channel($client_worker->channel);

	$client_worker->onWorkerStart = function() use ($client_worker){
		Channel\Client::connect(conf('server_addr'),conf('server_pass'));

		foreach($client_worker->channel as $k => $v)
		{
			Channel\Client::on('cs_connect_'.$k, function($event_data) use($client_worker,$k,$v){
				// _debug_echo('client['.$v['client_name'].']: connection from out network.');

				$client_worker->service[$k.':'.$event_data['conn']['imei']] = new AsyncTcpConnection('tcp://'.$v['client_addr'].':'.$v['client_port']);

				// $client_worker->service[$k.':'.$event_data['conn']['imei']]->onConnect = function($conn) use($event_data,$k,$v){
				// 	_debug_echo('client['.$v['client_name'].']['.$event_data['conn']['imei'].']: connection['.$v['client_addr'].':'.$v['client_port'].'] works.');

				// 	$connect_data['conn'] = array(
				// 		'addr' => $conn->getRemoteIp(),
				// 		'port' => $conn->getRemotePort(),
				// 		'imei' => $event_data['conn']['imei'],
				// 	);

				// 	Channel\Client::publish('sc_connect_'.$k,$connect_data);
				// };

				$client_worker->service[$k.':'.$event_data['conn']['imei']]->onMessage = function($conn,$data) use($event_data,$k){
					$message_data = array();

					$message_data['data'] = $data;

					$message_data['conn'] = array(
						'addr' => $conn->getRemoteIp(),
						'port' => $conn->getRemotePort(),
						'imei' => $event_data['conn']['imei'],
					);

					Channel\Client::publish('sc_message_'.$k,$message_data);
				};

				$client_worker->service[$k.':'.$event_data['conn']['imei']]->onClose = function($conn) use($event_data,$k){
					$close_data = array();

					$close_data['conn'] = [
						'addr' => $conn->getRemoteIp(),
						'port' => $conn->getRemotePort(),
						'imei' => $event_data['conn']['imei'],
					];
					
					Channel\Client::publish('sc_close_'.$k,$close_data);
				};
				
				$client_worker->service[$k.':'.$event_data['conn']['imei']]->connect();

				// _debug_echo('client['.$v['client_name'].']['.$event_data['conn']['imei'].']: connection['.$v['client_addr'].':'.$v['client_port'].'] starts.');
			});

			Channel\Client::on('cs_message_'.$k,function($event_data) use($client_worker,$k){
				$client_worker->service[$k.':'.$event_data['conn']['imei']]->send($event_data['data']);
			});

			Channel\Client::on('cs_close_'.$k,function($event_data) use($client_worker,$k){
				if(isset($client_worker->service[$k.':'.$event_data['conn']['imei']]))
				{
					$client_worker->service[$k.':'.$event_data['conn']['imei']]->close();

					unset($client_worker->service[$k.':'.$event_data['conn']['imei']]);
				}
			});
		}
	};
}

function build_server_worker()
{
	$tunnel_server = new Channel\Server('0.0.0.0',conf('server_pass'));

	$server_worker = array();

	init_channel($server_worker);

	foreach($server_worker as $k => $v)
	{
		$server_worker[$k]['worker_sock'] = new Worker('tcp://0.0.0.0:'.$v['server_port']);

		$server_worker[$k]['worker_sock']->name = $v['client_name'];

		$server_worker[$k]['worker_sock']->onWorkerStart = function() use($server_worker,$k,$v){
			Channel\Client::connect('127.0.0.1',conf('server_pass'));

			Channel\Client::on('sc_message_'.$k,function($event_data) use ($server_worker,$k){
				if(isset($server_worker[$k]['worker_sock']->connections[$event_data['conn']['imei']])){
					$server_worker[$k]['worker_sock']->connections[$event_data['conn']['imei']]->send($event_data['data']);
				}
			});

			Channel\Client::on('sc_close_'.$k,function($event_data) use ($server_worker,$k){
				if(isset($server_worker[$k]['worker_sock']->connections[$event_data['conn']['imei']])){
					$server_worker[$k]['worker_sock']->connections[$event_data['conn']['imei']]->close();
				}
			});

			// Channel\Client::on('sc_connect_'.$k,function($event_data) use($server_worker,$k,$v){
			// 	_debug_echo('client['.$v['client_name'].']['.$event_data['conn']['imei'].']: connection['.$v['client_addr'].':'.$v['client_port'].'] works.');
			// });
		};

		$server_worker[$k]['worker_sock']->onConnect = function($connection) use($server_worker,$k){
			$connection_data['conn'] = array(
				'addr' => $connection->getRemoteIp(),
				'port' => $connection->getRemotePort(),
				'imei' => $connection->id,
			);
		
			Channel\Client::publish('cs_connect_'.$k, $connection_data);
			
			$connection->onMessage = function($connection, $data) use($k){
				$message_data['conn'] = array(
					'addr' => $connection->getRemoteIp(),
					'port' => $connection->getRemotePort(),
					'imei' => $connection->id,
				);

				$message_data['data'] = $data;
		
				Channel\Client::publish('cs_message_'.$k,$message_data);
				
			};
			
			$connection->onClose = function ($connection) use($k){
				$close_data['conn'] = array(
					'addr' => $connection->getRemoteIp(),
					'port' => $connection->getRemotePort(),
					'imei' => $connection->id,
				);
			
				Channel\Client::publish('cs_close_'.$k, $close_data);
			};
		};
	}
}

?>