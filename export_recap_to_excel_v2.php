<?php
// This script try to call the SNCF's web service and generate an Excel file from the response
// this script can take 2 parameters, for example : "php -f export_recap_to_excel.php 2018 03"
// this first one is the year, like "2018"
// this second is the month, like 03 (March)
// in this case, the script will call the web service for getting data of the given month

// Otherwise, you can run the script without any parameter, for example : "php -f export_recap_to_excel.php"
// in this case, the script with call the web service for getting data of the current month

header("Content-Type:application/json");
require 'vendor/autoload.php';

// Import PHPMailer classes into the global namespace
// These must be at the top of your script, not inside a function
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Tell log4php to use our configuration file.
Logger::configure('resources/log4php.xml');
 
// Fetch a logger, it will inherit settings from the root logger
$GLOBALS['logger'] = Logger::getLogger('myLogger');

$GLOBALS['body_code_seperator'] = '**-**';

// Parse config file
//echo "loading configuration file...\n";
$config_array = parse_ini_file("resources/config.ini");

$webservice_endpoint = $config_array['webservice_endpoint'];
$webservice_login = $config_array['webservice_login'];
$webservice_pwd = $config_array['webservice_pwd'];
$webservice_api_key = $config_array['webservice_apikey'];
$headers = array('Content-Type:application/vnd.sncf.galapagos+json; version=1', 'x-api-key:'.$webservice_api_key);
$courses_update = $config_array['course_update'];
$updatecourse_url = $webservice_endpoint.$courses_update;

//http post method for updating course
if(!empty($_GET['update']) && $_GET['update'] == 'true'){
    $courseUpdateBody = cleanJsonStr(file_get_contents('php://input'));
    
    $updateResponse = callWebAPI('POST', $updatecourse_url, $webservice_login, $webservice_pwd, $headers, $courseUpdateBody);
    $responseArray = explode($GLOBALS['body_code_seperator'], $updateResponse);
    $responseBody = $responseArray[0];
    $responseCode = $responseArray[1];
    if ($responseCode !='201') {
        $GLOBALS['logger']->error('failed to confirm course '.'\n');
        response($responseCode, $responseBody);
    } else{
        response(201, $responseBody);
    }
    exit;
} else if(!empty($_GET['update'])){
    response(400, 'bad request : update should be true');
    $GLOBALS['logger']->error("bad request : update should be true");
    exit;
}

$year = date("Y");
$month = date("m");

$GLOBALS['RestAPI'] = false;

//http get method
if(!empty($_GET['year']) && !empty($_GET['month'])){
    $GLOBALS['RestAPI'] = true;
    $year = $_GET['year'];
    $month = $_GET['month'];
}
// firstly, read parameter from command line,
if(!$GLOBALS['RestAPI']){
    analyse_parameters($argc, $argv);
    // set the request month and year to the current date
    // if the specified month and year are given, then replace the current date by it
    if ($argc == 3) {
        $year = $argv[1];
        $month = $argv[2];
    }
}


if($config_array['env_profile'] == 'REC'){
    $year = "2019";
    $month = "01";
}
$GLOBALS['year'] = $year;
$GLOBALS['month'] = $month;


$courses_resource = $config_array['courses_resource'];
$tarif_path = $config_array['tarif_path'];
$excel_folder = $config_array['excel_folder'];
$excel_file_prefix = $config_array['excel_file_prefix'];
$getcourses_url = $webservice_endpoint.$courses_resource."/".$year."/".$month."/01";
$GLOBALS['send_email'] = $config_array['send_email'];
$GLOBALS['smtp_host'] = $config_array['smtp_host'];
$GLOBALS['smtp_port'] = $config_array['smtp_port'];
$GLOBALS['smtp_auth_enable'] = $config_array['smtp_auth_enable'];
$GLOBALS['smtp_host_username'] = $config_array['smtp_host_username'];
$GLOBALS['smtp_host_pwd'] = $config_array['smtp_host_pwd'];
$GLOBALS['smtp_secure_mode'] = $config_array['smtp_secure_mode'];
$GLOBALS['mail_from_address'] = $config_array['mail_from_address'];
$GLOBALS['mail_from_name'] = $config_array['mail_from_name'];
$GLOBALS['mail_to'] = $config_array['mail_to'];

