<?php
// This script try to call the SNCF's web service and generate an Excel file from the response
// this script can take 2 parameters, for example : "php -f export_recap_to_excel.php 2018 03"
// this first one is the year, like "2018"
// this second is the month, like 03 (March)
// in this case, the script will call the web service for getting data of the given month

// Otherwise, you can run the script without any parameter, for example : "php -f export_recap_to_excel.php"
// in this case, the script with call the web service for getting data of the current month


require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// firstly, read parameter from command line,
analyse_parameters($argc, $argv);
$year = date("Y");
$month = date("m");

if($argc == 3){
    $year = $argv[1];
    $month = $argv[2];
}

// Parse config file
$config_array = parse_ini_file("config.ini");
$webservice_endpoint = $config_array['webservice_endpoint'];
$webservice_login = $config_array['webservice_login'];
$webservice_pwd = $config_array['webservice_pwd'];
$commandes_resource = $config_array['commandes_resource'];
$courses_resource = $config_array['courses_resource'];
$excel_folder = $config_array['excel_folder'];
$excel_file_prefix = $config_array['excel_file_prefix'];
$headers = array('Content-Type:application/vnd.sncf.galapagos+json; version=1');
$getcommands_url = $webservice_endpoint.$commandes_resource."/".$year."/".$month;
$getcourses_url = $webservice_endpoint.$courses_resource."/".$year."/".$month;

// get commands as json object
$commandsBody = callWebAPI('GET', $getcommands_url, $webservice_login, $webservice_pwd, $headers);
$commandsJson = json_decode($commandsBody);

// get courses as json object
$coursesBody = callWebAPI('GET', $getcourses_url, $webservice_login, $webservice_pwd, $headers);
$coursesJson = json_decode($coursesBody);

// generate excel file
echo "now try to generate excel file\n";
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setCellValue('A1', 'Hello World');

$writer = new Xlsx($spreadsheet);
$writer->save($excel_folder.DIRECTORY_SEPARATOR.$excel_file_prefix.$year."_".$month.".xlsx");
echo "done\n";

/**
 * This function analyse the availabitity of the paramters
 */
function analyse_parameters($argc, $argv){
    // if different from 1 or 3 parameters, stop the script
    // 1 for running the script without parameter and 3 for 2 parameters as the script's filename is always the first parameter
    if($argc !=1 && $argc != 3){
        // parameter error, stop the script
        echo "Wrong paramters, please run the script as the following :\n\tphp export ".$argv[0]." [yyyy] [mm]\n";
        exit;
    } else if($argc == 3){
        // 2 parameters, we need to check if the parameters follow the right format 
        $year = (int)$argv[1];
        $month = (int)$argv[2];
        if($year > 9999 || $year < 1000){
            echo "Year should between 1000 ~ 9999\n";
            exit;
        }

        if($month > 12 || $month < 1){
            echo "Month should between 1 ~ 12\n";
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

    $result = curl_exec($curl);
    if($result === false)
    {
        echo 'Curl error: ' . curl_error($curl);
        exit;
    } 
    $returnCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    if($returnCode != 200 && $returnCode != 201){
        echo "Bad response : " . $returnCode . " : " .curl_error($curl);
    }
    curl_close($curl);

    // Then, after your curl_exec call:
    $header = substr($result, 0, $header_size);
    $body = substr($result, $header_size);

    return $body;
}

?>