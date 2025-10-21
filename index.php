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
                if (!$page) {
                    return ['error' => '(External Video) Page not found'];
                }

                $fieldPath = get('fieldPath'); // might include "+"
                if (!$fieldPath) return ['error' => '(External Video) Fieldpath missing'];
                $fieldPathSegments = preg_split('/\s*\+\s*/', trim($fieldPath));

                $fieldName = array_shift($fieldPathSegments);
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
                    kirbylog(00);
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
                                    $content[$key] = $updateBlocks(
                                        $value,
                                        $blockId,
                                        $posterId,
                                        $videoUrl
                                    );
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

                    // $fieldName
                    // $field = $page->{$seg}

                    // kirbylog();
                    // kirbylog(01);

                    // Helper: recursively descend structures/arrays by segments.
                    // - $data: array of structure entries (each entry is associative array)
                    // - $fieldPathSegments: remaining path segments (array)
                    // Returns modified $data in the same shape (strings kept as strings)
                    $descendAndUpdate = function (
                        array $data,
                        array $fieldPathSegments
                        ) use (
                            &$descendAndUpdate,
                            $isArrayBlocks,
                            $updateBlocks,
                            $blockId,
                            $posterFileUploaded,
                            $videoUrl
                            ) {
                        $seg = array_shift($fieldPathSegments);
                        // when no more segments, this means $data itself is the blocks array to update
                        if ($seg === null) {
                            // if $data is actually blocks (rare), update directly
                            if ($isArrayBlocks($data)) {
                                return $updateBlocks($data, $blockId, $posterFileUploaded->id(), $videoUrl);
                            }
                            return $data;
                        }

                        // We expect $data to be an array of structure entries here
                        foreach ($data as $idx => $entry) {
                            // if entry is not array, skip
                            if (!is_array($entry)) continue;

                            // if the key doesn't exist, skip
                            if (!array_key_exists($seg, $entry)) continue;

                            $value = $entry[$seg];

                            // Case A: value is a YAML/JSON-encoded string (common for structure fields saved as |-, JSON inside)
                            if (is_string($value) && $value !== '') {
                                $decoded = json_decode($value, true);
                                if (json_last_error() === JSON_ERROR_NONE) {
                                    // decoded might be blocks array or structure array
                                    if ($isArrayBlocks($decoded)) {
                                        // if this is the final segment, update blocks
                                        if (empty($fieldPathSegments)) {
                                            $updated = $updateBlocks($decoded, $blockId, $posterFileUploaded->id(), $videoUrl);
                                            $data[$idx][$seg] = json_encode($updated);
                                        } else {
                                            // decoded is an array of structure entries or blocks—descend further
                                            $descUpdated = $descendAndUpdate($decoded, $fieldPathSegments);
                                            // if decoded was array of entries (structures), we must re-encode to string
                                            $data[$idx][$seg] = json_encode($descUpdated);
                                        }
                                    } else {
                                        // decoded is an array but not blocks — treat as structure entries and descend
                                        $descUpdated = $descendAndUpdate($decoded, $fieldPathSegments);
                                        $data[$idx][$seg] = json_encode($descUpdated);
                                    }
                                    continue;
                                }
                                // empty string or non-json string: if empty and we are at final seg and want to create blocks array, set new value
                                if ($value === '' && empty($fieldPathSegments)) {
                                    // nothing to update here because there's no blocks to walk
                                    continue;
                                }
                            }

                            // Case B: value is already an array (structure entries or blocks)
                            if (is_array($value)) {
                                // blocks array directly stored
                                if ($isArrayBlocks($value)) {
                                    if (empty($fieldPathSegments)) {
                                        $data[$idx][$seg] = $updateBlocks($value, $blockId, $posterFileUploaded->id(), $videoUrl);
                                    } else {
                                        // blocks contain nested blocks in content; still call updateBlocks (it scans content keys)
                                        $data[$idx][$seg] = $updateBlocks($value, $blockId, $posterFileUploaded->id(), $videoUrl);
                                    }
                                    continue;
                                }

                                // structure entries array: descend further
                                $descUpdated = $descendAndUpdate($value, $fieldPathSegments);
                                $data[$idx][$seg] = $descUpdated;
                                continue;
                            }

                            // Case C: scalar or other — nothing to do
                        }

                        return $data;
                    };

                    kirbylog(02);

                    kirbylog(03);

                    // $fieldObj = $page->{$fieldName}();
                    $fieldObj = $page->field($fieldName);
                    if (!$fieldObj) {
                        return ['error' => "(External Video) Top field '{$fieldName}' not found on page"];
                    }

                    // kirbylog('top: ' . $fieldName);
                    // kirbylog('fields on page: ' . print_r(array_keys($page->content()->toArray()), true));
                    // kirbylog('page content raw: ' . print_r($page->content()->toArray(), true));
                    // kirbylog('fieldObj->value(): ' . var_export($page->field($fieldName)->value(), true));

                    kirbylog(print_r($page->blueprint()->fields()[$fieldName]['type'], true));

                    kirbylog(04);
                    // kirbylog(print_r($fieldObj,true));
                    // kirbylog(method_exists($fieldObj, 'toStructure'));
                    // kirbylog(is_a($fieldObj, 'Kirby\Cms\Field'));

                    // Determine initial data form and create $modifiedData to be saved later
                    // Replace the "Determine initial data form and create $modifiedData" section
                    // with this blueprint-driven version. It uses the blueprint's field type
                    // to decide whether to call toStructure() or toBlocks() and then proceeds
                    // with the existing descendAndUpdate / updateBlocks logic.

                    $modifiedData = null;

                    // determine top segment and get blueprint field type
                    // $fieldName = array_shift($fieldPathSegments);
                    // if ($fieldName === null) {
                    //     return ['error' => '(External Video) Invalid fieldPath'];
                    // }

                    // ensure the field exists in the blueprint
                    $bpFields = $page->blueprint()->fields();
                    if (!array_key_exists($fieldName, $bpFields)) {
                        return ['error' => "(External Video) Top field '{$fieldName}' not defined in blueprint"];
                    }

                    $fieldType = $bpFields[$fieldName]['type'] ?? null;
                    $fieldObj = $page->{$fieldName}();

                    // if field object is missing or empty, decide whether to abort or initialize
                    if (!$fieldObj || $fieldObj->isEmpty()) {
                        return ['error' => "(External Video) Field '{$fieldName}' exists but is empty or missing on this page"];
                    }

                    kirbylog(05);

                    if ($fieldType === 'structure') {
                        kirbylog("5 a");
                        // parse as structure
                        $struct = $fieldObj->toStructure()->toArray();
                        $modifiedData = empty($fieldPathSegments)
                            ? $descendAndUpdate($struct, [])
                            : $descendAndUpdate($struct, $fieldPathSegments);

                        // save structure back as array; Kirby will serialize it
                        $page->update([$fieldName => $modifiedData]);
                    }
                    elseif ($fieldType === 'blocks') {
                        kirbylog("5 b");
                        // parse as blocks
                        $blocks = $fieldObj->toBlocks()->toArray();

                        if (empty($fieldPathSegments)) {
                            $modifiedData = $updateBlocks($blocks, $blockId, $posterFileUploaded->id(), $videoUrl);
                            $page->update([$fieldName => $modifiedData]);
                        } else {
                            // descend into blocks' contents/structure fields
                            $modifiedData = $descendAndUpdate($blocks, $fieldPathSegments);
                            $page->update([$fieldName => $modifiedData]);
                        }
                    }
                    else {
                        kirbylog('5 c');
                        // fallback: raw content key from page content, try JSON/YAML decode
                        $raw = $page->content()->get($fieldName);
                        $decoded = json_decode($raw, true);

                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                            $modifiedData = $descendAndUpdate($decoded, $fieldPathSegments);
                            $page->update([$fieldName => $modifiedData]);
                        } else {
                            try {
                                $yamlDecoded = Kirby\Toolkit\Yaml::decode($raw);
                                if (is_array($yamlDecoded)) {
                                    $modifiedData = $descendAndUpdate($yamlDecoded, $fieldPathSegments);
                                    // re-encode YAML when saving through Kirby page->update will handle serialization
                                    $page->update([$fieldName => $modifiedData]);
                                } else {
                                    return ['error' => "(External Video) Unsupported top field type for '{$fieldName}'"];
                                }
                            } catch (Exception $e) {
                                return ['error' => "(External Video) Unable to parse top field '{$fieldName}'"];
                            }
                        }
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