//echo "done!\n\n";

if($GLOBALS['send_email']){
    $GLOBALS['logger']->info("preparation de PHPMailer");
    $GLOBALS['email'] = prepareEmail();
    $GLOBALS['logger']->info("PHPMailer ready!");
}

//echo "loading tarif file...\n";
$GLOBALS['tarifCSV'] = array_map('str_getcsv_with_semicolon', file($tarif_path));
$GLOBALS['tarifArray'] = array();
foreach($GLOBALS['tarifCSV'] as $tarifCSVLine){
    $tarifArrayLine = array();
    //$tarifArrayLine['Depart'] = $tarifCSVLine[0];
    $tarifArrayLine['Code Depart'] = $tarifCSVLine[0];
    //$tarifArrayLine['Arrivée'] = $tarifCSVLine[2];
    $tarifArrayLine['Code Arrivée'] = $tarifCSVLine[4];
    $tarifArrayLine['Prix jour'] = $tarifCSVLine[8];
    $tarifArrayLine['Prix Nuit'] = $tarifCSVLine[9];
    array_push($GLOBALS['tarifArray'], $tarifArrayLine);   
}

//var_dump($GLOBALS['tarifArray']);
//exit;
//echo "done!\n\n";
/*
/////////////this part is just for generating a completed tarif csv file////////////////////
$locationCSV = array();
$fileString = "Code Depart;Depart libelleLocalite;Depart libelleGM;Depart ville;Code Arrivee;Arrivee libelleLocatlite;Arrivee libelleGM;Arrivee ville\n"; 
//$locationCSV['header'] = "";

for($i = 1; $i<=12; $i++){
    $getCourseUrl = $webservice_endpoint.$courses_resource."/".$year."/".$i;
    echo "calling web service $getCourseUrl ...\n";
    $coursesBody = callWebAPI('GET', $getCourseUrl, $webservice_login, $webservice_pwd, $headers);
    $coursesJson = json_decode($coursesBody, true);
    echo "done!\n\n";
    if($coursesJson != null && count($coursesJson)  > 0){
        foreach($coursesJson as $courseJson){
            
            $lieuDepart = $courseJson['lieuDepart'];
            $lieuArrivee = $courseJson['lieuArrivee'];
            if(!array_key_exists($lieuDepart['codeGare'].'.'.$lieuDepart['codeChantier'].$lieuArrivee['codeGare'].'.'.$lieuArrivee['codeChantier'], $locationCSV)){
                $locationCSV[$lieuDepart['codeGare'].'.'.$lieuDepart['codeChantier'].$lieuArrivee['codeGare'].'.'.$lieuArrivee['codeChantier']] = $lieuDepart['codeGare'].'.'.$lieuDepart['codeChantier'].';'.$lieuDepart['libelleLocalite'].';'.$lieuDepart['libelleGM'].";".$lieuDepart['ville'].";".$lieuArrivee['codeGare'].'.'.$lieuArrivee['codeChantier'].';'.$lieuArrivee['libelleLocalite'].';'.$lieuArrivee['libelleGM'].';'.$lieuArrivee['ville'];
                $fileString = $fileString.$lieuDepart['codeGare'].'.'.$lieuDepart['codeChantier'].';'.$lieuDepart['libelleLocalite'].';'.$lieuDepart['libelleGM'].";".$lieuDepart['ville'].";".$lieuArrivee['codeGare'].'.'.$lieuArrivee['codeChantier'].';'.$lieuArrivee['libelleLocalite'].';'.$lieuArrivee['libelleGM'].';'.$lieuArrivee['ville']."\n";
            }
            //var_dump($courseJson);
        }
    }
}

file_put_contents('code.csv', $fileString);

exit;
////////////////////////////////////////////////////////////////////////////////////////////
*/

