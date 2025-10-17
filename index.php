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
                $videoUrl = get('videoUrl');

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

                    $updateBlocks = function (
                        array $blocks,
                        string $blockId,
                        string $posterId,
                        string $videoUrl
                        ) use (&$updateBlocks, $isArrayBlocks): array {
                        $blocksNew = [];

                        foreach ($blocks as $block) {
                            $blockNew = $block;
                            $content = is_array($block['content'] ?? null) ? $block['content'] : [];

                            if (($block['id'] ?? null) === $blockId) {
                                $content = array_merge($content, [
                                    'poster' => $posterId,
                                    'url'    => $videoUrl
                                ]);
                                $blockNew['content'] = $content;
                            }

                            foreach ($content as $key => $value) {
                                // case: value is an array that looks like blocks
                                if ($isArrayBlocks($value)) {
                                    $content[$key] = $updateBlocks($value, $blockId, $posterId, $videoUrl);
                                    $blockNew['content'] = $content;
                                    continue;
                                }
                                // case: value is a string that may contain JSON array of blocks
                                if (is_string($value)) {
                                    $valueDecoded = json_decode($value, true);
                                    if (json_last_error() === JSON_ERROR_NONE && $isArrayBlocks($valueDecoded)) {
                                        $blocksNested = $updateBlocks(
                                            $valueDecoded,
                                            $blockId,
                                            $posterId,
                                            $videoUrl
                                        );
                                        $content[$key] = json_encode($blocksNested);
                                        $blockNew['content'] = $content;
                                        continue;
                                    }
                                }
                            }

                            $blocksNew[] = $blockNew;
                        }

                        return $blocksNew;
                    };

                    $blocksOld = $page->{$fieldName}()->toBlocks()->toArray();

                    $blocksNew = $updateBlocks(
                        $blocksOld,
                        $blockId,
                        $posterFileUploaded->id(),
                        $videoUrl
                    );

                    $page->update([
                        $fieldName => $blocksNew
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
