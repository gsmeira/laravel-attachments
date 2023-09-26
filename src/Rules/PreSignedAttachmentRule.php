<?php

namespace GSMeira\LaravelAttachments\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;

class PreSignedAttachmentRule implements ValidationRule
{
    protected AwsS3V3Adapter $fs;

    public function __construct(?AwsS3V3Adapter $fs = null)
    {
        $this->fs = $fs ?: Storage::disk(config('filesystems.default'));
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (
            is_array($value) &&
            array_key_exists('path', $value) &&
            $this->fs->has($value['path'])
        ) {
            return;
        }

        $fail('laravel-attachments::validation.pre_signed_attachment')->translate();
    }
}
