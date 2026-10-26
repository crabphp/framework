# File storage

- [Introduction](#introduction)
- [Configuration](#configuration)
    - [Disks](#disks)
    - [S3, R2 and MinIO](#s3-r2-and-minio)
- [Storing files](#storing-files)
    - [Storing an upload](#storing-an-upload)
- [Reading files](#reading-files)
    - [Sending a file as a response](#sending-a-file-as-a-response)
    - [What is known of a file](#what-is-known-of-a-file)
    - [Listing files](#listing-files)
- [Copying, moving and deleting](#copying-moving-and-deleting)
- [URLs](#urls)
    - [Temporary URLs](#temporary-urls)
    - [Uploads straight from the client](#uploads-straight-from-the-client)
- [Paths](#paths)
- [Adding a driver](#adding-a-driver)
- [Testing](#testing)

## Introduction

A disk is where your application keeps files: users' avatars, exported reports, uploaded documents. It is a folder on this machine or a bucket on S3, and your code talks to both the same way, so the files can move to S3 when the application outgrows one machine without changing the code that writes them.

The storage provider is registered by default. Out of the box there is one disk, `local`, the folder `storage/files` in your application. Disks on S3, and on the services that speak its API (Cloudflare R2, MinIO, Backblaze B2, DigitalOcean Spaces), come from the `crabphp/s3` package.

## Configuration

Disks are described in `config/storage.php`, each under a name you choose, with the `driver` that keeps its files:

```php
// config/storage.php
return [
    'default' => 'local',
    'disks' => [
        'local' => ['driver' => 'local'],
        'public' => ['driver' => 'local', 'root' => '/srv/public-files', 'url' => 'https://files.example.com'],
    ],
];
```

| Setting | Default | |
| --- | --- | --- |
| `storage.default` | `local` | The disk used when you do not name one |
| `storage.disks` | `local`, a folder in `storage/files` | Each disk's settings, by name, with its `driver` |

The drivers are `local`, a folder on this machine; `memory`, for [tests](#testing); and `s3`, from [`crabphp/s3`](#s3-r2-and-minio). Every disk's driver is checked as each worker starts, so a misspelt one stops `start` with its name instead of failing on the first upload. A setting a driver does not know is refused the same way.

### Disks

A `local` disk takes two settings:

| Setting | Default | |
| --- | --- | --- |
| `root` | `storage/files` in the application | The folder; made when the first file is written |
| `url` | none | Where the folder is served from, for [`url()`](#urls) |

A local disk reads and writes in the worker, which is fast enough for files on this machine's disk. A folder on a network mount is not: each read would hold up every request on that worker. Keep such files on a disk that speaks to the service instead.

### S3, R2 and MinIO

Install the package and register its provider after the framework's:

```sh
composer require crabphp/s3
```

```php
// app.php
use Crab\S3\S3Provider;

->withProviders([S3Provider::class, AppProvider::class])
```

Then describe the bucket:

```php
// config/storage.php
return [
    'default' => 'uploads',
    'disks' => [
        'uploads' => [
            'driver' => 's3',
            'key' => env('S3_KEY'),
            'secret' => env('S3_SECRET'),
            'region' => 'eu-west-1',
            'bucket' => 'example-uploads',
        ],
    ],
];
```

| Setting | Default | |
| --- | --- | --- |
| `key`, `secret` | | The access key's id and secret |
| `bucket` | | The bucket's name |
| `region` | `us-east-1` | Where the bucket is; `auto` for Cloudflare R2 |
| `endpoint` | `https://s3.{region}.amazonaws.com` | The service's address, for one other than AWS |
| `path_style` | true when `endpoint` is set | Whether the bucket is named in the path rather than the host; MinIO needs it |
| `prefix` | none | Put before every path, so several disks can share one bucket |
| `url` | the bucket's own address | Where the bucket is served from to anyone, such as a CDN, for [`url()`](#urls) |
| `token` | none | The session token of temporary credentials |
| `timeout` | `60` | Seconds a request may take |

For another service, name its endpoint:

```php
'r2' => [
    'driver' => 's3',
    'key' => env('R2_KEY'),
    'secret' => env('R2_SECRET'),
    'region' => 'auto',
    'endpoint' => 'https://' . env('R2_ACCOUNT') . '.r2.cloudflarestorage.com',
    'bucket' => 'example-uploads',
],
'minio' => [
    'driver' => 's3',
    'key' => 'minioadmin',
    'secret' => 'minioadmin',
    'endpoint' => 'http://127.0.0.1:9000',
    'bucket' => 'example-uploads',
],
```

Requests to S3 go through Crab's [HTTP client](http-client.md): while one waits for its answer, the worker serves other requests. The package does not use the AWS SDK, whose requests would hold up the worker.

## Storing files

Ask for a disk in a constructor. `Crab\Contracts\Storage\Disk` is the default disk; `Crab\Contracts\Storage\Storage` gives any disk by name:

```php
use Crab\Contracts\Storage\Disk;
use Crab\Contracts\Storage\Storage;

final readonly class ExportReport
{
    public function __construct(private Disk $disk, private Storage $storage)
    {
    }

    public function handle(int $id): array
    {
        $this->disk->write("reports/{$id}.csv", $this->csv($id), 'text/csv');
        $this->storage->disk('backups')->write("reports/{$id}.csv", $this->csv($id));

        return ['report' => "reports/{$id}.csv"];
    }
}
```

`write()` takes a string or a PSR-7 stream, and the file's media type for a disk that keeps one (S3 does; a local disk does not). It replaces a file already at the path. A file is written whole or not at all: someone reading the path meanwhile gets the old file or the new one, never part of either.

### Storing an upload

Pass the upload's stream, which is copied as it is read and never held in memory whole:

```php
public function handle(User $user, NewAvatar $input): array
{
    $this->disk->write("avatars/{$user->id}.png", $input->avatar->getStream(), 'image/png');

    return ['avatar' => "avatars/{$user->id}.png"];
}
```

`NewAvatar` is the body's input class, whose `avatar` is the file. The path is made from the logged-in user's id, never from the client's file name. [Uploaded files](requests.md#uploaded-files) has the whole action and its input class, the rules for a file, and the limits on what a client may send.

## Reading files

`contents()` returns the bytes; `read()` returns them as a stream, for a file too large to hold in memory:

```php
$csv = $disk->contents('reports/7.csv');
$stream = $disk->read('reports/7.csv');
```

Both throw `Crab\Contracts\Storage\FileNotFound` when there is no file. Bytes come back exactly as they were written.

### Sending a file as a response

A stream is a response body as it is:

```php
use Crab\Contracts\Storage\FileNotFound;
use Crab\Http\HttpException;
use Crab\Http\Response;

try {
    $stream = $disk->read("reports/{$id}.csv");
} catch (FileNotFound) {
    throw new HttpException(404, 'No such report.');
}

return new Response(200, ['Content-Type' => 'text/csv'], $stream);
```

From S3, `read()` returns once the whole file has arrived, kept in memory up to 1 MiB and in a temporary file beyond. For a large download, send the client a [temporary URL](#temporary-urls) instead, so the bytes go from the bucket to the client without passing through the worker.

### What is known of a file

`file()` returns a `Crab\Contracts\Storage\File`, or null when there is none; `exists()` says only whether there is one:

```php
$file = $disk->file('reports/7.csv');

$file->path;          // "reports/7.csv"
$file->size;          // in bytes
$file->modified;      // a DateTimeImmutable
$file->type;          // "text/csv" on S3; null on a local disk
```

### Listing files

`files()` lists every file whose path starts with a prefix, in order of path. `"reports/"` is a folder's files, with those of the folders inside it:

```php
foreach ($disk->files('reports/') as $file) {
    // $file->path, $file->size, $file->modified
}
```

Files are read a page at a time as you iterate, so a bucket of millions does not fill memory. A listing does not carry each file's media type; ask `file()` for one.

## Copying, moving and deleting

```php
$disk->copy('incoming/upload.png', 'avatars/7.png');
$disk->move('incoming/upload.png', 'avatars/7.png');
$disk->delete('avatars/7.png');
```

`copy()` and `move()` replace a file at the destination, and throw `FileNotFound` when there is nothing to copy. On S3 the copy happens inside the service, without downloading the file. Deleting a file that is not there is no failure.

Writing to a staging path and moving the file into place once it has been checked keeps a half-processed upload from ever being served.

## URLs

`url()` is where anyone can download a file. It comes from the disk's `url` setting, such as a CDN in front of the bucket or the web server that serves a local folder:

```php
$disk->url('avatars/7.png');    // https://files.example.com/avatars/7.png
```

A local disk with no `url` throws `LogicException`. An S3 disk with none gives the bucket's own address, which serves the file only if the bucket is public.

### Temporary URLs

A temporary URL downloads a private file until the time you give, and carries its own signature, so the client needs no credentials:

```php
use Psr\Clock\ClockInterface;

// with ClockInterface $clock in the constructor
$url = $this->disk->temporaryUrl('reports/7.csv', $this->clock->now()->modify('+10 minutes'));
```

It lasts from one second to seven days. Anyone holding the URL can use it until then, so keep it short and give it only to whoever may read the file. A local disk cannot sign one and throws `LogicException`; serve the file from an action that checks who asks.

### Uploads straight from the client

For a large upload, let the client send the file to the bucket itself, with `PUT`, and keep its bytes out of your workers:

```php
// app/Actions/StartVideoUpload.php
final readonly class StartVideoUpload
{
    public function __construct(private Disk $disk, private ClockInterface $clock)
    {
    }

    public function handle(User $user): array
    {
        $path = "videos/{$user->id}/" . bin2hex(random_bytes(8)) . '.mp4';

        return [
            'path' => $path,
            'upload' => $this->disk->temporaryUploadUrl($path, $this->clock->now()->modify('+15 minutes'), 'video/mp4'),
        ];
    }
}
```

The client must send the media type you named as its `Content-Type`, or the service refuses the upload. Your application chose the path, so the client cannot write anywhere else. Check that the file arrived with `file()` before you record it.

## Paths

A path is relative, with `/` between its parts, the same on every disk. A path that is empty, absolute, contains `\`, or has an empty, `.` or `..` part throws `Crab\Contracts\Storage\InvalidPath` before the disk is touched, so a path built from user input cannot lead outside the disk's folder or prefix.

Any other character is allowed and is encoded where a URL needs it: `reports/q1 & q2.csv` is a valid path.

Every failure a disk reports is a `Crab\Contracts\Storage\StorageException`: `FileNotFound` and `InvalidPath` are kinds of it, and the rest are a disk that could not be reached or refused what was asked. The message says which disk, path and answer.

## Adding a driver

A package adds a driver in its provider, through `Crab\Contracts\Storage\Drivers`, after the framework's providers. It names the settings the driver takes, and the closure receives a disk's settings and name, once, when the disk is first used:

```php
use Crab\Contracts\Container\Container;
use Crab\Contracts\Provider;
use Crab\Contracts\Storage\Disk;
use Crab\Contracts\Storage\Drivers;

final class FtpProvider implements Provider
{
    public function register(Container $container): void
    {
        $container->get(Drivers::class)->add('ftp', ['host', 'username', 'password'], static function (array $settings, string $name): Disk {
            return new FtpDisk($settings['host'], $settings['username'], $settings['password']);
        });
    }
}
```

A setting the driver does not name is refused before the closure is called. A disk implements `Crab\Contracts\Storage\Disk` and checks its paths with `Crab\Contracts\Storage\Path`, as the framework's do; a driver that talks to a service over the network should use Crab's [HTTP client](http-client.md), so its requests do not hold up the worker.

## Testing

Point the default disk at the `memory` driver in `phpunit.xml`'s configuration, or in a test, and nothing is written outside the process:

```php
// config/storage.php
return [
    'default' => env('STORAGE_DISK', 'local'),
    'disks' => [
        'local' => ['driver' => 'local'],
        'testing' => ['driver' => 'memory'],
    ],
];
```

```xml
<env name="STORAGE_DISK" value="testing" force="true"/>
```

A memory disk keeps what is written until the worker ends, and its URLs name the path and time without reaching anything, so a test can assert on them:

```php
self::assertSame('a,b', $disk->contents('reports/7.csv'));
self::assertSame('memory://reports/7.csv?expires=1767225600', $disk->temporaryUrl('reports/7.csv', $expires));
```

See [Testing](testing.md) for running requests against your application.
