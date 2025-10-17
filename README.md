# Kirby External Video Block with Poster Generator

Kirby External Video Block with Poster Generator is a plugin for [Kirby CMS](https://getkirby.com) that includes a block for externally hosted videos from Vimeo. It uses Canvas API to get the initial or selected frame from a video file. It works directly in the Panel and is compatible with most Kirby websites.

## Install

You have to use composer to install the plugin into your project.

```console
composer require eriksiemund/external-video
```

## Setup

In the page blueprint add a blocks field including external_video in the fieldsets option.

*site/blueprints/mypage.yml*
```yaml
title: My Page
fields:
  myblocks:
    type: blocks
    label: My Blocks
    fieldsets:
      - external_video
      - ...
```

## Use in Template or Snippet

*site/templates/mypage.php*
```php
$blocks = $page->myblocks()->toBlocks();
foreach($blocks as $block) {
  echo $block
}
```

## Support and Questions

For the sake of reproducible bug reports, please include the following information in your bug reports:

- Kirby & Kirby External Video Block with Poster Generator version
- Browser environment (name, version, operating system)
- Global and section configuration (without any sensitive information)
- Steps to reproduce the bug (if no reproduction is provided)
- Screenshots or screen recordings if applicable

## Feedback

I value your feedback and ideas for improving Kirby External Video Block with Poster Generator. If you have any suggestions, please feel free to reach out to me.

## License

© 2025-PRESENT [Erik Siemund](https://github.com/eriksiemund)