<?php

Kirby::plugin('eriksiemund/external-video', [
    'blueprints' => [
        'blocks/external_video' => __DIR__ . '/blueprints/blocks/external-video.yml'
    ],
    'snippets' => [
        'blocks/external_video' => __DIR__ . '/snippets/blocks/external-video.php'
    ],
    'routes' => [
        [
            'pattern' => 'external-video/upload',
            'method' => 'POST',
            'action' => function () {
                $pageId = get('pageId');
                $page = page($pageId);
                if (!$page) {
                    return ['error' => '(External Video) Page not found'];
                }

                $fieldName = get('fieldName');
                if (!$fieldName) {
                    return ['error' => '(External Video) Fieldname missing'];
                }
                
                $blockId = get('blockId');

                $posterFile = $_FILES['posterFile'] ?? null;                
                if (!$posterFile || $posterFile['error'] !== UPLOAD_ERR_OK) {
                    return ['error' => '(External Video) No file uploaded or upload error'];
                }
                
                $posterFilename = get('posterFilename');
                if (!$posterFilename) {
                    return ['error' => '(External Video) Poster Filename missing'];
                }

                try {
                    if ($existing = $page->file($posterFilename)) {
                        $existing->delete();
                    }

                    $posterFileUploaded = $page->createFile([
                        'source'   => $posterFile['tmp_name'],
                        'filename' => $posterFilename,
                        'template' => 'image'
                    ]);

                    $isArrayBlocks = function ($arr): bool {
                        if (!is_array($arr) || empty($arr)) {
                            return false;
                        }
                        // only check first 5 elements for performance
                        $entriesSample = array_slice($arr, 0, 5);
                        foreach ($entriesSample as $entrySample) {
                            if (!is_array($entrySample)) {
                                return false;
                            }
                            if (!array_key_exists('id', $entrySample) || !array_key_exists('type', $entrySample)) {
                                return false;
                            }
                        }
                        return true;
                    };

                    $updateNestedBlocksArray = function (array $blocksArray, string $targetId, $posterId, string $videoUrl) use (&$updateNestedBlocksArray, $isArrayBlocks): array {
                        $result = [];

                        foreach ($blocksArray as $blockArray) {
                            $newBlock = $blockArray;
                            $content = is_array($blockArray['content'] ?? null) ? $blockArray['content'] : [];

                            if (($blockArray['id'] ?? null) === $targetId) {
                                $content = array_merge($content, [
                                    'poster' => $posterId,
                                    'url'    => $videoUrl
                                ]);
                                $newBlock['content'] = $content;
                            }

                            foreach ($content as $key => $value) {
                                // case: value is an array that looks like blocks
                                if ($isArrayBlocks($value)) {
                                    $content[$key] = $updateNestedBlocksArray($value, $targetId, $posterId, $videoUrl);
                                    $newBlock['content'] = $content;
                                    continue;
                                }

                                // case: value is a string that may contain JSON array of blocks
                                if (is_string($value)) {
                                    $decoded = json_decode($value, true);
                                    if (json_last_error() === JSON_ERROR_NONE && $isArrayBlocks($decoded)) {
                                        $updatedNested = $updateNestedBlocksArray($decoded, $targetId, $posterId, $videoUrl);
                                        $content[$key] = json_encode($updatedNested);
                                        $newBlock['content'] = $content;
                                        continue;
                                    }
                                }
                            }

                            $result[] = $newBlock;
                        }

                        return $result;
                    };

                    $blocksArray = $page->{$fieldName}()->toBlocks()->toArray();

                    $updatedArray = $updateNestedBlocksArray(
                        $blocksArray,
                        $blockId,
                        $posterFileUploaded->id(),
                        get('videoUrl')
                    );

                    $newBlocks = [];
                    foreach ($updatedArray as $b) {
                        $newBlocks[] = new Kirby\Cms\Block($b);
                    }

                    $blocksNew = new Kirby\Cms\Blocks($newBlocks);

                    $page->update([
                        $fieldName => $blocksNew->toArray()
                    ]);

                    return [
                        'success' => true,
                        'reload'  => true,
                        'file'    => [
                            'id'  => $posterFileUploaded->id(),
                            'url' => $posterFileUploaded->url()
                        ]
                    ];

                } catch (Exception $e) {
                    return [
                        'error' => '(External Video) ' . $e->getMessage()
                    ];
                }
            }
        ]
    ]
]);
