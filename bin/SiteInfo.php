<?php

include_once __DIR__ . "/../vendor/autoload.php";

if (count($argv) < 5) {
    echo "\n  SiteInfo - Version ##development##\n";
    die("\n  Usage: SiteInfo.phar url system api_key options <koalamon_server>\n\n");
}

$url = $argv[1];
$system = $argv[2];
$projectApiKey = $argv[3];

$options = json_decode($argv[4]);

if (!is_null($options) && $options !== false) {
    if (property_exists($options, 'pageSize')) {
        $maxPageSize = $options->pageSize;
    } else {
        $maxPageSize = 100000;
    }

    if (property_exists($options, 'fileSize')) {
        $maxFileSize = $options->fileSize;
    } else {
        $maxFileSize = 100000000;
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

$knownBigFiles = array("http://www.cosmopolitan.de/bilder/300x150/2015/04/17/72971-exchange-kostenlos.gif?itok=6bxlNQgC",
    "http://www.billigflieger.de/build/js/app.js",
    "http://stars-und-stories.com/wp-content/plugins/js_composer/assets/css/js_composer.min.css?ver=4.8.0.1",
    "http://stage.lecker.de/sites/all/themes/lecker/js/angular.package.js");

foreach ($dependencies as $dependency) {
    try {
        $response = $guzzle->request('GET', (string)$dependency);
        $responseSize = strlen($response->getBody());
        $totalSize += $responseSize;

        $known = false;
        foreach ($knownBigFiles as $knownBigFile) {
            if (preg_match("^" . preg_quote($knownBigFile) . "^", (string)$dependency) > 0) {
                $known = true;
                continue;
            }
        }

        if (!$known) {
            if ($responseSize > ($maxFileSize * 1024)) {
                var_dump((string)$dependency);
                $bigFiles++;
            }
        }
    } catch (\Exception $e) {
    }
}

if ($bigFiles > 0) {
    $status = \Koalamon\Client\Reporter\Event::STATUS_FAILURE;
    $message = "Too many big files (>" . $maxFileSize . " KB) on " . $url . " found.";
} else {
    $status = \Koalamon\Client\Reporter\Event::STATUS_SUCCESS;
    $message = "No big files (>" . $maxFileSize . " KB) found.";
}

$bigFileEvent = new \Koalamon\Client\Reporter\Event('SiteInfo_BigFiles_' . $url, $system, $status, 'SiteInfoBigFile', $message, $bigFiles);
$koalamonReporter->sendEvent($bigFileEvent);

$totalSizeInMb = round($totalSize / 1024 / 1024, 2);

if ($totalSizeInMb > $maxPageSize) {
    $status = \Koalamon\Client\Reporter\Event::STATUS_FAILURE;
    $message = "Total size of the site " . $url . " is " . $totalSizeInMb . "MB.";
} else {
    $status = \Koalamon\Client\Reporter\Event::STATUS_SUCCESS;
    $message = "";
}

$bigFileEvent = new \Koalamon\Client\Reporter\Event('SiteInfo_FileSize_' . $url, $system, $status, 'SiteInfoFileSize', $message, $totalSizeInMb);
$koalamonReporter->sendEvent($bigFileEvent);