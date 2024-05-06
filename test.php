<?php

require 'vendor/autoload.php';
$config = require('./config/box.php');

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Pool;

$client = new Client([
    'base_uri' => 'https://upload.box.com/api/2.0/',
    'timeout' => 2.0,
]);

uploadConcurrencyContents($client, null, 'test.txt', 'This is test');

/**
 * @param Client $client
 * @param string $folderId
 * @param string $fileName
 * @param $contents
 * @return void
 * @throws GuzzleException|Exception
 */
function uploadConcurrencyContents(Client $client, string $folderId, string $fileName, $contents)
{

    $requestUri = 'files/upload_sessions';

    $metaData = stream_get_meta_data($contents);
    $digestWhole = base64_encode(sha1_file($metaData['uri'], true));

    // アップロードセッションを作成する
    $stat = fstat($contents);
    $fileSize = $stat['size'];
    $sessionBody = createUploadSession($client, $requestUri, $folderId, $fileName, $fileSize);
    $sessionId = $sessionBody['id'];
    $partSize = $sessionBody['part_size'];

    try {
        // ファイルを分割して並列にアップロードする
        $parts = uploadChunks($client, $folderId, $contents, $fileSize, $sessionId, $partSize);

        // アップロードセッションをコミットする
        commitSession($client, $sessionId, $digestWhole, $parts);

        // アップロードセッションを削除
        $client->request('DELETE', "files/upload_sessions/$sessionId");
    } catch (Throwable $e) {
        // アップロードセッションを削除
        $client->request('DELETE', "files/upload_sessions/$sessionId");
        echo 'Upload session aborted.' . $e;
    }
}

/**
 * @param Client $client
 * @param string $requestUri
 * @param string $folderId
 * @param string $fileName
 * @param int $fileSize
 * @return array
 * @throws GuzzleException
 */
function createUploadSession(
    Client $client,
    string $requestUri,
    string $folderId,
    string $fileName,
    int $fileSize) : array
{
    $res = $client->request('POST', $requestUri, [
        'json' =>
            [
                // "folder_id" => $folderId, // if over write
                "file_size" => $fileSize,
                "file_name" => $fileName,
            ],
    ]);
    return json_decode($res->getBody(), true);
}

/**
 * @param Client $client
 * @param string $path
 * @param $stream
 * @param int $fileSize
 * @param string $sessionId
 * @param int $partSize
 * @return array
 */
function uploadChunks(
    Client $client,
    string $path,
           $stream,
    int $fileSize,
    string $sessionId,
    int $partSize) : array
{
    $requests = function () use ($stream, $fileSize, $sessionId, $partSize) {
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
        'concurrency' => 5, // TODO to be a constant
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
 * @param string $sessionId
 * @param string $digestWhole
 * @param array $parts
 * @return mixed
 * @throws GuzzleException|Exception
 */
function commitSession(
    Client $client,
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
            usleep(500);
        }
        $res = $client->request('POST', "files/upload_sessions/$sessionId/commit", [
            'headers' => [
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
