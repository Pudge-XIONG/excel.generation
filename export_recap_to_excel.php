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
echo "loading configuration file...\n";
$config_array = parse_ini_file("resources/config.ini");
$webservice_endpoint = $config_array['webservice_endpoint'];
$webservice_login = $config_array['webservice_login'];
$webservice_pwd = $config_array['webservice_pwd'];
$commandes_resource = $config_array['commandes_resource'];
$courses_resource = $config_array['courses_resource'];
$tarif_path = $config_array['tarif_path'];
$excel_folder = $config_array['excel_folder'];
$excel_file_prefix = $config_array['excel_file_prefix'];
$headers = array('Content-Type:application/vnd.sncf.galapagos+json; version=1');
$getcommands_url = $webservice_endpoint.$commandes_resource."/".$year."/".$month;
$getcourses_url = $webservice_endpoint.$courses_resource."/".$year."/".$month;
echo "done!\n\n";

echo "now try to call $getcommands_url web service\n";
// get commands as json object
$commandsBody = callWebAPI('GET', $getcommands_url, $webservice_login, $webservice_pwd, $headers);
$commandsJson = json_decode($commandsBody, true);

// get all courses's id and numero 
$coursesIdNumeroArray = getCourseIdNumeroArray($commandsJson);


// get courses as json object
echo "calling web service $getcourses_url ...\n";
$coursesBody = callWebAPI('GET', $getcourses_url, $webservice_login, $webservice_pwd, $headers);
$coursesJson = json_decode($coursesBody, true);
echo "done!\n\n";

// sort course by bupo 
$bupoCoursesArray = array();
sortCourseByBUPO($bupoCoursesArray, $coursesJson);

// load tarif
echo "loading tarif file...\n";
$tarifCSV = array_map('str_getcsv_with_semicolon', file($tarif_path));
echo "done!\n\n";

/*
/////////////this part is just for generating a completed tarif csv file////////////////////
foreach($coursesJson as $courseJson){
    $lieuDepart = $courseJson['lieuDepart'];
    $lieuArrivee = $courseJson['lieuArrivee'];
    echo $lieuDepart['codeGare'].';'.$lieuDepart['codeChantier'].';'.$lieuDepart['libelleLocalite'].';'.$lieuDepart['libelleGM'];
    echo "--->";
    echo $lieuArrivee['codeGare'].';'.$lieuArrivee['codeChantier'].';'.$lieuArrivee['libelleLocalite'].';'.$lieuArrivee['libelleGM'];

    echo "\n";
}
*/

////////////////////////////////////////////////////////////////////////////////////////////

// we begin to generate excel file from this part
echo "generating excel files\n";

$filePathPrefix = $excel_folder.DIRECTORY_SEPARATOR.$excel_file_prefix.$year."_".$month;

var_dump($bupoCoursesArray);

generateExcelFiles($bupoCoursesArray, $coursesIdNumeroArray, $filePathPrefix);

echo "done!\n\n";

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


/**
 * This function sort all courses by BUPO (course -> entiteCommanditaire -> libelle)
 */
function sortCourseByBUPO(&$bupoCoursesArray, $coursesJson){
    foreach($coursesJson as $courseJson){
        $BUPO = $courseJson['entiteCommanditaire']['libelle'];
        if (!array_key_exists($BUPO, $bupoCoursesArray)) {
            // new bupo, then creat a new array and add the course
            $bupoCourses = array();
            $bupoCoursesArray[$BUPO] = $bupoCourses;
        }
        
        $bupoCoursesArray[$BUPO][$courseJson['id']] = $courseJson;
    }
}


/**
 * This function get id and numero of courses from the response of /commandes/year/month web service
 * and keep them in an array
 */
function getCourseIdNumeroArray($commandesJson){
    $coursesIdNumeroArray = array();
    foreach($commandesJson as $commandeJson){
        $coursesArray = $commandeJson['courses'];
        foreach($coursesArray as $course){
            $id = $course['id'];
            $numero = $course['numero'];
            $coursesIdNumeroArray[$id] = $numero;
        }
    }

    return $coursesIdNumeroArray;
}


/**
 * This function generate excels files 
 */
function generateExcelFiles($bupoCoursesArray, $coursesIdNumeroArray, $filePathPrefix){
    foreach($bupoCoursesArray as $BUPO => $bupoCourses){
        $filePath = $filePathPrefix."_".$BUPO.".xlsx";
        generateExcel($BUPO, $bupoCourses, $coursesIdNumeroArray, $filePath);
    }
}


/**
 * This function generate an excel file which contains all coures of the specified month with the save BUPO
 */
function generateExcel($BUPO, $coursesJson, $coursesIdNumeroArray, $filePath){
    
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setCellValue('A1', 'Numéro')->setCellValue('B1', 'Date')
            ->setCellValue('C1', 'Départ')->setCellValue('D1', 'Gare')
            ->setCellValue('E1', 'Heure')->setCellValue('F1', 'Arrivée')
            ->setCellValue('G1', 'Gare')->setCellValue('H1', 'BUPO')
            ->setCellValue('I1', 'N° Bon')->setCellValue('J1', 'Attente')
            ->setCellValue('K1', 'Commentaires sur la course')->setCellValue('L1', 'Tarif HT');
    $number = 1;
    foreach($coursesJson as $courseJson){
        $numero = $number ++;
        $dateStr = $courseJson['dateRealisation'];
        // change date format from yyyy-mm-dd to dd/mm/yyy
        $date = date("d/m/Y", strtotime($dateStr));
        $lieuDepart = $courseJson['lieuDepart'];
        $lieuArrivee = $courseJson['lieuArrivee'];
        $depart = $lieuDepart['libelleLocalite'];
        $gareDepart = $lieuDepart['libelleGM'];
        $heureStr = $courseJson['heureDepart'];
        // change time string format from hhmm to hh:mm
        $heure = substr_replace($heureStr, ':', 2, 0);
        $arrivee = $lieuArrivee['libelleLocalite'];
        $gareArrivee = $lieuArrivee['libelleGM'];
        $numeroDeBon = $coursesIdNumeroArray[$courseJson['id']];

        $tarifHT = 0;

        $sheet->setCellValue('A'.$number, $numero)->setCellValue('B'.$number, $date)
            ->setCellValue('C'.$number, $depart)->setCellValue('D'.$number, $gareDepart)
            ->setCellValue('E'.$number, $heure)->setCellValue('F'.$number, $arrivee)
            ->setCellValue('G'.$number, $gareArrivee)->setCellValue('H'.$number, $BUPO)
            ->setCellValue('I'.$number, $numeroDeBon)->setCellValue('L'.$number, $tarifHT);
    }

    $writer = new Xlsx($spreadsheet);
    $writer->save($filePath);
    echo "saved to ".$filePath."\n";
}



?>