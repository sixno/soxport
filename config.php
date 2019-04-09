<?php

use \Workerman\Worker;
use \Workerman\Connection\AsyncTcpConnection;
use \Workerman\Lib\Timer;

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

				$client_worker->service[$k.':'.$event_data['conn']] = new AsyncTcpConnection('tcp://'.$v['client_addr'].':'.$v['client_port']);

				$client_worker->service[$k.':'.$event_data['conn']]->onConnect = function($conn) use($event_data,$k,$v){
					// _debug_echo('client['.$v['client_name'].']['.$event_data['conn'].']: connection['.$v['client_addr'].':'.$v['client_port'].'] works.');

					$connect_data = ['conn' => $event_data['conn']];

					Channel\Client::publish('sc_connect_'.$k,$connect_data);
				};

				$client_worker->service[$k.':'.$event_data['conn']]->onMessage = function($conn,$data) use($event_data,$k){
					$message_data = ['conn' => $event_data['conn'],'data' => $data];

					Channel\Client::publish('sc_message_'.$k,$message_data);
				};

				$client_worker->service[$k.':'.$event_data['conn']]->onClose = function($conn) use($event_data,$k){
					$close_data = ['conn' => $event_data['conn']];
					
					Channel\Client::publish('sc_close_'.$k,$close_data);
				};
				
				$client_worker->service[$k.':'.$event_data['conn']]->connect();

				// _debug_echo('client['.$v['client_name'].']['.$event_data['conn'].']: connection['.$v['client_addr'].':'.$v['client_port'].'] starts.');
			});

			Channel\Client::on('cs_message_'.$k,function($event_data) use($client_worker,$k){
				$client_worker->service[$k.':'.$event_data['conn']]->send($event_data['data']);
			});

			Channel\Client::on('cs_close_'.$k,function($event_data) use($client_worker,$k){
				if(isset($client_worker->service[$k.':'.$event_data['conn']]))
				{
					$client_worker->service[$k.':'.$event_data['conn']]->close();

					unset($client_worker->service[$k.':'.$event_data['conn']]);
				}
			});
		}
	};
}

function build_server_worker()
{
	$tunnel_server = new Channel\Server('0.0.0.0',conf('server_pass'));

	$server_worker = array();
	$_timer_worker = array();

	init_channel($server_worker);

	foreach($server_worker as $k => $v)
	{
		$server_worker[$k]['worker_sock'] = new Worker('tcp://0.0.0.0:'.$v['server_port']);

		$server_worker[$k]['worker_sock']->name = $v['client_name'];

		$server_worker[$k]['worker_sock']->onWorkerStart = function() use($server_worker,$k,$v,&$_timer_worker){
			Channel\Client::connect('127.0.0.1',conf('server_pass'));

			Channel\Client::on('sc_connect_'.$k,function($event_data) use($server_worker,$k,$v,&$_timer_worker){
				// _debug_echo('client['.$v['client_name'].']['.$event_data['conn'].']: connection['.$v['client_addr'].':'.$v['client_port'].'] works.');

				if(isset($_timer_worker[$k.':'.$event_data['conn']]))
				{
					Timer::del($_timer_worker[$k.':'.$event_data['conn']]);

					unset($_timer_worker[$k.':'.$event_data['conn']]);
				}
			});

			Channel\Client::on('sc_message_'.$k,function($event_data) use ($server_worker,$k){
				if(isset($server_worker[$k]['worker_sock']->connections[$event_data['conn']])){
					$server_worker[$k]['worker_sock']->connections[$event_data['conn']]->send($event_data['data']);
				}
			});

			Channel\Client::on('sc_close_'.$k,function($event_data) use ($server_worker,$k){
				if(isset($server_worker[$k]['worker_sock']->connections[$event_data['conn']])){
					$server_worker[$k]['worker_sock']->connections[$event_data['conn']]->close();
				}
			});
		};

		$server_worker[$k]['worker_sock']->onConnect = function($connection) use($server_worker,$k,&$_timer_worker){
			$connection_data = ['conn' => $connection->id];
		
			Channel\Client::publish('cs_connect_'.$k,$connection_data);

			$_timer_worker[$k.':'.$connection->id] = Timer::add(30,function() use($connection){
				$connection->close();
			},[],FALSE);
			
			$connection->onMessage = function($connection,$data) use($k){
				$message_data = ['conn' => $connection->id,'data' => $data];
		
				Channel\Client::publish('cs_message_'.$k,$message_data);
			};
			
			$connection->onClose = function($connection) use($k,&$_timer_worker){
				$close_data = ['conn' => $connection->id];

				if(isset($_timer_worker[$k.':'.$connection->id]))
				{
					Timer::del($_timer_worker[$k.':'.$connection->id]);

					unset($_timer_worker[$k.':'.$connection->id]);
				}
			
				Channel\Client::publish('cs_close_'.$k,$close_data);
			};
		};
	}
}

?>