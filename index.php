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

                    foreach ($page->{$fieldName}()->toBlocks() as $block) {
                        $old = $block->toArray();
                        
                        if ($block->id() === $blockId) {
                            $old['content']['poster'] = $posterFileUploaded->id();
                            $old['content']['url'] = get('videoUrl');
                        }

                        $new[] = new Kirby\Cms\Block($old);
                    }

                    $blocksNew = new Kirby\Cms\Blocks($new ?? []);

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
