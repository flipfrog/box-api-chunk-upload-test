
    /**
     * @param string $path
     * @param $contents
     * @param Config|null $config
     * @return void
     * @throws GuzzleException
     */
    uploadConcurrencyContents(string $path, $contents, Config $config=null)
    {
        $overWrite = false;
        $requestUri = 'files/upload_sessions';
        // ファイルが既にあれば上書きする
        if (($targetInfo = $this->getItemInfoByPath($path))) {
            if ($targetInfo['type'] === self::T_FILE) {
                $requestUri = sprintf('files/%s/upload_sessions', $targetInfo['id']);
                $overWrite = true;
            } else {
                // $pathにフォルダが指定されている場合エラー
                throw UnableToWriteFile::atLocation($path, 'File name is required.');
            }
        }

        $pathItems = explode(self::DIRECTORY_SEPARATOR, $path);
        [$parentPath, $parentId] = $this->getParentFolderInfo($path);

        // 親のフォルダがなければ(全ての階層を)作成する
        if ($parentId === null) {
            $parentId = $this->createDirectoryInner(implode(self::DIRECTORY_SEPARATOR, $parentPath), $config);
        }
        $fileName = end($pathItems);

        // ファイル全体のdigestを計算する
        $metaData = stream_get_meta_data($contents);
        $digestWhole = base64_encode(sha1_file($metaData['uri'], true));

        // アップロードセッションを作成する
        $stat = fstat($contents);
        $fileSize = $stat['size'];
        $sessionBody = $this->createUploadSession($requestUri, $parentId, $fileName, $fileSize, $overWrite);
        $sessionId = $sessionBody['id'];
        $partSize = $sessionBody['part_size'];

        try {
            // ファイルを分割して並列にアップロードする
            $parts = $this->uploadChunks($path, $contents, $fileSize, $sessionId, $partSize);

            // アップロードセッションをコミットする
            $info = $this->commitSession($path, $sessionId, $digestWhole, $parts);

            $this->addInfoToCache($path, $info);

            // アップロードセッションを削除
            $this->upload_client->request('DELETE', "files/upload_sessions/$sessionId");
        } catch (Throwable $e) {
            // アップロードセッションを削除
            $this->upload_client->request('DELETE', "files/upload_sessions/$sessionId");
            throw UnableToWriteFile::atLocation($path, 'Upload session aborted.', $e);
        }
    }

    /**
     * @param string $requestUri
     * @param string $folderId
     * @param string $fileName
     * @param int $fileSize
     * @param bool $overWrite
     * @return array
     * @throws GuzzleException
     */
    function createUploadSession(string $requestUri,
                                         string $folderId,
                                         string $fileName,
                                         int $fileSize,
                                         bool $overWrite) : array
    {
        $res = $this->upload_client->request('POST', $requestUri, [
            'json' => $overWrite ?
                [
                    "file_size" => $fileSize,
                    "file_name" => $fileName,
                ] :
                [
                    "folder_id" => $folderId,
                    "file_size" => $fileSize,
                    "file_name" => $fileName,
                ],
        ]);
        return json_decode($res->getBody(), true);
    }

    /**
     * @param string $path
     * @param $contents
     * @param int $fileSize
     * @param string $sessionId
     * @param int $partSize
     * @return array
     */
    function uploadChunks(
        string $path,
        $contents,
        int $fileSize,
        string $sessionId,
        int $partSize) : array
    {
        $requests = function () use ($contents, $fileSize, $sessionId, $partSize) {
            $chunkMaxSize = $partSize;
            $chunk = fread($contents, $chunkMaxSize);
            $startRange = 0;
            while (!feof($contents) || !empty($chunk)) {
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
                unset($chunk);
                $chunk = fread($contents, $chunkMaxSize);
            }
        };

        $responseMap = [];
        $pool = new Pool($this->upload_client, $requests(), [
            'concurrency' => $this->requestConcurrency,
            'fulfilled' => function (Response $response, $index) use (&$responseMap, $path) {
                $responseMap[$index] = $response;
            },
            'rejected' => function (Throwable $reason) use ($path) {
                throw UnableToWriteFile::atLocation($path, "Upload request failed.", $reason);
            },
        ]);

        $promise = $pool->promise();
        $promise->wait();

        // コミットするときに$partsの内容がチャンクの下限境界順になっていないとエラーになる
        array_multisort(array_keys($responseMap), SORT_ASC, $responseMap);

        return array_map(function ($response) {
            $body = json_decode($response->getBody(), true);
            return $body['part'];
        }, $responseMap);
    }

    /**
     * @param string $path
     * @param string $sessionId
     * @param string $digestWhole
     * @param array $parts
     * @return mixed
     * @throws GuzzleException
     */
    function commitSession(
        string $path,
        string $sessionId,
        string $digestWhole,
        array $parts) : array
    {
        $commitStatusCode = 202;
        $retryCount = 0;
        $maxRetryCount = 20;
        while ($commitStatusCode !== 201) {
            if ($commitStatusCode !== 202) {
                throw UnableToWriteFile::atLocation($path, "Commit error, status code $commitStatusCode");
            }
            if ($retryCount > $maxRetryCount - 1) {
                throw UnableToWriteFile::atLocation($path, "Commit retry count exceeds max retry count($maxRetryCount).");
            }
            if ($retryCount > 0) {
                usleep(500);
            }
            // アップロードセッションをコミット(大抵は1回目は処理中で202が返される)
            $res = $this->upload_client->request('POST', "files/upload_sessions/$sessionId/commit", [
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
