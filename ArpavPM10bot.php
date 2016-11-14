<?php
class ArpavPM10bot {
	
	//Telegram
	protected $host = 'api.telegram.org';
	//Port used on connection.
	protected $port = 443;
	//Url needed to access telegram (composed of protocol, port, host and token). It's automatically generated on the class construction.
	protected $apiUrl;
	//Id of the bot on telegram. [Initialized automatically]
	private $botId;
	//Username of the bot. [Initialized automatically]
	private $botUsername;
	//Bot token received from telegram on bot creation.
	protected $botToken = 'replaceWithToken';
	//cURL handle.
	protected $handle;
	//Delay, in secnds, on connection failures. 
	protected $netDelay = 5;
	//Used to mark updates as received.
	protected $updatesOffset = false;
	//Limit number of updates for one update request.
	protected $updatesLimit = 30;
	//Timeout in (seconds) for long polling.
	protected $updatesTimeout = 10;
	//The maximum number of (seconds) to allow cURL functions to execute.
	protected $netTimeout = 10;
	//The number of (seconds) to wait while trying to connect. 
	protected $netConnectTimeout = 30;
	//(seconds)Time interval at which check the PM10 concentrations of the subscribed stations. (And if concentration exceed limits, sends messages to subscribed users).
	protected $spamUpdateInterval = 300;
	