// get courses as json object
if(!$GLOBALS['RestAPI']){
    $GLOBALS['logger']->info("calling web service $getcourses_url ...\n");
}
//$coursesBody = callWebAPI('GET', $getcourses_url, $webservice_login, $webservice_pwd, $headers);


$responseBodyAndCode = callWebAPI('GET', $getcourses_url, $webservice_login, $webservice_pwd, $headers);
$responseArray = explode($GLOBALS['body_code_seperator'], $responseBodyAndCode);
$coursesBody = $responseArray[0];
$responseCode = $responseArray[1];


$coursesBody = cleanJsonStr($coursesBody);

$coursesJson = json_decode($coursesBody, true);

switch (json_last_error()) {
    case JSON_ERROR_NONE:
        // ' - No Errors';
    break;
    case JSON_ERROR_DEPTH:
        $GLOBALS['logger']->error(' - Maximum stack depth exceeded');
    break;
    case JSON_ERROR_STATE_MISMATCH:
        $GLOBALS['logger']->error(' - Underflow or the modes mismatch');
    break;
    case JSON_ERROR_CTRL_CHAR:
        $GLOBALS['logger']->error(' - Unexpected control character found');
    break;
    case JSON_ERROR_SYNTAX:
        $GLOBALS['logger']->error(' - Syntax error, malformed JSON');
    break;
    case JSON_ERROR_UTF8:
        $GLOBALS['logger']->error(' - Malformed UTF-8 characters, possibly incorrectly encoded');
    break;
    default:
        $GLOBALS['logger']->error(' - Unknown error');
    break;
}

if($coursesJson === null){
    $GLOBALS['logger']->error('Pas de courses, on quit le script');
    exit;
}

//http get method
if($GLOBALS['RestAPI']){
    $coursesJsonStr = generateJsonData($coursesJson);
    $coursesJsonStr = cleanJsonStr($coursesJsonStr);
    response(200, $coursesJsonStr);
    exit;
}

// sort course by bupo 
$bupoCoursesArray = array();
sortCourseByBUPO($bupoCoursesArray, $coursesJson);

// we begin to generate excel file from this part
$GLOBALS['logger']->info("generation des fichiers Excel...");

$filePathPrefix = $excel_folder.DIRECTORY_SEPARATOR.$excel_file_prefix.$year."_".$month;

generateExcelFiles($bupoCoursesArray, $filePathPrefix);

$GLOBALS['logger']->info("generation des fichiers Excel reussi");

// end of the script. 
// below are the declaration of functions




/**
 * This function parse a CSV string with semicolon as its separator
 */
function str_getcsv_with_semicolon($csvString){
    return str_getcsv($csvString, ";");
}

/**
 * This function analyse the availabitity of the paramters
 */
function analyse_parameters($argc, $argv){
    // if different from 1 or 3 parameters, stop the script
    // 1 for running the script without parameter and 3 for 2 parameters as the script's filename is always the first parameter
    if($argc !=1 && $argc != 3){
        // parameter error, stop the script
        $GLOBALS['logger']->error("Wrong paramters, please run the script as the following :\tphp export ".$argv[0]." [yyyy] [mm]");
        exit;
    } else if($argc == 3){
        // 2 parameters, we need to check if the parameters follow the right format 
        $year = (int)$argv[1];
        $month = (int)$argv[2];
        if($year > 9999 || $year < 1000){
            $GLOBALS['logger']->error("Year should between 1000 ~ 9999");
            exit;
        }

        if($month > 12 || $month < 1){
            $GLOBALS['logger']->error("Month should between 1 ~ 12");
            exit;
        }
    }
}

/**
 * 
 * This function call the web service the return the reponse from the server
 */
