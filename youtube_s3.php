<?php
/* korolev-ia(at)yandex.ru
https://github.com/ikorolev72/youtube_subtitles

v1.0
 */

$basedir = dirname(__FILE__);

require "$basedir/aws.phar";
//require 'vendor/autoload.php';
/*
$sharedConfig = [
    'profile' => 'default',
    'region' => 'us-west-2',
    'version' => 'latest',
];
*/
use Aws\Credentials\CredentialProvider;
$provider = CredentialProvider::ini();
$profile = 'default';
$path = "$basedir/.aws/credentials";
$provider = CredentialProvider::ini($profile, $path);
$provider = CredentialProvider::memoize($provider);
$sharedConfig = [
    'region' => 'us-west-2',
    'version' => 'latest',
    'credentials' => $provider
];



$s3BucketIn = 'freesound';
$s3BucketOut = 'freesound';
//$s3BucketIn='https://s3-us-west-2.amazonaws.com/freesound/test/in';
//$s3BucketOut='https://s3-us-west-2.amazonaws.com/freesound/test/out';

$youtube_dl = "youtube-dl";
$ffmpeg = "ffmpeg";
$ffprobe = "ffprobe";
$tmpDir = "/tmp";
$debug = false;
//

$tmpDir = "/tmp/youtube-dl";
$today = date("F j, Y, g:i a");
$dt = date("U");

$data = null;
if (!is_dir($tmpDir)) {
    @mkdir($tmpDir);
}

$options = getopt('i:f:h::n::m:s::a::l:');

$youtubeId = isset($options['i']) ? getYoutubeIdFromUrl($options['i']) : false;
$outputFileMask = isset($options['m']) ? $options['m'] : $youtubeId;
$formats = isset($options['f']) ? $options['f'] : false;
$language = isset($options['l']) ? $options['l'] : "en";

$doNotRemoveFiles = isset($options['n']) ? true : false;
$writeSubtitles = isset($options['s']) ? true: false;
$writeAutoSubtitles = isset($options['a']) ? true : false;
$help = isset($options['h']) ? $options['h'] : '';

if ($help) {
    help();
}

if (!$youtubeId) {
    help("Need youtube_id ( -i ) parameter (eg '-i pMXv2YR64R8')");
}
if (!$formats) {
  help("Need yotube-dl formats ( -f ) parameter ( eg '-f 22' or '-f 251,249') ");
}
if (!$formats) {
  help("Need yotube-dl formats ( -f ) parameter ( eg '-f 22' or '-f 251,249' ");
}

$youtubeDlOptions = " -f $formats ";
if( $writeAutoSubtitles || $writeSubtitles ) {
  $youtubeDlOptions.=($writeSubtitles) ? " --write-sub " :  " --write-auto-sub ";
  $youtubeDlOptions.=" --sub-lang $language ";
}


$tempFilesForDelete = array();

// download from YT
writeToLog("Info: Start download for youtube_id $youtubeId");

$cmd = "$youtube_dl --newline $youtubeDlOptions -o \"$tmpDir/$outputFileMask.%(ext)s\" \"https://www.youtube.com/watch?v=$youtubeId\"";
if (!doExec($cmd)) {
    writeToLog("Error. Cannot download files youtube_id $youtubeId to $tmpDir");
    deleteTempFiles($tempFilesForDelete);
    exit(2);
}

$sdk = new Aws\Sdk($sharedConfig);
$s3Client = $sdk->createS3();

foreach (glob("$tmpDir/${outputFileMask}.*") as $file) {
    if (!is_file($file)) {
        continue;
    }
    $pathParts = pathinfo($file);
//    $s3FileName = "${youtubeId}." . $pathParts['extension'];
    $s3FileName =  $pathParts['basename'];
    

// upload to s3
    writeToLog("Info: Upload $file to S3");
// Send a PutObject request and get the result object.
    try {
        $result = $s3Client->putObject([
            'Bucket' => $s3BucketIn,
            'Key' => $s3FileName,
            'SourceFile' => $file,
        ]);
        if ($debug) {
            echo $result;
        }
    } catch (Exception $e) {
        writeToLog('Error: ' . $e->getMessage());
    }
    if (!isset($result["@metadata"]["effectiveUri"])) {
        writeToLog('Error: Cannot get the effectiveUri in AWS answer');
        deleteTempFiles($tempFilesForDelete);
        exit(4);
    }
    if (!$doNotRemoveFiles) {
        if (file_exists($file)) {
            writeToLog("Info: Remove local file $file");
            @unlink($file);
        }
    }
}
writeToLog("Info: All done");
deleteTempFiles($tempFilesForDelete);
exit(0);

/*

Function

 */

function deleteTempFiles($tempFilesForDelete)
{
    foreach ($tempFilesForDelete as $filename) {
        if (file_exists($filename)) {
            @unlink($filename);
        }
    }
    return (true);
}

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

  This script download from youtube and upload it to s3
	Usage: $script -i youtube_id -f formats [-s|a] [-l lang] [-m filemask] [-n] [-h]
	where:
  -h this help
  -i youtube_id or youtube_url
  -f yotube-dl formats ( -f ) parameter ( eg '-f 22' or '-f 251,249') 
  -a download automatic captions ( auto subtitles ) ( optional, mutually exclusive with -s, default: without subtitles)
  -s download subtitles ( optional, mutually exclusive with -a, default: without subtitles  )
  -l subtitles language ( optional, default: 'en' )
  -m output file mask ( optional, default: youtube_id )
  -n do not remove downloaded files from local filesystem ( optional, default: remove local files)
  Example:
  $script -i https://www.youtube.com/watch?v=nfGQyKrRpyM  -f 22 -a -l en -m \'favorit_video_12345\' -n'" . PHP_EOL);
    exit(1);
}



function getYoutubeIdFromUrl($url)
{
    // https://www.youtube.com/watch?v=nfGQyKrRpyM
    if (preg_match("/v=([-\w]+)/", $url, $matches)) {
        return ($matches[1]);
    }
    if (preg_match("/^([-\w]+)$/", $url, $matches)) {
        return ($matches[1]);
    }
    return (false);
}
