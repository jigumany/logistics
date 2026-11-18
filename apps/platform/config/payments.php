<?php

return ['yoco' => ['accounts' => json_decode(env('YOCO_ACCOUNTS', '{}'), true, 512, JSON_THROW_ON_ERROR)]];
