<?php
/* korolev-ia(at)yandex.ru
https://github.com/ikorolev72/youtube_subtitles

v1.0
 */

$basedir = dirname(__FILE__);

require "$basedir/aws.phar";
//require "$basedir/credentials.php";
//require 'vendor/autoload.php';


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

$options = getopt('h::');
$help = isset($options['h']) ? $options['h'] : '';
if ($help) {
    help();
}

$tempFilesForDelete = array();

// download from YT
//writeToLog("Info: Get list of S3 objects in bucket $s3BucketIn");

$sdk = new Aws\Sdk($sharedConfig);
$s3Client = $sdk->createS3();
try {
    $result = $s3Client->listObjectsV2([
        'Bucket' => $s3BucketIn, // REQUIRED
        'MaxKeys' => 999,
    ]);
    if ($debug) {
        echo $result;
    }
} catch (Exception $e) {
    writeToLog('Error: ' . $e->getMessage());
    exit(1);
}

$out=array();
$out["Name"]=$result["Name"];
$out["Contents"]=$result["Contents"];
$out["x-amz-bucket-region"]=$result["@metadata"]["headers"]["x-amz-bucket-region"];

//"x-amz-bucket-region": "us-west-2",
$jsonResult=json_encode( $out , JSON_PRETTY_PRINT );
echo $jsonResult;



//writeToLog("Info: All done");

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

/**
 * writeToLog
 * function print messages to console
 *
 * @param    string $message
 * @return    string
 */
function writeToLog($message)
{
    //echo "$message" . PHP_EOL;
    fwrite(STDERR, "$message" . PHP_EOL);
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

  This script show s3 bucket list
	Usage: $script  [-h]
	where:
  -h this help

  Example:
  $script -h" . PHP_EOL);
    exit(1);
}