function callWebAPI($method, $url, $login, $pwd, $headers, $data = false){
    $curl = curl_init();

    switch ($method)
    {
        case "POST":
            curl_setopt($curl, CURLOPT_POST, 1);

            if ($data)
                curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
            break;
        case "PUT":
            curl_setopt($curl, CURLOPT_PUT, 1);
            break;
        default:
            if ($data)
                $url = sprintf("%s?%s", $url, http_build_query($data));
    }

    // Optional Authentication and headers:
    curl_setopt($curl, CURLOPT_HEADER, 1);
    curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($curl, CURLOPT_USERPWD, "$login:$pwd");
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

    $GLOBALS['logger']->info($method.' '.$url.'...');
    $result = curl_exec($curl);
    if($result === false)
    {
        $GLOBALS['logger']->error('Curl error: ' . curl_error($curl)." with data :".$data);
        exit;
    }
    $body = "";
    $returnCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $GLOBALS['logger']->info('http response code is '.$returnCode);

    if($returnCode != 200 && $returnCode != 201){
        $GLOBALS['logger']->error("Bad response : " . $returnCode . " : " .curl_error($curl));
    }

    curl_close($curl);

    // Then, after your curl_exec call:
    $header = substr($result, 0, $header_size);
    $body = substr($result, $header_size);

    if($returnCode != 200 && $returnCode != 201){
        $GLOBALS['logger']->error("body is : " . $body);
    }

    if($method == "GET"){
        if($returnCode == 200){
            $GLOBALS['logger']->info("les courses ont ete bien recuperees depuis le web service V2");
            file_put_contents('temp_coursesBody.txt', $body);
        } else{
            $GLOBALS['logger']->warn("probleme lors de la recuperation des courses depuis le web service V2, on prend les dernieres courses depuis le fichier temporaire...");
            $body = file_get_contents('temp_coursesBody.txt'); 
        }
    }
    
    return $body.$GLOBALS['body_code_seperator'].$returnCode;
}


/**
 * This function sort all courses by BUPO (course -> entiteCommanditaire -> libelle)
 */
function sortCourseByBUPO(&$bupoCoursesArray, $coursesJson){
    foreach($coursesJson as $courseJson){
        $courseJson['CODEBUPO'] = $courseJson['entiteFacture']['codeBupo'];
        $BUPO = $courseJson['CODEBUPO'].'-'.$courseJson['entiteCommanditaire']['libelle'];
        //$BUPO = $courseJson['entiteCommanditaire']['libelle'];
        if (!array_key_exists($BUPO, $bupoCoursesArray)) {
            // new bupo, then creat a new array and add the course
            $bupoCourses = array();
            $bupoCoursesArray[$BUPO] = $bupoCourses;
        }
        
        $bupoCoursesArray[$BUPO][$courseJson['id']] = $courseJson;
    }
}


/**
 * This function generate excels files 
 */
function generateExcelFiles($bupoCoursesArray, $filePathPrefix){
    foreach($bupoCoursesArray as $BUPO => $bupoCourses){
        $filePath = $filePathPrefix."_".$BUPO.".xlsx";
        generateExcel($BUPO, $bupoCourses, $filePath);
        if($GLOBALS['send_email']){
            $GLOBALS['email']->addAttachment($filePath);         // Add attachments
        }
    }

    if($GLOBALS['send_email']){
        $GLOBALS['logger']->info("Sending email...");
        $GLOBALS['email']->send();
        $GLOBALS['logger']->info("Email sent!");
    }
}



