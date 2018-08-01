#						Transcribe youtube videos with AWS Amazon Transcribe and save result into vtt subtitles format


##  What is it?
##  -----------
Several youtube files haven't subtiles ( for example for very long videos ). This project take
youtube video and transcribe, then save result into WEBVTT subtitles.

This project use AWS services like _S3_ and _AWS Amazon Transcribe_ and require AWS account.
Required soft :

  + youtube-dl
  + ffmpeg
  + awscli


##  The Latest Version

	version 1.1 2018.07.31

##  Whats new

	version 1.1 2018.08.01
  + Fixed exit codes for errors.
  + Remove temporary files



##  How to install
For Ubuntu ( or any Debian distributive):
```
apt-get -y install php php-xml python
sudo curl -L https://yt-dl.org/downloads/latest/youtube-dl -o /usr/local/bin/youtube-dl
sudo chmod a+rx /usr/local/bin/youtube-dl

wget https://johnvansickle.com/ffmpeg/releases/ffmpeg-release-64bit-static.tar.xz
tar xf ffmpeg-release-64bit-static.tar.xz
sudo mkdir /usr/share/ffmpeg
sudo mv ffmpeg-4.0.2-64bit-static/ /usr/share/ffmpeg
sudo ln -s /usr/share/ffmpeg/ffmpeg-4.0.2-64bit-static/ffmpeg /usr/bin/ffmpeg
sudo ln -s /usr/share/ffmpeg/ffmpeg-4.0.2-64bit-static/ffprobe /usr/bin/ffprobe

wget http://docs.aws.amazon.com/aws-sdk-php/v3/download/aws.phar
mv aws.phar /home/ubuntu/php/ # this path is the same where youtube_subtitles.php script
```

## How to run
```
$ php youtube_subtitles.php -i 4LGe265pwvU  -s /tmp/4LGe265pwvU.vtt
Info: Start download audio for youtube_id 4LGe265pwvU
Info: Convert audio track to wav format
Info: Upload wav audio to S3
Info: Start transcription job
Info: Check status of transcription job
Info: Job job_4LGe265pwvU_2018-07-30_21_03_55 in progress
Info: Job job_4LGe265pwvU_2018-07-30_21_03_55 in progress
Info: Job job_4LGe265pwvU_2018-07-30_21_03_55 completed! Output file: https://s3.us-west-2.amazonaws.com/freesound/job_4LGe265pwvU_2018-07-30_21_03_55.json
Info: Download transcribed json file from S3 to local fs
Info: Decode json file
Info: Make subtitles file
Info: Save subtitles file
Info: All done. Output subtitles file /tmp/4LGe265pwvU.vtt
```


##  Bugs
##  ------------
	1. Do not remove temporary files



  Licensing
  ---------
	GNU

  Contacts
  --------

     o korolev-ia [at] yandex.ru
     o http://www.unixpin.com
