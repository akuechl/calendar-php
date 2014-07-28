<?php
/**
 * This script can create a ICS appointment file or a redirect to the google calendar.
 * 
 * Date format is "<day 2 digits>.<month 2 digits>.<year 4 digits>" (without ")
 * Time format is "<hour 2 digits>:<minutes 2 digits>" (without ")
 * 
 * Timezone can be set with $TIME_ZONE globally.
 * 
 * Input parameter
 *  start-date The start date 
 *  start-time The start time, optionally
 *  end-date The end date
 *  end-time The end time, optionally
 *  theme the description of the appointment, max 1024 signs long
 *  location the location, max 1024 signs long
 *  type the type can be "google" or "ics" (without ")
 *
 * @author https://github.com/admiralsmaster
 * @url https://github.com/admiralsmaster/calendar-php
 * @version 1.0
 * @license MIT license http://opensource.org/licenses/MIT
 * 
 * example:
 * http://example.com/calendar.php?start-date=12.04.2014&start-time=09%3A00
 *      &end-date=12.04.2014&end-time=12%3A00
 *      &theme=Fr%C3%BChjahrsputz%202014&location=Parkeingang&type=google
 * http://example.de/calendar.php?start-date=12.04.2014&end-date=12.04.2014
 *      &theme=Fr%C3%BChjahrsputz%202014&location=Parkeingang&type=google
 * http://example.de/calendar.php?start-date=12.04.2014&end-date=12.04.2014
 *      &theme=Fr%C3%BChjahrsputz%202014&location=Parkeingang&type=ics
 */

// 
// Configure this values
//
// this text is added to the appointment body text
static $ADD_TO_TEXT = "http://www.example.com";
// "namespace", i.e. set the name of your website
static $NAME_SPACE = "example.com";
// timezone of input values
static $TIME_ZONE = "Europe/Berlin";
// ICS file name
static $ICS_FILE = "appointment.ics";

// ICS line separator, see http://tools.ietf.org/html/rfc5545#section-3.1
static $ICS_NL = "\r\n";
date_default_timezone_set($TIME_ZONE);

// validation pattern
static $val_date = '/^\d\d\.\d\d.\d\d\d\d$/';
static $val_time = '/^\d\d:\d\d$/';
static $val_max_length = 1024;

// read in data
$startDate = $_REQUEST['start-date'];
$startTime = $_REQUEST['start-time'];
if ($startTime == null)
    $startTime = "";
$endDate = $_REQUEST['end-date'];
$endTime = $_REQUEST['end-time'];
if ($endTime == null)
    $endTime = "";
$theme = $_REQUEST['theme'];
$location = $_REQUEST['location'];
$type = $_REQUEST['type'];

// validation
if ($type != "ics" && $type != "google")
    die ("Typ unknown");
if (!preg_match($val_date, $startDate)) 
    die ("Format start date not valid");
if ($startTime != "" && !preg_match($val_time, $startTime)) 
    die ("Format start time not valid");
if (!preg_match($val_date, $endDate)) 
    die ("Format end date not valid");
if ($endTime != "" && !preg_match($val_time, $endTime)) 
    die ("Format end time not valid");
if (strlen($theme) > $val_max_length) 
    die ("theme to long");
if (strlen($location) > $val_max_length) 
    die ("Location to long");

/** 
 * mask special characters for ICS.
 * @param $input input string
 * @return the mask string
 */
function encodeTextForIcs($input) {
    return str_replace("(:|,|;|\\)", "\\$1", $input);
}

/**
 * Limit the line to 75 signs for ICS format.
 * @see http://tools.ietf.org/html/rfc5545#section-3.1
 * @param $content the line to cut
 * @return the line with needed line breaks
 */
function limitLineICS($content) {
    global $ICS_NL;

    $maxLenFirst = 75 - strlen($ICS_NL); // first line has a maximum length of 75 minus \r\n
    $maxLenAll = 75 - strlen(" ") - strlen($ICS_NL); // further line have a maximum length of 75 minus start space and \r\n
    $string = $content;
    
    $result = substr($string, 0, $maxLenFirst) . $ICS_NL;
    $string = substr($string, $maxLenFirst);
    while ($string != "") {
        $result .= " " . substr($string, 0, $maxLenAll) . $ICS_NL;
        $string = substr($string, $maxLenAll);
    }
    return $result;
}

/**
 * Convert a date (without time) in a date string.
 * @param $date the date in format day.month.year
 * @param $offsetString add an offset to the date
 * @return the date string
 */
