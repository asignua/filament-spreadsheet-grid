<?php

declare(strict_types=1);

return [
    /*
     * Upper bounds of one save request. The browser sends every pending edit in one go, so
     * these guard the server against a pasted 100 000-row sheet. Over the limit nothing is
     * saved and every row gets a message.
     */
    'max_rows' => 500,
    'max_cells' => 5000,
];
