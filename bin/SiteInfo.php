<?php

//  /usr/bin/php /var/tools/KoalaSiteInfo/SiteInfo.phar 'http://www.aboutyou.de/dein-profil' AY-Live 4B288DE3-A40F-4CA7-B18E-F49361A26AF2 '{"pageSize":"3","fileSize":"800","excludedFiles":{"89576813":{"filename":"http:\/\/www.aboutyou.de\/assets\/js\/theme-v3.min.js(.*)"},"28771459":{"filename":" http:\/\/www.aboutyou.de\/assets\/css\/theme-v3.css(.*)"}}}' http://status.leankoala.com/webhook/

include_once __DIR__ . "/../vendor/autoload.php";

if (count($argv) < 5) {
    echo "\n  SiteInfo - Version ##development##\n";
    die("\n  Usage: SiteInfo.phar url system api_key options <koalamon_server>\n\n");
}

$url = $argv[1];
$system = $argv[2];
$projectApiKey = $argv[3];

$options = json_decode($argv[4]);

$knownBigFiles = [];
$maxFileSize = 100000000;
$maxPageSize = 100000;

if (!is_null($options) && $options !== false) {
    if (property_exists($options, 'pageSize')) {
        $maxPageSize = $options->pageSize;
    }

    if (property_exists($options, 'fileSize')) {
        $maxFileSize = $options->fileSize;
    }

    if (property_exists($options, 'excludedFiles')) {
        foreach ($options->excludedFiles as $excludedFile) {
            $knownBigFiles[] = $excludedFile->filename;
        }
    }
}

if (array_key_exists(5, $argv)) {
    $koalamonServer = $argv[5];
} else {
    $koalamonServer = null;
}

$guzzle = new \GuzzleHttp\Client();
$res = $guzzle->request('GET', $url);

$koalamonReporter = new \Koalamon\Client\Reporter\Reporter('', $projectApiKey, $guzzle, $koalamonServer);

$document = new \whm\Html\Document((string)$res->getBody());

$dependencies = $document->getDependencies(new \GuzzleHttp\Psr7\Uri($url), false);

$totalSize = 0;
$bigFiles = 0;

$bigFileNames = [];

foreach ($dependencies as $dependency) {
    try {
        $response = $guzzle->request('GET', (string)$dependency);
        $responseSize = strlen($response->getBody());
        $totalSize += $responseSize;

        $known = false;
        foreach ($knownBigFiles as $knownBigFile) {
            if (preg_match("^" . $knownBigFile . "^", (string)$dependency) > 0) {
                $known = true;
                continue;
            }
        }

        if (!$known) {
            if ($responseSize > ($maxFileSize * 1024)) {
                echo "\nBig file found: " . ((string)$dependency) . "\n";
                $bigFileNames[] = ['file' => $dependency, 'size' => $responseSize];
                $bigFiles++;
            }
        }
    } catch (\Exception $e) {
    }
}

if ($bigFiles > 0) {
    $status = \Koalamon\Client\Reporter\Event::STATUS_FAILURE;
    $message = "Too many big files (>" . $maxFileSize . " KB) on " . $url . " found. <ul>";
    foreach ($bigFileNames as $bigFileName) {
        $message .= "<li>File: " . $bigFileName['file'] . ", size: " . round($bigFileName['size'] / 1024) . " KB</li>";
    }
    $message .= "</ul>";
} else {
    $status = \Koalamon\Client\Reporter\Event::STATUS_SUCCESS;
    $message = "No big files (>" . $maxFileSize . " KB) found. Checked " . count($dependencies) . " files.";
}

$bigFileEvent = new \Koalamon\Client\Reporter\Event('SiteInfo_BigFiles_' . $url, $system, $status, 'SiteInfoBigFile', $message, $bigFiles);
$koalamonReporter->sendEvent($bigFileEvent);

$totalSizeInMb = round($totalSize / 1024 / 1024, 2);

if ($totalSizeInMb > $maxPageSize) {
    $status = \Koalamon\Client\Reporter\Event::STATUS_FAILURE;
} else {
    $status = \Koalamon\Client\Reporter\Event::STATUS_SUCCESS;
}
$message = "Total size of the site " . $url . " is " . $totalSizeInMb . "MB.";

$bigFileEvent = new \Koalamon\Client\Reporter\Event('SiteInfo_FileSize_' . $url, $system, $status, 'SiteInfoFileSize', $message, $totalSizeInMb);
$koalamonReporter->sendEvent($bigFileEvent);