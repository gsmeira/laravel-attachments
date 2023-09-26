<?php

namespace GSMeira\LaravelAttachments\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Http\UploadedFile;
use GSMeira\LaravelAttachments\Enums\AttachmentsAppend;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

trait HasAttachments
{
    public function attachmentsBaseFolder(): string
    {
        return config('attachments.file.base_folder');
    }

    public function isAttachmentsWrapperFolderEnabled(): bool
    {
        return config('attachments.file.wrapper_folder');
    }

    public function isAttachmentsPathObfuscationEnabled(): bool
    {
        return config('attachments.path_obfuscation.enabled');
    }

    public function attachmentsPathObfuscationLevels(): int
    {
        return config('attachments.path_obfuscation.levels');
    }

    public function attachmentsDisk(): string
    {
        return config('filesystems.default');
    }

    public static function bootHasAttachments(): void
    {
        $self = new static;

        $self::deleted(static function (Model $model) use ($self) {
            if (!isset($model->forceDeleting)) {
                $self->removeAttachments($model);
            } else if ($model->forceDeleting) {
                $self->removeAttachments($model);
            }
        });
    }

    protected function attachments(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value): object {
                if (!$value) {
                    return (object) [];
                }

                $fs = $this->getAttachmentsFs();

                $attachments = json_decode($value, true);

                $appends = config('attachments.file.appends');

                foreach ($attachments as $attachment => $path) {
                    $data = [];

                    if (in_array(AttachmentsAppend::Url, $appends)) {
                        $data['url'] = $fs->url($path);
                    }

                    if (in_array(AttachmentsAppend::Exists, $appends)) {
                        $data['exists'] = $fs->has($path);
                    }

                    if (in_array(AttachmentsAppend::Path, $appends) || !empty($data)) {
                        $data['path'] = $path;
                    }

                    $attachments[$attachment] = empty($data) ? $path : (object) $data;
                }

                return (object) $attachments;
            },
            set: function (mixed $value) {
                $attachments = $this->mergeAttachments($value);

                return !empty($attachments) ? json_encode($attachments) : null;
            },
        );
    }

    public function deleteAttachment(string|array $value): void
    {
        $rawAttachments = $this->getRawAttachments();

        $backupAttachments = [];

        if (is_array($value)) {
            $backupAttachments = array_map(fn ($key) => [ $key => $rawAttachments[$key] ], $value);
        } else {
            $backupAttachments[$value] = $rawAttachments[$value];
        }

        foreach ($backupAttachments as $key => $path) {
            unset($rawAttachments[$key]);
        }

        $this->update(compact('rawAttachments'));

        $fs = $this->getAttachmentsFs();

        foreach ($backupAttachments as $key => $path) {
            $this->removeAttachment($fs, $path);
        }
    }

    public function deleteAttachments(): void
    {
        $this->update(['attachments' => null]);
    }

    protected function mergeAttachments(?array $newAttachments): ?array
    {
        if (!$newAttachments) {
            $this->removeAttachments($this);

            return null;
        }

        $rawAttachments = $this->getRawAttachments();

        foreach ($newAttachments as $key => $path) {
            if (!empty($rawAttachments[$key])) {
                $this->deleteAttachment($key);
            }
        }

        foreach ($newAttachments as $key => $attachment) {
            $rawAttachments[$key] = $this->handleAttachment($attachment);
        }

        foreach ($rawAttachments as $key => $path) {
            if (!$path) {
                unset($rawAttachments[$key]);
            }
        }

        return $rawAttachments;
    }

    protected function handleAttachment(mixed $attachment): ?string
    {
        if ($attachment instanceof UploadedFile) {
            return $this->moveFileAttachment($attachment);
        }

        if (is_array($attachment) && Arr::exists($attachment, 'path')) {
            return $this->moveSignedFileAttachment($attachment);
        }

        return null;
    }

    protected function moveFileAttachment(UploadedFile $attachment): string
    {
        return $this->getAttachmentsFs()->putFile($this->generateAttachmentPath(), $attachment);
    }

    protected function moveSignedFileAttachment(array $attachment): string
    {
        $destination = $this->generateAttachmentPath();

        $tempFolder = trim(config('attachments.signed_storage.temp_folder'), '/');

        $path = vsprintf('%s.%s', [
            str_replace("$tempFolder/", "$destination/", $attachment['path']),
            pathinfo($attachment['name'], PATHINFO_EXTENSION),
        ]);

        $this->getAttachmentsFs()->move($attachment['path'], $path);

        return $path;
    }

    protected function removeAttachments(Model $model): void
    {
        $fs = $this->getAttachmentsFs();

        foreach ($model->getRawAttachments() as $key => $path) {
            $this->removeAttachment($fs, $path);
        }
    }

    protected function removeAttachment(FilesystemAdapter|AwsS3V3Adapter $fs, string $path): void
    {
         if (Str::contains(trim($path, '/'), '/')) {
            $fs->deleteDirectory(dirname($path));
        } else {
            $fs->delete($path);
        }
    }

    protected function generateAttachmentPath(): string
    {
        $path = '';

        $randomStr = md5(uniqid(mt_rand(), true));

        if ($this->isAttachmentsPathObfuscationEnabled()) {
            for ($i = 1; $i <= $this->attachmentsPathObfuscationLevels(); $i++) {
                $path .= $randomStr[random_int(0, strlen($randomStr) - 1)].DIRECTORY_SEPARATOR;
            }
        }

        if ($this->isAttachmentsWrapperFolderEnabled()) {
            $path .= $randomStr.DIRECTORY_SEPARATOR;
        }

        $folder = trim($this->attachmentsBaseFolder(), '/');

        if ($folder) {
            $path = $folder.DIRECTORY_SEPARATOR.$path;
        }

        if (!$path) {
            return '';
        }

        $fs = $this->getAttachmentsFs();

        if ($fs->exists($path)) {
            return $this->generateAttachmentPath($fs, $folder);
        } else {
            return trim($path, '/');
        }
    }

    protected function getRawAttachments(): array
    {
        return json_decode($this->getRawOriginal('attachments') ?: '{}', true);
    }

    protected function getAttachmentsFs(): FilesystemAdapter|AwsS3V3Adapter
    {
        return Storage::disk($this->attachmentsDisk());
    }
}
