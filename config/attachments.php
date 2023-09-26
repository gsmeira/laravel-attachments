<?php

use GSMeira\LaravelAttachments\Enums\AttachmentsAppend;

return [

    'file' => [
        'base_folder' =>  '',
        'appends' => [
            AttachmentsAppend::Path,
            AttachmentsAppend::Url,
            AttachmentsAppend::Exists,
        ],
    ],

    'path_obfuscation' => [
        'enabled' =>  true,
        'levels' =>  3,
    ],

    'signed_storage' => [
        'enabled' => false,
        'temp_folder' => 'tmp',
        'expire_after' => 5 /* minutes */,
        'route' => [
            'url' => 'attachments/signed-storage-url',
            'middleware' => 'web',
        ],
    ],

];
