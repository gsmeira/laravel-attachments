# Laravel Attachments

![image](https://github.com/gsmeira/laravel-attachments/assets/1690400/af0f3ff4-55f3-4113-8f18-23f784f56233)

- Seamless integration
- Flexible configuration
- Good for serverless environments
- One JSON column to hold all files in a record
- Append pre-defined data to file base path
- Handle and validate pre-signed url responses out of the box
- Path obfuscation

## Installation

**1 —** This package requires PHP 8.1 and at least Laravel 9.52. To get the latest version, simply require using [Composer](https://getcomposer.org/):

```
composer require gsmeira/laravel-attachments
```

**2 —** Once installed, if you are not using automatic package discovery, then you need to register the `GSMeira\LaravelAttachments\AttachmentsServiceProvider` service provider in your `config/app.php`.

**3 —** Publish the configuration file.

```
php artisan vendor:publish --tag=laravel-attachments
```

An `attachments.php` file will be created in your applications `config` folder.

## Usage

**1 —** Set the filesystem disk on your `.env` file.

```
FILESYSTEM_DISK=<disk>
```

**2 —** Add a `json` column named `attachments` to your model's table. Also, a `jsonb` column could be used if you are using PostgreSQL.

```php
$table->json('attachments')->nullable();
```

**3 —** Add the `HasAttachments` trait to your model.

```php
class MyModel extends Model
{
    use HasAttachments;
}
```

**4 —** Pass a valid value for the attachments when creating or updating.

```php
MyModel::create([
    // ...
    'attachments' => [
        'file' => $request->file,
    ],
]);
```

```php
$myModel->update([
  // ...
  'attachments' => [
    'file' => $request->file,
  ],
]);
```

The file will be store in the `attachments` column like this:

```json
{
  "file": "Y5EsfRvITY4SEyP3IBwUMurkkpRo69DfEMEIRlp9.jpg"
}
```

**IMPORTANT:**  There are two accepted values for an attachment. The first one is when a file is sent directly to the server as an `UploadedFile` value. The second one is when pre-signed urls are being used and an `array` with a valid `path` key is expected. We'll talk more about this second type in the section about pre-signed urls.

## How it works?

The main rule you need to be aware of when using this package is that every time the `attachments` attribute is set, a merge will be performed between the new value and the previous one already stored (if exist). So, even if you have multiple files stored you'll be able to perform actions individually. All examples below would work even if multiple files were already stored.

### How to update/create an attachment?

**Option 1 —** To update an attachment just set a new value to the key you want to update. The old file will be permanently removed from the storage disk.

```php
$myModel->update([
  'attachments' => [
    'file' => $request->file,
  ],
]);
```

**Option 2 —** The second way is using the `updateAttachments` method.

```php
$myModel->updateAttachments([
  'file' => $request->file,
]);
```

### How to remove an attachment?

**Option 1 —** Set the attachments with the key you want to remove equals `null` (or any invalid value).

```php
$myModel->update([
  'attachments' => [
    'file' => null,
  ],
]);
```

The `file` attachment will be removed from the storage disk and the key will be removed from the attachments column.

**Option 2 —** The second way is using the `deleteAttachment` method.

```php
$myModel->deleteAttachment('file');
```

If you want to delete more than one file an array can be provided to this method.

```php
$myModel->deleteAttachment(['file', 'cover_image']);
```

### How to remove all the attachments?

**Option 1 —** To remove all files you can set the attachments to `null`.

```php
$myModel->update([
  'attachments' => null,
]);
```

**Option 2 —** Also, you can use the `deleteAttachments` method.

```php
$myModel->delateAttachments();
```

### Deleting a model record

Every time a record from a model that has `HasAttachments` trait is deleted, all the files in the attachments column will be removed from the storage disk. If your model has `SoftDeletes` trait present, the files will be removed only on `forceDelete`.

## Global configuration

Located inside the `config/attachments.php` file.

### File

#### file.base_folder

The folder where all attachments will be stored. **Default:** `''`

#### file.appends

Transforms the path string stored in the database into an array with extra information. If empty, nothing will be appended to the path. **Default:** `[ AttachmentsAppend::Path, AttachmentsAppend::Url, AttachmentsAppend::Exists ]`

### Path

#### path_obfuscation.enabled

Determines if the path obfuscation is active. **Default:** `false`

#### path_obfuscation.levels

Defines how deep the path will be. **Default:** `3`

### Signed Storage

#### signed_storage.enabled

If `true`, the route to generate pre-signed urls will be registered. **Default:** `false`

#### signed_storage.temp_folder

The temporary folder that will be used to store the files when using pre-signed urls. **Default:** `tmp`

#### signed_storage.expire_after

The pre-signed urls expiration time in minutes. **Default:** `5`

#### signed_storage.route

The pre-signed url route configuration.

## Model configuration

If you want a more granular configuration you can customize some options directly at your model's file.

```php
public function attachmentsBaseFolder(): string
{
    return 'users';
}
```

```php
public function isAttachmentsPathObfuscationEnabled(): bool
{
    return true;
}
```

```php
public function attachmentsPathObfuscationLevels(): int
{
    return 2;
}
```

```php
public function attachmentsDisk(): string
{
    return 'private';
}
```

## Pre-Signed URLs

When using pre-signed urls, your files will be stored in services like s3 and the files won't be sent through the server anymore. Instead, the files should be uploaded directly from the frontend to the cloud service and just a reference for this file will be sent to the server. This package expects an array with a `path` that should match with the file location in your storage service.

### How to use this approach?

**1 —** [Configure s3 in your Laravel app](https://laravel-news.com/using-s3-with-laravel) and don't forget to set your filesystem disk to `s3`.

```
FILESYSTEM_DISK=s3
```

**2 —** Set a CORS policy for your bucket in order to send files to it.

```json
[
  {
    "AllowedHeaders": [
      "*"
    ],
    "AllowedMethods": [
      "GET",
      "PUT"
    ],
    "AllowedOrigins": [
      "*"
    ],
    "ExposeHeaders": []
  }
]
```

**IMPORTANT:** Be careful, you should probably be more restrictive than the example above in non development environments.

**3 —** Enable and configure the pre-signed url endpoint in the `attachments.php` config file.

**4 —** Create an endpoint to receive the file from the pre-signed url response. Example:

```php
protected function update(User $user, Request $request) {
    $request->validate([
        'file' => ['required', new PreSignedAttachmentRule],
    ]);

    $user->update([
        // ...
        'attachments' => [
            'file' => $request->file,
        ],
    ]);

    // ...
}
```

Always use the `PreSignedAttachmentRule` to check if the signed file is valid. This rule will check if the value is an array with a `path` key and check if the path exists on `s3`.

**5 —** On your frontend you should have something like this.

```html
<input id="file" type="file" />
```

```js
import axios from 'axios'

axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest'

const host = 'http://localhost/'

async function generatePreSignedUrlAndStore(file) {
  const response = await axios.post(`${host}/attachments/signed-storage-url`)

  const headers = response.data.headers

  if ('Host' in headers)
    delete headers.Host

  await axios.put(response.data.url, file, { headers })

  response.data.extension = file.name.split('.').pop()

  return response.data
}

document.querySelector('#file').addEventListener('change', (evt) => {
  const file = evt.target.files[0]

  generatePreSignedUrlAndStore(file).then((response) => {
    axios.post(`${host}/profile-image`, {
      file: {
        path: response.path,
        name: file.name,
        content_type: file.type,
      },
    })
  })
})
```

## Tests

This package will be fully tested before 1.0 release.

# License

Laravel Attachments is licensed under [The MIT License (MIT)](LICENSE).