	//Business configuration
	//Types of the stations to be excluded from the management.
	protected $zoneTypesToFilter = array("TU","IU","IS");
	//The concentrations that trigger notifications.
	protected $PM10criticalConcentration1 = 50;
	protected $PM10criticalConcentration2 = 100;
	
	
	//CORE - BEGIN//
	
	
	/**
	 * Constructor.
	 */
	public function __construct() {
		$host = $this->host;
		$port = $this->port;
		$token = $this->botToken;
		$protocol_part = ($port == 443 ? 'https' : 'http');
		$port_part = ($port == 443 || $port == 80) ? '' : ':'.$port;
		$this->apiUrl = "{$protocol_part}://{$host}{$port_part}/bot{$token}";
	}
	
	
	/**
	 * Starts the bot.
	 */
	public function start(){
		$this->initialize();
		$this->run();
	}
	
	
	/**
	 * Initializes $botId and $botUsername variables, retrives them from Telegram.
	 */
	private function initialize() {
		$response = array();
		$firstTry = TRUE;
		do{
			if($firstTry){
				$firstTry = FALSE;
			}else{
				sleep(1);
			}
			$this->handle = curl_init();
			$response = $this->request('getMe');
			$this->log('Connecting to Telegram.');//LOG
			
		}while(!$response['ok']);
		$this->log('Connected.');//LOG
		$botInfo = $response['result'];
		$this->botId = $botInfo['id'];
		$this->botUsername = $botInfo['username'];
		$this->log('Bot initialized.');//LOG
	}
	
	
	/**
	 * Start long poll requests to Telegram, invoke {@link #receiveUpdate($update)} for each update.
	 * Check every ($spamUpdateInterval) seconds if the concentrations of PM10 of the stations signed by someone have exceeded limits.
	 * And for every station sends a message to all of the users subscribed to it.
	 */
	private function run(){
		$params = array(
			'limit' => $this->updatesLimit,
			'timeout' => $this->updatesTimeout,
		);
		$options = array(
			'timeout' => $this->netConnectTimeout + $this->updatesTimeout + 2,
		);
		$time = time();
		while(TRUE){
			if ($this->updatesOffset) {
				$params['offset'] = $this->updatesOffset;
			}
			$response = $this->request('getUpdates', $params, $options);
			if ($response['ok']) {
				$updates = $response['result'];
				if (is_array($updates)) {
					foreach ($updates as $update) {
						$this->updatesOffset = $update['update_id'] + 1;
						$this->receiveUpdate($update);
					}
				}
			}
			if ((time() - $time) >= $this->spamUpdateInterval) {
			  $this->sendInfoToFollowers();
				$time = time();
			}
		}//while
	}//run
	
	
	/**
	 * Automatically invoked for each update from telegram. It analyzes command received from user.
	 * If there is a command match, the corresponding method for the command {command_[command name]}is invoked.
	 * If no command match is found, the response to Telegram is sent from this method.
	 * @param $update Associative array with information from telegram.
	 */
	private function receiveUpdate($update){
		if ($update['message']) {
			$message = $update['message'];
			$chat_id = intval($message['chat']['id']);
			if($chat_id) {
				if(isset($message['text'])){
					$text = trim($message['text']);
					$this->log('Function: receiveUpdate; $message[\'text\']: '.$message['text'].PHP_EOL.'$chatId: '.$chat_id);//LOG
					$username = strtolower('@'.$this->botUsername);
					$username_len = strlen($username);
					if(strtolower(substr($text, 0, $username_len)) == $username) {
						$text = trim(substr($text, $username_len));
					}
					if(preg_match('/^(?:\/(?:([a-z0-9_]+)(?=@)|([a-z0-9_]+?))(@[a-z0-9]+)?(?:[\s_]+(.*))?)$/is', $text, $matches)) {
						$command = empty($matches[1])?$matches[2]:$matches[1];
						$command_owner = !empty($matches[3])?strtolower($matches[3]):'';
						$command_params = !empty($matches[4])?$matches[4]:'';
						if (empty($command_owner) || $command_owner == $username) {
							//Command to this bot.
							$method = 'command_'.$command;
							if (method_exists($this, $method)){
								//Requested existent command.
								$command_params=str_replace('_', ' ', $command_params);
								$arrayOfParams = array_filter(explode(' ', $command_params), 'strlen' );
								$this->$method($arrayOfParams, $message);
							}else{
								//Requested inexistent command.
								$this->sendTextMessage($this->info_inexistentCommand(), $chat_id);
							}
						}else{
							//Command not to this bot.
						}
					}else{
						//Generic phrase.
						$this->sendTextMessage($this->getAvailableCommandsPhrase(), $chat_id); 
					}
				}elseif(isset($message['location'])){
					$this->saveLocation($message);
				}
			}
		}
	}
	
	
	/**
	 * Used to invoke methodhs on telegram.
	 * @param method name of the method to invoke.
	 * @param params parameters to pass to the method of telegram.
	 * @param options options for curl request.
	 * @return associative array containing response or null. 
	 */
	private function request($method, $params = array(), $options = array()) {
		$options += array(
			'http_method' => 'GET',
			'timeout' => $this->netTimeout,
		);
		$params_arr = array();
		foreach ($params as $key => &$val) {
			if (!is_numeric($val) && !is_string($val)) {
				$val = json_encode($val);
			}
			$params_arr[] = urlencode($key).'='.urlencode($val);
		}
		$query_string = implode('&', $params_arr);
		$url = $this->apiUrl.'/'.$method;
		if ($options['http_method'] === 'POST') {
			curl_setopt($this->handle, CURLOPT_SAFE_UPLOAD, false);
			curl_setopt($this->handle, CURLOPT_POST, true);
			curl_setopt($this->handle, CURLOPT_POSTFIELDS, $query_string);
		} else {
			$url .= ($query_string ? '?'.$query_string : '');
			curl_setopt($this->handle, CURLOPT_HTTPGET, true);
		}
		$connect_timeout = $this->netConnectTimeout;
		$timeout = $options['timeout'] ?: $this->netTimeout;
		curl_setopt($this->handle, CURLOPT_URL, $url);
		curl_setopt($this->handle, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($this->handle, CURLOPT_CONNECTTIMEOUT, $connect_timeout);
		curl_setopt($this->handle, CURLOPT_TIMEOUT, $timeout);
		$response_str = curl_exec($this->handle);
		$errno = curl_errno($this->handle);
		$http_code = intval(curl_getinfo($this->handle, CURLINFO_HTTP_CODE));
		if ($http_code == 401) {
			throw new Exception('Invalid bot token.');
		} else {
			if ($http_code >= 500 || $errno) {
				sleep($this->netDelay);
			}
		}
		$response = json_decode($response_str, true);
		return $response;
	}
	
	
	/**
	 * Sends a text message to telegram.
	 * @param $text Text to send (with optional formatting in HTML style).
	 * @param $chatId Identifier (received from Telegram) of the message destination chat.
	 * @param $params Parameters for the method (higher priority than other parameters).
	 */
	private function sendTextMessage($text, $chatId, $params = array()) {
		$maxTextLength = 3600;
		$textLength = strlen($text);
		if($textLength>$maxTextLength){
			while(strlen($text) != 0){
				$cut = strrpos(substr($text, 0, $maxTextLength), PHP_EOL) + 1;
				$textPart = substr($text, 0, $cut);
				$text = substr($text, $cut);
				$paramsSend =$params + array(
					'chat_id' => $chatId,
					'parse_mode' => 'HTML',
					'text' => $textPart,
				);
				$this->request('sendMessage', $paramsSend);
			}
		}else{
			$params += array(
				'chat_id' => $chatId,
				'parse_mode' => 'HTML',
				'text' => $text,
			);
			$this->request('sendMessage', $params);
		}
	}
	
	/**
	 * Sends the chart to Telegram.
	 * @param $chatId Identifier of the chat.
	 * @param $caption Caption for the chart.
	 */
	private function sendChart($chatId, $caption){
		$url = $this->apiUrl.'/sendPhoto?chat_id='.$chatId;
		$post_fields = array('chat_id'   => $chatId,
			'photo'=> new CURLFile(realpath(dirname(__FILE__).'/chart.png')),
			'caption' => $caption
		);
		$ch = curl_init(); 
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			"Content-Type:multipart/form-data"
		));
		curl_setopt($ch, CURLOPT_URL, $url); 
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 
		curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields); 
		curl_exec($ch);
	}
	
	
	/**
	 * Sends a location to telegram.
	 * @param $latitude Latitude of the location.
	 * @param $longitude Longitude of the location.
	 * @param $chatId Identifier (received from Telegram) of the message destination chat.
	 * @param $params Parameters for the method (higher priority than other parameters).
	 */
	private function sendLocation($latitude, $longitude, $chatId, $params = array()) {
		$params += array(
			'chat_id' => $chatId,
			'latitude' => $latitude,
			'longitude' => $longitude,
		);
		$this->request('sendLocation', $params);
	}
	
	
	//CORE - END//
	
	
	//COMMANDS - BEGIN//
	
	
	/**
	 * Command invoked from receiveUpdate method to send to Telegram user - list of hydro stations in given range.
	 * @param $arrayOfParams Parameters for the command (received from Telegram user).
	 * @param $message Associative array with informations about the received message.
	 */
	private function command_stazionikm($arrayOfParams, $message){
		$chat_id = intval($message['chat']['id']);
		$telegramUserId = intval($message['from']['id']);
		$location = $this->getLocation($telegramUserId);
		if(count($arrayOfParams)==1 || count($arrayOfParams)==2){
			if(!empty($location)){
				$rangeKm = $this->getValidKm($arrayOfParams[0]);
				if($rangeKm !== FALSE){
					if($this->isInRangeKm($rangeKm)){
						$rangeMeters = $rangeKm*1000;
						$stations_info = $this->getStationsInfo();
						if(!empty($stations_info)){
							$coordinates = $this->getStationsCoordinates();
							if(!empty($coordinates)){
								$stations_data = $this->getStationsData();
								if(!empty($stations_data)){
									$PM10stations_data = $this->getPM10stations_data($stations_data);
									if(!empty($PM10stations_data)){
										$this->filterByZoneType($stations_info, $this->zoneTypesToFilter);
										$this->filterNotPM10($stations_info, $PM10stations_data);
										if(!empty($stations_info)){
											$this->enrichWithDistance($stations_info, $coordinates, $location);
											$this->orderByDistance($stations_info);
											$this->filterStationsNotInRange($stations_info, $rangeMeters);
											if(!empty($stations_info)){
												$registeredStationsIds = $this->getRegistrations($telegramUserId);
												if(!empty($registeredStationsIds)){
													$this->enrichWithRegistrationInfo($stations_info, $registeredStationsIds);
												}
												$toSend = $this->getMessageStationsInRangeList($rangeMeters).PHP_EOL;
												$toSend.= $this->show_stations($stations_info);
												$this->sendTextMessage($toSend, $chat_id);
											}else{
												$toSend = $this->info_noStationsInRange($rangeMeters);
												$this->sendTextMessage($toSend, $chat_id);
											}
										}else{
											$this->sendTextMessage($this->info_genericError(), $chat_id);
											$this->logFunctionLine('No station found, all stations have been filtered.',__LINE__,__FUNCTION__);
										}
									}else{
										$this->sendTextMessage($this->info_genericError(), $chat_id);
										$this->logFunctionLine('No PM10 station found.',__LINE__,__FUNCTION__);
									}
								}else{
									$this->sendTextMessage($this->info_genericError(), $chat_id);
									$this->logFunctionLine('No station found, the file with the stations data is probably empty.',__LINE__,__FUNCTION__);
								}
							}else{
								$this->sendTextMessage($this->info_genericError(), $chat_id);
								$this->logFunctionLine('No coordinates found, the file with the stations coordinates is probably empty.',__LINE__,__FUNCTION__);
							}
						}else{
							$this->sendTextMessage($this->info_genericError(), $chat_id);
							$this->logFunctionLine('No station found, the file with the stations info is probably empty.',__LINE__,__FUNCTION__);
						}
					}else{
						$this->sendTextMessage($this->info_numberOutOfRange(), $chat_id);
					}
				}else{
					$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
					$toSend=$this->info_wrongCommandSyntax();
					$toSend.=$this->getSyntaxForCommand($commandName);
					$toSend.=$this->getHelpMessageFor($commandName);
					$this->sendTextMessage($toSend, $chat_id);
				}
			}else{
				$this->sendTextMessage($this->info_locationRequired(), $chat_id);
			}
		}else{
			$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
			if(empty($arrayOfParams)){
				$this->sendTextMessage($this->getDescriptionForCommand($commandName), $chat_id);
			}else{
				$toSend=$this->info_wrongCommandSyntax();
				$toSend.=$this->getSyntaxForCommand($commandName);
				$toSend.=$this->getHelpMessageFor($commandName);
				$this->sendTextMessage($toSend, $chat_id);	
			}
		}
	}
	
	
	/**
	 * Command invoked from receiveUpdate method to send to Telegram user - information about his location.
	 * @param $arrayOfParams Parameters for the command (received from Telegram user).
	 * @param $message Associative array with informations about the received message.
	 */
	private function command_posizione($arrayOfParams, $message){
		$chat_id = intval($message['chat']['id']);
		if(empty($arrayOfParams)){
			include('connect_DB.php');
			if(!$mysqli->connect_error){
				$telegramUserId = intval($message['from']['id']);
				if ($result = $mysqli->query("CALL BotPM10_p_getLocation(\"$telegramUserId\")")) {
					$location = array();
					if($row = $result->fetch_assoc()){
						$location['latitude']= $row['latitude'];
						$location['longitude']= $row['longitude'];
					}
					if(!empty($location)){
						$this->sendTextMessage($this->show_location($location), $chat_id);
						$this->sendLocation($location['latitude'], $location['longitude'], $chat_id);
					}else{
						$this->sendTextMessage($this->info_noLocation(), $chat_id);
					}
				}else{
					//Error on procedure call.
					$this->sendTextMessage($this->info_genericError(), $chat_id);
					$this->logProcedureCallError(__LINE__,__FUNCTION__,$mysqli);//LOG
				}
			}else{
				//Error on connection to database.
				$this->sendTextMessage($this->info_genericError(), $chat_id);
				$this->logDatabaseConnectionError(__LINE__,__FUNCTION__,$mysqli);//LOG
			}
			$mysqli->close();
		}else{
			$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
			$toSend=$this->info_wrongCommandSyntax();
			$toSend.=$this->getSyntaxForCommand($commandName);
			$toSend.=$this->getHelpMessageFor($commandName);
			$this->sendTextMessage($toSend, $chat_id);	
		}
	}
	
	
	/**
	 * Command invoked from receiveUpdate method to save Telegram user position and send outcome message.
	 * @param $message Associative array with informations about the received message.
	 */
	private function saveLocation($message){
		$chat_id = intval($message['chat']['id']);
		include('connect_DB.php');
		if(!$mysqli->connect_error){
			$latitude = $message['location']['latitude'];
			$longitude = $message['location']['longitude'];
			$telegramUserId = intval($message['from']['id']);
			if ($mysqli->query("SET @esito = ''") && $mysqli->query("CALL BotPM10_p_insertLocation(\"$telegramUserId\", \"$chat_id\", \"$latitude\", \"$longitude\", @esito)")) {
				$res = $mysqli->query("SELECT @esito as esito");
				if ($res) {
					$row = $res->fetch_assoc();
					$esito = $row['esito'];
					if($esito==1){
						$this->sendTextMessage($this->info_locationSaved(), $chat_id);
					}elseif($esito==2){
						$this->sendTextMessage($this->info_locationChanged(), $chat_id);
					}else{
						$this->sendTextMessage($this->info_locationSaveError(), $chat_id);
					}
				}else{
					//Error on fetch from Mysql variable.
					$this->sendTextMessage($this->info_genericError(), $chat_id);
					$this->logMysqlVariableFetchError(__LINE__,__FUNCTION__,$mysqli);//LOG
				}
			}else{
				//Error on procedure call or on Mysql variable creation.
				$this->sendTextMessage($this->info_genericError(), $chat_id);
				$this->logProcedureCallError(__LINE__,__FUNCTION__,$mysqli);//LOG
			}
		}else{
			//Database connection error.
			$this->sendTextMessage($this->info_genericError(), $chat_id);
			$this->logDatabaseConnectionError(__LINE__,__FUNCTION__,$mysqli);//LOG
		}
		$mysqli->close();
	}
	
	
	/**
	 * Command invoked from receiveUpdate method to send to Telegram user start message.
	 * @param $arrayOfParams Parameters for the command (received from Telegram user).
	 * @param $message Associative array with informations about the received message.
	 */
	private function command_start($arrayOfParams, $message){
		$chat_id = intval($message['chat']['id']);
		$this->sendTextMessage($this->description_start(), $chat_id);
	}
	
	
	/**
	 * Command invoked from receiveUpdate method to send to Telegram user description of given command or generic help if no parameters were given.
	 * @param $arrayOfParams Parameters for the command (received from Telegram user).
	 * @param $message Associative array with informations about the received message.
	 */
	private function command_help($arrayOfParams, $message){
		$chat_id = intval($message['chat']['id']);
		if(empty($arrayOfParams)){
			$this->sendTextMessage($this->description_help(), $chat_id);
		}else{
			if(count($arrayOfParams)==1){
				$commandName = preg_replace('/[^a-z]/', '', strtolower($arrayOfParams[0]));
				$method = 'description_'.$commandName;
				if(method_exists($this, $method)){
					$this->sendTextMessage($this->$method(), $chat_id);
				}else{
					$this->sendTextMessage($this->info_inexistentCommandForHelp(), $chat_id);
				}
			}else{
				$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
				$toSend=$this->info_wrongCommandSyntax();
				$toSend.=$this->getSyntaxForCommand($commandName);
				$toSend.=$this->getHelpMessageFor($commandName);
				$this->sendTextMessage($toSend, $chat_id);
			}
		}
	}
	
	
	/**
	 * Command invoked from receiveUpdate method to send to Telegram user - detailed information about one station.
	 * @param $arrayOfParams Parameters for the command (received from Telegram user).
	 * @param $message Associative array with informations about the received message.
	 */
	private function command_stazione($arrayOfParams, $message){
		$chat_id = intval($message['chat']['id']);
		$telegramUserId = intval($message['from']['id']);
		$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
		if(!empty($arrayOfParams) && count($arrayOfParams)==1){
			$stationId = $arrayOfParams[0];
			if($this->itCanBeValidStationId($stationId)){
				$stations_info = $this->getStationsInfo();
				if(!empty($stations_info)){
					$station_info = $this->getStationInfo($stationId, $stations_info);
					if(!empty($station_info)){
						$rightZoneStations_info = $this->getRightZoneStations_info($stations_info, $this->zoneTypesToFilter);
						if($this->isInRightZone($stationId, $rightZoneStations_info)){
							$stations_data = $this->getStationsData();
							if(!empty($stations_data)){
								$PM10stations_data = $this->getPM10stations_data($stations_data);
								if($this->isPM10station($stationId, $PM10stations_data)){
									$station_data = $this->getStationData($stationId, $stations_data);
									$PM10_data = $this->getPM10fromStationData($station_data);
									if(!empty($PM10_data)){
										$xml_url = $this->getValidatedDataURL($station_info['provincia']);
										$xml = simplexml_load_file($xml_url);
										if($xml){
											$stationValidatedData = $xml->xpath('//row[CODSEQST="'.$stationId.'"]');
											if(!empty($stationValidatedData)){
												$location = $this->getLocation($telegramUserId);
												$distance = NULL;
												if(!empty($location)){
													$stationsCoordinates = $this->getStationsCoordinates();
													if(!empty($stationsCoordinates)){
														$stationLocation = $this->getStationLocation($stationId, $stationsCoordinates);
														$distance=$this->calcDistance($location, $stationLocation);
													}else{
														$this->logFunctionLine('Failed to load coordinates.',__LINE__,__FUNCTION__);
													}
												}
												$stationValidated_data = $stationValidatedData[0];
												$textToSend = $this->show_station($station_info, $PM10_data, $stationValidated_data, $telegramUserId, $distance);
												$this->sendTextMessage($textToSend, $chat_id);
												$this->sendChart($chat_id, 'Stazione: '.$station_info['nome'].'. Grafico degli andamenti.');
											}else{
												$this->sendTextMessage($this->info_genericError(), $chat_id);
												$this->logFunctionLine('No station validated data found in xml, probably error in validated data file.',__LINE__,__FUNCTION__);
											}
										}else{
											//Failed to open XML file with validated data.
											$this->sendTextMessage($this->info_genericError(), $chat_id);
											$this->logXmlError(__LINE__,__FUNCTION__,$xml_url);//LOG
										}
									}else{
										$this->sendTextMessage($this->info_genericError(), $chat_id);
										$this->logFunctionLine('No PM10 data, probably error in stations data file.',__LINE__,__FUNCTION__);
									}
								}else{
									$this->sendTextMessage($this->info_notPM10StationId(), $chat_id);
								}
							}else{
								$this->sendTextMessage($this->info_genericError(), $chat_id);
								$this->logFunctionLine('No station found, the file with the stations data is probably empty.',__LINE__,__FUNCTION__);
							}
						}else{
							$this->sendTextMessage($this->info_stationFilteredByZone(), $chat_id);
						}
					}else{
						$this->sendTextMessage($this->info_inexistentStationId(), $chat_id);
					}
				}else{
					$this->sendTextMessage($this->info_genericError(), $chat_id);
					$this->logFunctionLine('No station found, the file with the stations info is probably empty.',__LINE__,__FUNCTION__);
				}
			}else{
				$toSend=$this->info_wrongStationId();
				$toSend.=$this->getSyntaxForCommand($commandName);
				$toSend.=$this->getHelpMessageFor($commandName);
				$this->sendTextMessage($toSend, $chat_id);
			}
		}else{
			if(empty($arrayOfParams)){
				$this->sendTextMessage($this->getDescriptionForCommand($commandName), $chat_id);
			}else{
				$toSend=$this->info_wrongCommandSyntax();
				$toSend.=$this->getSyntaxForCommand($commandName);
				$toSend.=$this->getHelpMessageFor($commandName);
				$this->sendTextMessage($toSend, $chat_id);	
			}
		}
	}
	
	
	/**
	 * Command invoked from receiveUpdate method to send to Telegram user - list of all stations.
	 * @param $arrayOfParams Parameters for the command (received from Telegram user).
	 * @param $message Associative array with informations about the received message.
	 */
	private function command_stazioni($arrayOfParams, $message){
		$chat_id = intval($message['chat']['id']);
		$telegramUserId = intval($message['from']['id']);
		if(empty($arrayOfParams)){
			$stations_info = $this->getStationsInfo();
			if(!empty($stations_info)){
				$stations_data = $this->getStationsData();
				if(!empty($stations_data)){
					$PM10stations_data = $this->getPM10stations_data($stations_data);
					if(!empty($PM10stations_data)){
						$this->filterByZoneType($stations_info, $this->zoneTypesToFilter);
						$this->filterNotPM10($stations_info, $PM10stations_data);
						if(!empty($stations_info)){
							$registeredStationsIds = $this->getRegistrations($telegramUserId);
							if(!empty($registeredStationsIds)){
								$this->enrichWithRegistrationInfo($stations_info, $registeredStationsIds);
							}
							$this->orderByFields($stations_info, array('provincia', 'nome'));
							$toSend = $this->getMessageAllStationsList().''.PHP_EOL;
							$toSend.= $this->show_stations($stations_info);
							$this->sendTextMessage($toSend, $chat_id);
						}else{
							$this->sendTextMessage($this->info_genericError(), $chat_id);
							$this->logFunctionLine('No station found, all stations have been filtered.',__LINE__,__FUNCTION__);
						}
					}else{
						$this->sendTextMessage($this->info_genericError(), $chat_id);
						$this->logFunctionLine('No PM10 station found.',__LINE__,__FUNCTION__);
					}
				}else{
					$this->sendTextMessage($this->info_genericError(), $chat_id);
					$this->logFunctionLine('No station found, the file with the stations data is probably empty.',__LINE__,__FUNCTION__);
				}
			}else{
				$this->sendTextMessage($this->info_genericError(), $chat_id);
				$this->logFunctionLine('No station found, the file with the stations info is probably empty.',__LINE__,__FUNCTION__);
			}
		}else{
			$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
			$toSend=$this->info_wrongCommandSyntax();
			$toSend.=$this->getSyntaxForCommand($commandName);
			$toSend.=$this->getHelpMessageFor($commandName);
			$this->sendTextMessage($toSend, $chat_id);
		}
	}
	
	
	/**
	 * Command invoked from receiveUpdate method to subscribe Telegram user to indicated station and send the outcome message.
	 * @param $arrayOfParams Parameters for the command (received from Telegram user).
	 * @param $message Associative array with informations about the received message.
	 */
	private function command_segui($arrayOfParams, $message){
		$chat_id = intval($message['chat']['id']);
		if(!empty($arrayOfParams) && count($arrayOfParams)==1){
			$stationId = ltrim($arrayOfParams[0], '0');
			if($this->itCanBeValidStationId($stationId)){
				$stations_info = $this->getStationsInfo();
				if(!empty($stations_info)){
					$station_info = $this->getStationInfo($stationId, $stations_info);
					if(!empty($station_info)){
						$rightZoneStations_info = $this->getRightZoneStations_info($stations_info, $this->zoneTypesToFilter);
						if($this->isInRightZone($stationId, $rightZoneStations_info)){
							$stations_data = $this->getStationsData();
							if(!empty($stations_data)){
								$PM10stations_data = $this->getPM10stations_data($stations_data);
								if($this->isPM10station($stationId, $PM10stations_data)){
									include('connect_DB.php');
									if(!$mysqli->connect_error){
										$telegramUserId = intval($message['from']['id']);
										if ($mysqli->query("SET @esito = ''") && $mysqli->query("CALL BotPM10_p_insertRegistration(\"$telegramUserId\", \"$chat_id\", \"$stationId\", @esito)")) {
											$res = $mysqli->query("SELECT @esito as esito");
											if ($res) {
												$row = $res->fetch_assoc();
												$esito = $row['esito'];
												if($esito==1){
													$this->sendTextMessage($this->info_registrationSuccess(), $chat_id);
												}else{
													//Duplicate registration.
													$this->sendTextMessage($this->info_registrationDuplicate(), $chat_id);
												}
											}else{
												//Error on fetch from Mysql variable.
												$this->sendTextMessage($this->info_genericError(), $chat_id);
												$this->logMysqlVariableFetchError(__LINE__,__FUNCTION__,$mysqli);//LOG
											}
										}else{
											//Error on procedure call or on Mysql variable creation.
											$this->sendTextMessage($this->info_genericError(), $chat_id);
											$this->logProcedureCallError(__LINE__,__FUNCTION__,$mysqli);//LOG
										}
									}else{
										//Database connection error.
										$this->sendTextMessage($this->info_genericError(), $chat_id);
										$this->logDatabaseConnectionError(__LINE__,__FUNCTION__,$mysqli);//LOG
									}
									$mysqli->close();
								}else{
									$this->sendTextMessage($this->info_notPM10StationId(), $chat_id);
								}
							}else{
								$this->sendTextMessage($this->info_genericError(), $chat_id);
								$this->logFunctionLine('No station found, the file with the stations data is probably empty.',__LINE__,__FUNCTION__);
							}
						}else{
							$this->sendTextMessage($this->info_stationFilteredByZone(), $chat_id);
						}
					}else{
						$this->sendTextMessage($this->info_inexistentStationId(), $chat_id);
					}
				}else{
					$this->sendTextMessage($this->info_genericError(), $chat_id);
					$this->logFunctionLine('No station found, the file with the stations info is probably empty.',__LINE__,__FUNCTION__);
				}
			}else{
				$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
				$toSend=$this->info_wrongStationId();
				$toSend.=$this->getSyntaxForCommand($commandName);
				$toSend.=$this->getHelpMessageFor($commandName);
				$this->sendTextMessage($toSend, $chat_id);
			}
		}else{
			$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
			if(empty($arrayOfParams)){
				$this->sendTextMessage($this->getDescriptionForCommand($commandName), $chat_id);
			}else{
				$toSend=$this->info_wrongCommandSyntax();
				$toSend.=$this->getSyntaxForCommand($commandName);
				$toSend.=$this->getHelpMessageFor($commandName);
				$this->sendTextMessage($toSend, $chat_id);	
			}
		}
	}
	
	
	/**
	 * Command invoked from receiveUpdate method to subscribe Telegram user to the stations in given range and send the outcome message.
	 * @param $arrayOfParams Parameters for the command (received from Telegram user).
	 * @param $message Associative array with informations about the received message.
	 */
	private function command_seguikm($arrayOfParams, $message){
		$telegramUserId = intval($message['from']['id']);
		$chat_id = intval($message['chat']['id']);
		$location = $this->getLocation($telegramUserId);
		if(!empty($arrayOfParams) && count($arrayOfParams)==1){
			if(!empty($location)){
				$rangeKm = $this->getValidKm($arrayOfParams[0]);
				if($rangeKm !== FALSE){
					if($this->isInRangeKm($rangeKm)){
						$rangeMeters = $rangeKm*1000;
						$stations_info = $this->getStationsInfo();
						if(!empty($stations_info)){
							$coordinates = $this->getStationsCoordinates();
							if(!empty($coordinates)){
								$stations_data = $this->getStationsData();
								if(!empty($stations_data)){
									$PM10stations_data = $this->getPM10stations_data($stations_data);
									if(!empty($PM10stations_data)){
										$this->filterByZoneType($stations_info, $this->zoneTypesToFilter);
										$this->filterNotPM10($stations_info, $PM10stations_data);
										if(!empty($stations_info)){
											$this->enrichWithDistance($stations_info, $coordinates, $location);
											$this->orderByDistance($stations_info);
											$this->filterStationsNotInRange($stations_info, $rangeMeters);
											if(!empty($stations_info)){
												$registeredStationsIds = $this->getRegistrations($telegramUserId);
												$this->filterStationsInIdArray($stations_info, $registeredStationsIds);
												if(!empty($stations_info)){
													include('connect_DB.php');
													if(!$mysqli->connect_error){
														$numRegistrations = 0;
														foreach($stations_info as $station_info){
															$stationId = $station_info['codseqst'];
															if($mysqli->query("SET @esito = ''") && $mysqli->query("CALL BotPM10_p_insertRegistration(\"$telegramUserId\", \"$chat_id\", \"$stationId\", @esito)")) {
																$res = $mysqli->query("SELECT @esito as esito");
																if ($res) {
																	$row = $res->fetch_assoc();
																	$esito = $row['esito'];
																	if($esito==1){
																		++$numRegistrations;
																	}else{
																		//Duplicate registration.
																	}
																}else{
																	//Error on fetch from Mysql variable.
																	$this->logMysqlVariableFetchError(__LINE__,__FUNCTION__,$mysqli);//LOG
																}
															}else{
																//Error on procedure call or on Mysql variable creation.
																$this->logProcedureCallError(__LINE__,__FUNCTION__,$mysqli);//LOG
															}
														}
														$stazioni_or_stazione = $numRegistrations === 1 ? 'stazione':'stazioni';
														$this->sendTextMessage('Ti sei registrato a '.$numRegistrations.' '.$stazioni_or_stazione.'.', $chat_id);
													}else{
														//Database connection error.
														$this->sendTextMessage($this->info_genericError(), $chat_id);
														$this->logDatabaseConnectionError(__LINE__,__FUNCTION__,$mysqli);//LOG
													}
													$mysqli->close();
												}else{
													$toSend = $this->info_alreadySubscribedToStationsInRange($rangeMeters);
													$this->sendTextMessage($toSend, $chat_id);
												}
											}else{
												$toSend = $this->info_noStationsInRange($rangeMeters);
												$this->sendTextMessage($toSend, $chat_id);
											}
										}else{
											$this->sendTextMessage($this->info_genericError(), $chat_id);
											$this->logFunctionLine('No station found, all stations have been filtered.',__LINE__,__FUNCTION__);
										}
									}else{
										$this->sendTextMessage($this->info_genericError(), $chat_id);
										$this->logFunctionLine('No PM10 station found.',__LINE__,__FUNCTION__);
									}
								}else{
									$this->sendTextMessage($this->info_genericError(), $chat_id);
									$this->logFunctionLine('No station found, the file with the stations data is probably empty.',__LINE__,__FUNCTION__);
								}
							}else{
								$this->sendTextMessage($this->info_genericError(), $chat_id);
								$this->logFunctionLine('No coordinates found, the file with the stations coordinates is probably empty.',__LINE__,__FUNCTION__);	
							}
						}else{
							$this->sendTextMessage($this->info_genericError(), $chat_id);
							$this->logFunctionLine('No station found, the file with the stations info is probably empty.',__LINE__,__FUNCTION__);
						}
					}else{
						$this->sendTextMessage($this->info_numberOutOfRange(), $chat_id);
					}
				}else{
					$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
					$toSend=$this->info_wrongCommandSyntax();
					$toSend.=$this->getSyntaxForCommand($commandName);
					$toSend.=$this->getHelpMessageFor($commandName);
					$this->sendTextMessage($toSend, $chat_id);				
				}
			}else{
				$this->sendTextMessage($this->info_locationRequired(), $chat_id);
			}
		}else{
			$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
			if(empty($arrayOfParams)){
				$this->sendTextMessage($this->getDescriptionForCommand($commandName), $chat_id);
			}else{
				$toSend=$this->info_wrongCommandSyntax();
				$toSend.=$this->getSyntaxForCommand($commandName);
				$toSend.=$this->getHelpMessageFor($commandName);
				$this->sendTextMessage($toSend, $chat_id);	
			}
		}
	}
	
	
	/**
	 * Command invoked from receiveUpdate method to subscribe Telegram user to all the stations and send the outcome message.
	 * @param $arrayOfParams Parameters for the command (received from Telegram user).
	 * @param $message Associative array with informations about the received message.
	 */
	private function command_seguitutte($arrayOfParams, $message){
		$chat_id = intval($message['chat']['id']);
		if(empty($arrayOfParams)){
			$stations_info = $this->getStationsInfo();
			if(!empty($stations_info)){
				$stations_data = $this->getStationsData();
				if(!empty($stations_data)){
					$PM10stations_data = $this->getPM10stations_data($stations_data);
					if(!empty($PM10stations_data)){
						$this->filterByZoneType($stations_info, $this->zoneTypesToFilter);
						$this->filterNotPM10($stations_info, $PM10stations_data);
						if(!empty($stations_info)){
							$telegramUserId = intval($message['from']['id']);
							$registeredStationsIds = $this->getRegistrations($telegramUserId);
							$this->filterStationsInIdArray($stations_info, $registeredStationsIds);
							if(!empty($stations_info)){
								include('connect_DB.php');
								if(!$mysqli->connect_error){
									$numRegistrations = 0;
									foreach($stations_info as $station_info){
										$stationId = $station_info['codseqst'];
										if($mysqli->query("SET @esito = ''") && $mysqli->query("CALL BotPM10_p_insertRegistration(\"$telegramUserId\", \"$chat_id\", \"$stationId\", @esito)")) {
											$res = $mysqli->query("SELECT @esito as esito");
											if ($res) {
												$row = $res->fetch_assoc();
												$esito = $row['esito'];
												if($esito==1){
													++$numRegistrations;
												}else{
													//Duplicate registration.
												}
											}else{
												//Error on fetch from Mysql variable.
												$this->logMysqlVariableFetchError(__LINE__,__FUNCTION__,$mysqli);//LOG
											}
										}else{
											//Error on procedure call or on Mysql variable creation.
											$this->logProcedureCallError(__LINE__,__FUNCTION__,$mysqli);//LOG
										}
									}
									$stazioni_or_stazione = $numRegistrations === 1 ? 'stazione':'stazioni';
									$this->sendTextMessage('Ti sei registrato a '.$numRegistrations.' '.$stazioni_or_stazione.'.', $chat_id);
								}else{
									//Database connection error.
									$this->sendTextMessage($this->info_genericError(), $chat_id);
									$this->logDatabaseConnectionError(__LINE__,__FUNCTION__,$mysqli);//LOG
								}
								$mysqli->close();
							}else{
								//Subscribed to all the stations.
								$this->sendTextMessage('Sei già iscritto a tutte le stazioni.', $chat_id);
							}
						}else{
							$this->sendTextMessage($this->info_genericError(), $chat_id);
							$this->logFunctionLine('No station found, all stations have been filtered.',__LINE__,__FUNCTION__);
						}
					}else{
						$this->sendTextMessage($this->info_genericError(), $chat_id);
						$this->logFunctionLine('No PM10 station found.',__LINE__,__FUNCTION__);
					}
				}else{
					$this->sendTextMessage($this->info_genericError(), $chat_id);
					$this->logFunctionLine('No station found, the file with the stations data is probably empty.',__LINE__,__FUNCTION__);
				}
			}else{
				$this->sendTextMessage($this->info_genericError(), $chat_id);
				$this->logFunctionLine('No station found, the file with the stations info is probably empty.',__LINE__,__FUNCTION__);
			}	
		}else{
			$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
			if(empty($arrayOfParams)){
				$this->sendTextMessage($this->getDescriptionForCommand($commandName), $chat_id);
			}else{
				$toSend=$this->info_wrongCommandSyntax();
				$toSend.=$this->getSyntaxForCommand($commandName);
				$toSend.=$this->getHelpMessageFor($commandName);
				$this->sendTextMessage($toSend, $chat_id);	
			}
		}
	}
	
	
	/**
	 * Command invoked from receiveUpdate method to unsubscribe Telegram user from indicated station and send the outcome message.
	 * @param $arrayOfParams Parameters for the command (received from Telegram user).
	 * @param $message Associative array with informations about the received message.
	 */
	private function command_nonseguire($arrayOfParams, $message){
		$chat_id = intval($message['chat']['id']);
		if(!empty($arrayOfParams) && count($arrayOfParams)==1){
			$stationId = ltrim($arrayOfParams[0], '0');
			if($this->itCanBeValidStationId($stationId)){
				include('connect_DB.php');
				if(!$mysqli->connect_error){
					$telegramUserId = intval($message['from']['id']);
					if ($mysqli->query("SET @esito = ''") && $mysqli->query("CALL BotPM10_p_removeRegistration(\"$telegramUserId\", \"$stationId\", @esito)")) {
						$res = $mysqli->query("SELECT @esito as esito");
						if ($res) {
							$row = $res->fetch_assoc();
							$esito = $row['esito'];
							if($esito==3){
								$this->sendTextMessage($this->info_unRegistrationSuccess(), $chat_id);
							}elseif($esito==2){
								$this->sendTextMessage($this->info_notRegistered(), $chat_id);
							}elseif($esito==1 || $esito==0){
								$this->sendTextMessage($this->info_notRegisteredToAnyStation(), $chat_id);
							}
						}else{
							//Error on fetch from Mysql variable.
							$this->sendTextMessage($this->info_genericError(), $chat_id);
							$this->logMysqlVariableFetchError(__LINE__,__FUNCTION__,$mysqli);//LOG
						}
					}else{
						//Error on procedure call or on Mysql variable creation.
						$this->sendTextMessage($this->info_genericError(), $chat_id);
						$this->logProcedureCallError(__LINE__,__FUNCTION__,$mysqli);//LOG
					}
				}else{
					//Database connection error.
					$this->sendTextMessage($this->info_genericError(), $chat_id);
					$this->logDatabaseConnectionError(__LINE__,__FUNCTION__,$mysqli);//LOG
				}
				$mysqli->close();
			}else{
				$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
				$toSend=$this->info_wrongStationId();
				$toSend.=$this->getSyntaxForCommand($commandName);
				$toSend.=$this->getHelpMessageFor($commandName);
				$this->sendTextMessage($toSend, $chat_id);
			}
		}else{
			$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
			if(empty($arrayOfParams)){
				$this->sendTextMessage($this->getDescriptionForCommand($commandName), $chat_id);
			}else{
				$toSend=$this->info_wrongCommandSyntax();
				$toSend.=$this->getSyntaxForCommand($commandName);
				$toSend.=$this->getHelpMessageFor($commandName);
				$this->sendTextMessage($toSend, $chat_id);	
			}
		}
	}
	
	
	/**
	 * Command invoked from receiveUpdate method to delete informations related to Telegram user and send outcome.
	 * @param $arrayOfParams Parameters for the command (received from Telegram user).
	 * @param $message Associative array with informations about the received message.
	 */
	private function command_stop($arrayOfParams, $message){
		$chat_id = intval($message['chat']['id']);
		$telegramUserId = intval($message['from']['id']);
		if(empty($arrayOfParams)){
			include('connect_DB.php');
			if(!$mysqli->connect_error){
				if($mysqli->query("SET @esito = ''") && $mysqli->query("CALL BotPM10_p_deleteUser(\"$telegramUserId\", @esito)")){
					$res = $mysqli->query("SELECT @esito as esito");
					if($res){
						$row = $res->fetch_assoc();
						$esito = $row['esito'];
						if($esito==1){
							$this->sendTextMessage($this->info_stopSuccessful(), $chat_id);
						}elseif($esito==2){
							$this->sendTextMessage($this->info_stopNothingToDelete(), $chat_id);
						}else{
							$this->sendTextMessage($this->info_genericError(), $chat_id);
						}
					}else{
						//Error on fetch from Mysql variable.
						$this->sendTextMessage($this->info_genericError(), $chat_id);
						$this->logMysqlVariableFetchError(__LINE__,__FUNCTION__,$mysqli);//LOG
					}
				}else{
					//Error on procedure call or on Mysql variable creation.
					$this->sendTextMessage($this->info_genericError(), $chat_id);
					$this->logProcedureCallError(__LINE__,__FUNCTION__,$mysqli);//LOG
				}
			}else{
				//Database connection error.
				$this->sendTextMessage($this->info_genericError(), $chat_id);
				$this->logDatabaseConnectionError(__LINE__,__FUNCTION__,$mysqli);//LOG
			}
			$mysqli->close();
		}else{
			$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
			$toSend=$this->info_wrongCommandSyntax();
			$toSend.=$this->getSyntaxForCommand($commandName);
			$toSend.=$this->getHelpMessageFor($commandName);
			$this->sendTextMessage($toSend, $chat_id);
		}
	}
	
	
	/**
	 * Command invoked from receiveUpdate method to send to Telegram user list of subscribed stations.
	 * @param $arrayOfParams Parameters for the command (received from Telegram user).
	 * @param $message Associative array with informations about the received message.
	 */
	private function command_iscrizioni($arrayOfParams, $message){
		$chat_id = intval($message['chat']['id']);
		if(empty($arrayOfParams)){
			include('connect_DB.php');
			if(!$mysqli->connect_error){
				$telegramUserId = intval($message['from']['id']);
				if ($result = $mysqli->query("CALL BotPM10_p_getRegistrations(\"$telegramUserId\")")) {
					$stationsId = array();
					$i = 0;
					while($row = $result->fetch_assoc()){
						$stationsId[$i]= $row['stationId'];
						++$i;
					}//while
					if(!empty($stationsId)){
						$stations_info = $this->getStationsInfo();
						if(!empty($stations_info)){
							$this->filterStationsNotInIdArray($stations_info, $stationsId);
							if(!empty($stations_info)){
								$registeredStationsIds = $this->getRegistrations($telegramUserId);
								if(!empty($registeredStationsIds)){
									$this->enrichWithRegistrationInfo($stations_info, $registeredStationsIds);
								}
								$this->orderByFields($stations_info, array('provincia', 'nome'));
								$toSend = $this->getMessageSubscribedStationsList().''.PHP_EOL;
								$toSend.= $this->show_stations($stations_info);
								$this->sendTextMessage($toSend, $chat_id);
							}else{
								$this->sendTextMessage($this->info_genericError(), $chat_id);
								$this->logFunctionLine('No station found, all stations have been filtered.',__LINE__,__FUNCTION__);//LOG
							}
						}else{
							$this->sendTextMessage($this->info_genericError(), $chat_id);
							$this->logFunctionLine('No station found, the file with the stations info is probably empty.',__LINE__,__FUNCTION__);//LOG
						}
					}else{
						$toSend =$this->info_notRegisteredToAnyStation();
						$toSend.=$this->getCommandsForRegistrationPhrase();
						$this->sendTextMessage($toSend, $chat_id);
					}
				}else{
					//Error on procedure call.
					$this->sendTextMessage($this->info_genericError(), $chat_id);
					$this->logProcedureCallError(__LINE__,__FUNCTION__,$mysqli);//LOG
				}
			}else{
				//Database connection error.
				$this->sendTextMessage($this->info_genericError(), $chat_id);
				$this->logDatabaseConnectionError(__LINE__,__FUNCTION__,$mysqli);//LOG
			}
			$mysqli->close();
		}else{
			$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
			$toSend=$this->info_wrongCommandSyntax();
			$toSend.=$this->getSyntaxForCommand($commandName);
			$toSend.=$this->getHelpMessageFor($commandName);
			$this->sendTextMessage($toSend, $chat_id);
		}
	}
	
	
	//COMMANDS - END//


	//STRINGS TO USER - BEGIN//
	
	
	private function description_seguikm(){
		$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
		$s='Questo comando permette di iscriversi alle notifiche delle stazioni nel raggio indicato (in km).'.PHP_EOL;
		$s.='Per ogni stazione a cui ti sei iscritto, il bot ti avviserà del superamento di certe soglie del livello di PM10 (sui dati validati).'.PHP_EOL;
		$s.=$this->getSyntaxForCommand($commandName);
		$s.=$this->info_numberOutOfRange();
		$s.='Per far funzionare questo comando mandaci la tua posizione usando il pulsante "graffetta" e poi "posizione".'.PHP_EOL;
		return $s;
	}
	private function syntax_seguikm(){
		$commandName = 'seguiKm';
		$s=$this->getFirstPhraseForSyntax();
		$s.='/'.$commandName.'_10'.PHP_EOL;
		$s.='/'.$commandName.'_20'.PHP_EOL;
		$s.='/'.$commandName.'_30'.PHP_EOL;
		return $s.='per iscriversi alle stazioni nel raggio di 10km, 20km o 30km dalla tua posizione.'.PHP_EOL;
	}
	
	private function description_stazionikm(){
		$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
		$s='Questo comando permette ottenere la lista delle stazioni nel raggio indicato (in km).'.PHP_EOL;
		$s.=$this->getSyntaxForCommand($commandName);
		$s.=$this->info_numberOutOfRange();
		$s.='Per far funzionare questo comando mandaci la tua posizione usando il pulsante "graffetta" e poi "posizione".'.PHP_EOL;
		return $s;
	}
	private function syntax_stazionikm(){
		$commandName = 'stazioniKm';
		$s=$this->getFirstPhraseForSyntax();
		$s.='/'.$commandName.'_10'.PHP_EOL;
		$s.='/'.$commandName.'_20'.PHP_EOL;
		$s.='/'.$commandName.'_30'.PHP_EOL;
		return $s.='per ottenere la lista delle stazioni nel raggio di 10km, 20km o 30km dalla tua posizione.'.PHP_EOL;
	}
	
	private function description_posizione(){
		$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
		$s='Questo comando permette di vedere la posizione che ci hai mandato.'.PHP_EOL;
		$s.=$this->getSyntaxForCommand($commandName);
		$s.='Se la posizione è impostata potrai usare i comandi:'.PHP_EOL;
		$s.='/seguikm e /stazionikm.'.PHP_EOL;
		$s.='Il comando:'.PHP_EOL;
		$s.='/stazione'.PHP_EOL;
		$s.='indicherà anche la distanza della stazione dalla posizione.'.PHP_EOL;
		return $s;
	}
	private function syntax_posizione(){
		$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
		$s=$this->getFirstPhraseForSyntax();
		$s.='/'.$commandName.PHP_EOL;
		return $s.='per vedere l\'ultima posizione che ci hai mandato.'.PHP_EOL;
	}
	
	private function description_help(){
		$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
		$s='Questo comando può essere usato per ottenere la descrizione dettagliata dei comandi.'.PHP_EOL;
		$s.='Puoi ottenere la descrizione dei seguenti comandi:'.PHP_EOL;
		$s.=$this->getAllCommandsList();
		return $s;
	}
	private function syntax_help(){
		$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
		$s=$this->getFirstPhraseForSyntax();
		$s.='/'.$commandName.'_stazioni'.PHP_EOL;
		return $s.='per ottenere la descrizione completa del comando "stazioni"'.PHP_EOL;
	}
	
	private function description_stazione(){
		$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
		$s='Questo comando permette di consultare le informazioni su una stazione a scelta.'.PHP_EOL;
		$s.=$this->getSyntaxForCommand($commandName);
		return $s.=$this->getCommandsForIdDiscoveryPhrase();
	}
	private function syntax_stazione(){
		$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
		$s=$this->getFirstPhraseForSyntax();
		$s.='/'.$commandName.'_500015004'.PHP_EOL;
		return $s.='per avere le informazioni sulla stazione avente l\'identificativo 500015004.'.PHP_EOL;
	}
	
	private function description_stazioni(){
		$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
		$s='Questo comando permette di ottenere la lista di tutte le stazioni.'.PHP_EOL;
		$s.=$this->getSyntaxForCommand($commandName);
		$s.='Le stazioni visualizzate sono solo quelle di fondo che hanno le rilevazioni di PM10.'.PHP_EOL;
		return $s;
	}
	private function syntax_stazioni(){
		$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
		$s=$this->getFirstPhraseForSyntax();
		$s.='/'.$commandName.PHP_EOL;
		return $s.='per ottenere la lista di tutte le stazioni.'.PHP_EOL;
	}
	
	private function description_segui(){
		$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
		$s='Questo comando permette di iscriversi alle notifiche di una stazione.'.PHP_EOL;
		$s.='Per ogni stazione a cui ti sei iscritto, il bot ti avviserà del superamento di certe soglie del livello di PM10 (sui dati validati). '.PHP_EOL;
		$s.=$this->getSyntaxForCommand($commandName);
		return $s.=$this->getCommandsForIdDiscoveryPhrase();
	}
	private function syntax_segui(){
		$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
		$s=$this->getFirstPhraseForSyntax();
		$s.='/'.$commandName.'_500015004'.PHP_EOL;
		return $s.='per iscriverti alla stazione avente l\'identificativo 500015004.'.PHP_EOL;
	}
	
	private function description_seguitutte(){
		$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
		$s='Questo comando permette di iscriversi alle notifiche di tutte le stazioni.'.PHP_EOL;
		$s.='Per ogni stazione a cui ti sei iscritto, il bot ti avviserà del superamento di certe soglie del livello di PM10 (sui dati validati). '.PHP_EOL;
		$s.=$this->getSyntaxForCommand($commandName);
		$s.='Per scoprire le stazioni a cui sei iscritto usa il comando:'.PHP_EOL;
		$s.='/iscrizioni'.PHP_EOL;
		return $s;
	}
	private function syntax_seguitutte(){
		$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
		$s=$this->getFirstPhraseForSyntax();
		$s.='/'.$commandName.PHP_EOL;
		return $s.='per iscriverti a tutte le stazioni.'.PHP_EOL;
	}
	
	private function description_nonseguire(){
		$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
		$s='Questo comando permette di disiscriversi dalle notifiche di una stazione a scelta.'.PHP_EOL;
		$s.=$this->getSyntaxForCommand($commandName);
		$s.='Per scoprire gli identificatori delle stazioni a cui sei iscritto usa il comando:'.PHP_EOL;
		$s.='/iscrizioni'.PHP_EOL;
		return $s;
	}
	private function syntax_nonseguire(){
		$s=$this->getFirstPhraseForSyntax();
		$s.='/nonSeguire_500015004'.PHP_EOL;
		return $s.='per disiscriversi dalla stazione avente l\'identificativo 500015004.'.PHP_EOL;
	}
	
	private function description_stop(){
		$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
		$s='Questo comando ti disiscrive dalle notifiche di tutte le stazioni ed elimina la posizione associata a te.'.PHP_EOL;
		$s.=$this->getSyntaxForCommand($commandName);
		return $s;
	}
	private function syntax_stop(){
		$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
		$s=$this->getFirstPhraseForSyntax();
		$s.='/'.$commandName.PHP_EOL;
		return $s.='per disiscriverti da tutte le stazioni ed cancellare la posizione associata a te.'.PHP_EOL;
	}
	
	private function description_iscrizioni(){
		$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
		$s='Questo comando permette di visualizzare le stazioni a cui sei iscritto.'.PHP_EOL;
		$s.=$this->getSyntaxForCommand($commandName);
		$s.='Per disiscriverti dalle stazioni puoi usare i comandi:'.PHP_EOL;
		$s.='/nonseguire e /stop'.PHP_EOL;
		return $s;
	}
	private function syntax_iscrizioni(){
		$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
		$s=$this->getFirstPhraseForSyntax();
		$s.='/'.$commandName.PHP_EOL;
		return $s.='per ottenere la lista delle stazioni a cui sei iscritto.'.PHP_EOL;
	}
	
	private function description_start(){
		$s ='';
		$s.='Visualizza i livelli del <b>PM10</b> misurati attraverso le <b>stazioni di fondo</b> della rete Arpav. ';
		$s.='Permette inoltre di iscriversi alle notifiche delle stazioni.'.PHP_EOL;
		$s.='Per iniziare, usa il comando /stazioni per ottenere la lista di tutte le stazioni.'.PHP_EOL;
		$s.='Qui trovi la pagina web del bot:'.PHP_EOL;
		$s.='www.arpa.veneto.it/temi-ambientali/aria/arpavpm10bot';
		return $s;
	}
	
	/**
	 * @return String with "clickable" help commands for all commands.
	 */
	private function getAllCommandsList(){
		$commands = Array(
			'iscrizioni',
			'nonSeguire',
			'posizione',
			'segui',
			'seguikm',
			'seguitutte',
			'start',
			'stazione',
			'stazioni',
			'stazionikm',
			'stop',
		);
		$s='';
		foreach($commands as $command){
			$s.='/help_'.$command.PHP_EOL;
		}
		return $s;
	}
	
	/**
	 * @return Phrase that gives commands useful to discover identifiers of the stations.
	 */
	private function getCommandsForIdDiscoveryPhrase(){
	 	$s='Per scoprire gli identificatori delle stazioni puoi usare il comando:'.PHP_EOL;
		return $s.='/stazioni'.PHP_EOL;
	}
	
	/**
	 * @return Phrase used before the example of the right syntax for a command.
	 */
	private function getFirstPhraseForSyntax(){
		return 'Per esempio scrivi: '.PHP_EOL;
	}
	
	/**
	 * @return Phrase that tells how to get avvailable commands.
	 */
	private function  getAvailableCommandsPhrase(){
		return 'Inserisci il simbolo "/" per vedere tutti i comandi disponibili.'.PHP_EOL;
	}
	
	/**
	 * @return Phrase that tells how to send position.
	 */
	private function getSendYourPositionPhrase(){
		return 'Mandaci la tua posizione usando il pulsante "graffetta" e poi "posizione".'.PHP_EOL;
	}
	
	/**
	 * @return Phrase that gives the commands used to subscribe to stations.
	 */
	private function getCommandsForRegistrationPhrase(){
		return 'Usa il comando:'.PHP_EOL.'/segui'.PHP_EOL.'per iscriverti alle stazioni.'.PHP_EOL;
	}
	
	/**
	 * @return Phrase that is located before the list of subscribed stations.
	 */
	private function getMessageSubscribedStationsList(){
		return 'Sei iscritto alle seguenti stazioni:'.PHP_EOL;
	}
	
	/**
	 * @return Phrase that is placed before the list of all stations.
	 */
	private function getMessageAllStationsList(){
		return 'Elenco di tutte le stazioni:'.PHP_EOL;
	}
	
	/**
	 * @return Phrase that is placed before the list of stations in the given range.
	 * @param $rangeMeters Range in meters.
	 */
	private function getMessageStationsInRangeList($rangeMeters){
		return 'Elenco di stazioni nel raggio di '.$this->show_distance($rangeMeters).' dalla tua posizione:'.PHP_EOL;
	}
	
	/**
	 * @param $commandName Name of the command.
	 * @return String with message that describes how to obtain detailed information about the given command.
	 */
	private function getHelpMessageFor($commandName){
		return 'Scrivi "/help_'.$commandName.'" per avere la descrizione completa del comando "'.$commandName.'".'.PHP_EOL;
	}
	
	
	/**
	 * @param $location Associative array with specified 'latitude' and 'longitude' of the location.
	 * @return String that describes the indicated location.
	 */
	private function show_location($location){
		$s='La tue posizione è: '.PHP_EOL;
		$s.='latitudine: '.$location['latitude'].PHP_EOL;
		$s.='longitudine: '.$location['longitude'];
		return $s;
	}
	
	/**
	 * @param $stations Associative array of stations.
	 * @return String with the list of indicated stations.
	 */
	private function show_stations($stations){
		$s='';
		foreach ($stations as $station) {
			$stationId = $station['codseqst'];
			$s.='<b>Provincia:</b> '.$station['provincia'].PHP_EOL;
			$s.='<b>Nome:</b> '.$station['nome'].PHP_EOL;
			if(isset($station['distance'])){
				$s.='<b>Distanza:</b> '.$this->show_distance($station['distance']).PHP_EOL;
			}
			$s.='Dettagli:'.PHP_EOL;
			$s.='/Stazione_'.$stationId.PHP_EOL;
			if(isset($station['registered']) && $station['registered']){
				$s.="Disiscriviti:".PHP_EOL;
				$s.='/NonSeguire_'.$stationId.PHP_EOL;
			}else{
				$s.="Iscriviti:".PHP_EOL;
				$s.='/Segui_'.$stationId.PHP_EOL;
			}
			$s.=PHP_EOL;
		}
		return $s;	
	}
	
	
	/**
	 * @param $station_info Associative array.
	 * @param $PM10_datas Associative array.
	 * @param $stationValidated_data SimpleXMLElement.
	 * @return String with informations about the station.
	 */
	private function show_station($station_info, $PM10_datas, $stationValidated_data ,$telegramUserId, $distance = NULL){
		$dataBollettino = $this->getDataFromDataTime((string)$stationValidated_data->DATA_BOLLETTINO);
		$dataRif = $this->getDataFromDataTime((string)$stationValidated_data->DATA_RIF);
		$concPM10 = (string)$stationValidated_data->CONC_PM10;
		$concPM10 = is_numeric($concPM10) ? $concPM10.' µg/m³' : 'Non definita';
		$supPM10 = (string)$stationValidated_data->SUP_PM10;
		if(!is_numeric($supPM10)){
			$supPM10 = 'Non definito';
		}
		$IQA = (string)$stationValidated_data->IQA;
		$IQA = is_numeric($IQA) ? $this->getIQArankingPhrase($IQA) : 'Non definito';
		$s='';
		$stationName = $station_info['nome'];
		$s.='<b>Stazione:</b> '.$stationName.PHP_EOL;
		$s.='<b>Provincia:</b> '.$station_info['provincia'].PHP_EOL;
		$s.='<b>Bollettino del:</b> '.$dataBollettino.PHP_EOL;
		$s.='<b>Dati riferiti al:</b> '.$dataRif.PHP_EOL;
		$s.='<b>PM10 - media giornaliera:</b> '.$concPM10.PHP_EOL;
		$s.='<b>PM10 - superamenti limiti di legge da inzio anno:</b> '.$supPM10.PHP_EOL;
		$s.='<b>Indice di qualità dell\'aria della stazione:</b> '.$IQA.PHP_EOL;
		if(!is_null($distance)){
			$s.='<b>Distanza dalla tua posizione:</b> ';
			$s.=$this->show_distance($distance).PHP_EOL;
		}
		$stationId = $station_info['codseqst'];
		$registered = FALSE;
		$registrations = $this->getRegistrations($telegramUserId);
		if(!is_null($registrations)){
			$registered = in_array($stationId, $registrations);
		}
		if($registered){
			$s.="Sei iscritto a questa stazione, puoi disiscriverti:".PHP_EOL;
			$s.='/NonSeguire_'.$stationId.PHP_EOL;
		}else{
			$s.="Iscriviti a questa stazione:".PHP_EOL;
			$s.='/Segui_'.$stationId.PHP_EOL;
		}
		$this->generateChart($PM10_datas, $stationName);
		return $s;
	}
	
	
	/**
	 * @param $IQA Air quality index, must be numeric.
	 * @return String containing ranking phrase, or '' if parameter was invalid.
	 */
	private function getIQArankingPhrase($IQA){
		if($IQA <= 50){
			return 'Buona';
		}elseif($IQA <= 100){
			return 'Accettabile';
		}elseif($IQA <= 150){
			return 'Mediocre';
		}elseif($IQA <= 200){
			return 'Scadente';
		}else{
			return 'Pessima';
		}
	}
	
	
	/**
	 * @return Phrase to show when user doesn't have a location.
	 */
	private function info_noLocation(){
		$s='Non hai ancora indicato una posizione.'.PHP_EOL;
		return $s.=$this->getSendYourPositionPhrase();
	}
	
	/**
	 * @return Phrase to show when user doesn't have a location.
	 */
	private function info_locationRequired(){
		$s='Questo comando ha bisogno della tua posizione per funzionare.'.PHP_EOL;
		return $s.=$this->getSendYourPositionPhrase();
	}
	
	/**
	 * @return Phrase to show when inserted number is out of range.
	 */
	private function info_numberOutOfRange(){
		return 'Il numero deve essere compreso tra 1 e 2000.'.PHP_EOL;
	}
	
	/**
	 * @return Phrase to be displayed on successful location save.
	 */
	private function info_locationSaved(){
		return 'La tua posizione è stata salvata.'.PHP_EOL;
	} 
	
	/**
	 * @return Phrase to be displayed on successful location change.
	 */
	private function info_locationChanged(){
		return 'La tua nuova posizione è stata salvata.'.PHP_EOL;
	}
	
	/**
	 * @return Phrase to be displayed on failed location save.
	 */
	private function info_locationSaveError(){
		return 'Si è verificato un errore, durante il salvataggio della tua posizione, riprova più tardi.'.PHP_EOL;
	}
	
	/**
	 * @return Phrase to show when there is a syntax error in command.
	 */
	private function info_wrongCommandSyntax(){
		return 'La sintassi del comando non è corretta.'.PHP_EOL;
	}
	
	/**
	 * @return Phrase to show when station id is wrong.
	 */
	private function info_wrongStationId(){
		return 'L\'identificatore della stazione non è corretto.'.PHP_EOL;
	}
	
	/**
	 * @return Phrase to be displayed on failed location save.
	 */
	private function info_inexistentCommandForHelp(){
		$s='Il comando indicato non esiste.'.PHP_EOL;
		return $s.=$this->getAvailableCommandsPhrase();
	}
	
	/**
	 * @return Phrase to be displayed on inexistent command request.
	 */
	private function  info_inexistentCommand(){
		$s='Il comando inserito non esiste.'.PHP_EOL;
		return $s.=$this->getAvailableCommandsPhrase();
	}
	
	/**
	 * @param $rangeMeters Radius in meters. 
	 * @return Phrase to be displayed when no stations were found in indicated range.
	 */
	private function info_noStationsInRange($rangeMeters){
		return 'Non ci sono stazioni nel raggio di '.$this->show_distance($rangeMeters).' dalla tua posizione.'.PHP_EOL;
	}
	
	/**
	 * @param $rangeMeters Radius in meters. 
	 * @return Phrase to be displayed when already subscribed to all of the stations in the indicated range.
	 */
	private function info_alreadySubscribedToStationsInRange($rangeMeters){
		return 'Sei già iscritto a tutte le stazioni nel raggio di '.$this->show_distance($rangeMeters).' dalla tua posizione.'.PHP_EOL;
	}
	
	/**
	 * @return Phrase to show on generic (like connection) errors.
	 */
	private function info_genericError(){
		return "Si è verificato un errore, riprova più tardi.";
	}
	
	/**
	 * @return Phrase to show when station id is wrong.
	 */
	private function info_inexistentStationId(){
		return 'Non esiste una stazione con l\'identificatore indicato.'.PHP_EOL;
	}
	
	/**
	 * @return Phrase to show when station was filtered by zone filter.
	 */
	private function info_stationFilteredByZone(){
		return 'La stazione indicata è esclusa dalle stazioni gestite dal bot.'.PHP_EOL;
	}

	/**
	 * @return Phrase to show when station was filtered by zone filter.
	 */
	private function info_notPM10StationId(){
		return 'La stazione indicata non ha le rilevazioni di PM10.'.PHP_EOL;
	}
	
	/**
	 * @return Phrase to be displayed when user try to register twice to the same station.
	 */
	private function info_registrationDuplicate(){
		return 'Stai già seguendo questa stazione.'.PHP_EOL;
	}

	/**
	 * @return Phrase to be displayed on successful registration to the station.
	 */
	private function info_registrationSuccess(){
		return 'Iscrizione avvenuta con successo.'.PHP_EOL;
	}
	
	/**
	 * @return Phrase to be displayed on successful registration to the station.
	 */
	private function info_notRegisteredToAnyStation(){
		return 'Non sei registrato ad alcuna stazione.'.PHP_EOL;
	}  
	
	/**
	 * @return Phrase to be displayed when user try to unsubscribe from not subscribed station.
	 */
	private function info_notRegistered(){
		return 'Non sei registrato alla stazione indicata.'.PHP_EOL;
	}

	/**
	 * @return Phrase to be displayed on successful unsubscription.
	 */
	private function info_unRegistrationSuccess(){
		return 'Non segui più la stazione indicata.'.PHP_EOL;
	}
	
	/**
	 * @return Phrase to be displayed on successful invocation of the command "stop".
	 */
	private function info_stopSuccessful(){
		$s ='Sei stato disiscritto da tutte le stazioni.'.PHP_EOL;
		$s.='Non riceverai più le notifiche.'.PHP_EOL;
		return $s;
	}
	
	/**
	 * @return Phrase to be displayed on unsuccessful invocation of the command "stop".
	 */
	private function info_stopNothingToDelete(){
		return 'Non hai nessun dato da cancellare.'.PHP_EOL;
	}
	
	
	//STRINGS TO USER - END//
	
	
	//SUPPORT FUNCTIONS - BEGIN//
	
	
	/**
	 * @param $telegramUserId User identifier given by Telegram.
	 * @return Associative array with 'latitude' and 'longitude' of the location  found in database. Empty array if the location isn't found.
	 */
	private function getLocation($telegramUserId){
		$location = array();
		include('connect_DB.php');
		if(!$mysqli->connect_error){
			if ($result = $mysqli->query("CALL BotPM10_p_getLocation(\"$telegramUserId\")")) {
				if($row = $result->fetch_assoc()){
					$location['latitude']= $row['latitude'];
					$location['longitude']= $row['longitude'];
				}else{
					//User not registered.
				}
			}else{
				//Error on procedure call.
				$this->logProcedureCallError(__LINE__,__FUNCTION__,$mysqli);//LOG
			}
		}else{
			//Error on database connection.
			$this->logDatabaseConnectionError(__LINE__,__FUNCTION__,$mysqli);//LOG
		}
		$mysqli->close();
		return $location;
	}
	
	
	/**
	 * Used to add to &$stations_info 'registered' indication.
	 * @param &$stations_info Associative array with stations info.
	 * @param $registeredStationsIds Array with id of registered stations.
	 */
	private function enrichWithRegistrationInfo(&$stations_info, $registeredStationsIds){
		foreach($stations_info as &$station_info){
			$station_info['registered']=in_array($station_info['codseqst'], $registeredStationsIds);
		}
	}
	
	
	/**
	 * @param $telegramUserId User identifier. 
	 * @return Array of identifiers of the stations subscribed by the specified user.
	 */
	private function getRegistrations($telegramUserId){
		$a;
		include('connect_DB.php');
		if(!$mysqli->connect_error){
			if ($result = $mysqli->query("CALL BotPM10_p_getRegistrations(\"$telegramUserId\")")) {
				$a = array();
				$i = 0;
				while($row = $result->fetch_assoc()){
					$a[$i]= (int)$row['stationId'];
					++$i;
				}//while
			}else{
				$a=NULL;
				//Error on procedure call.
				$this->logProcedureCallError(__LINE__,__FUNCTION__,$mysqli);//LOG
			}
		}else{
			$a=NULL;
			//Database connection error.
			$this->logDatabaseConnectionError(__LINE__,__FUNCTION__,$mysqli);//LOG
		}
		$mysqli->close();
		return $a;
	}
	
	
	/**
	 * Used to remove from the array of stations stations out of the given radius.
	 * @param &$stations Associative array of stations ordered in ascending order of distance.
	 * @param $range Maximum radius of acceptance of the stations.
	 */
	private function filterStationsNotInRange(&$stations, $range){
		$continue = true;
		$count = count($stations);
		for($i=0; $i<$count && $continue; $i++){
			if($stations[$i]['distance']>$range){
				$stations = array_slice($stations, 0, $i);
				$continue = false;
			}
		}
	}
	
	
	/**
	 * @param $dataTime String with data and time. Like: "2016-10-27 13:32:30".
	 * @return String with data. Like: "2016-10-27".
	 */
	private function getDataFromDataTime($dataTime){
		$dataBollettinoPieces = explode(' ', $dataTime);
		return count($dataBollettinoPieces)===2 ? $dataBollettinoPieces[0] : '';
	}
	
	/**
	 * @param $station_datas data from a station.
	 * @return Associative array with PM10 data, or empty array.
	 */
	private function getPM10fromStationData($station_datas){
		$PM10_data = array();
		if(!empty($station_datas)){
			foreach($station_datas as $station_data){
				if(isset($station_data['pm10'])){
					$PM10_data = $station_data['pm10'];
				}
			}
		}
		return $PM10_data;
	}
	
	
	/**
	 * @param $stationId Identifier of the station.
	 * @param $statios_info Associative array with stations info.
	 * @return Associative array with info about the station.
	 */
	private function getStationInfo($stationId, $statios_info){
		foreach($statios_info as $stationKey => $station){
			if($station['codseqst']===(string)$stationId){
				return $station;
			}
		}
		return array();
	}
	
	/**
	 * Used to get data of a station.
	 * @param $stationId Identifier of the station.
	 * @param $stations_data Associative array with stations data.
	 * @return Data from the indicated station.
	 */
	private function getStationData($stationId, $stations_data){
		foreach($stations_data as $stationKey => $station){
			if($station['codseqst']===$stationId){
				return $station['misurazioni'];
			}
		}
		return array();
	}
	
	
	/**
	 * Used to remove from the array &$stations, the stations that doesn't have identifier in $stationsId matrix.
	 * @param &$stations Associative array of stations.
	 * @param $stationsId Array of stations identifiers.
	 */
	private function filterStationsNotInIdArray(&$stations_info, $stationsId){
		$a=array();
		$a = array_filter($stations_info, function($elem) use($stationsId){
			return in_array($elem['codseqst'], $stationsId);
		});
		$stations_info=array_values($a);
	}
	
	
	/**
	 * Used to remove from the array &$stations, the stations that have identifier in $stationsId matrix.
	 * @param &$stations Associative array of stations.
	 * @param $stationsId Array of stations identifiers.
	 */
	private function filterStationsInIdArray(&$stations_info, $stationsId){
		$a=array();
		$a = array_filter($stations_info, function($elem) use($stationsId){
			return !in_array($elem['codseqst'], $stationsId);
		});
		$stations_info=array_values($a);
	}
	
	
	/**
	Removes stations with indicated zone type.
	@param &$stations associative array to filter.
	@param $zoneTypesArray zone types to filter.
	*/
	private function filterByZoneType(&$stations, $zoneTypesArray){
		$a=array();
		$a = array_filter($stations, function($elem) use(&$zoneTypesArray){
			return !in_array($elem['tipozona'], $zoneTypesArray);
		});
		$stations=array_values($a);
	}
	
	/**
	 * @param &$stations Associative array with stations to filter.
	 * @param $PM10stations_data 
	 */
	private function filterNotPM10(&$stations, $PM10stations_data){
		$a=array();
		$PM10stationsIds = array_column($PM10stations_data, 'codseqst');
		$a = array_filter($stations, function($elem) use(&$PM10stationsIds){
			return in_array($elem['codseqst'], $PM10stationsIds);
		});
		$stations=array_values($a);
	}
	
	/**
	 * @param $stations_data Associative array with stations data.
	 * @return stations with PM10.
	 */
	private function getPM10stations_data($stations_data){
		$a=array();
		$a = array_filter($stations_data, function($elem){
			return $this->hasPM10($elem['misurazioni']);
		});
		$a = array_values($a);
		return $a;
	}
	
	/**
	 * @param $misurazioni Data of one station.
	 * @return return true if in $misurazioni there is PM10.
	 */
	private function hasPM10($misurazioni){
		if(empty($misurazioni)){
			return false;
		}else{
			foreach($misurazioni as $misurazione){
				if(isset($misurazione['pm10'])){
					return true;
				}
			}
			return false;
		}
	}
	
	
	/**
	 * @param $stationId Identifier of the station.
	 * @param $rightZoneStations_info Associative array with stations info of the stations from not excluded zones.
	 * @return TRUE if indicated station is in given array.
	 */
	private function isInRightZone($stationId, $rightZoneStations_info){
		foreach($rightZoneStations_info as $rightZoneStation_info){
			if($rightZoneStation_info['codseqst'] === $stationId){
				return true;
			}
		}
		return false;
	}
	
	
	/**
	 * @param $stationId Identifier of the station.
	 * @param $PM10stations_data Associative array with stations data of the stations with set PM10.
	 * @return TRUE if indicated station is in given array.
	 */
	private function isPM10station($stationId, $PM10stations_data){
		foreach($PM10stations_data as $PM10station_data){
			if($PM10station_data['codseqst'] === $stationId){
				return true;
			}
		}
		return false;
	}
	
	
	/**
	 * @param $stations_info
	 * @param $zoneTypesArray Zone types, stations with this zone types will be excluded.
	 */
	private function getRightZoneStations_info($stations_info, $zoneTypesArray){
		$a=array();
		$a = array_filter($stations_info, function($elem) use (&$zoneTypesArray){
			return !in_array($elem['tipozona'], $zoneTypesArray);
		});
		$a = array_values($a);
		return $a;
	}
	
	
	/**
	 * Used to sort the stations according to the indicated fields.
	 * @param &$stations Associative array of stations.
	 * @param $fieldsArray Names of the fields.
	 */
	private function orderByFields(&$array, $fieldsArray){
		usort($array, function ($a, $b) use (&$fieldsArray) {
			foreach ($fieldsArray as $field) {
				$diff = strcmp($a[$field], $b[$field]);
				if($diff != 0) {
					return $diff;
				}
			}
			return 0;
		});
	}
	
	
	/**
	 * Used to sort stations in ascending order of distance.
	 * @param &$stations Associative array of stations.
	 */
	private function orderByDistance(&$stations){
		usort($stations, function($a, $b){
			return $b['distance'] < $a['distance'];
		});
	}
	
	
	/**
	 * @param $stationId String that supposedly represents a station identifier.
	 * @return True if the string has the right syntax to represent a station identifier.
	 */
	private function itCanBeValidStationId($stationId){
		return (boolean) preg_match("/^[0-9]{9}$/", ltrim($stationId, '0'));
	}
	
	/**
	 * @param $locationA First location.
	 * @param $locationB Second location.
	 * @return Distance between the two positions in meters.
	 */
	private function calcDistance($locationA, $locationB){
		$a1 = $locationA['latitude'];
		$a2 = $locationA['longitude'];
		$b1 = $locationB['latitude'];
		$b2 = $locationB['longitude'];
		$distanceMeters = $this->vincentyGreatCircleDistance($a1,$a2,$b1,$b2);
		return $distanceMeters;
	}
	
	/**
	 * @param $distanceMeters 
	 * @return Formatted string that represents the distance in km.
	 */
	private function show_distance($distanceMeters){
		$distanceKm = $distanceMeters/1000;
		$formattedDistance;
		if($distanceKm<10){
			$formattedDistance = number_format($distanceKm, 1, '.', '');
		}else{
			$formattedDistance = round($distanceKm);
		}
		return ''.$formattedDistance.' km';
	}
	
	/**
	* Calculates the great-circle distance between two points, with the Vincenty formula.
	* @param float $latitudeFrom Latitude of start point in [deg decimal]
	* @param float $longitudeFrom Longitude of start point in [deg decimal]
	* @param float $latitudeTo Latitude of target point in [deg decimal]
	* @param float $longitudeTo Longitude of target point in [deg decimal]
	* @param float $earthRadius Mean earth radius in [meters]
	* @return float Distance between points in [meters] (same as earthRadius)
	*/
	public static function vincentyGreatCircleDistance($latitudeFrom, $longitudeFrom, $latitudeTo, $longitudeTo, $earthRadius = 6371000){
		$latFrom = deg2rad($latitudeFrom);
		$lonFrom = deg2rad($longitudeFrom);
		$latTo = deg2rad($latitudeTo);
		$lonTo = deg2rad($longitudeTo);
		$lonDelta = $lonTo - $lonFrom;
		$a = pow(cos($latTo) * sin($lonDelta), 2) +
			pow(cos($latFrom) * sin($latTo) - sin($latFrom) * cos($latTo) * cos($lonDelta), 2);
		$b = sin($latFrom) * sin($latTo) + cos($latFrom) * cos($latTo) * cos($lonDelta);
		$angle = atan2(sqrt($a), $b);
		return $angle * $earthRadius;
	}
	
	/**
	 * @return String representing the URL of the file containing descriptions of the stations.
	 */
	private function getStationsURL(){
		return 'http://192.168.230.31/aria-json/exported/aria/stats.json';
	}
	
	/**
	 * @return String representing the URL of the file containing coordinates of the stations.
	 */
	private function getCoordinatesURL(){
		return 'http://192.168.230.31/aria-json/exported/aria/coords.json';
	}
	
	/**
	 * @return String representing the URL of the file containing data from the stations.
	 */
	private function getDataURL(){
		return 'http://192.168.230.31/aria-json/exported/aria/data.json';
	}
	
	/**
	 * @return String representing the URL of the file containing validated data from the stations.
	 */
	private function getValidatedDataURL($provincia){
		return 'http://xmlenabler.arpa.veneto.it/xml/tabella_cop?provincia='.strtoupper($provincia);
	}
	
	/**
	 * Used to output messages to the system.
	 * @param $str message.
	 */
	public function log($str){
		error_log('[ '.date("Y-m-d H:i:s").' ] '.$str.PHP_EOL, 3, "php://stdout");
	}
	
	/**
	 * Used to output a database connection error.
	 * @param $lineNumber number of the line in source code.
	 * @param $functionName name of the function where the error occurred.
	 * @param $mysqli handle to mysqli connection.
	 */
	private function logDatabaseConnectionError($lineNumber, $functionName, $mysqli){
		$this->log('Function: '.$functionName.': error on connection to database. Line: '.$lineNumber.PHP_EOL.'Connection failed: ('.$mysqli->errno.') '.$mysqli->error);//LOG
	}
	
	/**
	 * Used to output a database connection error.
	 * @param $lineNumber number of the line in source code.
	 * @param $functionName name of the function where the error occurred.
	 * @param $mysqli handle to mysqli connection.
	 */
	private function logProcedureCallError($lineNumber, $functionName, $mysqli){
		$this->log('Function: '.$functionName.': error on procedure call. Line: '.$lineNumber.PHP_EOL.'Call failed: ('.$mysqli->errno.') '.$mysqli->error);//LOG
	}
	
	/**
	 * Used to display a retrieval failure of a MySQL variable.
	 * @param $lineNumber number of the line in source code.
	 * @param $functionName name of the function where the error occurred.
	 * @param $mysqli handle to mysqli connection.
	 */
	private function logMysqlVariableFetchError($lineNumber, $functionName, $mysqli){
		$this->log('Function: '.$functionName.': error on fetch from Mysql variable. Line: '.$lineNumber.PHP_EOL.'Fetch failed: ('.$mysqli->errno.') '.$mysqli->error);//LOG
	}
	
	/**
	 * Used to generate a log.
	 */
	private function logFunctionLine($logMessage, $lineNumber, $functionName){
		$this->log('Function: '.$functionName.'; Line: '.$lineNumber.'; '.$logMessage);//LOG
	}
	
	/**
	 * Used to display a XML error.
	 * @param $lineNumber number of the line in source code.
	 * @param $functionName name of the function where the error occurred.
	 * @param $xml_url url to xml file.
	 */
	private function logXmlError($lineNumber, $functionName, $xml_url){
		$this->log('Function: '.$functionName.'; Line: '.$lineNumber.'; Failed to open XML at URL: '.$xml_url);//LOG
	}
	
	/**
	 * Used by functions having function name composed of two prats: [function type]_[command].
	 * @param $callerFunctionName name of the function given by _FUNCTION_ magic constant.
	 * @return String which represents the command.
	 */
	private function getCommandNameFromFunctionName($callerFunctionName){
		$arr=explode('_', $callerFunctionName);
		return end($arr);
	}
	
	/**
	 * Used to get syntax for a command.
	 * @param $commandName Name of the command (method that describes the syntax for the given command must exist).
	 * @return return message obtained from invocation of the method that describes the syntax of the command.
	 */
	private function getSyntaxForCommand($commandName){
		$method = 'syntax_'.$commandName;
		return $this->$method();
	}
	
	/**
	 * Used to get description for a command.
	 * @param $commandName Name of the command (method that gives description for the given command must exist).
	 * @return return message obtained from invocation of the method that describes the command.
	 */
	private function getDescriptionForCommand($commandName){
		$method = 'description_'.$commandName;
		return $this->$method();
	}
	
	/**
	 * Used to test if given distance is in acceptable range.
	 * @param $number representing distance in km.
	 * @return TRUE if $rangeKm is in ]0,2000] range, FALSE otherwise.
	 */
	private function isInRangeKm($rangeKm){
		return ($rangeKm>0 && $rangeKm<=2000);
	}
	
	/**
	 * Used to get number of km from given string.
	 * @param $candidateKmStr string that presumably represents a number of km. 
	 * @return Float number if the given string represents a valid number, FALSE otherwise.
	 */
	private function getValidKm($candidateKmStr){
		$candidateKmStr = str_replace(',', '.', $candidateKmStr);
		$candidateKmStr = preg_replace('/[^0-9.]/', '', $candidateKmStr);
		if(is_numeric($candidateKmStr)){
			return (float)$candidateKmStr;
		}else{
			return FALSE;
		}
	}
	
	/**
	 * @param $stationId Identifier of the station.
	 * @param $stationsCoordinates Coordinates of the station.
	 * @return Location of the indicated station.
	 */
	private function getStationLocation($stationId, $stationsCoordinates){
		$coordinate = array();
		$key = array_search($stationId, array_column($stationsCoordinates, 'codseqst'));
		if($key !== FALSE){
			$stationCoordinate = $stationsCoordinates[$key];
			$coordinate['latitude'] = $stationCoordinate['lat'];
			$coordinate['longitude'] = $stationCoordinate['lon'];
		}
		return $coordinate;
	}
	
	
	//SUPPORT FUNCTIONS - END//
	
	
	//ARRAYS FUNCTIONS - BEGIN//
	
	
	private function getStationsCoordinates(){
		$coordinates = array();
		$coordinates_url = $this->getCoordinatesURL();
		$coordinates_encoded = file_get_contents($coordinates_url);
		if($coordinates_encoded !== FALSE){
			$coordinates = json_decode($coordinates_encoded, true);
			if(json_last_error() === JSON_ERROR_NONE){
				//File successfuly decoded.
				$coordinates = $coordinates["coordinate"];
			}else{
				$coordinates = array();
				$this->logFunctionLine('Fail to decode file at URL: '.$coordinates_url.' Probably it\'s malformed.',__LINE__,__FUNCTION__);//LOG
			}
		}else{
			$this->logFunctionLine('Cannot get file at URL: '.$coordinates_url,__LINE__,__FUNCTION__);//LOG
		}
		return $coordinates;
	}
	
	
	/**
	 * @return Associative array with all the stations.
	 */
	private function getStationsInfo(){
		$stations = array();
		$stations_url = $this->getStationsURL();
		$stations_encoded = file_get_contents($stations_url);
		if($stations_encoded !== FALSE){
			$stations = json_decode($stations_encoded, true);
			if(json_last_error() === JSON_ERROR_NONE){
				//File successfuly decoded.
				$stations = $stations["stazioni"];
			}else{
				$stations = array();
				$this->logFunctionLine('Fail to decode file at URL: '.$stations_url.' Probably it\'s malformed.',__LINE__,__FUNCTION__);//LOG
			}
		}else{
			$this->logFunctionLine('Cannot get file at URL: '.$stations_url,__LINE__,__FUNCTION__);//LOG
		}
		return $stations;
	}
	
	/**
	 * @param &$stations_info Informations about stations.
	 * @param $coordinates Coordinates of the stations.
	 * @param $location Location.
	 */
	private function enrichWithDistance(&$stations_info, $coordinates, $location){
		$latitudes = array_column($coordinates, 'lat', 'codseqst');
		$longitudes = array_column($coordinates, 'lon', 'codseqst');
		foreach($stations_info as &$station){
			$stationId = $station['codseqst'];
			$stationLatitude = $latitudes[$stationId];
			$stationLongitude = $longitudes[$stationId];
			$distance = $this->vincentyGreatCircleDistance($location['latitude'], $location['longitude'], $stationLatitude, $stationLongitude);
			$station['distance'] = $distance;
		}
	}
	
	
	/**
	 * @return Data of all the stations.
	 */
	public function getStationsData(){
		$statios_data = array();
		$data_url = $this->getDataURL();
		$data_encoded = file_get_contents($data_url);
		if($data_encoded !== FALSE){
			$data = json_decode($data_encoded, true);
			if(json_last_error() === JSON_ERROR_NONE){
				//File successfuly decoded.
				$statios_data = $data['stazioni'];
			}else{
				$this->logFunctionLine('Fail to decode file at URL: '.$data_url.' Probably it\'s malformed.',__LINE__,__FUNCTION__);//LOG
			}
		}else{
			$this->logFunctionLine('Cannot get file at URL: '.$data_url,__LINE__,__FUNCTION__);//LOG
		}
		return $statios_data;
	}
	
	
	/**
	 * Used to get stations that have at least one subscriber.
	 * @param &$subscribedStations Empty array, that will be filled with the identifiers of the stations as Keys and 'lastNotificationDate' as Values.
	 * @param &$nullStationsIds Empty array, will be filled with identifiers of the stations that have the notification date set to null.
	 */
	private function getSubscribedStations(&$subscribedStations, &$nullStationsIds) {
		include('connect_DB.php');
		if(!$mysqli->connect_error){
			if($res=$mysqli->query("CALL BotPM10_p_getLastNotificationDate")){
				$i=0;
				while($row = $res->fetch_assoc()){
					if(is_null($row['lastNotificationDate'])){
						$nullStationsIds[$i] = $row['stationId'];
						++$i;
					}else{
						$subscribedStations[$row['stationId']] = (string)$row['lastNotificationDate'];
					}
				}
			}else{
				//Error on procedure call.
				$this->logProcedureCallError(__LINE__,__FUNCTION__,$mysqli);//LOG
			}
		}else{
			//Error on database connection.
			$this->logDatabaseConnectionError(__LINE__,__FUNCTION__,$mysqli);//LOG
		}
		$mysqli->close();
	}
	
	
	//  ARRAYS FUNCTIONS - END  //
	
	
	//  NOTIFICATION FUNCTIONS - BEGIN  //
	
	
	/**
	 * Check if the validated data of the stations signed by someone have changed.
	 * And for every station (with changed data) sends a message to all of the users subscribed to it.
	 */
	private function sendInfoToFollowers() {
		$nullStationsIds = array();
		$subscribedStations = array();
		$this->getSubscribedStations($subscribedStations, $nullStationsIds);
		$notNotifiedStationsIds = $this->getNotNotifiedStationsIds($subscribedStations);
		$stationsToCheckIds = array_merge($notNotifiedStationsIds,$nullStationsIds);
		$stationsToSilentlySave = array();
		$stationsToNotify = $this->getStationsToNotify($stationsToCheckIds, $stationsToSilentlySave);
		/* Save on db new states. */
		$this->saveCriticalityStates(array_merge(array_column($stationsToNotify, 'codseqst'), $stationsToSilentlySave));
		/* Send messages to all followers of each station */
		$this->sendNotificationMessages($stationsToNotify);
	}
	
	
	/**
	 * Send a message to all users registered to the indicated stations.
	 * @param $stationsToNotify Associative array.
	 */
	private function sendNotificationMessages($stationsToNotify){
		foreach ($stationsToNotify as $stationToNotify) {
			$stationId = $stationToNotify['codseqst'];
			$message = $this->info_getRankBasedMessage($stationToNotify['rank'], $stationToNotify['stationName'], $stationId, $stationToNotify['dataBollettino'], $stationToNotify['dataRif']);
			$this->sendNotificationMessagesToStationSubscribers($stationId, $message);
		}
	}
	
	
	/**
	 * Sends a message to each subscriber of the indicated station.
	 * @param $stationId Identifier of the station. 
	 * @param $message.
	 */
	private function sendNotificationMessagesToStationSubscribers($stationId, $message){
		$stationSubscribersChats = $this->getStationSubscribersChats($stationId);
		foreach($stationSubscribersChats as $chat_id){
			$this->sendTextMessage($message, $chat_id);
		}
	}
	

	/**
	 * Used to generate message of notification.
	 * @param $rank Rank of the concenration.
	 * @param $stationName Name of the station.
	 * @param $stationId Identifier of the station.
	 * @param $dataBollettino Date of generation of the data.
	 * @param $dataRif Date related to the data.
	 * @return String with notification message.
	 */
	private function info_getRankBasedMessage($rank, $stationName, $stationId, $dataBollettino, $dataRif){
		$s = '';
		if($rank === 1){
			$sInfo='La concentrazione di PM10 della stazione ha superato il valore limite giornaliero di '.$this->PM10criticalConcentration1.' µg/m³.'.PHP_EOL;
		}elseif($rank === 2){
			$sInfo='Superata la concentrazione doppia di PM10 ('.$this->PM10criticalConcentration2.' µg/m³) del limite giornaliero ('.$this->PM10criticalConcentration1.' µg/m³).'.PHP_EOL;
		}
		$s.='[Notifica]'.PHP_EOL;
		$s.='<b>Stazione</b>: '.$stationName.PHP_EOL;
		$s.='/Stazione_'.$stationId.PHP_EOL;
		$s.='<b>Bollettino del</b>: '.$dataBollettino.PHP_EOL;
		$s.='<b>Dati riferiti al</b>: '.$dataRif.PHP_EOL;
		$s.=$sInfo;
		return $s;
	}
	
	
	/**
	 * @param $stationId Identifier of a station.
	 * @return Array with the identifiers of the chats of the subscribers of the indicated station.
	 */
	private function getStationSubscribersChats($stationId){
		$stationSubscribersChats = array();
		include('connect_DB.php');
		if(!$mysqli->connect_error){
			if($res = $mysqli->query("CALL BotPm10_p_getStationSubscribersChats(\"$stationId\")")){
				while($row = $res->fetch_assoc()){
					array_push($stationSubscribersChats, (int)$row['chatId']);
				}
			}else{
				//Error on procedure call.
				$this->logProcedureCallError(__LINE__,__FUNCTION__,$mysqli);//LOG
			}
		}else{
			//Error on database connection.
			$this->logDatabaseConnectionError(__LINE__,__FUNCTION__,$mysqli);//LOG
		}
		$mysqli->close();
		return $stationSubscribersChats;
	}
	
	
	/**
	 * @param $stationsToCheckIds Identifiers of the staions.
	 * @param $stationsToSilentlySave Empty array, will be filled with identifiers of the stations that don't require notification.
	 * @return Stations to notify. 
	 */
	private function getStationsToNotify($stationsToCheckIds, &$stationsToSilentlySave){
		$i=0;
		$stationsToNotify = array();
		$stations_info = $this->getStationsInfo();
		$stationsValidatedData = array(); 
		if(!empty($stations_info)){
			foreach($stationsToCheckIds as $stationToCheckId){
				$stationInfo = $this->getStationInfo($stationToCheckId, $stations_info);
				if(!empty($stationInfo)){
					$provincia = $stationInfo['provincia'];
					$stationValidatedData = array();
					if(isset($stationsValidatedData[$provincia])){
						$stationValidatedData = $this->getStationValidatedData($stationToCheckId, $stationsValidatedData[$provincia]);
					}else{
						$xml_url = $this->getValidatedDataURL($provincia);
						$xml = simplexml_load_file($xml_url);
						if($xml){
							$stationsValidatedData[$provincia] = $xml;
							$stationValidatedData = $this->getStationValidatedData($stationToCheckId, $xml);
						}else{
							//Failed to open XML file with validated data.
							$this->logXmlError(__LINE__,__FUNCTION__,$xml_url);//LOG
						}
					}
					if(!empty($stationValidatedData)){
						$validationDate = $this->getDataFromDataTime((string)$stationValidatedData->DATA_VALIDAZIONE);
						if($validationDate === date("Y-m-d")){
							$concPM10 = (string)$stationValidatedData->CONC_PM10;
							if(is_numeric($concPM10)){
								$PM10rank = $this->getPM10rank($concPM10);
								if($PM10rank !== 0){
									$stationsToNotify[$i]['codseqst']= $stationToCheckId;
									$stationsToNotify[$i]['rank']= $PM10rank;
									$stationsToNotify[$i]['stationName']= (string)$stationValidatedData->STATNM;
									$dataBollettino = $this->getDataFromDataTime((string)$stationValidatedData->DATA_BOLLETTINO);
									$stationsToNotify[$i]['dataBollettino']= $dataBollettino;
									$dataRif = $this->getDataFromDataTime((string)$stationValidatedData->DATA_RIF);
									$stationsToNotify[$i]['dataRif']= $dataRif;
									++$i;
								}else{
									array_push($stationsToSilentlySave, $stationToCheckId);
								}
							}else{
								array_push($stationsToSilentlySave, $stationToCheckId);
							}
						}else{
							//not fresh data
						}
					}else{
						$this->logFunctionLine('No validated data for the station with id: '.$stationToCheckId,__LINE__,__FUNCTION__);//LOG
					}
				}else{
					$this->logFunctionLine('No station found with id:'.$stationToCheckId.' in the file with the stations info.',__LINE__,__FUNCTION__);//LOG
				}
			}
		}else{
			$this->logFunctionLine('No station found, the file with the stations info is probably empty.',__LINE__,__FUNCTION__);//LOG
		}
		return $stationsToNotify;
	}
	
	
	/**
	 * @param $concPM10 Concentration of PM10.
	 * @return Number representing rank of the concentration.
	 */
	private function getPM10rank($concPM10){
		$rank;
		if($concPM10 > $this->PM10criticalConcentration2){
			$rank = 2;
		}elseif($concPM10 > $this->PM10criticalConcentration1){
			$rank = 1;
		}else{
			$rank = 0;
		}
		return $rank;
	}
	
	/**
	 * USed to get data of indicated station.
	 * @param $stationId Identifier of the station.
	 * @param $stationsValidatedData_provincia Validated data of the stations.
	 * @return Validated data of the indicated station.
	 */
	private function getStationValidatedData($stationId, $stationsValidatedData_provincia){
		$stationValidatedData = array();
		$stationValidatedDataRaw = $stationsValidatedData_provincia->xpath('//row[CODSEQST="'.$stationId.'"]');
		if(!empty($stationValidatedDataRaw)){
			$stationValidatedData = $stationValidatedDataRaw[0];
		}else{
			$this->logFunctionLine('No station found with id:'.$stationId.' in the file with the stations validated data.',__LINE__,__FUNCTION__);
		}
		return $stationValidatedData;
	}
	
	
	/**
	 * Used to get stations not yet notified today.
	 * @param $subscribedStations Stations with the date of the last notification information.
	 * @return Array with identifiers of the stations that have the date of the last notification not the same as today's date.
	 */
	private function getNotNotifiedStationsIds($subscribedStations){
		$stationsIds = array();
		$i = 0;
		$currentDate = date("Y-m-d");
		foreach($subscribedStations as $subscribedStationId => $lastNotificationDate){
			if($lastNotificationDate !== $currentDate){
				$stationsIds[$i]=$subscribedStationId;
				++$i;
			}
		}
		return $stationsIds;
	}
	
	
	/**
	 * Save on database, the new notification date.
	 * @param $notificatedStationsIds Array with id of the stations.
	 */
	private function saveCriticalityStates($stationsIds){
	  include('connect_DB.php');
		if(!$mysqli->connect_error){
			foreach ($stationsIds as $stationId) {
				$currentDate = date("Y-m-d");
				if ($mysqli->query("CALL BotPM10_p_setLastNotificationDate(\"$stationId\", \"$currentDate\")")){
				}else{
					//Error on procedure call.
					$this->logProcedureCallError(__LINE__,__FUNCTION__,$mysqli);//LOG
				}
			}
		}else{
			//Error on database connection.
			$this->logDatabaseConnectionError(__LINE__,__FUNCTION__,$mysqli);//LOG
		}
		$mysqli->close();
	}
	
	
	//NOTIFICATION FUNCTIONS - END//
	
	
	//CHART GENERATION FUNCTIONS - BEGIN//
	
	
	/**
	 * Used to generate chart in location {dirname(__FILE__).'/chart.png'}.
	 * @param $PM10_datas Associative array with PM10 data.
	 * @param $stationName Name of the staion.
	 */
	private function generateChart($PM10_datas, $stationName){
		$dates = array();
		$measurements = array();
		foreach ($PM10_datas as $i => $PM10_data){
			$dates[$i] = $this->getDataFromDataTime($PM10_data['data']);
			$tempMeasure = $PM10_data['mis'];
			if($tempMeasure == "" || $tempMeasure == '0'){
				$measurements[$i] = VOID;
			}else{
				$measurements[$i] = round($PM10_data['mis']);
			}
		}
		
		/* Create your dataset object */ 
		$myData = new pData(); 
		
		/* Add data in your dataset */ 
		$myData->addPoints($measurements, "measurements");
		$myData->addPoints($dates,"dates");
		$myData->setAbscissa("dates");
		
		/* Create a pChart object and associate your dataset */ 
		$myPicture = new pImage(700,410,$myData);
		
		/* Font */
		$fontLocation = ''.dirname(__FILE__).'/pChart/fonts/calibri.ttf';
		$myPicture->setFontProperties(array("FontName"=>$fontLocation,"FontSize"=>11));
		
		/* Draw the background */
		$Settings1 = array("R"=>86, "G"=>180, "B"=>233, "Dash"=>1, "DashR"=>86, "DashG"=>200, "DashB"=>233);
		$myPicture->drawFilledRectangle(0,0,700,410,$Settings1);
		
		/* Background for graphic */
		$myPicture->drawFilledRectangle(50,50,650,360,array("R"=>255,"G"=>255,"B"=>255,"Surrounding"=>-200,"Alpha"=>10));
		$myPicture->drawText(350,35, $stationName.' - Concentrazione di PM10',array("FontSize"=>20,"Align"=>TEXT_ALIGN_BOTTOMMIDDLE));
		
		/* Define the boundaries of the graph area */
		$myPicture->setGraphArea(50,50,650,360);

		/* Draw the scale */
		$ScaleSettings = array("Mode"=>SCALE_MODE_START0);
		$myPicture->drawScale($ScaleSettings);
		
		$myPicture->setShadow(TRUE,array("X"=>1,"Y"=>1,"R"=>0,"G"=>0,"B"=>0,"Alpha"=>20));
		$maxValue = $myData->getMax('measurements');
		if($maxValue>=$this->PM10criticalConcentration1){
			$myPicture->drawThresholdArea($this->PM10criticalConcentration1,$this->PM10criticalConcentration2,array("R"=>226,"G"=>194,"B"=>54,"Alpha"=>40,"NoMargin"=>TRUE));
			if($maxValue>=$this->PM10criticalConcentration2){
				$myPicture->drawThresholdArea($this->PM10criticalConcentration2,1000,array("R"=>226,"G"=>150,"B"=>30,"Alpha"=>40,"NoMargin"=>TRUE));
			}
		}
		
		$myPicture->setShadow(FALSE);
		/* Unit y axis. */
		$TextSettings = array("R"=>0,"G"=>0,"B"=>0,"Angle"=>0,"FontSize"=>13);
		$myPicture->drawText(10,30,"μg/m³",$TextSettings);
		
		/* Unit x axis. */
		$TextSettings = array("R"=>0,"G"=>0,"B"=>0,"Angle"=>0,"FontSize"=>13);
		$myPicture->drawText(650,390,"Data",$TextSettings);
		
		$myPicture->drawBarChart(array("DisplayValues"=>TRUE,"Rounded"=>TRUE,"Surrounding"=>60));
		
		/* Build the PNG file */ 
		$myPicture->Render(dirname(__FILE__).'/chart.png');
	}
	
	
	//CHART GENERATION FUNCTIONS - END//
	
	
}
