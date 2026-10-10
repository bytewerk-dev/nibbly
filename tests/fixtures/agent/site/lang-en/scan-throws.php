<?php
// Fixture: a template that throws.
@file_put_contents(dirname(__DIR__) . '/render-count-throws.txt', 'x', FILE_APPEND);
throw new RuntimeException('fixture template failure');
