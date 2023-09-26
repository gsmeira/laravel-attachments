<?php

namespace GSMeira\LaravelAttachments\Enums;

enum AttachmentsAppend: string
{
    case Path = 'path';
    case Url = 'url';
    case Exists = 'exists';
}