function generateJsonData($coursesJson){
    // sort courses by Date
    /*
    usort($coursesJson, function ($item1, $item2) {
        return $item1['dateRealisation']."-".$item1['heureDepart'] <=> $item2['dateRealisation']."-".$item2['heureDepart'];
    });
    */
    //$coursesJson = sortArray( $coursesJson, array( 'dateRealisation', 'heureDepart' ) );
    $number = 0;
    $jsonData = array();

    //sort courses by BUPO
    $temp_courses = array();
    $temp_num = 0;
    foreach($coursesJson as $courseJson){
        $courseJson['CODEBUPO'] = $courseJson['entiteFacture']['codeBupo'];
        $courseJson['BUPO'] = $courseJson['CODEBUPO'].'-'.$courseJson['entiteCommanditaire']['libelle'];
        $temp_courses[$temp_num] = $courseJson;
        $temp_num ++;
    }
    $temp_courses = sortArray( $temp_courses, array('CODEBUPO', 'dateRealisation') );

    foreach($temp_courses as $courseJson){
        $courseArray = array();
        $dateStr = $courseJson['dateRealisation'];
        // change date format from yyyy-mm-dd to dd/mm/yyy
        $date = date("d/m/Y", strtotime($dateStr));
        $lieuDepart = $courseJson['lieuDepart'];
        $lieuArrivee = $courseJson['lieuArrivee'];
        $BUPO = $courseJson['BUPO'];
        $idCourse = $courseJson['id'];
				
        // sometimes courses in response do not contain 'libelleLocalite' and 'libelleGM'
        // so we just set 'ville' as their value
        $depart = "";
        $lieuDepSNCF = "";
        $adresseDep = "";
        $lieuArrSNCF = "";
        $adresseArr = "";
        if (array_key_exists("libelleLocalite", $lieuDepart)) {
            $depart = $lieuDepart['libelleLocalite'];
        } else{
            $depart = $lieuDepart['ville'];
        }
        $gareDepart = "";
        if (array_key_exists("libelleGM", $lieuDepart)) {
            $gareDepart = $lieuDepart['libelleGM'];
            $lieuDepSNCF = $lieuDepart['libelleGM'];
        }

        if (array_key_exists("numNivTypVoie", $lieuDepart)) {
            $adresseDep = $lieuDepart['numNivTypVoie'];
        }

        $heureStr = $courseJson['heureDepart'];
        // change time string format from hhmm to hh:mm
        $heure = substr_replace($heureStr, ':', 2, 0);
        $heureDep = $heure;

        $arrivee = "";
        if (array_key_exists("libelleLocalite", $lieuArrivee)) {
            $arrivee = $lieuArrivee['libelleLocalite'];
        } else{
            $arrivee = $lieuArrivee['ville'];
        }
        $gareArrivee = "";
        if (array_key_exists("libelleGM", $lieuArrivee)) {
            $gareArrivee = $lieuArrivee['libelleGM'];
            $lieuArrSNCF =  $lieuArrivee['libelleGM'];
        }
        if (array_key_exists("numNivTypVoie", $lieuArrivee)) {
            $adresseArr = $lieuArrivee['numNivTypVoie'];
        }

        $numeroDeBon = $courseJson['commande'];

        $dateRealisation = $courseJson['dateRealisation'];
        
        $dtime = DateTime::createFromFormat("Y-m-d", $dateRealisation);
        
        $timestamp = $dtime->getTimestamp();
        $etat = $courseJson['etat'];
        $tarifHT = getTarif($lieuDepart, $lieuArrivee, $timestamp, $heure, $etat);
        
        $courseArray['date'] = $date;
        $courseArray['depart'] = $depart;
        $courseArray['gareDepart'] = $gareDepart;
        $courseArray['heure'] = $heure;
        $courseArray['arrivee'] = $arrivee;
        $courseArray['gareArrivee'] = $gareArrivee;
        $courseArray['BUPO'] = $BUPO;
        $courseArray['numeroDeBon'] = $numeroDeBon;
        //$courseArray['Attente'] = '';
        //$courseArray['Commentaires sur la course'] = '';
        $courseArray['tarifHT'] = $tarifHT;
        $courseArray['etat'] = $etat;
        $courseArray['lieuDepSNCF'] = $lieuDepSNCF;
        $courseArray['adresseDep'] = $adresseDep;
        $courseArray['lieuArrSNCF'] = $lieuArrSNCF;
        $courseArray['adresseArr'] = $adresseArr;
        $courseArray['heureDep'] = $heureDep;
        $courseArray['idCourse'] = $idCourse;
        $courseArray['referenceCourse'] = $courseArray['numeroDeBon'];
        
        $jsonData[$number] = $courseArray;
        $number ++;
    }

    return json_encode($jsonData);
}


