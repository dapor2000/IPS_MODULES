<?

require_once(__DIR__ . "/netatmo.php");  // Netatmo Helper Klasse

    // Klassendefinition
    class Netatmo extends IPSModule {
 
        // Der Konstruktor des Moduls
        // Überschreibt den Standard Kontruktor von IPS
        public function __construct($InstanceID) {
            // Diese Zeile nicht löschen
            parent::__construct($InstanceID);
 
            // Selbsterstellter Code
        }
 
        // Überschreibt die interne IPS_Create($id) Funktion
        public function Create() {
            // Diese Zeile nicht löschen.
        	parent::Create();
		$this->RegisterPropertyString("username", "");
		$this->RegisterPropertyString("password", "");
		$this->RegisterPropertyString("client_id", "");
		$this->RegisterPropertyString("client_secret", "");
        }
 
        // Überschreibt die intere IPS_ApplyChanges($id) Funktion
        public function ApplyChanges() {
            // Diese Zeile nicht löschen
            parent::ApplyChanges();
        }
 
        /**
        * Die folgenden Funktionen stehen automatisch zur Verfügung, wenn das Modul über die "Module Control" eingefügt wurden.
        * Die Funktionen werden, mit dem selbst eingerichteten Prefix, in PHP und JSON-RPC wiefolgt zur Verfügung gestellt:
        *
        * ABC_MeineErsteEigeneFunktion($id);
        *
        */
        
	public function Update()
		{


			$username = $this->ReadPropertyString("username");
			$password = $this->ReadPropertyString("password");
			$client_id = $this->ReadPropertyString("client_id");
			$client_secret = $this->ReadPropertyString("client_secret");
			
			if(
			(IPS_GetProperty($this->InstanceID, "username") != "") 
			&& (IPS_GetProperty($this->InstanceID, "password") != "")  
			&& (IPS_GetProperty($this->InstanceID, "client_id") != "") 
			&& (IPS_GetProperty($this->InstanceID, "client_secret") != "")
			){
			

//App client configuration
$scope = NAScopes::SCOPE_READ_STATION;
$config = array("client_id" => $client_id,
                "client_secret" => $client_secret,
                "username" => $username,
                "password" => $password);

$client = new NAWSApiClient($config);

//Authentication with Netatmo server (OAuth2)
try
{
    $tokens = $client->getAccessToken();
}
catch(NAClientException $ex)
{
    handleError("An error happened while trying to retrieve your tokens: " .$ex->getMessage()."\n", TRUE);
}

//Retrieve user's Weather Stations Information

try
{
    //retrieve all stations belonging to the user, and also his favorite ones
    $data = $client->getData(NULL, TRUE);
  //  printMessageWithBorder("Weather Stations Basic Information");
  echo "Weather Stations Basic Information";
}
catch(NAClientException $ex)
{
    handleError("An error occured while retrieving data: ". $ex->getMessage()."\n", TRUE);
}

if(empty($data['devices']))
{
    echo 'No devices affiliated to user';
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
            $fav[] = $device;
        else if(isset($device['read_only']) && $device['read_only'])
            $friends[] = $device;
        else $users[] = $device;
    }

    //print first User's device Then friends, then favorite
    printDevices($users, "User's weather stations");
    printDevices($friends, "User's friends weather stations");
    printDevices($fav, "User's favorite weather stations");

    // now get some daily measurements for the last 30 days
     $type = "temperature,Co2,humidity,noise,pressure";

    //first for the main device
    try
    {
        $measure = $client->getMeasure($device['_id'], NULL, "1day" , $type, time() - 24*3600*30, time(), 30,  FALSE, FALSE);
        printMeasure($measure, $type, $tz, $device['_id'] ."'s daily measurements of the last 30 days");
    }
    catch(NAClientException $ex)
    {
        handleError("An error occured while retrieving main device's daily measurements: " . $ex->getMessage() . "\n");
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
            printMeasure($measure, $type, $tz, $module['_id']. "'s daily measurements of the last 30 days ");
        }
        catch(NAClientException $ex)
        {
            handleError("An error occured while retrieving main device's daily measurements: " . $ex->getMessage() . "\n");
        }

    }

    //Finally, retrieve general info about last month for main device
    $type = "max_temp,date_max_temp,min_temp,date_min_temp,max_hum,date_max_hum,min_hum,date_min_hum,max_pressure,date_max_pressure,min_pressure,date_min_pressure,max_noise,date_max_noise,min_noise,date_min_noise,max_co2,date_max_co2,min_co2,date_min_co2";
    try
    {
        $measures = $client->getMeasure($device['_id'], NULL, "1month", $type, NULL, "last", 1, FALSE, FALSE);
        printMeasure($measures, $type, $tz, "Last month information of " .$device['_id'], TRUE);
    }
    catch(NAClientException $ex)
    {
        handleError("An error occcured while retrieving last month info: ".$ex->getMessage() . " \n");
    }

			}
		}
}
    }
?>
