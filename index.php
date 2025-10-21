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
                if (!$page)
                    return ['error' => '(External Video) Page not found'];

                $fieldPath = get('fieldPath');
                if (!$fieldPath)
                    return ['error' => '(External Video) Fieldpath missing'];

                $fieldPathSegments = preg_split('/\s*\+\s*/', trim($fieldPath));
                $fieldPathSubsegments = array_slice($fieldPathSegments, 1);

                $fieldName = $fieldPathSegments[0];
                if (!$fieldName)
                    return ['error' => '(External Video) Fieldname missing'];

                $fieldObj = $page->{$fieldName}();
                if (!$fieldObj || $fieldObj->isEmpty())
                    return ['error' => "(External Video) Field '{$fieldName}' exists but is empty or missing on this page"];

                $pageBlueprintFields = $page->blueprint()->fields();
                if (!array_key_exists($fieldName, $pageBlueprintFields))
                    return ['error' => "(External Video) Top field '{$fieldName}' not defined in blueprint"];

                $fieldType = $pageBlueprintFields[$fieldName]['type'] ?? null;

                $blockId = get('blockId');

                $videoUrl = get('videoUrl');

                $posterFile = $_FILES['posterFile'] ?? null;
                if (!$posterFile || $posterFile['error'] !== UPLOAD_ERR_OK)
                    return ['error' => '(External Video) No file uploaded or upload error'];

                $posterFilename = get('posterFilename');
                if (!$posterFilename)
                    return ['error' => '(External Video) Poster Filename missing'];

                try {
                    if ($posterFileOld = $page->file($posterFilename)) {
                        $posterFileOld->delete();
                    }

                    $posterFileUploaded = $page->createFile([
                        'source'   => $posterFile['tmp_name'],
                        'filename' => $posterFilename,
                        'template' => 'image'
                    ]);

                    $isArrayBlocks = function ($arr): bool {
                        if (!is_array($arr) || empty($arr)) return false;

                        // only check first 5 elements for performance
                        $entriesSample = array_slice($arr, 0, 5);
                        foreach ($entriesSample as $entrySample) {
                            if (!is_array($entrySample)) return false;
                            if (
                                !array_key_exists('id', $entrySample) ||
                                !array_key_exists('type', $entrySample)
                            ) return false;
                        }
                        return true;
                    };

                    $updateBlocks = function (
                        array $blocks
                    ) use (
                        &$updateBlocks,
                        $isArrayBlocks,
                        $blockId,
                        $posterFileUploaded,
                        $videoUrl
                    ): array {
                        $blocksNew = [];

                        foreach ($blocks as $block) {
                            $blockNew = $block;
                            $content = is_array($block['content'] ?? null) ? $block['content'] : [];

                            if (($block['id'] ?? null) === $blockId) {
                                $content = array_merge($content, [
                                    'poster' => $posterFileUploaded->id(),
                                    'url'    => $videoUrl
                                ]);
                                $blockNew['content'] = $content;
                            }

                            foreach ($content as $key => $value) {
                                // case: value is an array that looks like blocks
                                if ($isArrayBlocks($value)) {
                                    $content[$key] = $updateBlocks($value);
                                    $blockNew['content'] = $content;
                                    continue;
                                }
                                // case: value is a string that may contain JSON array of blocks
                                if (is_string($value)) {
                                    $valueDecoded = json_decode($value, true);
                                    if (json_last_error() === JSON_ERROR_NONE && $isArrayBlocks($valueDecoded)) {
                                        $blocksNested = $updateBlocks($valueDecoded);
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

                    $updateStructure = function (
                        array $data,
                        array $segments
                    ) use (
                        &$updateStructure,
                        $isArrayBlocks,
                        $updateBlocks
                    ) {
                        $segment = array_shift($segments);
                        if ($segment === null) {
                            if ($isArrayBlocks($data)) {
                                return $updateBlocks($data);
                            }
                            return $data;
                        }

                        foreach ($data as $idx => $entry) {
                            if (!is_array($entry)) continue;
                            if (!array_key_exists($segment, $entry)) continue;

                            $value = $entry[$segment];

                            // case: bottom level structure
                            if (empty($segments) && is_string($value) && $value !== '') {
                                $decoded = json_decode($value, true);
                                if (json_last_error() === JSON_ERROR_NONE) {
                                    $blocksNew = $updateBlocks($decoded);
                                    $data[$idx][$segment] = json_encode($blocksNew);
                                }
                            }

                            // case: parent structure
                            if (is_array($value)) {
                                $structureNew = $updateStructure($value, $segments);
                                $data[$idx][$segment] = $structureNew;
                            }
                        }

                        return $data;
                    };

                    $fieldDataNew = null;

                    if ($fieldType === 'structure') {
                        $structureOld = $fieldObj->toStructure()->toArray();
                        $fieldDataNew = $updateStructure($structureOld, $fieldPathSubsegments);
                    } elseif ($fieldType === 'blocks') {
                        $blocksOld = $fieldObj->toBlocks()->toArray();
                        $fieldDataNew = $updateBlocks($blocksOld);
                    } else {
                        return ['error' => "(External Video) Unsupported top field type for '{$fieldName}'"];
                    }

                    $page->update([$fieldName => $fieldDataNew]);

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