/**
 * This function generate an excel file which contains all coures of the specified month with the same BUPO
 */
function generateExcel($BUPO, $coursesJson, $filePath){
    
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setCellValue('A1', 'Date')->setCellValue('B1', 'Départ')
            ->setCellValue('C1', 'Gare')->setCellValue('D1', 'Heure')
            ->setCellValue('E1', 'Arrivée')->setCellValue('F1', 'Gare')
            ->setCellValue('G1', 'BUPO')->setCellValue('H1', 'N° Bon')
            ->setCellValue('I1', 'Attente')->setCellValue('J1', 'Commentaires sur la course')
            ->setCellValue('K1', 'Tarif HT')->setCellValue('L1', 'Etat');
    $number = 1;

    // sort courses by date
    /*
    usort($coursesJson, function ($item1, $item2) {
        return $item1['dateRealisation']."-".$item1['heureDepart'] <=> $item2['dateRealisation']."-".$item2['heureDepart'];
    });
    */
    $coursesJson = sortArray( $coursesJson, array( 'CODEBUPO', 'heureDepart' ) );

    foreach($coursesJson as $courseJson){
        $numero = $number ++;
        $dateStr = $courseJson['dateRealisation'];
        // change date format from yyyy-mm-dd to dd/mm/yyy
        $date = date("d/m/Y", strtotime($dateStr));
        $lieuDepart = $courseJson['lieuDepart'];
        $lieuArrivee = $courseJson['lieuArrivee'];
				
        // sometimes courses in response do not contain 'libelleLocalite' and 'libelleGM'
        // so we just set 'ville' as their value
        $depart = "";
        if (array_key_exists("libelleLocalite", $lieuDepart)) {
            $depart = $lieuDepart['libelleLocalite'];
        } else{
            $depart = $lieuDepart['ville'];
        }
        $gareDepart = "";
        if (array_key_exists("libelleGM", $lieuDepart)) {
            $gareDepart = $lieuDepart['libelleGM'];    
        }

        $heureStr = $courseJson['heureDepart'];
        // change time string format from hhmm to hh:mm
        $heure = substr_replace($heureStr, ':', 2, 0);

        $arrivee = "";
        if (array_key_exists("libelleLocalite", $lieuArrivee)) {
            $arrivee = $lieuArrivee['libelleLocalite'];
        } else{
            $arrivee = $lieuArrivee['ville'];
        }
        $gareArrivee = "";
        if (array_key_exists("libelleGM", $lieuArrivee)) {
            $gareArrivee = $lieuArrivee['libelleGM'];    
        }

        $numeroDeBon = $courseJson['commande'];;
        
        $dateRealisation = $courseJson['dateRealisation'];
        
        $dtime = DateTime::createFromFormat("Y-m-d", $dateRealisation);
        
        $timestamp = $dtime->getTimestamp();
        $etat = $courseJson['etat'];
        $tarifHT = getTarif($lieuDepart, $lieuArrivee, $timestamp, $heure, $etat);
				
        $sheet->setCellValue('A'.$number, $date)->setCellValue('B'.$number, $depart)
            ->setCellValue('C'.$number, $gareDepart)->setCellValue('D'.$number, $heure)
            ->setCellValue('E'.$number, $arrivee)->setCellValue('F'.$number, $gareArrivee)
            ->setCellValue('G'.$number, $BUPO)->setCellValue('H'.$number, $numeroDeBon)
            ->setCellValue('K'.$number, $tarifHT)->setCellValue('L'.$number, $etat);
    }

    // set the formula for calculating the total price
    $sheet->setCellValue('K'.($number + 1), "=SUM(K2:K".$number.")");

    $writer = new Xlsx($spreadsheet);
    $writer->save($filePath);
    $GLOBALS['logger']->info("saved to ".$filePath);
}



