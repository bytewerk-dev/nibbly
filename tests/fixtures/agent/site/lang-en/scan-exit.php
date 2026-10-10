<?php
// Fixture: a template that ends the request.
@file_put_contents(dirname(__DIR__) . '/render-count-exit.txt', 'x', FILE_APPEND);
echo '<p>partial output</p>';
exit;
