<?php
/* korolev-ia(at)yandex.ru
Transcribe youtube videos with AWS Amazon Transcribe and save result into vtt subtitles format
 */

require '/home/ubuntu/php/aws.phar';
//require 'vendor/autoload.php';

use Aws\TranscribeService\TranscribeServiceClient;

$s3BucketIn = 'freesound';
$s3BucketOut = 'freesound';
//   $s3BucketIn='https://s3-us-west-2.amazonaws.com/freesound/test/in';
//$s3BucketOut='https://s3-us-west-2.amazonaws.com/freesound/test/out';

$youtube_dl = "youtube-dl";
$ffmpeg = "ffmpeg";
$ffprobe = "ffprobe";
$tmpDir = "/tmp";
$debug = false;
//
// converting to subtitles variables
$maxLineLength = 40;
$maxSubtitlesShow = 4;
$minSubtitlesShow = 1;
$cps = 18; // chars per second
// end of converting to subtitles variables
//

$options = getopt('s:i:h::');

$youtubeId = isset($options['i']) ? $options['i'] : '';
$vttFilename = isset($options['s']) ? $options['s'] : '';
$help = isset($options['h']) ? $options['h'] : '';

if ($help) {
    help();
}

if (!$youtubeId) {
    help("Need youtube_id ( -i ) parameter");
}
if (!$vttFilename) {
    help("Need output subtitles filename ( -s ) parameter");
}

$s3FileName = "test/in/$youtubeId.wav";
$tmpAudio1 = "$tmpDir/$youtubeId.mp3";
$tmpAudio2 = "$tmpDir/$youtubeId.wav";

if (!$debug) {
// download from YT and convert into mp3
    writeToLog("Info: Start download audio for youtube_id $youtubeId");

    $cmd = "$youtube_dl --newline -f bestaudio/140/251/171/250/249/worstaudio --extract-audio --audio-format mp3 -o \"$tmpAudio1\" \"https://www.youtube.com/watch?v=$youtubeId\"";
    if (!doExec($cmd)) {
        writeToLog("Error. Cannot download audio stream for youtube_id $youtubeId to $tmpAudio1");
        exit(2);
    }

// convert into wav
    writeToLog("Info: Convert audio track to wav format");

    $cmd = "$ffmpeg -y -loglevel warning -i $tmpAudio1 -vn -f wav $tmpAudio2";
    if (!doExec($cmd)) {
        writeToLog("Error. Cannot convert $tmpAudio1 to $tmpAudio2");
        exit(2);
    }
}

// upload to s3

writeToLog("Info: Upload wav audio to S3");

$sharedConfig = [
    'profile' => 'default',
    'region' => 'us-west-2',
    'version' => 'latest',
];
$sdk = new Aws\Sdk($sharedConfig);
$s3Client = $sdk->createS3();

// Send a PutObject request and get the result object.
try {
    $result = $s3Client->putObject([
        'Bucket' => $s3BucketIn,
        'Key' => $s3FileName,
        'SourceFile' => $tmpAudio2,
    ]);
    if ($debug) {
        echo $result;
    }
} catch (Exception $e) {
    writeToLog('Error: ' . $e->getMessage());
}
if (!isset($result["@metadata"]["effectiveUri"])) {
    writeToLog('Error: Cannot get the effectiveUri in AWS answer');
    exit(2);
}

// do Transcription
writeToLog("Info: Start transcription job");

$effectiveUri = $result["@metadata"]["effectiveUri"];
$TranscriptionJobName = "job_${youtubeId}_" . date("Y-m-d_H_i_s");
$client = new Aws\TranscribeService\TranscribeServiceClient($sharedConfig);

try {
    $result = $client->startTranscriptionJob([
        'LanguageCode' => 'en-US', // REQUIRED
        'Media' => [ // REQUIRED
            'MediaFileUri' => $effectiveUri,
        ],
        'MediaFormat' => 'wav', // REQUIRED
        //'MediaSampleRateHertz' => 16000,
        'OutputBucketName' => $s3BucketOut,
        'Settings' => [
            'MaxSpeakerLabels' => 5,
            'ShowSpeakerLabels' => true,
            //'VocabularyName' => '<string>',
        ],
        'TranscriptionJobName' => $TranscriptionJobName, // REQUIRED
    ]);

    if ($debug) {
        echo $result;
    }
} catch (Exception $e) {
    writeToLog('Error: ' . $e->getMessage());
}

