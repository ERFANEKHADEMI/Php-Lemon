<?php

readline_callback_handler_install('', function () {
});
while (true) {
    $r = array(STDIN);
    $w = null;
    $e = null;
    $n = stream_select($r, $w, $e, null);
    if ($n && in_array(STDIN, $r)) {
        $c = stream_get_contents(STDIN, 1);
        echo "Char read: $c\n";
        break;
    }
}