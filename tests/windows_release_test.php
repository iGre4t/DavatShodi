<?php
declare(strict_types=1);
define('WINDOWS_RELEASE_DIRECTORY', sys_get_temp_dir().'/windows-release-test-'.bin2hex(random_bytes(6)));
require_once dirname(__DIR__).'/api/lib/windows-releases.php';
function releaseAssert(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
mkdir(WINDOWS_RELEASE_DIRECTORY);
try {
 $exe=WINDOWS_RELEASE_DIRECTORY.'/input.exe';file_put_contents($exe,'MZ'.str_repeat('x',2046));
 $release=windowsReleasePublish('3.0.0','تست',$exe,'exe');
 releaseAssert($release['size']===2048&&$release['sha256']===hash_file('sha256',$exe),'Release checksum/size incorrect');
 releaseAssert(windowsReleaseRead()['version']==='3.0.0','Manifest not published');
 try {windowsReleasePublish('2.0.0','',$exe,'exe');throw new RuntimeException('Downgrade allowed');}catch(InvalidArgumentException $expected){}
 $archive=WINDOWS_RELEASE_DIRECTORY.'/build.zip';$zip=new ZipArchive();$zip->open($archive,ZipArchive::CREATE);$zip->addFile($exe,'MCI EGM.exe');$zip->close();
 windowsReleasePublish('3.1.0','',$archive,'zip');
 $zip=new ZipArchive();$zip->open($archive);$zip->addFromString('../escape.exe','MZ'.str_repeat('x',2046));$zip->close();
 try{windowsReleasePublish('3.2.0','',$archive,'zip');throw new RuntimeException('Multiple/traversing files allowed');}catch(InvalidArgumentException $expected){}
 releaseAssert(windowsReleaseRead()['version']==='3.1.0','Rejected archive changed public release');
 file_put_contents($exe,'invalid'.str_repeat('x',2048));
 try{windowsReleasePublish('3.2.0','',$exe,'exe');throw new RuntimeException('Invalid executable published');}catch(InvalidArgumentException $expected){}
 echo "PASS: executable and ZIP publication, numeric versions, checksum, atomic manifest, invalid/archive-path rejection.\n";
}finally{foreach(glob(WINDOWS_RELEASE_DIRECTORY.'/*')as$file)unlink($file);foreach(glob(WINDOWS_RELEASE_DIRECTORY.'/.*')as$file)if(is_file($file))unlink($file);rmdir(WINDOWS_RELEASE_DIRECTORY);}
