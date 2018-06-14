<?
define('__ROOT__', dirname(dirname(__FILE__)));
require_once (__ROOT__.'/Netatmo/src/Netatmo/autoload.php');


    // Klassendefinition
    class Netatmo extends IPSModule {
    
    private $client;
    private $tokens;     	
    private $refresh_token;
    private $access_token;
    private $deviceList;
    private $echoString;
        
        // Der Konstruktor des Moduls
        // Überschreibt den Standard Kontruktor von IPS
        public function __construct($InstanceID) {
            // Diese Zeile nicht löschen
            parent::__construct($InstanceID);
        }
 
        // Überschreibt die interne IPS_Create($id) Funktion
        public function Create() {
            // Diese Zeile nicht löschen.
        	parent::Create();
		$this->RegisterPropertyString("username", "");
		$this->RegisterPropertyString("password", "");
		$this->RegisterPropertyString("client_id", "");
		$this->RegisterPropertyString("client_secret", "");
		$this->RegisterPropertyBoolean("logging", false);
		$this->RegisterPropertyInteger("archive", 0);
		$this->RegisterTimer("ReadNetatmo", 300, 'NAW_SaveData($_IPS[\'TARGET\']);');
        }
 
        // Überschreibt die intere IPS_ApplyChanges($id) Funktion
        public function ApplyChanges() 
        {
            	// Diese Zeile nicht löschen
            	parent::ApplyChanges();
    		//  IPS_LogMessage(__CLASS__, __FUNCTION__); //                   
     		//  IPS_LogMessage('Config', print_r(json_decode(IPS_GetConfiguration($this->InstanceID)), 1));
   		$this->PrepareConnection();
        }
 
	private function PrepareConnection() 
	{
 		global $client;
    		global $tokens;     	
    		global $refresh_token;
    		global $access_token;
    		global $logging;
    		global $archive;
    		try
		{
    		
    		$logging = $this->ReadPropertyBoolean("logging");	
    		$archive = $this->ReadPropertyInteger("archive");	
		$config = array();
		$config['client_id'] = $this->ReadPropertyString("client_id");
		$config['client_secret'] = $this->ReadPropertyString("client_secret");
		//application will have access to station and theromstat
		$config['scope'] = "read_station";
	    $client = new Netatmo\Clients\NAWSApiClient($config);
    		
    		$username = $this->ReadPropertyString("username");
		$pwd = $this->ReadPropertyString("password");
		$client->setVariable("username", $username);
		$client->setVariable("password", $pwd);

		//Authentication with Netatmo server (OAuth2)
		$tokens = $client->getAccessToken();
    			$this->SetStatus(102);// login OK
		}
		catch(Exception $ex)
		{
			$this->SetStatus(201);// login failed
			$this->handleError("An error happened while trying to retrieve your tokens: " .$ex."\n", TRUE); 
	     		// IPS_LogMessage(__CLASS__, "ALL OK !!!!");
		
     		}
	}
 
	//ShowData
	public function ShowData() 
	{
		global $echoString;
		global $client;
    		global $tokens ;     	
    		global $refresh_token ;
    		global $access_token ;
		global $deviceList;
    	
		$this->PrepareConnection();	
		//Retrieve user's Weather Stations Information
		try
		{
			 //retrieve all stations belonging to the user, and also his favorite ones
    			$data = $client->getData(NULL, TRUE);
    			$this->printMessageWithBorder("Weather Stations Basic Information");
		}
		catch(NAClientException $ex)
		{
    			$this->handleError("An error occured while retrieving data: ". $ex->getMessage()."\n", TRUE);
		}
		if(empty($data['devices']))
		{
    			$this->echoLog('No devices affiliated to user');
		}
		else
		{
    			$users = array();
    			$friends = array();
    			$fav = array();
    			$device = $data['devices'][0];
    			$tz = isset($device['place']['timezone']) ? $device['place']['timezone'] : "GMT";
    			//devices are already sorted in the following way: first weather stations owned by user, then "friend" WS, and finally favorites stations. Still let's store them in different arrays according to their type
    			foreach($data['devices'] as $device)
    			{
        			//favorites have both "favorite" and "read_only" flag set to true, whereas friends only have read_only
        			if(isset($device['favorite']) && $device['favorite'])
        			{
            				$fav[] = $device;
        			}
        			else if(isset($device['read_only']) && $device['read_only']){
        				$friends[] = $device;	
        			}
        			else 
        			{
        				$users[] = $device;
        			}
    			}
    			//print first User's device Then friends, then favorite
    			$this->printDevices($users, "User's weather stations");
    			$this->printDevices($friends, "User's friends weather stations");
    			$this->printDevices($fav, "User's favorite weather stations");
    			// now get some daily measurements for the last 30 days
     			$type = "temperature,Co2,humidity,noise,pressure";
    			//first for the main device
    			try
    			{
        			$measure = $client->getMeasure($device['_id'], NULL, "1day" , $type, time() - 24*3600*30, time(), 30,  FALSE, FALSE);
        			$this->printMeasure($measure, $type, $tz, $device['_id'] ."'s daily measurements of the last 30 days");
    			}
    			catch(NAClientException $ex)
    			{
        			$this->handleError("An error occured while retrieving main device's daily measurements: " . $ex->getMessage() . "\n");
    			}
    			//Then for its modules
    			foreach($device['modules'] as $module)
    			{
        			//requested data type depends on the module's type
        			switch($module['type'])
        			{
            				case "NAModule3": $type = "sum_rain";
                              			break;
            				case "NAModule2": $type = "WindStrength,WindAngle,GustStrength,GustAngle,date_max_gust";
                              			break;
            				case "NAModule1" : $type = "temperature,humidity";
                               			break;
            				default : $type = "temperature,Co2,humidity,noise,pressure";
        			}
        			try
        			{
            				$measure = $client->getMeasure($device['_id'], $module['_id'], "1day" , $type, time()-24*3600*30 , time(), 30,  FALSE, FALSE);
            				$this->printMeasure($measure, $type, $tz, $module['_id']. "'s daily measurements of the last 30 days ");
        			}
        			catch(NAClientException $ex)
        			{
        				 $this->handleError("An error occured while retrieving main device's daily measurements: " . $ex->getMessage() . "\n");
        			}
    			}			
		}
		//IPS_LogMessage('Netatmo_Modul', $echoString);
		echo "done...siehe Logs";
	}
	

	// SAVE DATA
	public function SaveData() 
	{
		global $client;
    		global $tokens ;     	
    		global $refresh_token ;
    		global $access_token ;
		global $deviceList;
    	
		$this->PrepareConnection();	
	
		$deviceList = $client->api("devicelist");	
		// IPS_LogMessage(__CLASS__, "Devicelist: ". print_r($deviceList ,1));	
		// $this->echoLog(print_r($deviceList));
		//Retrieve user's Weather Stations Information
		try
		{
    			//retrieve all stations belonging to the user, and also his favorite ones
    			$data = $client->getData(NULL, TRUE);
  			//  $this->printMessageWithBorder("Weather Stations Basic Information");
		}
		catch(NAClientException $ex)
		{
			 $this->handleError("An error occured while retrieving data: ". $ex->getMessage()."\n", TRUE);
			 //IPS_LogMessage('Netatmo_Modul', "An error occured while retrieving data: ". $ex->getMessage()."\n");
		}
		if(empty($data['devices']))
		{
    			$this->echoLog( 'No devices affiliated to user');
    			//IPS_LogMessage('Netatmo_Modul', $echoString);
		}
		else
		{
    			$users = array();
    			$friends = array();
    			$fav = array();
 			//   $device = $data['devices'][0];
 			//   $tz = isset($device['place']['timezone']) ? $device['place']['timezone'] : "GMT";
    			//devicaes are already sorted in the following way: first weather stations owned by user, then "friend" WS, and finally favorites stations. Still let's store them in different arrays according to their type
    			foreach($data['devices'] as $device)
    			{
     				$this->saveWSBasicInfo($device);
    			}
     			foreach($deviceList['modules'] as $module)
                	{
                		$this->saveModules($module);
        		}
		}
		//IPS_LogMessage('Netatmo_Modul', "GetData finished successfully");
	}

	private function getModuleName($device)
	{
		if (array_key_exists('module_name',$device) )
		{
			return $device['module_name'];
		}
		else if (array_key_exists('_id',$device)) 
		{
			return $device['_id'];
		} 
		else 
		{
			return "unknown";
		}
	}
	

	private function saveModules($device)
	{
 		$instance_id_parent = $this->InstanceID;	
  		$this->echoLog("id: " . $device['_id']. "\n");
		$instance_id_station = $this->CreateCategoryByIdent($instance_id_parent, $device['main_device'] , $device['main_device'] );
  		//$instance_id_station = $this->CreateCategoryByIdent($instance_id_parent, $device['_id'] , $device['_id'] );
		$module = $this->getModuleName($device);
 		$instance_id = $instance_id_station;
 
    		if(isset($device['type']))
    		{
        		switch($device['type'])
        		{
            			// Outdoor Module
            			case "NAModule1": //	 IPS_LogMessage('NETATMO',"Outdoor");
             				$instance_id = $this->CreateCategoryByIdent($instance_id, $module , $module );
                              		break;
            			//Wind Sensor
            			case "NAModule2": 	// IPS_LogsMessage('NETATMO',"Wind Sensor");
        				$instance_id = $this->CreateCategoryByIdent($instance_id, $module , $module );
        		                break;
            			//Rain Gauge
            			case "NAModule3": //	 IPS_LogMessage('NETATMO',"Rain Gauge");
              				$instance_id = $this->CreateCategoryByIdent($instance_id, $module , $module );
                		       break;
            			//Indoor Module
            			case "NAModule4": //	 IPS_LogMessage('NETATMO',"Indoor");
				        $instance_id = $this->CreateCategoryByIdent($instance_id, $module, $module );
                              		break;
            			case "NAMain" : //	 IPS_LogMessage('NETATMO',"Main device");
            				$instance_id = $this->CreateCategoryByIdent($instance_id,  $module, $module );
                        		break;
        		}
    		}
    		if(isset($device['place']['timezone']))
        		$tz = $device['place']['timezone'];
    		else $tz = 'GMT';
    			if(isset($device['dashboard_data']))
    			{
     			//   $this->echoLog("Last data: \n");
        		foreach($device['dashboard_data'] as $key => $val)
        		{
        			switch (gettype($val)) 
        			{
    					case "double":
        					$ips_type = 2;
						break;
					case "integer":
        					$ips_type = 2;
        					break;
        				case "string":
        					$ips_type = 3;
        					break;
        				case "boolean":
						$ips_type = 0;
						break;	
				}
  				$this->CreateVariableByIdent($instance_id, $key,$key,$val , $ips_type)  ;
        		}
    		}
	}	
	
	
	private function saveWSBasicInfo($device)
	{
 		$instance_id_parent = $this->InstanceID;	
		$instance_id = $this->CreateCategoryByIdent($instance_id_parent, trim($device['_id']) , $device['_id'] );
 /*		if(isset($device['module_name']))
		{
    		}
   if(isset($device['type']))
    {
		$module = $this->getModuleName($device);
      //  $this->echoLog("type: ");
        switch($device['type'])
        {
			
            // Outdoor Module
            case "NAModule1": //	 IPS_LogMessage('NETATMO',"Outdoor");
             $instance_id = $this->CreateCategoryByIdent($instance_id, $module , $module );
                              break;
            //Wind Sensor
            case "NAModule2": 	// IPS_LogsMessage('NETATMO',"Wind Sensor");
               $instance_id = $this->CreateCategoryByIdent($instance_id, $module , $module );
                              break;
            //Rain Gauge
            case "NAModule3": //	 IPS_LogMessage('NETATMO',"Rain Gauge");
              $instance_id = $this->CreateCategoryByIdent($instance_id, $module , $module );
                              break;
            //Indoor Module
            case "NAModule4": //	 IPS_LogMessage('NETATMO',"Indoor");
            $instance_id = $this->CreateCategoryByIdent($instance_id, $module, $module );
                              break;
            case "NAMain" : //	 IPS_LogMessage('NETATMO',"Main device");
            $instance_id = $this->CreateCategoryByIdent($instance_id,  $module, $module );
                            break;
        }
    }
    */
    	if(isset($device['place']['timezone']))
		$tz = $device['place']['timezone'];
    	else $tz = 'GMT';
    		if(isset($device['dashboard_data']))
    		{
		 	//   $this->echoLog("Last data: \n");
        		foreach($device['dashboard_data'] as $key => $val)
        		{
        			switch (gettype($val)) 
        			{
    					case "double":
	        				$ips_type = 2;
						break;
					case "integer":
        					$ips_type = 2;
        					break;
        				case "string":
        					$ips_type = 3;
        					break;
        				case "boolean":
						$ips_type = 0;
						break;	
				}
  			$this->CreateVariableByIdent($instance_id, $key,$key,$val , $ips_type);
        		}
      
    		}
  		//  $this->echoLog("       ----------------------   \n");
	}	


	private function maskUmlaute($text)
	{
		$text = str_replace ("ä", "a", $text);
		$text = str_replace ("Ä", "AE", $text);
		$text = str_replace ("ö", "oe", $text);
		$text = str_replace ("Ö", "OE", $text);
		$text = str_replace ("ü", "ue", $text);
		$text = str_replace ("Ü", "UE", $text);
		$text = str_replace ("ß", "ss", $text);
		$text = str_replace (" ", "_", $text);
		$text = str_replace ("(", "_", $text);
		$text = str_replace (")", "_", $text);
		$text = str_replace ("&", "_", $text);
		$text = str_replace ("§", "_", $text);
		$text = str_replace ("/", "_", $text);
		$text = str_replace ("=", "_", $text);
		$text = str_replace ("{", "_", $text);
		$text = str_replace ("}", "_", $text);
		$text = str_replace (":", "_", $text);
		$text = str_replace (",", "_", $text);
		$text = str_replace (";", "_", $text);
	
		return $text;
	}

	private function CreateCategoryByIdent($id, $ident, $name)
 	{
 		$cid = @IPS_GetObjectIDByIdent($this->maskUmlaute($ident), $id);
 		if($cid === false)
 		{
			 $cid = IPS_CreateCategory();
			 IPS_SetParent($cid, $id);
			 IPS_SetName($cid, $name);
		//	 IPS_LogMessage('373', $this->maskUmlaute($ident));
			 IPS_SetIdent($cid, $this->maskUmlaute($ident));
		}
		return $cid;
	}
		
	private function CreateVariableByIdent($id, $ident, $name, $value, $type, $profile = "")
	{
    		global $logging;
    		global $archive;
    		
		$vid = @IPS_GetObjectIDByIdent($this->maskUmlaute($ident), $id);
		if($vid === false)
		{
			 if (preg_match("/^date_.*/", $ident)) 
			{	
					$logging = false;
					$type = 1;
				
			} else if (preg_match("/^time_.*/", $ident)) 
			{	
					$logging = false;
					$type = 1;
				
			}
			if (preg_match("/^Rain.*/", $ident) || preg_match("/^sum_rain.*/", $ident)) 
			{	
				$type = 2;
			}
			$vid = IPS_CreateVariable($type);
			IPS_SetParent($vid, $id);
			IPS_SetName($vid, $name);
			IPS_SetIdent($vid, $this->maskUmlaute($ident));
			if($profile != "")
			{
				IPS_SetVariableCustomProfile($vid, $profile);
			} else  if (preg_match("/^date_.*/", $ident)) 
			{		IPS_SetVariableCustomProfile($vid, "~UnixTimestamp");
					$logging = false;
				
			} else if (preg_match("/^time_.*/", $ident)) 
			{		IPS_SetVariableCustomProfile($vid, "~UnixTimestamp");
					$logging = false;
				
			}
			if ($logging) 
			{
				AC_SetLoggingStatus($archive, $vid, true);
				IPS_ApplyChanges($archive);
			}
		}
		@SetValue($vid,$value);
		// IPS_LogMessage('NETATMO',$name .": " . print_r($value));
		return $vid;
	}
		
	private function CreateInstanceByIdent($id, $ident, $name, $moduleid = "{24B57877-C24C-4690-8421-B41DCC22BE1B}")
	{
		$iid = @IPS_GetObjectIDByIdent($this->maskUmlaute($ident), $id);
		if($iid === false)
		{
			$iid = IPS_CreateInstance($moduleid);
			IPS_SetParent($iid, $id);
			IPS_SetName($iid, $name);
			IPS_SetIdent($iid, $this->maskUmlaute($ident));
		}
		return $iid;
	}	
	
	
	/**
	* Prints a list of devices
 	*
	*/
	private  function printDevices($devices, $title = NULL)
	{
    		if(!is_null($devices) && is_array($devices) && !empty($devices))
    		{	
        		if(!is_null($title))
        		{
            			$this->printMessageWithBorder($title);
        		}
        		foreach($devices as $device)
        		{
            			$this->printWSBasicInfo($device);
        		}
    		}
	}

	private function handleError($message, $exit = FALSE)
	{
		//IPS_LogMessage('Netatmo_ERROR', $message);
    		if($exit)
    		{
        		exit(-1);
    		}
	}
	
	private function printTimeInTz($time, $timezone, $format)
	{
	    	try
	    	{
	        	$tz = new DateTimeZone($timezone);
	    	}
	    	catch(Exception $ex)
	   	{
	        	$tz = new DateTimeZone("GMT");
	    	}
	   	$date = new DateTime();
	   	$date->setTimezone($tz);
	   	$date->setTimestamp($time);
	   	$this->echoLog( $date->format($format));
	}
	
	private  function printBorder($message)
	{
		 $size = strlen($message);
		 for($i = 0; $i < $size; $i++)
		 {
			  $this->echoLog("-");
		 }
    		$this->echoLog("\n");
	}
	
	private function printMessageWithBorder($message)
	{
		$message = "- " . $message . " -";
    		$this->printBorder($message);
    		$this->echoLog($message . "\n");
    		$this->printBorder($message);
	}

	private function echoLog($message) 
	{
		global $echoString;	
		$echoString = $echoString . $message;

	}

	private function printMeasure($measurements, $type, $tz, $title = NULL, $monthly = FALSE)
	{
		if(!empty($measurements))
		{
        		if(!empty($title))
            		{
            			$this->printMessageWithBorder($title);
            		}
        		if($monthly)
        		{
            			$dateFormat = 'F: ';
        		}
        		else 
        		{
        			$dateFormat = 'j F: ';
        		}
        		//array of requested info type, needed to map result values to what they mean
        		$keys = explode(",", $type);
        		foreach($measurements as $timestamp => $values)
        		{
            			$this->printTimeinTz($timestamp, $tz, $dateFormat);
             			$this->echoLog("\n");
            			foreach($values as $key => $val)
            			{
                			$this->echoLog( $keys[$key] . ": ");
                			if($keys[$key] === "time_utc" || preg_match("/^date_.*/", $keys[$key]))
                    			{
                    				$this->echoLog ($this->printTimeInTz($val, $tz, "j F H:i"));
                    			}
                			else
                			{
                    				$this->echoLog( $val);
                    				$this->printUnit($keys[$key]);
                			}
                			if(count($values)-1 === $key || $monthly)
                    			{
                    				$this->echoLog( "\n");
                    			}
                			else 
                			{
                				$this->echoLog( ", ");
                			}
            			}
        		}
    		}
	}
	
	/**
 	* function printing a weather station or modules basic information such as id, name, dashboard data, modules (if main device), type(if module)
 	*
 	*/
	private function printWSBasicInfo($device)
	{
    		if(isset($device['station_name']))
        	{
        		$this->echoLog("- ".$device['station_name']. " -\n");
        	}
    		else if(isset($device['module_name']))
        	{
        		$this->echoLog("- ".$device['module_name']. " -\n");
    			$this->echoLog("id: " . $device['_id']. "\n");
        	}
    if(isset($device['type']))
    {
        $this->echoLog("type: ");
        switch($device['type'])
        {
            // Outdoor Module
            case "NAModule1": $this->echoLog("Outdoor\n");
                              break;
            //Wind Sensor
            case "NAModule2": $this->echoLog("Wind Sensor\n");
                              break;
            //Rain Gauge
            case "NAModule3": $this->echoLog("Rain Gauge\n");
                              break;
            //Indoor Module
            case "NAModule4": $this->echoLog("Indoor\n");
                              break;
            case "NAMain" : $this->echoLog("Main device \n");
                            break;
        }
    }
    if(isset($device['place']['timezone']))
        $tz = $device['place']['timezone'];
    else $tz = 'GMT';
    if(isset($device['dashboard_data']))
    {
        $this->echoLog("Last data: \n");
        foreach($device['dashboard_data'] as $key => $val)
        {
            if($key === 'time_utc' || preg_match("/^date_.*/", $key))
            {
                $this->echoLog( $key .": ");
                $this->printTimeInTz($val, $tz, 'j F H:i');
                $this->echoLog("\n");
            }
            else if(is_array($val))
            {
                //do nothing : don't print historic
            }
            else {
                $this->echoLog($key .": " . $val);
                $this->printUnit($key);
                $this->echoLog( "\n");
            }
        }
        if(isset($device['modules']))
        {
            $this->echoLog(" \n\nModules: \n");
            foreach($device['modules'] as $module)
                $this->printWSBasicInfo($module);
        }
    }
    $this->echoLog("       ----------------------   \n");
}
private function printUnit($key)
{
    $typeUnit = array('temp' => '°C', 'hum' => '%', 'noise' => 'db', 'strength' => 'km/h', 'angle' => '°', 'rain' => 'mm', 'pressure' => 'mbar', 'co2' => 'ppm');
    foreach($typeUnit as $type => $unit)
    {
        if(preg_match("/.*$type.*/i", $key))
        {
            $this->echoLog( " ".$unit);
            return;
        }
    }
}
/** THERM Utils function **/
/*
* @brief print a thermostat basic information in CLI
*/
private function printThermBasicInfo($dev)
{
    //Device
    $this->echoLog(" -".$dev['station_name']."- \n");
    $this->echoLog(" id: ".$dev['_id']." \n");
    $this->echoLog("Modules : \n");
    // Device's modules info
    foreach($dev['modules'] as $module)
    {
        $this->echoLog("    - ".$module['module_name']." -\n");
        //module last measurements
        $this->echoLog("    Last Measure date : ");
        $this->printTimeInTz($module['measured']['time'], $dev['place']['timezone'], 'j F H:i');
        $this->echoLog("\n");
        $this->echoLog("    Last Temperature measured: ". $module['measured']['temperature']);
        $this->printUnit("temperature");
        $this->echoLog("\n");
        $this->echoLog("    Last Temperature setpoint: ". $module['measured']['setpoint_temp']);
        $this->printUnit('setpoint_temp');
        $this->echoLog("\n");
        $this->echoLog("    Program List: \n");
        //program list
        foreach($module['therm_program_list'] as $program)
        {
            if(isset($program['name']))
                $this->echoLog("        -".$program['name']."- \n");
            else $this->echoLog("        -Standard- \n");
            $this->echoLog("        id: ".$program['program_id']." \n");
            if(isset($program['selected']) && $program['selected'] === TRUE)
            {
                $this->echoLog( "         This is the current program \n");
            }
        }
    }
}
/**
* @brief returns the current program of a therm module
*/
private function getCurrentProgram($module)
{
    foreach($module['therm_program_list'] as $program)
    {
        if(isset($program['selected']) && $program['selected'] === TRUE)
            return $program['program_id'];
    }
    //not found
    return NULL;
}
/**
* @brief returns the current setpoint of a therm module along with its setpoint temperature and endtime if defined
*/
	private function getCurrentMode($module)
{
    $initialMode = $module["setpoint"]["setpoint_mode"];
    $initialTemp = isset($module["setpoint"]["setpoint_temp"]) ? $module["setpoint"]["setpoint_temp"]: NULL;
    $initialEndtime = isset($module['setpoint']['setpoint_endtime']) ? $module['setpoint']['setpoint_endtime'] : NULL;
    return array($initialMode, $initialTemp, $initialEndtime);
}


private function removeHTMLTags($string)
{
   return preg_replace("/<.*?>/", "", $string);
}


 protected function RegisterTimer($ident, $interval, $script) {
    $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);

    if ($id && IPS_GetEvent($id)['EventType'] <> 1) {
      IPS_DeleteEvent($id);
      $id = 0;
    }

    if (!$id) {
      $id = IPS_CreateEvent(1);
      IPS_SetParent($id, $this->InstanceID);
      IPS_SetIdent($id, $ident);
    }

    IPS_SetName($id, $ident);
    IPS_SetHidden($id, true);
    IPS_SetEventScript($id, "\$id = \$_IPS['TARGET'];\n$script;");

    if (!IPS_EventExists($id)) throw new Exception("Ident with name $ident is used for wrong object type");

    if (!($interval > 0)) {
      IPS_SetEventCyclic($id, 0, 0, 0, 0, 1, 1);
      IPS_SetEventActive($id, false);
    } else {
      IPS_SetEventCyclic($id, 0, 0, 0, 0, 1, $interval);
      IPS_SetEventActive($id, true);
    }
  }  

    }
?>
