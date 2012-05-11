<?php
/**
 * This file is part of
 * Kimai - Open Source Time Tracking // http://www.kimai.org
 * (c) 2006-2009 Kimai-Development-Team
 *
 * Kimai is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; Version 3, 29 June 2007
 *
 * Kimai is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Kimai; If not, see <http://www.gnu.org/licenses/>.
 */

include_once('../../includes/basics.php');

// libs TinyButStrong
include_once('TinyButStrong/tinyButStrong.class.php');
include_once('TinyButStrong/tinyDoc.class.php');

/**
 * returns true if event is in the arrays
 *
 * @param $arrays
 * @return true if $event is in the array
 * @author AA
 */
function array_event_exists($arrays, $event) {
   $index = 0;
   foreach ($arrays as $array) {
      if ( in_array($event,$array) ) {
          return $index;
      }
      $index++;
   }
   return -1;
}

function RoundValue( $value, $prec ) {
   $precision = $prec;

   // suppress division by zero errror
   if ($precision == 0.0) {
      $precision = 1.0;
   }

   return floor($value / $precision + 0.5)*$precision;
}

// insert KSPI
$isCoreProcessor = 0;
$dir_templates   = "templates/";
$usr             = $database->checkUser();
$timespace       = get_timespace();
$in              = $timespace[0];
$out             = $timespace[1];

$timeArray = $database->get_arr_timeSheet($in, $out, null, null, array($_REQUEST['pct_ID']), null,false,false,$_REQUEST['filter_cleared']);
/* $timeArray now contains: zef_ID, zef_in, zef_out, zef_time, zef_rate, zef_pctID,
	zef_evtID, zef_usrID, pct_ID, knd_name, pct_kndID, evt_name, pct_comment,
	pct_name, zef_location, zef_trackingnr, zef_comment, zef_comment_type,
	usr_name, usr_alias, zef_cleared
*/

$date  = time();
$month = $kga['lang']['months'][date("n", $out)-1];
$year  = date("Y", $out );

if (count($timeArray) > 0) {
    // customer data
    $kndArray        = $database->customer_get_data($timeArray[0]['customerID']);
    $pctArray        = $database->project_get_data($timeArray[0]['projectID']);
	$project         = html_entity_decode($timeArray[0]['projectName']);
	$customerName    = html_entity_decode($timeArray[0]['customerName']);
	$companyName     = $kndArray['company'];
	$customerStreet  = $kndArray['street'];
	$customerCity    = $kndArray['city'];
	$customerZip     = $kndArray['zipcode'];
	$customerComment = $kndArray['comment'];
	$customerPhone   = $kndArray['phone'];
	$customerFax     = $kndArray['fax'];
	$customerMobile  = $kndArray['mobile'];
	$customerEmail   = $kndArray['mail'];
	$customerContact = $kndArray['contact'];
	$customerURL	 = $kndArray['homepage'];
	$customerVat     = $kndArray['vat'];
	$projectComment  = $pctArray['projectComment'];
	$beginDate       = $in;
	$endDate         = $out;
	$invoiceID       = $customerName. "-" . date("y", $in). "-" . date("m", $in);
	$today           = time();
	$dueDate         = mktime(0, 0, 0, date("m") + 1, date("d"),   date("Y"));
} else {
    echo '<script language="javascript">alert("'.$kga['lang']['ext_invoice']['noData'].'")</script>';
    return;
}

// MERGE SORT
$time_index   = 0;
$invoiceArray = array();

while ($time_index < count($timeArray)) {
	$wage    = $timeArray[$time_index]['wage'];
	$time    = $timeArray[$time_index]['duration']/3600;
	$event   = html_entity_decode($timeArray[$time_index]['activityName']);
	$comment = $timeArray[$time_index]['comment'];
	$description = $timeArray[$time_index]['description'];
	$evtdt   = date("m/d/Y", $timeArray[$time_index]['start']);
	$userName  = $timeArray[$time_index]['userName'];
	$userAlias = $timeArray[$time_index]['userAlias'];

   // do we have to create a short form?
   if ( isset($_REQUEST['short']) ) {

      $index = array_event_exists($invoiceArray,$event);
      if ( $index >= 0 ) {
         $totalTime = $invoiceArray[$index]['hour'];
         $totalAmount = $invoiceArray[$index]['amount'];
         $invoiceArray[$index] = array(
            'desc'    => $event,
            'hour'    => $totalTime+$time,
            "amount"  => $totalAmount+$wage,
            'date'    => $evtdt,
            'description' => $description,
            'comment' => $comment
         );
	  }
	  else {
   	     $invoiceArray[] = array('desc'=>$event, 'hour'=>$time, 'amount'=>$wage, 'date'=>$evtdt, 'description'=>$description, 'comment'=>$comment,  'username'=>'', 'useralias'=>'');
	  }
   }
   else {
      $invoiceArray[] = array('desc'=>$event, 'hour'=>$time, 'amount'=>$wage, 'date'=>$evtdt, 'description'=>$description, 'comment'=>$comment, 'username'=>$userName, 'useralias'=>$userAlias);
   }
   $time_index++;
}

$round = 0;
// do we have to round the time ?
if (isset($_REQUEST['round'])) {
   $round      = $_REQUEST['pct_round'];
   $time_index = 0;
   $amount     = count($invoiceArray);

    while ($time_index < $amount) {
        $rounded = RoundValue( $invoiceArray[$time_index]['hour'], $round/10);

        // Write a logfile entry for each value that is rounded.
        Logger::logfile("Round ".  $invoiceArray[$time_index]['hour'] . " to " . $rounded . " with ".  $round);

        $rate = RoundValue($invoiceArray[$time_index]['amount']/$invoiceArray[$time_index]['hour'],0.05);
        $invoiceArray[$time_index]['hour'] = $rounded;
        $invoiceArray[$time_index]['amount'] = $invoiceArray[$time_index]['hour']*$rate;
        $time_index++;
    }
}

// calculate invoice sums
$ttltime = 0;
$gtotal  = 0;
while (list($id, $fd) = each($invoiceArray)) {
    $gtotal  += $invoiceArray[$id]['amount'];
    $ttltime += $invoiceArray[$id]['hour'];
}

$vat_rate = $kndArray['knd_vat'];
if (!is_numeric($vat_rate)) {
    $vat_rate = $kga['conf']['defaultVat'];
}

$vat   = $vat_rate*$gtotal/100;
$total = $gtotal-$vat;
$doc   = new tinyDoc();

// use zip extension if available
if (class_exists('ZipArchive')) {
    $doc->setZipMethod('ziparchive');
}
else {
    $doc->setZipMethod('shell');
    try {
        $doc->setZipBinary('zip');
        $doc->setUnzipBinary('unzip');
    }
    catch (tinyDocException $e) {
        $doc->setZipMethod('pclzip');
    }
}

$doc->setProcessDir('../../temporary');

//This is where the template is selected

$templateform = "templates/" . $_REQUEST['ivform_file'];
$doc->createFrom($templateform);

$doc->loadXml('content.xml');

$doc->mergeXmlBlock('row', $invoiceArray);

$doc->saveXml();
$doc->close();

// send and remove the document
$doc->sendResponse();
$doc->remove();
