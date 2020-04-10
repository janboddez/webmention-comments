<?php

namespace Webmention_Comments;

use Webmention_Comments\Sami\Sami;
return new \Webmention_Comments\Sami\Sami(__DIR__ . '/src', array('title' => 'HTML5-PHP API', 'build_dir' => __DIR__ . '/build/apidoc', 'cache_dir' => __DIR__ . '/build/sami-cache', 'default_opened_level' => 1));
