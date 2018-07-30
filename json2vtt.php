<?php
/* korolev-ia(at)yandex.ru
This script take json file - result of "AWS Transcribe Service"
and convert to vtt subtitles format
 */

$debug = false;
$jsonFile = "asrOutput.json";
$vttFilename = "asrOutput.vtt";

$maxLineLength = 40;
$maxSubtitlesShow = 4;
$minSubtitlesShow = 1;
$cps = 18; // chars per second


try {
    $string = file_get_contents($jsonFile);
    $json = json_decode($string, true);
} catch (Exception $e) {
    writeToLog('Error: Cannot parse json file ' . $e->getMessage());
    exit(1);
}

if (!isset($json["results"]["items"])) {
    echo var_dump($json);
    writeToLog('Error: Incorrect format of json file');
    exit(1);
}

$vtt = "WEBVTT


";

$startNewLine = true;
$begin = 0;
$finish = 0;
$vttTextLine = "";

foreach ($json["results"]["items"] as $item) {
    //echo var_dump($item["alternatives"][0]);

    $content = $item["alternatives"][0]["content"] . " ";
    if ("pronunciation" != $item["type"]) {
        $vttTextLine .= $content;
        continue;
    }

    if (!$startNewLine
        &&
        (strlen($vttTextLine) + strlen($content) > $maxLineLength)
        ||
        ($item["end_time"] - $begin) > $maxSubtitlesShow
    ) {
        if (strlen($vttTextLine) / ($finish - $begin) > $cps) {
            $finish = $begin + max(strlen($vttTextLine) / $cps, $minSubtitlesShow);
        }
        $vtt .= doVttRecord($vttTextLine, $begin, $finish);
        $startNewLine = true;
    }

    if ($startNewLine) {
        if ($finish > $item["start_time"]) {
            $begin = $finish;
        } else {
            $begin = $item["start_time"];
        }
        $finish = $item["end_time"];
        $vttTextLine = $content;
        $startNewLine = false;
        continue;
    }

    if (!$startNewLine) {
        $finish = $item["end_time"];
        $vttTextLine .= $content;
        continue;
    }

//  00:00.000 --> 01:24.000
    //  Introduction
}
$vtt .= doVttRecord($vttTextLine, $begin, $finish);

if ($debug) {
    echo $vtt;
    exit;
}

try {
    file_put_contents($vttFilename, $vtt);
} catch (Exception $e) {
    writeToLog("Error: Cannot save file $$vttFilename " . $e->getMessage());
    exit(1);
}

/**
 * doVttRecord
 * function return striong in vtt format ( time+text )
 *
 * @param    string $vttTextLine
 * @param    integer $begin
 * @param    integer $finish
 * @return    string
 */

function doVttRecord($vttTextLine, $begin, $finish)
{
    $start = float2time($begin);
    $end = float2time($finish);
    return ("$start --> $end" . PHP_EOL . $vttTextLine . PHP_EOL . PHP_EOL);

}

/**
 * writeToLog
 * function print messages to console
 *
 * @param    string $message
 * @return    string
 */
function writeToLog($message)
{
    echo "$message\n";
}

/**
 * time2float
 * this function translate time in format 00:00:00.00 to seconds
 *
 * @param    string $t
 * @return    float
 */

function time2float($t)
{
    $matches = preg_split("/:/", $t, 3);
    if (array_key_exists(2, $matches)) {
        list($h, $m, $s) = $matches;
        return ($s + 60 * $m + 3600 * $h);
    }
    $h = 0;
    list($m, $s) = $matches;
    return ($s + 60 * $m);
}

/**
 * float2time
 * this function translate time from seconds to format 00:00:00.00
 *
 * @param    float $i
 * @return    string
 */
function float2time($i)
{
    $h = intval($i / 3600);
    $m = intval(($i - 3600 * $h) / 60);
    $s = $i - 60 * floatval($m) - 3600 * floatval($h);
    return sprintf("%01d:%02d:%05.2f", $h, $m, $s);
}
