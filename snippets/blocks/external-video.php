<?php
$isLoop =
    $block->loop()->isNotEmpty() 
    ? $block->loop()->isTrue()
    : true;
$isAutoplay =
    $block->autoplay()->isNotEmpty() 
    ? $block->autoplay()->isTrue()
    : true;
$hasControls = 
    $block->controls()->isNotEmpty() 
    ? $block->controls()->isTrue()
    : false;
$hasPoster = $block->poster()->isNotEmpty() && $block->poster()->first()->toFile() !== null;
$poster =
    $hasPoster
    ? $block->poster()->first()->toFile()
    : null;
$posterUrl = $hasPoster ? $poster->url() : '';
?>

<video
    src="<?= $block->url() ?>"
    muted
    playsinline
    <?= $isLoop ? 'loop' : '' ?>
    <?= $isAutoplay ? 'autoplay' : '' ?>
    <?= $hasControls ? 'controls' : '' ?>
    <?= $poster ? 'poster="' . $posterUrl . '"' : '' ?>
    <?= $hasPoster ? 'width="' . $poster->dimensions()->width() . '"' : '' ?>
    <?= $hasPoster ? 'height="' . $poster->dimensions()->height() . '"' : '' ?>></video>