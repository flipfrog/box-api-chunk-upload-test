<?php

require 'vendor/autoload.php';
$config = require('./config/box.php');

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Pool;

const UPLOAD_CONCURRENCY = 5;

$client = new Client([
    'base_uri' => 'https://upload.box.com/api/2.0/',
    'timeout' => 10.0,
]);

try {
    // upload file to root folder which folder id 0.
    uploadConcurrencyContents($client, $config, 0, 'test.dat', fopen('./data/test.dat', 'r'));
} catch (Throwable $e) {
    echo "Aborted. {$e->getMessage()}\n";
}

/**
 * @param Client $client
 * @param array $config
 * @param string $folderId
 * @param string $fileName
 * @param $contents
 * @return void
 * @throws GuzzleException|Exception
 */
function uploadConcurrencyContents(Client $client, array $config, string $folderId, string $fileName, $contents): void
{
    $metaData = stream_get_meta_data($contents);
    $digestWhole = base64_encode(sha1_file($metaData['uri'], true));

    // create upload session
    $stat = fstat($contents);
    $fileSize = $stat['size'];
    // if you overwrite the existing file, API path is 'files/:fileId/upload_sessions'
    $sessionBody = createUploadSession($client, $config, 'files/upload_sessions', $folderId, $fileName, $fileSize);
    $sessionId = $sessionBody['id'];
    $partSize = $sessionBody['part_size'];

    try {
        // upload file
        $parts = uploadAsChunks($client, $config, $folderId, $contents, $fileSize, $sessionId, $partSize);

        // commit upload session
        commitSession($client, $config, $sessionId, $digestWhole, $parts);

    } catch (Throwable $e) {
        echo 'Upload session aborted.' . $e;
    }
    // delete upload session
    deleteSession($client, $sessionId, $config);
}

/**
 * @param Client $client
 * @param array $config
 * @param string $requestUri
 * @param string $folderId
 * @param string $fileName
 * @param int $fileSize
 * @return array
 * @throws GuzzleException
 */
function createUploadSession(
    Client $client,
    array $config,
    string $requestUri,
    string $folderId,
    string $fileName,
    int $fileSize) : array
{
    $res = $client->request('POST', $requestUri, [
        'headers' => [
            'Authorization' => 'Bearer ' . $config['accessToken'],
        ],
        'json' =>
            [
                "folder_id" => $folderId, // if you overwrite the existing file, this property should be omitted.
                "file_size" => $fileSize,
                "file_name" => $fileName,
            ],
    ]);
    return json_decode($res->getBody(), true);
}

/**
 * @param Client $client
 * @param array $config
 * @param string $path
 * @param $stream
 * @param int $fileSize
 * @param string $sessionId
 * @param int $partSize
 * @return array
 */
function uploadAsChunks(
    Client $client,
    array $config,
    string $path,
           $stream,
    int $fileSize,
    string $sessionId,
    int $partSize) : array
{
    $requests = function () use ($stream, $fileSize, $sessionId, $partSize, $config) {
        $chunkMaxSize = $partSize;
        $chunk = fread($stream, $chunkMaxSize);
        $startRange = 0;
        while (!feof($stream) || !empty($chunk)) {
            $chunkSize = strlen($chunk);
            $endRange = $startRange + $chunkSize - 1;
            $digest = base64_encode(sha1($chunk, true));
            yield new Request(
                'PUT',
                "files/upload_sessions/$sessionId",
                [
                    'Authorization' => 'Bearer ' . $config['accessToken'],
                    'Digest' => "sha=$digest",
                    'Content-Range' => "bytes $startRange-$endRange/$fileSize",
                    'Content-Type' => 'application/octet-stream',
                ],
                $chunk
            );
            $startRange += $chunkSize;
            $chunk = fread($stream, $chunkMaxSize);
        }
    };

    $responseMap = [];
    $pool = new Pool($client, $requests(), [
        'concurrency' => UPLOAD_CONCURRENCY,
        'fulfilled' => function (Response $response, $index) use (&$responseMap, $path) {
            $responseMap[$index] = $response;
        },
        'rejected' => function (Throwable $reason) use ($path) {
            throw new Exception("Upload request failed." . $reason);
        },
    ]);

    $promise = $pool->promise();
    $promise->wait();

    // Response contents should be sorted to commit.
    array_multisort(array_keys($responseMap), SORT_ASC, $responseMap);

    return array_map(function ($response) {
        $body = json_decode($response->getBody(), true);
        return $body['part'];
    }, $responseMap);
}

/**
 * @param Client $client
 * @param array $config
 * @param string $sessionId
 * @param string $digestWhole
 * @param array $parts
 * @return mixed
 * @throws GuzzleException|Exception
 */
function commitSession(
    Client $client,
    array $config,
    string $sessionId,
    string $digestWhole,
    array $parts) : array
{
    $commitStatusCode = 202;
    $retryCount = 0;
    $maxRetryCount = 20;
    $res = null;
    while ($commitStatusCode !== 201) {
        if ($commitStatusCode !== 202) {
            throw new Exception("Commit error, status code $commitStatusCode");
        }
        if ($retryCount > $maxRetryCount - 1) {
            throw new Exception("Commit retry count exceeds max retry count($maxRetryCount).");
        }
        if ($retryCount > 0) {
            usleep(500000);
        }
        $res = $client->request('POST', "files/upload_sessions/$sessionId/commit", [
            'headers' => [
                'Authorization' => 'Bearer ' . $config['accessToken'],
                'Digest' => "sha=$digestWhole",
            ],
            'json' => [
                'parts' => $parts,
            ],
        ]);
        $commitStatusCode = $res->getStatusCode();
        $retryCount++;
    }

    $body = json_decode($res->getBody(), true);
    return $body['entries'][0];
}

/**
 * @param Client $client
 * @param string $sessionId
 * @param array $config
 * @return void
 * @throws GuzzleException
 */
function deleteSession(Client $client, string $sessionId, array $config): void
{
    $client->request('DELETE', "files/upload_sessions/$sessionId", [
        'headers' => [
            'Authorization' => 'Bearer ' . $config['accessToken'],
        ],
    ]);
}