/**
 * This funciton try to find the corresponding price of the course by the given depart and arrival
 * 
 * The price of day and night and holidays is different, so we need to check if we need to get the 
 * normal price or not
 */
function getTarif($lieuDepart, $lieuArrivee, $timestamp, $heure, $etat){
    // load tarif
    $courseTarif = 0.0;

    if(strcasecmp('ANNULEE', $etat) != 0 && strcasecmp('MODIFICATION_POTENTIELLE', $etat) != 0){
        // just get the price for courses with etat is neither ANNULEE nor MODIFICATION_POTENTIELLE
        $tarifArray = $GLOBALS['tarifArray'];

        $departCode = $lieuDepart['ville'];
        if(array_key_exists("codeGare", $lieuDepart)||array_key_exists("codeChantier", $lieuDepart)){
            $departCode = $lieuDepart['codeGare'].".".$lieuDepart['codeChantier'];
        }
        $arriveeCode = $lieuArrivee['ville'];
        if(array_key_exists("codeGare", $lieuArrivee)||array_key_exists("codeChantier", $lieuArrivee)){
            $arriveeCode = $lieuArrivee['codeGare'].".".$lieuArrivee['codeChantier'];
        }

        foreach($tarifArray as $tarifLine){
            if (strcasecmp($departCode, $tarifLine['Code Depart']) == 0
                && strcasecmp($arriveeCode, $tarifLine['Code Arrivée']) == 0){

                $dt = DateTime::createFromFormat("H:i", $heure);
                $hours = $dt->format('H');
                if($hours >= 19 || $hours < 7 || isHoliday($timestamp)){
                    // 19h -- 7h ou holiday ou dimanche
                    $courseTarif = $tarifLine['Prix Nuit'];
                } else{
                    $courseTarif = $tarifLine['Prix jour'];
                }
                
            }
        }

        $courseTarif = str_replace(',', '.', $courseTarif);
    }
    
    return floatval($courseTarif);
}


/**
 * This function check if the given date is a holiday (weekends included)
 */
function isHoliday($timestamp)
{
        $iDayNum = strftime('%u', $timestamp);
        $iYear = strftime('%Y', $timestamp);

        $aHolidays = getHolidays($iYear);

        /*
        * On est oblige de convertir les timestamps en string a cause des decalages horaires.
        */
        $aHolidaysString = array_map(function ($value)
        {
                return strftime('%Y-%m-%d', $value);
        }, $aHolidays);

        if (in_array(strftime('%Y-%m-%d', $timestamp), $aHolidaysString) OR $iDayNum == 7)
        {
                return true;
        }

        return false;
}


/**
 * This funciton retrun a list of holidays (weekends included)
 */
function getHolidays($year = null)
{
        if ($year === null)
        {
                $year = intval(strftime('%Y'));
        }

        $easterDate = easter_date($year);
        $easterDay = date('j', $easterDate);
        $easterMonth = date('n', $easterDate);
        $easterYear = date('Y', $easterDate);

        $holidays = array(
                // Jours feries fixes
                mktime(0, 0, 0, 1, 1, $year),// 1er janvier
                mktime(0, 0, 0, 5, 1, $year),// Fete du travail
                mktime(0, 0, 0, 5, 8, $year),// Victoire des allies
                mktime(0, 0, 0, 7, 14, $year),// Fete nationale
                mktime(0, 0, 0, 8, 15, $year),// Assomption
                mktime(0, 0, 0, 11, 1, $year),// Toussaint
                mktime(0, 0, 0, 11, 11, $year),// Armistice
                mktime(0, 0, 0, 12, 25, $year),// Noel

                // Jour feries qui dependent de paques
                mktime(0, 0, 0, $easterMonth, $easterDay + 1, $easterYear),// Lundi de paques
                mktime(0, 0, 0, $easterMonth, $easterDay + 39, $easterYear),// Ascension
                mktime(0, 0, 0, $easterMonth, $easterDay + 50, $easterYear), // Pentecote
        );

        sort($holidays);

        return $holidays;
}


