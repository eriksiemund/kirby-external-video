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
                kirbylog('------------------------');
                $pageId = get('pageId');
                $page = page($pageId);
                if (!$page) return ['error' => '(External Video) Page not found'];

                $fieldPath = get('fieldPath');
                if (!$fieldPath) return ['error' => '(External Video) Fieldpath missing'];
                $fieldPathSegments = preg_split('/\s*\+\s*/', trim($fieldPath));

                $fieldName = array_shift($fieldPathSegments);
                if (!$fieldName) return ['error' => '(External Video) Fieldname missing'];

                $blockId = get('blockId');

                $videoUrl = get('videoUrl');

                $posterFile = $_FILES['posterFile'] ?? null;
                if (!$posterFile || $posterFile['error'] !== UPLOAD_ERR_OK) {
                    return ['error' => '(External Video) No file uploaded or upload error'];
                }

                $posterFilename = get('posterFilename');
                if (!$posterFilename) return ['error' => '(External Video) Poster Filename missing'];

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

                    $descendAndUpdate = function (
                        array $data,
                        array $segments
                    ) use (
                        &$descendAndUpdate,
                        $isArrayBlocks,
                        $updateBlocks,
                        $blockId,
                        $posterFileUploaded,
                        $videoUrl
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

                            // Case A: value is a YAML/JSON-encoded string (common for structure fields saved as |-, JSON inside)
                            if (is_string($value) && $value !== '') {
                                $decoded = json_decode($value, true);
                                if (json_last_error() === JSON_ERROR_NONE) {
                                    // decoded might be blocks array or structure array
                                    if ($isArrayBlocks($decoded)) {
                                        // if this is the final segment, update blocks
                                        if (empty($fieldPathSegments)) {
                                            $updated = $updateBlocks($decoded, $blockId, $posterFileUploaded->id(), $videoUrl);
                                            $data[$idx][$segment] = json_encode($updated);
                                        } else {
                                            // decoded is an array of structure entries or blocks—descend further
                                            $descUpdated = $descendAndUpdate($decoded, $fieldPathSegments);
                                            // if decoded was array of entries (structures), we must re-encode to string
                                            $data[$idx][$segment] = json_encode($descUpdated);
                                        }
                                    } else {
                                        // decoded is an array but not blocks — treat as structure entries and descend
                                        $descUpdated = $descendAndUpdate($decoded, $segments);
                                        $data[$idx][$segment] = json_encode($descUpdated);
                                    }
                                    continue;
                                }

                                if ($value === '' && empty($fieldPathSegments)) continue;
                            }

                            // Case B: value is already an array (structure entries or blocks)
                            if (is_array($value)) {
                                if ($isArrayBlocks($value)) {
                                    if (empty($fieldPathSegments)) {
                                        $data[$idx][$segment] = $updateBlocks($value, $blockId, $posterFileUploaded->id(), $videoUrl);
                                    } else {
                                        // blocks contain nested blocks in content; still call updateBlocks (it scans content keys)
                                        $data[$idx][$segment] = $updateBlocks($value, $blockId, $posterFileUploaded->id(), $videoUrl);
                                    }
                                    continue;
                                }

                                $descUpdated = $descendAndUpdate($value, $segments);
                                $data[$idx][$segment] = $descUpdated;
                                continue;
                            }
                        }

                        return $data;
                    };

                    $fieldObj = $page->{$fieldName}();
                    if (!$fieldObj || $fieldObj->isEmpty()) {
                        return ['error' => "(External Video) Field '{$fieldName}' exists but is empty or missing on this page"];
                    }

                    $pageBlueprintFields = $page->blueprint()->fields();
                    if (!array_key_exists($fieldName, $pageBlueprintFields)) {
                        return ['error' => "(External Video) Top field '{$fieldName}' not defined in blueprint"];
                    }

                    $fieldType = $pageBlueprintFields[$fieldName]['type'] ?? null;

                    $modifiedData = null;

                    if ($fieldType === 'structure') {
                        kirbylog("5 a");
                        $structure = $fieldObj->toStructure()->toArray();
                        $modifiedData = empty($fieldPathSegments)
                            ? $descendAndUpdate($structure, [])
                            : $descendAndUpdate($structure, $fieldPathSegments);

                        $page->update([$fieldName => $modifiedData]);
                    } elseif ($fieldType === 'blocks') {
                        kirbylog("5 b");
                        $blocks = $fieldObj->toBlocks()->toArray();

                        if (empty($fieldPathSegments)) {
                            $modifiedData = $updateBlocks($blocks);
                            $page->update([$fieldName => $modifiedData]);
                        } else {
                            // descend into blocks' contents/structure fields
                            $modifiedData = $descendAndUpdate($blocks, $fieldPathSegments);
                            $page->update([$fieldName => $modifiedData]);
                        }
                    } else {
                        kirbylog('5 c');
                        return ['error' => "(External Video) Unsupported top field type for '{$fieldName}'"];
                    }

                    kirbylog(99);

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