writeToLog("Info: Check status of transcription job");
while (true) {
    try {
        $result = $client->getTranscriptionJob([
            'TranscriptionJobName' => $TranscriptionJobName, // REQUIRED
        ]);
        if (!isset($result["TranscriptionJob"]["TranscriptionJobStatus"])) {
            writeToLog("Error: Cannot get status of transcript job $TranscriptionJobName");
            exit(3);
        }

        if ($result["TranscriptionJob"]["TranscriptionJobStatus"] === "FAILED") {
            writeToLog("Error: Job $TranscriptionJobName failed." . $result["TranscriptionJob"]["FailureReason"]);
            exit(3);
        }
        if ($result["TranscriptionJob"]["TranscriptionJobStatus"] === "COMPLETED") {
            writeToLog("Info: Job $TranscriptionJobName completed! Output file: " . $result["TranscriptionJob"]["Transcript"]["TranscriptFileUri"]);
            break;
        }
        writeToLog("Info: Job $TranscriptionJobName in progress");
        sleep(10);
    } catch (Exception $e) {
        writeToLog('Error: ' . $e->getMessage());
    }
}



// download transcribed json file from S3 to local fs
writeToLog("Info: Download transcribed json file from S3 to local fs");
$s3UriArray = parseS3Uri($sharedConfig, $result["TranscriptionJob"]["Transcript"]["TranscriptFileUri"]);
$jsonFile = "$tmpDir/" . $s3UriArray["key"];
try {
    $result = $s3Client->getObject([
        'Bucket' => $s3UriArray["bucket"],
        'Key' => $s3UriArray["key"],
        'SaveAs' => $jsonFile,
    ]);
    if ($debug) {
        echo $result;
    }
} catch (Exception $e) {
    writeToLog("Error: Cannot download S3 file " . $e->getMessage());
}

// decode json file
writeToLog("Info: Decode json file");
try {
    $string = file_get_contents($jsonFile);
    $json = json_decode($string, true);
} catch (Exception $e) {
    writeToLog('Error: Cannot parse json file ' . $e->getMessage());
    exit(1);
}

// make subtitles from json
writeToLog("Info: Make subtitles file");
$vtt = makeVtt($json, $maxLineLength, $maxSubtitlesShow, $minSubtitlesShow, $cps);

// save  vtt file
writeToLog("Info: Save subtitles file");
try {
    file_put_contents($vttFilename, $vtt);
} catch (Exception $e) {
    writeToLog("Error: Cannot save file $vttFilename " . $e->getMessage());
    exit(1);
}
writeToLog("Info: All done. Output subtitles file $vttFilename");
exit(0);



/* 

Function 

*/
function parseS3Uri($sharedConfig, $s3Uri)
{
    $s3Parser = new Aws\S3\S3UriParser($sharedConfig);
    try {
        $s3UriArray = $s3Parser->parse($s3Uri);
    } catch (Exception $e) {
        writeToLog('Error: Cannot parce output URI for json file' . $e->getMessage());
        exit(1);
    }
    return ($s3UriArray);
}

function makeVtt($json, $maxLineLength, $maxSubtitlesShow, $minSubtitlesShow, $cps)
{

    if (!isset($json["results"]["items"])) {
        echo var_dump($json);
        writeToLog('Error: Incorrect format of json file');
        exit(1);
    }

    $vtt = "WEBVTT" . PHP_EOL . PHP_EOL;

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
    }
    $vtt .= doVttRecord($vttTextLine, $begin, $finish);
    return ($vtt);
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
    echo "$message" . PHP_EOL;
}

/**
 * doExec
 * @param    string    $Command
 * @return integer 0-error, 1-success
 */

function doExec($Command)
{
    $outputArray = array();
    exec($Command, $outputArray, $execResult);
    if ($execResult) {
        writeToLog(join(PHP_EOL, $outputArray));
        return 0;
    }
    return 1;
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

function help($msg = '')
{
    $script = basename(__FILE__);
    fwrite(STDERR,
        "$msg

  This script download audio track from youtube, upload it to s3 and transcript it into WEBVTT subtitles
	Usage: $script -i youtube_id -s subtitles.vtt [-h]
	where:
  -h this help
  -i youtube_id
  -s subtitles filename

  Example: $script -i 4LGe205pwc -s /tmp/4LGe205pwc.vtt" . PHP_EOL);
    exit(-1);
}
