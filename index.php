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
                $blocksJSON = $page->{$fieldName}()->value();
                $blocks = json_decode($blocksJSON, true);

                $posterFile = $_FILES['posterFile'] ?? null;                
                if (!$posterFile || $posterFile['error'] !== UPLOAD_ERR_OK) {
                    return ['error' => '(External Video) No file uploaded or upload error'];
                }
                
                $posterFilename = get('posterFilename');
                if (!$posterFilename) {
                    return ['error' => '(External Video) Poster Filename missing'];
                }

                try {
                    $posterFileUploaded = $page->createFile([
                        'source'   => $posterFile['tmp_name'],
                        'filename' => $posterFilename,
                        'template' => 'image'
                    ]);

                    foreach ($blocks as &$block) {
                        if ($block['id'] === $blockId) {
                            $block['content']['poster'] = $posterFileUploaded->id();
                        }
                    }

                    $page->update([
                      $fieldName => json_encode($blocks)
                    ]);

                    return [
                        'success' => true,
                        'reload'  => true,
                    ];
                } catch (Exception $e) {
                    return [
                        'error'   => '(External Video) ' . $e->getMessage()
                    ];
                }
            }
        ]
    ]
]);