/**
 * This function prepare the mail object for sending generated files to destinations
 */
function prepareEmail(){
    //Server settings
    $mail = new PHPMailer(true);                          // Passing `true` enables exceptions
    $mail->SMTPDebug = 2;                                 // Enable verbose debug output
    $mail->CharSet = 'UTF-8';
    //$mail->isSMTP();                                      // Set mailer to use SMTP
    //$mail->Host = $GLOBALS['smtp_host'];  // Specify main and backup SMTP servers
    //$mail->SMTPAuth = $GLOBALS['smtp_auth_enable'];       // Enable SMTP authentication
    //$mail->Username = $GLOBALS['smtp_host_username'];     // SMTP username
    //$mail->Password = $GLOBALS['smtp_host_pwd'];          // SMTP password
    //$mail->SMTPSecure = $GLOBALS['smtp_secure_mode'];     // Enable TLS encryption, `ssl` also accepted
    //$mail->Port = $GLOBALS['smtp_port'];                  // TCP port to connect to

    //Recipients
    $mail->setFrom($GLOBALS['mail_from_address'], $GLOBALS['mail_from_name']);

    $recipientsArray = explode(';', $GLOBALS['mail_to']);
    foreach($recipientsArray as $recipient){
        $mail->addAddress($recipient);     // Add a recipient
    }
    //$mail->addReplyTo('info@example.com', 'Information');
    //$mail->addCC('cc@example.com');
    //$mail->addBCC('bcc@example.com');

    //Content
    $mail->isHTML(true);                                  // Set email format to HTML
    $mail->Subject = 'Recap de '.$GLOBALS['month'].'/'.$GLOBALS['year'];
    $mail->Body    = 'Voici les fichiers excel de '.$GLOBALS['month'].'/'.$GLOBALS['year']." ci-joint dans ce mail.\n";
    //$mail->AltBody = 'This is the body in plain text for non-HTML mail clients';


    return $mail;
}


/**
 * This function send a http response to the browser with status code and json data
 */
function response($status, $coursesJsonStr){
    header("HTTP/1.1 ".$status);

    echo $coursesJsonStr;
}


/**
 * Sorting array of associative arrays - multiple row sorting using a closure.
 * See also: http://the-art-of-web.com/php/sortarray/
 *
 * @param array $data input-array
 * @param string|array $fields array-keys
 * @license Public Domain
 * @return array
 */
function sortArray( $data, $field ) {
    $field = (array) $field;
    uasort( $data, function($a, $b) use($field) {
        $retval = 0;
        foreach( $field as $fieldname ) {
            if( $retval == 0 ) $retval = strnatcmp( $a[$fieldname], $b[$fieldname] );
        }
        return $retval;
    } );
    return $data;
}

function cleanJsonStr($coursesBody){
    // This will remove unwanted characters.
    // Check http://www.php.net/chr for details
    for ($i = 0; $i <= 31; ++$i) { 
        $coursesBody = str_replace(chr($i), "", $coursesBody); 
    }
    $coursesBody = str_replace(chr(127), "", $coursesBody);
    $coursesBody = str_replace('- No errors', '', $coursesBody);

    // This is the most common part
    // Some file begins with 'efbbbf' to mark the beginning of the file. (binary level)
    // here we detect it and we remove it, basically it's the first 3 characters 
    if (0 === strpos(bin2hex($coursesBody), 'efbbbf')) {
        $coursesBody = substr($coursesBody, 3);
    }
    return $coursesBody;
}

?>
