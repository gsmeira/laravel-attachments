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

trait HasAttachments
{
    public function attachmentsBaseFolder(): string
    {
        return config('attachments.file.base_folder');
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
        static::deleted(static function (Model $model) {
            if (!isset($model->forceDeleting) || $model->forceDeleting) {
                $model->removeAttachments($model->getCurrentAttachments());
            }
        });
    }

    protected function attachments(): Attribute
    {
        return Attribute::make(
            get: function (?string $value): array {
                $fs = $this->getAttachmentsFs();

                $attachments = json_decode($value ?: '{}', true);

                $appends = config('attachments.file.appends');

                foreach ($attachments as $attachment => $path) {
                    $data = [];

                    if (in_array(AttachmentsAppend::Url, $appends)) {
                        $data['url'] = $fs->url($path);
                    }

                    if (in_array(AttachmentsAppend::Exists, $appends)) {
                        $data['exists'] = $fs->has($path);
                    }

                    if (in_array(AttachmentsAppend::Path, $appends) || !$data) {
                        $data['path'] = $path;
                    }

                    $attachments[$attachment] = $data ?: $path;
                }

                return $attachments;
            },
            set: function (?array $value): ?string {
                $attachments = null;

                if ($value) {
                    $attachments = json_encode($this->mergeAttachments($value));
                } else {
                    $this->removeAttachments($this->getCurrentAttachments());
                }

                return $attachments;
            },
        );
    }

    public function updateAttachments(array $newAttachments): void
    {
        $this->update(['attachments' => $newAttachments]);
    }

    public function deleteAttachment(string|array $key): void
    {
        $attachments = $this->getCurrentAttachments();

        if (is_array($key)) {
            foreach ($key as $k) {
                $attachments[$k] = null;
            }
        } else {
            $attachments[$key] = null;
        }

        $this->update(compact('attachments'));
    }

    public function deleteAttachments(): void
    {
        $this->update(['attachments' => null]);
    }

    protected function mergeAttachments(?array $newAttachments): ?array
    {
        $currentAttachments = $this->getCurrentAttachments();

        $oldAttchments = [];

        foreach ($newAttachments as $key => $attachment) {
            if ($this->isAttachmentOld($currentAttachments, $key, $attachment)) {
                $oldAttchments[$key] = $currentAttachments[$key];
            }

            if ($this->isAttachmentNew($currentAttachments, $key, $attachment)) {
                $currentAttachments[$key] = $this->handleAttachment($attachment);
            }

            if (!$currentAttachments[$key]) {
                unset($currentAttachments[$key]);
            }
        }

        $this->removeAttachments($oldAttchments);

        return $currentAttachments;
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

    protected function removeAttachments(array $attachments): void
    {
        $fs = $this->getAttachmentsFs();

        foreach ($attachments as $key => $path) {
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

        $folder = trim($this->attachmentsBaseFolder(), '/');

        if ($folder) {
            $path = $folder.DIRECTORY_SEPARATOR.$path;
        }

        return trim($path, '/');
    }

    protected function getCurrentAttachments(): array
    {
        return json_decode($this->getRawOriginal('attachments') ?: '{}', true);
    }

    protected function getAttachmentsFs(): FilesystemAdapter|AwsS3V3Adapter
    {
        return Storage::disk($this->attachmentsDisk());
    }

    protected function isAttachmentOld(array $currentAttachments, string $key, UploadedFile|array|string|null $attachment): bool
    {
        return Arr::exists($currentAttachments, $key) && $currentAttachments[$key] !== $attachment;
    }

    protected function isAttachmentNew(array $currentAttachments, string $key, UploadedFile|array|string|null $attachment): bool
    {
        return !Arr::exists($currentAttachments, $key) || $currentAttachments[$key] !== $attachment;
    }
}