function convertToUTCDate($date, $offsetString) {
    global $TIME_ZONE;
    
    $date = date_create_from_format ("d.m.Y" , $date, timezone_open($TIME_ZONE) );
    date_add($date, new DateInterval($offsetString));
    // no conversion to UTC (is day only)
    return date_format($date, 'Ymd');
}

/**
 * Convert the date and the time in a UTC string.
 * @param $date date in format day.month.year
 * @param $time time in format hour:minutes
 * @return UTC string
 */
function convertToUTC($date, $time) {
    global $TIME_ZONE;

    $date = date_create_from_format ("d.m.Y H:i" , $date . ' ' . $time, timezone_open($TIME_ZONE));
    date_timezone_set($date, timezone_open('UTC'));
    return date_format($date, "Ymd\THi00\Z");
}

/**
 * Build the Google date pattern.
 * @param $startDate start date
 * @param $startTime start time
 * @param $endDate end date
 * @param $endTime end time
 * @return the date-time string
 */
function getGoogleCalendarDate($startDate, $startTime, $endDate, $endTime) {
    if ($startTime === "" || $endTime === "") {
        return convertToUTCDate($startDate, 'P0D') . '/' . convertToUTCDate($endDate, 'P1D');
    }
    return convertToUTC($startDate, $startTime) . '/' . convertToUTC($endDate, $endTime);
}

/**
 * Create a date string for ICS. If $time is an empty string the date is used only. In this case
 * ";VALUE=DATE" is added to the return string. The returned string added th colon too.
 * @param $date the date, required
 * @param $time the time, could be an empty string
 * @param $notInclusive if true and $time is not set the end day is non inclusive, 
 *              see http://tools.ietf.org/html/rfc5545#section-3.6.1
 */
function getIcsCalendarDateWithPrefix($date, $time, $notInclusive) {
    if ($time === "") {
        return ";VALUE=DATE:" . convertToUTCDate($date, ($notInclusive ? 'P1D' : 'P0D'));
    }
    return ":" . convertToUTC($date, $time);
}

/**
 * Add HTTP header for non caching.
 */
function addNonCacheHeader() {
    @header('pragma: no-cache');
    @header('expires: Mon, 26 Jul 1997 05:00:00 GMT');
    @header('cache-control: no-store, no-cache, must-revalidate');
}

if ($type == "ics") {
    $link ="BEGIN:VCALENDAR" . $ICS_NL
    . "VERSION:2.0" . $ICS_NL
    . "PRODID:-//" . $NAME_SPACE . "//v1.0//EN" . $ICS_NL
    . "METHOD:PUBLISH" . $ICS_NL
    . "BEGIN:VEVENT" . $ICS_NL
    . limitLineICS("UID:" . hash_hmac("ripemd160", ($theme . $startDate), "7z5uiohoui547d9eh") . "@" . $NAME_SPACE)
    . limitLineICS("LOCATION:" . encodeTextForIcs($location))
    . limitLineICS("DESCRIPTION:" . encodeTextForIcs($theme) . "\\n" . encodeTextForIcs($ADD_TO_TEXT))
    . limitLineICS("SUMMARY:" . encodeTextForIcs($theme))
    . "CLASS:PUBLIC" . $ICS_NL
    . "DTSTART" . getIcsCalendarDateWithPrefix($startDate, $startTime, false) . $ICS_NL
    // DTEND is non-inclusive, is relevant if is a day only
    . "DTEND" . getIcsCalendarDateWithPrefix($endDate, $endTime, true) . $ICS_NL
    . "END:VEVENT" . $ICS_NL
    . "END:VCALENDAR" . $ICS_NL;
    
    header("Content-Type: text/calendar; charset=utf-8");
    header("Content-Length: " . strlen($link));
    header('Content-Disposition: attachment; filename="' . $ICS_FILE . '"');
    header("X-Content-Type-Options: nosniff"); // trust the content type, no mime sniffing
    addNonCacheHeader();
    
    echo ($link);
}else if ($type == "google") {
    
    $link = "https://www.google.com/calendar/event?action=TEMPLATE";
    $link .= "&text=" . rawurlencode($theme);
    $link .= "&dates=" . getGoogleCalendarDate($startDate, $startTime, $endDate, $endTime);
    $link .= "&details=" . rawurlencode($theme) . '%0A' .  rawurlencode($ADD_TO_TEXT);
    $link .= "&location=" . rawurlencode($location);
    $link .= "&trp=true&sprop=";
    $link .= "&sprop=name:" . rawurlencode($NAME_SPACE);
   
    header('Content-Length: ' . strlen($link));
    header('Location: ' . $link, true, 303);
    addNonCacheHeader();
    
    echo $link;
}
?>