<?php
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

$options = getopt('i:h::');

$youtubeId = isset($options['i']) ? $options['i'] : '';
$help = isset($options['h']) ? $options['h'] : '';

if ($help) {
    help("");
}

if (!$youtubeId) {
    help("Need youtube_id ( -i ) parameter");
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

    $cmd = "$ffmpeg -y -i $tmpAudio1 -vn -f wav $tmpAudio2";
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

exit(0);

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
 * doExec
 * @param    string    $Command
 * @return integer 0-error, 1-success
 */

function doExec($Command)
{
    $outputArray = array();
    exec($Command, $outputArray, $execResult);
    if ($execResult) {
        writeToLog(join("\n", $outputArray));
        return 0;
    }
    return 1;
}

function help($msg)
{
    $script = basename(__FILE__);
    fwrite(STDERR,
        "$msg

  This script download audio track from youtube, upload it to s3 and transcript it into subtitles
	Usage: $script -i youtube_id [-h]
	where:
  -h this help
  -i youtube_id

	Example: $script -i 4LGe205pwc
	\n");
    exit(-1);
}
